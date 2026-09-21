<?php

namespace App\Http\Controllers\Api;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use App\Models\Agent;
use App\Models\Vendor;
use App\Models\User;
use App\Models\Organization;
use App\Models\EmailLog;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;
use App\Http\Controllers\Controller;
use App\Notifications\SystemNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use App\Models\Notification as NotificationModel;
use Illuminate\Support\Facades\Notification as NotificationFacade;

class NotificationController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'source' => 'required|string|max:255', // e.g. order
            'source_id' => 'required|string|max:255', // e.g. order UUID
            'type' => 'required|string|max:255', // e.g. order_created, order_cancelled
            'description' => 'required|string',

            // UUID references
            'agent_uuid' => 'nullable|string|max:255',
            'vendor_uuids' => 'nullable|array',
            'vendor_uuids.*' => 'string|max:255',
            'user_uuid' => 'nullable|string|max:255',

            'role' => 'required|in:admin,vendor,agent',
            'created_by_name' => 'required|string|max:255',
            'meta_data' => 'nullable|array',
            'diff_data' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $data['uuid'] = (string) Str::uuid();

        if (isset($data['vendor_uuids'])) {
            $data['vendor_uuids'] = json_encode($data['vendor_uuids']);
        }

        // Auto-assign organization_id and enrich meta_data if source is order
        if (strtolower($data['source']) === 'order' && !empty($data['source_id'])) {
            $order = Order::withoutGlobalScopes()->where('uuid', $data['source_id'])->first();
            if ($order) {
                if (empty($data['organization_id'])) {
                    $data['organization_id'] = $order->organization_id;
                }
                if (empty($data['meta_data'])) {
                    $data['meta_data'] = [
                        'order_id' => $order->id,
                        'order_uuid' => $order->uuid,
                        'property_address' => $order->property_address,
                        'property_location' => $order->property_location,
                    ];
                }
            }
        }

        $notification = NotificationModel::create($data);

        return response()->json([
            'success' => true,
            'data' => $notification,
            'message' => 'Notification created successfully',
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'data' => null,
                    'message' => 'User not authenticated',
                ], 401);
            }

            $query = NotificationModel::query();

            // Role-based filtering
            if ($user instanceof Vendor) {
                $query->where(function ($q) use ($user) {
                    $q->where('vendor_uuids', 'ILIKE', "%{$user->uuid}%")
                    ->orWhere('user_uuid', $user->uuid);
                });
            } elseif ($user instanceof Agent) {
                $query->where(function ($q) use ($user) {
                    $q->where('agent_uuid', $user->uuid)
                    ->orWhere('user_uuid', $user->uuid)
                    ->orWhere('role', 'agent');
                })
                ->whereNotNull('source_id');
            } else {
                $query->where(function ($q) use ($user) {
                    $q->where('role', 'admin')
                    ->orWhere('user_uuid', $user->uuid);
                });
            }

            // Date window: default to last 1 month unless client explicitly requests a range
            $startDate = null;
            $endDate = null;

            // Accept explicit start/end or a relative month count (e.g., months=3)
            $request->validate([
                'start_date' => 'sometimes|date',
                'end_date' => 'sometimes|date',
                'months' => 'sometimes|integer|min:1|max:12',
            ]);

            if ($request->filled('start_date') || $request->filled('end_date')) {
                $startDate = $request->filled('start_date')
                    ? Carbon::parse($request->input('start_date'))->startOfDay()
                    : null;
                $endDate = $request->filled('end_date')
                    ? Carbon::parse($request->input('end_date'))->endOfDay()
                    : Carbon::now();
            } elseif ($request->filled('months')) {
                $months = (int) $request->input('months');
                $startDate = Carbon::now()->subMonths($months)->startOfDay();
                $endDate = Carbon::now();
            } else {
                // Default: restrict to last 1 month
                $startDate = Carbon::now()->subMonth()->startOfDay();
                $endDate = Carbon::now();
            }

            if ($startDate && $endDate) {
                $query->whereBetween('created_at', [$startDate, $endDate]);
            }

            // Get counts before fetching (using cloned query)
            $totalCount = (clone $query)->count();
            $unreadCount = (clone $query)->where('is_read', false)->count();

            // Get ALL notifications for the period (no pagination - frontend handles it)
            $notifications = $query
                ->with([
                    'order.agent',
                    'order.services.service',
                    'order.services.option',
                    'order.slots.vendor',
                ])
                ->orderByDesc('created_at')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $notifications,
                'counts' => [
                    'total' => $totalCount,
                    'unread' => $unreadCount,
                    'read' => $totalCount - $unreadCount,
                ],
                'date_range' => [
                    'start' => $startDate ? $startDate->toDateTimeString() : null,
                    'end' => $endDate ? $endDate->toDateTimeString() : null,
                    'months' => $request->input('months', 1),
                ],
                'message' => 'Notifications fetched successfully',
            ]);

        } catch (\Throwable $e) {
            Log::error('Notification index failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Internal server error',
            ], 500);
        }
    }


    public function markAsRead($uuid)
    {
        $notification = NotificationModel::where('uuid', $uuid)->first();

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found',
            ], 404);
        }

        $notification->update(['is_read' => true]);

        return response()->json([
            'success' => true,
            'data' => $notification,
            'message' => 'Notification marked as read',
        ]);
    }

//      public function sendEmail(Request $request): JsonResponse
// {
//     try {
//         Log::info('Send email request received', ['request' => $request->all()]);

//         $data = $request->validate([
//             'to' => 'required|email',
//             'subject' => 'required|string|max:255',
//             'template' => 'required|string',
//             'data' => 'nullable|array',
//         ]);

