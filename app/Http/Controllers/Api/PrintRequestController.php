<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PrintRequest;
use App\Models\FeatureSheet;
use App\Models\Notification;
use App\Mail\NewPrintRequestSubmitted;
use App\Mail\PrintRequestReady;
use App\Mail\PrintRequestCompleted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
                'is_read' => false,
                'read_at' => null,
            ]
        );

        // Send in-app notification & email to admin for newly submitted print request
        self::notifyAdminNewPrintRequest($printRequest, $featureSheet);

        // Check and notify admin if both published AND paid
        self::checkAndNotifyPrintReady($featureSheet);

        return response()->json([
            'message' => 'Print request processed successfully',
            'data' => $printRequest
        ], 201);
    }

    /**
     * Notify admin that a new print request has been submitted.
     */
    public static function notifyAdminNewPrintRequest(PrintRequest $printRequest, FeatureSheet $featureSheet): void
    {
        try {
            $order = $featureSheet->order;
            $agent = $order?->agent ?? \App\Models\Agent::where('uuid', $printRequest->agent_id)->first();
            $property = $order?->property ?? \App\Models\Property::where('uuid', $printRequest->property_id)->first();
            $orgId = $order?->organization_id ?? $agent?->organization_id ?? $printRequest->organization_id;
            $organization = $orgId ? \App\Models\Organization::find($orgId) : null;

            $agentName = $agent ? trim("{$agent->first_name} {$agent->last_name}") : 'Agent';
            $address = $order?->property_address ?? $property?->address ?? 'Property';

            // Query active admins (case-insensitive for PostgreSQL, without non-existent deleted_at)
            $adminQuery = \App\Models\User::whereHas('roles', function ($query) {
                $query->whereIn(DB::raw('LOWER(roles.name)'), ['admin', 'super admin']);
            })
            ->where(function ($q) {
                $q->whereNull('account_closed')->orWhere('account_closed', false);
            });

            if ($orgId) {
                $adminQuery->where(function ($q) use ($orgId) {
                    $q->where('organization_id', $orgId)->orWhereNull('organization_id');
                });
            }

            $adminUsers = $adminQuery->get();

            foreach ($adminUsers as $adminUser) {
                // 1. In-app notification for Admin
                try {
                    Notification::create([
                        'source' => 'PrintRequest',
                        'source_id' => $printRequest->uuid,
                        'type' => 'print_request',
                        'description' => "New print request received from {$agentName} for {$address} ({$printRequest->copies} copies).",
                        'user_uuid' => $adminUser->uuid,
                        'agent_uuid' => $agent?->uuid,
                        'role' => 'admin',
                        'created_by_name' => $agentName,
                        'organization_id' => $orgId,
                        'diff_data' => [],
                        'meta_data' => [
                            'print_request_uuid' => $printRequest->uuid,
                            'feature_sheet_uuid' => $featureSheet->uuid,
                            'order_id' => $order?->id ?? $featureSheet->order_id,
                            'order_uuid' => $order?->uuid ?? (string) $featureSheet->order_id,
                            'property_address' => $address,
                            'copies' => $printRequest->copies,
                            'agent_name' => $agentName,
                            'agent_email' => $agent?->email,
                        ],
                    ]);
                } catch (\Throwable $notifEx) {
                    Log::warning("Failed creating admin print request notification for {$adminUser->email}: " . $notifEx->getMessage());
                }

                // 2. Email notification to Admin
                if (!empty($adminUser->email) && ($adminUser->notification_email ?? true)) {
                    try {
                        $mailable = new NewPrintRequestSubmitted(
                            $printRequest,
                            $featureSheet,
                            $order,
                            $adminUser->first_name ?: 'Admin'
                        );
                        app(\App\Services\EmailDispatchService::class)->sendDirectMailable(
                            $mailable,
                            $adminUser->email,
                            'admin',
                            $organization,
                            'new_print_request'
                        );
                    } catch (\Throwable $mailEx) {
                        Log::warning("Failed sending new print request email to {$adminUser->email}: " . $mailEx->getMessage());
                    }
                }
            }

            // 3. In-app notification for the Agent confirming submission
            if ($agent) {
                try {
                    Notification::create([
                        'source' => 'PrintRequest',
                        'source_id' => $printRequest->uuid,
                        'type' => 'print_request',
                        'description' => "Your print request for {$address} ({$printRequest->copies} copies) has been submitted.",
                        'user_uuid' => null,
                        'agent_uuid' => $agent->uuid,
                        'role' => 'agent',
                        'created_by_name' => 'System',
                        'organization_id' => $orgId,
                        'diff_data' => [],
                        'meta_data' => [
                            'print_request_uuid' => $printRequest->uuid,
                            'feature_sheet_uuid' => $featureSheet->uuid,
                            'order_id' => $order?->id ?? $featureSheet->order_id,
                            'order_uuid' => $order?->uuid ?? (string) $featureSheet->order_id,
                            'property_address' => $address,
                            'copies' => $printRequest->copies,
                        ],
                    ]);
                } catch (\Throwable $agNotifEx) {
                    Log::warning("Failed creating agent print request submission notification: " . $agNotifEx->getMessage());
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to dispatch new print request notification: ' . $e->getMessage());
        }
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
                $alreadyNotified = Notification::where('source', 'PrintRequest')
                    ->where('source_id', $printRequest->uuid)
                    ->where('type', 'admin_print_request_ready')
                    ->exists();

                if (!$alreadyNotified) {
                    $order = $featureSheet->order;
                    $agent = $order?->agent ?? \App\Models\Agent::where('uuid', $printRequest->agent_id)->first();
                    $property = $order?->property ?? \App\Models\Property::where('uuid', $printRequest->property_id)->first();
                    $orgId = $order?->organization_id ?? $agent?->organization_id ?? $printRequest->organization_id;
                    $organization = $orgId ? \App\Models\Organization::find($orgId) : null;
                    $address = $order?->property_address ?? $property?->address ?? 'Property';

                    // Query active admins in organization
                    $adminQuery = \App\Models\User::whereHas('roles', function ($query) {
                        $query->whereIn(DB::raw('LOWER(roles.name)'), ['admin', 'super admin']);
                    })
                    ->where(function ($q) {
                        $q->whereNull('account_closed')->orWhere('account_closed', false);
                    });

                    if ($orgId) {
                        $adminQuery->where(function ($q) use ($orgId) {
                            $q->where('organization_id', $orgId)->orWhereNull('organization_id');
                        });
                    }

                    $adminUsers = $adminQuery->get();

                    foreach ($adminUsers as $adminUser) {
                        // 1. In-portal notification
                        try {
                            Notification::create([
                                'source' => 'PrintRequest',
                                'source_id' => $printRequest->uuid,
                                'type' => 'admin_print_request_ready',
                                'description' => "Feature sheet for order #{$featureSheet->order_id} is published and ready for printing ({$printRequest->copies} copies).",
                                'user_uuid' => $adminUser->uuid,
                                'agent_uuid' => $agent?->uuid,
                                'role' => 'admin',
                                'created_by_name' => 'System',
                                'organization_id' => $orgId,
                                'diff_data' => [],
                                'meta_data' => [
                                    'print_request_uuid' => $printRequest->uuid,
                                    'feature_sheet_uuid' => $featureSheet->uuid,
                                    'order_id' => $order?->id ?? $featureSheet->order_id,
                                    'order_uuid' => $order?->uuid ?? (string) $featureSheet->order_id,
                                    'property_address' => $address,
                                    'copies' => $printRequest->copies,
                                ],
                            ]);
                        } catch (\Throwable $notifEx) {
                            Log::warning("Failed creating admin print ready notification: " . $notifEx->getMessage());
                        }

                        // 2. Email notification
                        if (!empty($adminUser->email) && ($adminUser->notification_email ?? true)) {
                            try {
                                $mailable = new PrintRequestReady(
                                    $printRequest,
                                    $featureSheet,
                                    $order,
                                    $adminUser->first_name ?: 'Admin'
                                );
                                app(\App\Services\EmailDispatchService::class)->sendDirectMailable(
                                    $mailable,
                                    $adminUser->email,
                                    'admin',
                                    $organization,
                                    'print_request_ready'
                                );
                            } catch (\Throwable $mailEx) {
                                Log::warning("Failed sending print ready email to {$adminUser->email}: " . $mailEx->getMessage());
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to dispatch print ready notification: ' . $e->getMessage());
            }
        }
    }

    /**
     * Helper to resolve the matching invoice and order service for a print request.
     */
    protected function resolveInvoiceForRequest(PrintRequest $item)
    {
        $fsUuid = $item->feature_sheet_id;
        $fsNumericId = $item->featureSheet?->id;
        $order = $item->featureSheet?->order;

        $orderService = \App\Models\OrderService::where(function ($q) use ($fsUuid, $fsNumericId) {
            if ($fsUuid) {
                $q->where('feature_sheet_uuid', $fsUuid);
            }
            if ($fsNumericId) {
                $q->orWhere('feature_sheet_id', $fsNumericId);
            }
        })->first();

        if (!$orderService && $order) {
            $orderService = \App\Models\OrderService::where('order_id', $order->id)
                ->where(function ($q) {
                    $q->where('custom', 'like', '%Print%')
                      ->orWhere('custom', 'like', '%Feature Sheet%')
                      ->orWhereHas('service', function ($sq) {
                          $sq->where('name', 'like', '%Print%')
                             ->orWhere('name', 'like', '%Feature Sheet%');
                      });
                })->latest()->first();
        }

        $invoice = null;
        if ($orderService) {
            $invoice = \App\Models\Invoice::with(['items.orderService.service', 'agent', 'order', 'organization'])
                ->whereHas('items', function ($itemQuery) use ($orderService) {
                    $itemQuery->where('order_service_id', $orderService->id);
                })
                ->where('status', '!=', 'void')
                ->latest()
                ->first();
        }

        if (!$invoice && $order) {
            $invoice = \App\Models\Invoice::with(['items.orderService.service', 'agent', 'order', 'organization'])
                ->where('order_id', $order->id)
                ->where('status', '!=', 'void')
                ->latest()
                ->first();
        }

        // If no invoice exists yet but orderService and order exist, auto-generate invoice
        if (!$invoice && $orderService && $order) {
            try {
                $invoice = \App\Models\Invoice::generateForNewServices($order, collect([$orderService]));
                if ($invoice) {
                    $invoice->load(['items.orderService.service', 'agent', 'order', 'organization']);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Failed to auto-generate invoice for print request: " . $e->getMessage());
            }
        }

        return [$invoice, $orderService];
    }

    /**
     * Fetch print requests.
     * Admin: all requests in organization
     * Agent/SubAccount: only their own requests
     * GET /api/admin/print-requests
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        $query = PrintRequest::with(['featureSheet.order', 'agent', 'property', 'tour']);

        // Check if user is an agent or subaccount
        if ($user instanceof \App\Models\Agent) {
            $query->where('agent_id', $user->uuid);
        } elseif ($user instanceof \App\Models\SubAccount) {
            $parentAgent = \App\Models\Agent::find($user->agent_id);
            $parentAgentUuid = $parentAgent?->uuid;
            if ($parentAgentUuid) {
                $query->where('agent_id', $parentAgentUuid);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        $requests = $query->orderBy('created_at', 'desc')->get();

        // Attach actual invoice and payment status to each request
        $requests->transform(function ($item) {
            [$invoice, $orderService] = $this->resolveInvoiceForRequest($item);
            $order = $item->featureSheet?->order;

            $paymentStatus = 'UNPAID';
            if ($invoice) {
                if (in_array(strtolower($invoice->status), ['paid', 'refunded'])) {
                    $paymentStatus = 'PAID';
                } elseif ($invoice->total > 0 && $invoice->paid_amount >= $invoice->total) {
                    $paymentStatus = 'PAID';
                } elseif ($orderService && strtoupper($orderService->payment_status) === 'PAID') {
                    $paymentStatus = 'PAID';
                }
            } elseif ($orderService && strtoupper($orderService->payment_status) === 'PAID') {
                $paymentStatus = 'PAID';
            } elseif ($order && ($order->payment_status === 'PAID' || $order->status === 'paid')) {
                $paymentStatus = 'PAID';
            }

            $item->payment_status = $paymentStatus;
            $item->order_uuid = $order?->uuid ?? $item->featureSheet?->order_id;
            $item->order_number = $order?->id ?? null;
            $item->order = $order;
            $item->invoice = $invoice;
            $item->invoice_uuid = $invoice?->uuid;
            $item->invoice_number = $invoice?->invoice_number;
            return $item;
        });

        return response()->json([
            'data' => $requests
        ]);
    }

    /**
     * Get the actual invoice for a print request.
     * GET /api/admin/print-requests/{uuid}/invoice
     */
    public function getInvoice($uuid)
    {
        $user = auth()->user();
        $printRequest = PrintRequest::with(['featureSheet.order', 'agent', 'property'])->where('uuid', $uuid)->firstOrFail();

        if ($user instanceof \App\Models\Agent && $printRequest->agent_id !== $user->uuid) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        [$invoice, $orderService] = $this->resolveInvoiceForRequest($printRequest);
        $order = $printRequest->featureSheet?->order;

        $paymentStatus = 'UNPAID';
        if ($invoice && (in_array(strtolower($invoice->status), ['paid', 'refunded']) || ($invoice->total > 0 && $invoice->paid_amount >= $invoice->total))) {
            $paymentStatus = 'PAID';
        } elseif ($orderService && strtoupper($orderService->payment_status) === 'PAID') {
            $paymentStatus = 'PAID';
        } elseif ($order && ($order->payment_status === 'PAID' || $order->status === 'paid')) {
            $paymentStatus = 'PAID';
        }

        return response()->json([
            'data' => $invoice,
            'order' => $order,
            'payment_status' => $paymentStatus,
        ]);
    }

    /**
     * Get count of unread print requests for admin, or attention count (unpaid / status changes) for agent.
     * GET /api/admin/print-requests/unread-count
     */
    public function unreadCount(Request $request)
    {
        $user = auth()->user();

        if ($user instanceof \App\Models\Agent || $user instanceof \App\Models\SubAccount) {
            $agentUuid = $user instanceof \App\Models\Agent
                ? $user->uuid
                : \App\Models\Agent::find($user->agent_id)?->uuid;

            if (!$agentUuid) {
                return response()->json(['unread_count' => 0]);
            }

            $agentRequests = PrintRequest::with(['featureSheet.order'])->where('agent_id', $agentUuid)->get();
            $agentBadgeCount = 0;

            foreach ($agentRequests as $req) {
                [$invoice, $orderService] = $this->resolveInvoiceForRequest($req);
                $isPaid = false;

                if ($invoice && (in_array(strtolower($invoice->status), ['paid', 'refunded']) || ($invoice->total > 0 && $invoice->paid_amount >= $invoice->total))) {
                    $isPaid = true;
                } elseif ($orderService && strtoupper($orderService->payment_status) === 'PAID') {
                    $isPaid = true;
                } else {
                    $order = $req->featureSheet?->order;
                    $isPaid = $order && ($order->payment_status === 'PAID' || $order->status === 'paid');
                }

                if (!$isPaid) {
                    $agentBadgeCount++;
                } elseif (!$req->agent_read) {
                    $agentBadgeCount++;
                }
            }

            return response()->json([
                'unread_count' => $agentBadgeCount
            ]);
        }

        // Admin: count unread requests
        $count = PrintRequest::where('is_read', false)->count();

        return response()->json([
            'unread_count' => $count
        ]);
    }

    /**
     * Mark a single print request as read (by admin or agent).
     * PATCH /api/admin/print-requests/{uuid}/read
     */
    public function markAsRead($uuid)
    {
        $user = auth()->user();
        $printRequest = PrintRequest::where('uuid', $uuid)->firstOrFail();

        if ($user instanceof \App\Models\Agent || $user instanceof \App\Models\SubAccount) {
            $printRequest->update([
                'agent_read' => true,
                'agent_read_at' => now(),
            ]);
        } else {
            $printRequest->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Print request marked as read',
            'data' => $printRequest
        ]);
    }

    /**
     * Mark all print requests as read (by admin or agent).
     * POST /api/admin/print-requests/mark-all-read
     */
    public function markAllRead()
    {
        $user = auth()->user();

        if ($user instanceof \App\Models\Agent || $user instanceof \App\Models\SubAccount) {
            $agentUuid = $user instanceof \App\Models\Agent
                ? $user->uuid
                : \App\Models\Agent::find($user->agent_id)?->uuid;

            if ($agentUuid) {
                PrintRequest::where('agent_id', $agentUuid)
                    ->where('agent_read', false)
                    ->update([
                        'agent_read' => true,
                        'agent_read_at' => now(),
                    ]);
            }
        } else {
            PrintRequest::where('is_read', false)->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'All print requests marked as read'
        ]);
    }

    /**
     * Update the status of a print request.
     * PATCH /api/admin/print-requests/{request_uuid}
     */
    public function update(Request $request, $uuid)
    {
        $user = auth()->user();
        if ($user instanceof \App\Models\Agent || $user instanceof \App\Models\SubAccount) {
            return response()->json([
                'message' => 'Unauthorized. Agents cannot update print request status.'
            ], 403);
        }

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

        $oldStatus = $printRequest->status;
        $statusChanged = $oldStatus !== $request->status;

        $printRequest->update([
            'status' => $request->status,
            // When status changes (e.g. Processing, Completed), mark as unread for the agent so they get a badge
            'agent_read' => $statusChanged ? false : $printRequest->agent_read,
        ]);

        if ($statusChanged) {
            self::notifyAgentStatusChange($printRequest, $request->status, $oldStatus);
        }

        return response()->json([
            'message' => 'Print request status updated successfully',
            'data' => $printRequest
        ]);
    }

    /**
     * Notify agent when their print request status changes or is completed.
     */
    public static function notifyAgentStatusChange(PrintRequest $printRequest, string $newStatus, string $oldStatus = ''): void
    {
        try {
            $agent = $printRequest->agent ?? \App\Models\Agent::where('uuid', $printRequest->agent_id)->first();
            if (!$agent) {
                return;
            }

            $featureSheet = $printRequest->featureSheet ?? FeatureSheet::where('uuid', $printRequest->feature_sheet_id)->first();
            $order = $featureSheet?->order;
            $property = $order?->property ?? $printRequest->property ?? \App\Models\Property::where('uuid', $printRequest->property_id)->first();
            $address = $order?->property_address ?? $property?->address ?? 'Property';
            $agentName = trim("{$agent->first_name} {$agent->last_name}") ?: ($agent->company_name ?: 'Agent');
            $orgId = $order?->organization_id ?? $agent->organization_id ?? $printRequest->organization_id;
            $organization = $orgId ? \App\Models\Organization::find($orgId) : null;

            $isCompleted = ($newStatus === 'Completed');
            $description = $isCompleted
                ? "Your print request for {$address} ({$printRequest->copies} copies) has been marked as Completed!"
                : "Your print request for {$address} ({$printRequest->copies} copies) status changed to {$newStatus}.";

            // 1. In-app notification for the Agent
            try {
                Notification::create([
                    'source' => 'PrintRequest',
                    'source_id' => $printRequest->uuid,
                    'type' => $isCompleted ? 'print_request_completed' : 'print_request',
                    'description' => $description,
                    'user_uuid' => null,
                    'agent_uuid' => $agent->uuid,
                    'role' => 'agent',
                    'created_by_name' => 'Admin',
                    'organization_id' => $orgId,
                    'diff_data' => [
                        'status' => [
                            'old' => $oldStatus,
                            'new' => $newStatus,
                        ],
                    ],
                    'meta_data' => [
                        'print_request_uuid' => $printRequest->uuid,
                        'feature_sheet_uuid' => $featureSheet?->uuid,
                        'order_id' => $order?->id ?? $featureSheet?->order_id,
                        'order_uuid' => $order?->uuid ?? (string) ($featureSheet?->order_id ?? ''),
                        'property_address' => $address,
                        'copies' => $printRequest->copies,
                        'status' => $newStatus,
                    ],
                ]);
            } catch (\Throwable $notifEx) {
                Log::warning("Failed creating agent status change notification: " . $notifEx->getMessage());
            }

            // 2. Email notification to Agent when marked Completed
            if ($isCompleted && !empty($agent->email) && ($agent->notification_email ?? true)) {
                try {
                    $mailable = new PrintRequestCompleted(
                        $printRequest,
                        $featureSheet,
                        $order,
                        $agent,
                        $agentName
                    );
                    app(\App\Services\EmailDispatchService::class)->sendDirectMailable(
                        $mailable,
                        $agent->email,
                        'agent',
                        $organization,
                        'print_request_completed'
                    );
                } catch (\Throwable $mailEx) {
                    Log::warning("Failed sending print request completed email to {$agent->email}: " . $mailEx->getMessage());
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Failed to dispatch agent print request status change notification: " . $e->getMessage());
        }
    }
}
