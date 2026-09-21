<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PrintRequest;
use App\Models\FeatureSheet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PrintRequestController extends Controller
{
    /**
     * Create a new print request notification for the admin.
     * POST /api/feature-sheets/{feature_sheet_uuid}/print-request
     */
    public function store(Request $request, $feature_sheet_uuid)
    {
        $featureSheet = FeatureSheet::where('uuid', $feature_sheet_uuid)->firstOrFail();

        $validator = Validator::make($request->all(), [
            'copies' => 'required|integer|min:1',
            'option_id' => 'nullable|string',
            'amount' => 'nullable|numeric',
            'with_bleed' => 'required|boolean',
            'additional_info' => 'nullable|string',
            'agent_id' => 'required|uuid|exists:agents,uuid',
            'property_id' => 'required|uuid|exists:properties,uuid',
            'tour_id' => 'required|uuid|exists:tours,uuid',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $printRequest = PrintRequest::updateOrCreate(
            [
                'feature_sheet_id' => $featureSheet->uuid,
                'status' => 'Pending',
            ],
            [
                'copies' => $request->copies,
                'option_id' => $request->option_id,
                'amount' => $request->amount,
                'with_bleed' => $request->with_bleed,
                'additional_info' => $request->additional_info,
                'agent_id' => $request->agent_id,
                'property_id' => $request->property_id,
                'tour_id' => $request->tour_id,
            ]
        );

        // Check and notify admin if both published AND paid
        self::checkAndNotifyPrintReady($featureSheet);

        return response()->json([
            'message' => 'Print request processed successfully',
            'data' => $printRequest
        ], 201);
    }

    /**
     * Check if a feature sheet print request is both published and paid,
     * and notify admin if so.
     */
    public static function checkAndNotifyPrintReady(FeatureSheet $featureSheet): void
    {
        if (!$featureSheet->is_published) {
            return;
        }

        $printRequest = PrintRequest::where('feature_sheet_id', $featureSheet->uuid)
            ->whereNotIn('status', ['Completed', 'Cancelled'])
            ->latest()
            ->first();

        if (!$printRequest) {
            return;
        }

        // Check if the order service or order is paid
        $orderService = \App\Models\OrderService::where('feature_sheet_uuid', $featureSheet->uuid)->first();
        $isPaid = false;
        if ($orderService) {
            $isPaid = ($orderService->payment_status === 'PAID');
        } else {
            $order = $featureSheet->order;
            $isPaid = $order && ($order->payment_status === 'PAID' || $order->status === 'paid');
        }

        if ($isPaid) {
            try {
                $alreadyNotified = \App\Models\Notification::where('source', 'PrintRequest')
                    ->where('source_id', $printRequest->uuid)
                    ->where('type', 'admin_print_request_ready')
                    ->exists();

                if (!$alreadyNotified) {
                    $order = $featureSheet->order;
                    $agent = $order?->agent ?? \App\Models\Agent::where('uuid', $printRequest->agent_id)->first();
                    $orgId = $order?->organization_id ?? $agent?->organization_id ?? $printRequest->organization_id;

                    // Query admins belonging to this organization
                    $adminQuery = \App\Models\User::whereHas('roles', function ($query) {
                        $query->where('roles.name', 'admin');
                    })
                    ->whereNull('deleted_at')
                    ->where('account_closed', false);

                    if ($orgId) {
                        $adminQuery->where('organization_id', $orgId);
                    }

                    $adminUsers = $adminQuery->get();

                    $notifications = [];
                    foreach ($adminUsers as $adminUser) {
                        // 1. In-portal notification
                        $notifications[] = [
                            'uuid' => (string) \Illuminate\Support\Str::uuid(),
                            'source' => 'PrintRequest',
                            'source_id' => $printRequest->uuid,
                            'type' => 'admin_print_request_ready',
                            'description' => "Feature sheet for order #{$featureSheet->order_id} is published and ready for printing ({$printRequest->copies} copies).",
                            'diff_data' => json_encode([]),
                            'meta_data' => json_encode([
                                'print_request_uuid' => $printRequest->uuid,
                                'feature_sheet_uuid' => $featureSheet->uuid,
                                'order_uuid' => $featureSheet->order_id,
                                'copies' => $printRequest->copies,
                            ]),
                            'user_id' => $adminUser->uuid,
                            'role' => 'admin',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];

                        // 2. Email notification
                        if ($adminUser->notification_email && !empty($adminUser->email)) {
                            try {
                                \Illuminate\Support\Facades\Mail::to($adminUser->email)
                                    ->send(new \App\Mail\PrintRequestReady($printRequest, $featureSheet, $order, $adminUser->first_name ?: 'Admin'));
                            } catch (\Throwable $mailEx) {
                                \Illuminate\Support\Facades\Log::warning("Failed sending print ready email to {$adminUser->email}: " . $mailEx->getMessage());
                            }
                        }
                    }

                    if (!empty($notifications)) {
                        \App\Models\Notification::insert($notifications);
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Failed to dispatch print ready notification: ' . $e->getMessage());
            }
        }
    }

    /**
     * Fetch all pending print requests for admin review.
     * GET /api/admin/print-requests
     */
    public function index()
    {
        $requests = PrintRequest::with(['featureSheet', 'agent', 'property', 'tour'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $requests
        ]);
    }

    /**
     * Update the status of a print request.
     * PATCH /api/admin/print-requests/{request_uuid}
     */
    public function update(Request $request, $uuid)
    {
        $printRequest = PrintRequest::where('uuid', $uuid)->firstOrFail();

        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:Pending,Processing,Completed,Cancelled',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $printRequest->update([
            'status' => $request->status,
        ]);

        return response()->json([
            'message' => 'Print request status updated successfully',
            'data' => $printRequest
        ]);
    }
}