//         NotificationFacade::route('mail', $data['to'])
//             ->notify(new SystemNotification(
//                 subject: $data['subject'],
//                 view: $data['template'],
//                 data: $data['data'] ?? []
//             ));

//         return response()->json([
//             'status' => true,
//             'message' => 'Email sent successfully'
//         ]);

//     } catch (\Throwable $e) {
//         Log::error('Email send failed', ['error' => $e->getMessage()]);

//         return response()->json([
//             'status' => false,
//             'message' => 'Failed to send email',
//             'error' => $e->getMessage()
//         ], 500);
//     }
// }

    public function sendEmail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'html' => 'required|string',
            'order_uuid' => 'nullable|string|max:255',
            'source_id' => 'nullable|string|max:255',
        ]);

        $toInput = strtolower(trim($data['to']));
        $isAdminTarget = in_array($toInput, ['admin', 'admins', 'all_admins', 'info@bcfplatform.com'])
            || !filter_var($data['to'], FILTER_VALIDATE_EMAIL);

        if ($isAdminTarget) {
            // 1. Resolve Order and Organization context
            $order = null;
            $orderUuid = $request->input('order_uuid') ?: $request->input('source_id');
            if ($orderUuid) {
                $order = Order::withoutGlobalScopes()->where('uuid', $orderUuid)->first();
            }

            // Fallback: extract Order ID from subject or HTML if not provided directly
            if (!$order) {
                if (preg_match('/Order\s*#?([0-9]+)/i', $data['subject'] . ' ' . $data['html'], $matches)) {
                    $order = Order::withoutGlobalScopes()->where('id', (int) $matches[1])->first();
                }
            }

            $orgId = $order?->organization_id;

            // Fallback to auth user organization or current organization context
            if (!$orgId) {
                $authUser = Auth::user();
                $orgId = $authUser?->organization_id;
            }

            if (!$orgId && app()->bound('current_organization_id')) {
                $orgId = app('current_organization_id');
            }

            $organization = null;
            if ($orgId) {
                $organization = is_numeric($orgId) ? Organization::find($orgId) : Organization::where('uuid', $orgId)->first();
            }

            // 2. Resolve all active admins for this organization
            $adminEmails = [];
            if ($orgId) {
                $adminUsers = User::withoutGlobalScopes()
                    ->where('organization_id', $orgId)
                    ->where(function ($q) {
                        $q->where('account_closed', false)
                          ->orWhereNull('account_closed');
                    })
                    ->where(function ($q) {
                        $q->where('notification_email', true)
                          ->orWhereNull('notification_email');
                    })
                    ->get();

                foreach ($adminUsers as $adminUser) {
                    if (!empty($adminUser->email) && filter_var($adminUser->email, FILTER_VALIDATE_EMAIL)) {
                        $adminEmails[] = strtolower(trim($adminUser->email));
                    }
                    if (!empty($adminUser->secondary_email) && filter_var($adminUser->secondary_email, FILTER_VALIDATE_EMAIL)) {
                        $adminEmails[] = strtolower(trim($adminUser->secondary_email));
                    }
                }

                // Check portal_settings notification_email and organization contact_email
                if ($organization) {
                    try {
                        $settingsService = app(SettingsService::class);
                        $portalSettings = $settingsService->get($organization->uuid, 'portal_settings');
                        $portalNotificationEmail = $portalSettings['notification_email'] ?? null;
                        if (!empty($portalNotificationEmail) && filter_var($portalNotificationEmail, FILTER_VALIDATE_EMAIL)) {
                            $adminEmails[] = strtolower(trim($portalNotificationEmail));
                        }
                    } catch (\Throwable $e) {}

                    if (!empty($organization->contact_email) && filter_var($organization->contact_email, FILTER_VALIDATE_EMAIL)) {
                        $adminEmails[] = strtolower(trim($organization->contact_email));
                    }
                }
            }

            $adminEmails = array_values(array_unique($adminEmails));

            // Fallback if no admin emails were resolved
            if (empty($adminEmails)) {
                $fallback = $organization?->from_email ?: config('mail.from.address', 'support@bcfpsoftware.com');
                if ($fallback && filter_var($fallback, FILTER_VALIDATE_EMAIL)) {
                    $adminEmails = [$fallback];
                }
            }

            $sentCount = 0;
            foreach ($adminEmails as $email) {
                try {
                    NotificationFacade::route('mail', $email)
                        ->notify(new SystemNotification(
                            $data['subject'],
                            $data['html']
                        ));
                    $sentCount++;

                    // Log to EmailLog
                    try {
                        EmailLog::withoutGlobalScopes()->create([
                            'organization_id' => $orgId,
                            'event_type' => 'admin_approval_required',
                            'recipient_role' => 'admin',
                            'to_email' => $email,
                            'from_email' => $organization?->from_email ?: config('mail.from.address'),
                            'subject' => $data['subject'],
                            'status' => 'sent',
                            'metadata' => [
                                'order_id' => $order?->id,
                                'order_uuid' => $order?->uuid,
                                'trigger' => 'vendor_media_approval',
                            ],
                        ]);
                    } catch (\Throwable $logEx) {
                        Log::warning("Failed to log EmailLog for admin {$email}: " . $logEx->getMessage());
                    }
                } catch (\Throwable $mailEx) {
                    Log::error("Failed to send approval email to admin {$email}: " . $mailEx->getMessage());
                }
            }

            return response()->json([
                'status' => true,
                'message' => "Email sent successfully to {$sentCount} administrator(s)",
                'recipients_count' => $sentCount,
            ]);
        }

        // Standard single recipient send
        NotificationFacade::route('mail', $data['to'])
            ->notify(new SystemNotification(
                $data['subject'],
                $data['html']
            ));

        return response()->json([
            'status' => true,
            'message' => 'Email sent successfully'
        ]);
    }


}
