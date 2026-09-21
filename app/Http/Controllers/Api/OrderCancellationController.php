<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderSlot;
use App\Models\OrderService;
use App\Models\Invoice;
use App\Models\AgentPayment;
use App\Models\Agent;
use App\Models\Vendor;
use App\Services\SettingsService;
use App\Jobs\SyncOrderCalendarEvents;
use App\Jobs\SyncVoidInvoiceToQuickBooks;
use App\Jobs\SyncRefundToQuickBooks;
use App\Mail\OrderCancelled;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use Stripe\StripeClient;
use Illuminate\Support\Str;

class OrderCancellationController extends Controller
{
    protected SettingsService $settings;

    public function __construct(SettingsService $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Preview cancellation details for the ENTIRE order.
     */
    public function preview(Request $request, string $uuid): JsonResponse
    {
        try {
            $order = Order::with(['slots.vendor.workHours', 'agent', 'property'])
                ->where('uuid', $uuid)
                ->first();

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found.'
                ], 404);
            }

            if ($order->order_status === 'Cancelled') {
                return response()->json([
                    'success' => false,
                    'message' => 'Order is already cancelled.'
                ], 422);
            }

            $orgId = $order->organization_id;
            $org = $order->organization ?? \App\Models\Organization::find($orgId);
            $portalSettings = $this->getPortalSettings($org?->uuid);

            list($canCancel, $isFree, $cancellationFee, $bookingTime, $deadline, $timezone) = 
                $this->resolveCancellationState($order, $portalSettings);

            $invoices = Invoice::where('order_id', $order->id)->where('status', '!=', 'void')->get();
            $totalPaid = (float) $invoices->sum('paid_amount');
            $totalRefunded = (float) $invoices->sum('refunded_amount');
            $netPaid = round($totalPaid - $totalRefunded, 2);

            $expectedRefund = 0.0;
            if ($canCancel) {
                if ($isFree) {
                    $expectedRefund = $netPaid;
                } else {
                    $expectedRefund = max(0.0, $netPaid - $cancellationFee);
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'order_uuid' => $order->uuid,
                    'can_cancel' => $canCancel,
                    'is_free' => $isFree,
                    'cancellation_fee' => $cancellationFee,
                    'total_paid' => $netPaid,
                    'expected_refund' => $expectedRefund,
                    'threshold_hours' => (int) ($portalSettings['cancellation_threshold_hours'] ?? 24),
                    'fee_percentage' => (float) ($portalSettings['cancellation_fee_percentage'] ?? 25.0),
                    'booking_datetime' => $bookingTime ? $bookingTime->toIso8601String() : null,
                    'deadline' => $deadline ? $deadline->toIso8601String() : null,
                    'timezone' => $timezone,
                    'message' => $this->getCancellationMessage($canCancel, $isFree, $cancellationFee, $deadline)
                ]
            ]);
        } catch (\Throwable $e) {
            Log::error('Cancellation Preview Error: ' . $e->getMessage(), [
                'uuid' => $uuid,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while generating cancellation preview: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Preview cancellation details for a SINGLE service booking.
     */
    public function previewService(Request $request, string $uuid, string $serviceUuid): JsonResponse
    {
        try {
            $order = Order::where('uuid', $uuid)->first();
            if (!$order) {
                return response()->json(['success' => false, 'message' => 'Order not found.'], 404);
            }

            $orderService = OrderService::where('order_id', $order->id)->where('uuid', $serviceUuid)->first();
            if (!$orderService) {
                return response()->json(['success' => false, 'message' => 'Service booking not found in this order.'], 404);
            }

            $orgId = $order->organization_id;
            $org = $order->organization ?? \App\Models\Organization::find($orgId);
            $portalSettings = $this->getPortalSettings($org?->uuid);

            // Resolve state for this single service
            list($canCancel, $isFree, $cancellationFee, $bookingTime, $deadline, $timezone) = 
                $this->resolveSingleServiceCancellationState($order, $orderService, $portalSettings);

            // Find individual service invoice
            $serviceInvoice = $this->findIndividualServiceInvoice($order->id, $orderService->id);
            $netPaid = 0.0;
            if ($serviceInvoice) {
                $netPaid = round((float)$serviceInvoice->paid_amount - (float)$serviceInvoice->refunded_amount, 2);
            }

            $expectedRefund = 0.0;
            if ($canCancel) {
                if ($isFree) {
                    $expectedRefund = $netPaid;
                } else {
                    $expectedRefund = max(0.0, $netPaid - $cancellationFee);
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'order_uuid' => $order->uuid,
                    'service_uuid' => $orderService->uuid,
                    'can_cancel' => $canCancel,
                    'is_free' => $isFree,
                    'cancellation_fee' => $cancellationFee,
                    'total_paid' => $netPaid,
                    'expected_refund' => $expectedRefund,
                    'threshold_hours' => (int) ($portalSettings['cancellation_threshold_hours'] ?? 24),
                    'fee_percentage' => (float) ($portalSettings['cancellation_fee_percentage'] ?? 25.0),
                    'booking_datetime' => $bookingTime ? $bookingTime->toIso8601String() : null,
                    'deadline' => $deadline ? $deadline->toIso8601String() : null,
                    'timezone' => $timezone,
                    'message' => $this->getCancellationMessage($canCancel, $isFree, $cancellationFee, $deadline)
                ]
            ]);

        } catch (\Throwable $e) {
            Log::error('Service Cancellation Preview Error: ' . $e->getMessage(), [
                'uuid' => $uuid,
                'service_uuid' => $serviceUuid,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while generating service cancellation preview: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel the ENTIRE order/booking.
     */
    public function cancel(Request $request, string $uuid): JsonResponse
    {
        $request->validate([
            'reason' => 'nullable|string|max:1000'
        ]);

        try {
            $order = Order::with(['slots.vendor.workHours', 'agent', 'property'])
                ->where('uuid', $uuid)
                ->first();

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found.'
                ], 404);
            }

            if ($order->order_status === 'Cancelled') {
                return response()->json([
                    'success' => false,
                    'message' => 'Order is already cancelled.'
                ], 422);
            }

            $user = auth()->user();
            $authorized = $this->authorizeCancellation($user, $order);
            if (!$authorized) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You do not have permission to cancel this order.'
                ], 403);
            }

            $orgId = $order->organization_id;
            $org = $order->organization ?? \App\Models\Organization::find($orgId);
            $portalSettings = $this->getPortalSettings($org?->uuid);

            list($canCancel, $isFree, $cancellationFee, $bookingTime, $deadline, $timezone) = 
                $this->resolveCancellationState($order, $portalSettings);

            if (!$canCancel) {
                return response()->json([
                    'success' => false,
                    'message' => "Cancellation is not allowed after the threshold window of {$portalSettings['cancellation_threshold_hours']} hours."
                ], 422);
            }

            $invoices = Invoice::where('order_id', $order->id)
                ->where('status', '!=', 'void')
                ->with('payments')
                ->get();

            DB::beginTransaction();

            // 1. Update Order Status
            $order->update([
                'order_status' => 'Cancelled',
                'cancellation_fee' => $cancellationFee,
                'cancelled_at' => now(),
                'cancelled_by_type' => get_class($user),
                'cancelled_by_uuid' => $user->uuid,
                'cancellation_reason' => $request->reason
            ]);

            // 2. Handle Invoices & Refunds
            $totalPaid = (float) $invoices->sum('paid_amount');
            $totalRefunded = (float) $invoices->sum('refunded_amount');
            $netPaid = round($totalPaid - $totalRefunded, 2);

            if ($isFree || $cancellationFee === 0.0) {
                foreach ($invoices as $invoice) {
                    if ($invoice->paid_amount <= 0 && $invoice->status !== 'paid') {
                        $invoice->update(['status' => 'void']);
                        if ($invoice->quickbooks_invoice_id) {
                            SyncVoidInvoiceToQuickBooks::dispatch($invoice->id, $invoice->quickbooks_invoice_id);
                        }
                    } else {
                        $invoiceNetPaid = round((float)$invoice->paid_amount - (float)$invoice->refunded_amount, 2);
                        if ($invoiceNetPaid > 0) {
                            $this->processRefund($invoice, $invoiceNetPaid, "Full refund on cancellation - Order #{$order->id}");
                        }
                    }
                }
            } else {
                if ($netPaid <= 0.0) {
                    foreach ($invoices as $invoice) {
                        $invoice->update(['status' => 'void']);
                        if ($invoice->quickbooks_invoice_id) {
                            SyncVoidInvoiceToQuickBooks::dispatch($invoice->id, $invoice->quickbooks_invoice_id);
                        }
                    }

                    $province = $order->agent->headquarter_province ?? $order->property->province ?? 'ON';
                    $taxInfo = (new InvoiceController)->getCanadaTaxRate($province);
                    
                    $taxAmount = round($cancellationFee * ($taxInfo['rate'] / 100), 2);
                    $feeInvoice = Invoice::create([
                        'organization_id' => $order->organization_id,
                        'order_id' => $order->id,
                        'agent_id' => $order->agent_id,
                        'status' => 'issued',
                        'subtotal' => $cancellationFee,
                        'tax_rate' => $taxInfo['rate'],
                        'tax_amount' => $taxAmount,
                        'tax_details' => [$taxInfo['name'] => ['rate' => $taxInfo['rate'], 'amount' => $taxAmount]],
                        'total' => $cancellationFee + $taxAmount,
                        'paid_amount' => 0.0,
                        'currency' => 'cad',
                        'issued_at' => now(),
                        'notes' => "Late Cancellation Fee - Order #{$order->id}",
                        'agent_type' => 'primary',
                    ]);

                    $feeInvoice->items()->create([
                        'order_service_id' => null,
                        'description' => "Late Cancellation Fee (25% of subtotal)",
                        'quantity' => 1,
                        'unit_price' => $cancellationFee,
                        'amount' => $cancellationFee
                    ]);
                } else {
                    $refundAmount = round($netPaid - $cancellationFee, 2);

                    if ($refundAmount > 0.0) {
                        $remainingToRefund = $refundAmount;

                        foreach ($invoices as $invoice) {
                            if ($remainingToRefund <= 0.0) break;

                            $invoiceNetPaid = round((float)$invoice->paid_amount - (float)$invoice->refunded_amount, 2);
                            if ($invoiceNetPaid <= 0.0) continue;

                            $amountToRefundFromThisInvoice = min($remainingToRefund, $invoiceNetPaid);
                            $this->processRefund($invoice, $amountToRefundFromThisInvoice, "Proportional refund on late cancellation - Order #{$order->id}");

                            $remainingToRefund = round($remainingToRefund - $amountToRefundFromThisInvoice, 2);
                        }
                    }
                }
            }

            DB::commit();

            try {
                SyncOrderCalendarEvents::dispatchSync($order->id);
            } catch (\Throwable $e) {
                Log::error('Failed to dispatch SyncOrderCalendarEvents job on cancellation: ' . $e->getMessage());
            }

            $this->sendCancellationEmails($order, $cancellationFee, $request->reason);

            return response()->json([
                'success' => true,
                'message' => 'Order cancelled successfully.',
                'data' => [
                    'order_uuid' => $order->uuid,
                    'order_status' => 'Cancelled',
                    'cancellation_fee' => $cancellationFee,
                    'cancelled_at' => $order->cancelled_at->toIso8601String()
                ]
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Order Cancellation Error: ' . $e->getMessage(), [
                'uuid' => $uuid,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while cancelling the order: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel a SINGLE service booking.
     */
    public function cancelService(Request $request, string $uuid, string $serviceUuid): JsonResponse
    {
        $request->validate([
            'reason' => 'nullable|string|max:1000'
        ]);

        try {
            $order = Order::with(['slots.vendor.workHours', 'agent', 'property'])
                ->where('uuid', $uuid)
                ->first();

            if (!$order) {
                return response()->json(['success' => false, 'message' => 'Order not found.'], 404);
            }

            if ($order->order_status === 'Cancelled') {
                return response()->json(['success' => false, 'message' => 'Order is already cancelled.'], 422);
            }

            $orderService = OrderService::where('order_id', $order->id)->where('uuid', $serviceUuid)->first();
            if (!$orderService) {
                return response()->json(['success' => false, 'message' => 'Service booking not found in this order.'], 404);
            }

            $user = auth()->user();
            $authorized = $this->authorizeCancellation($user, $order);
            if (!$authorized) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You do not have permission to cancel this booking.'
                ], 403);
            }

            $orgId = $order->organization_id;
            $org = $order->organization ?? \App\Models\Organization::find($orgId);
            $portalSettings = $this->getPortalSettings($org?->uuid);

            // Resolve cancellation state for this specific service booking
            list($canCancel, $isFree, $cancellationFee, $bookingTime, $deadline, $timezone) = 
                $this->resolveSingleServiceCancellationState($order, $orderService, $portalSettings);

            if (!$canCancel) {
                return response()->json([
                    'success' => false,
                    'message' => "Cancellation is not allowed after the threshold window of {$portalSettings['cancellation_threshold_hours']} hours."
                ], 422);
            }

            $serviceInvoice = $this->findIndividualServiceInvoice($order->id, $orderService->id);

            DB::beginTransaction();

            // Track deleted slot for calendar sync
            $deletedSlotId = null;
            $slot = OrderSlot::where('order_id', $order->id)->where('service_id', $orderService->service_id)->first();
            if ($slot) {
                $deletedSlotId = $slot->id;
                $slot->delete();
            }

            // Handle Financials
            if ($serviceInvoice) {
                $netPaid = round((float)$serviceInvoice->paid_amount - (float)$serviceInvoice->refunded_amount, 2);

                if ($isFree || $cancellationFee === 0.0) {
                    if ($serviceInvoice->paid_amount <= 0 && $serviceInvoice->status !== 'paid') {
                        $serviceInvoice->update(['status' => 'void']);
                        if ($serviceInvoice->quickbooks_invoice_id) {
                            SyncVoidInvoiceToQuickBooks::dispatch($serviceInvoice->id, $serviceInvoice->quickbooks_invoice_id);
                        }
                    } else {
                        if ($netPaid > 0) {
                            $this->processRefund($serviceInvoice, $netPaid, "Full refund on service cancel - Order #{$order->id}, Service #{$orderService->id}");
                        }
                    }
                } else {
                    // Late cancellation: Fee applies
                    if ($netPaid <= 0.0) {
                        // Void the unpaid service invoice
                        $serviceInvoice->update(['status' => 'void']);
                        if ($serviceInvoice->quickbooks_invoice_id) {
                            SyncVoidInvoiceToQuickBooks::dispatch($serviceInvoice->id, $serviceInvoice->quickbooks_invoice_id);
                        }

                        // Create new late fee invoice
                        $province = $order->agent->headquarter_province ?? $order->property->province ?? 'ON';
                        $taxInfo = (new InvoiceController)->getCanadaTaxRate($province);
                        $taxAmount = round($cancellationFee * ($taxInfo['rate'] / 100), 2);

                        $feeInvoice = Invoice::create([
                            'organization_id' => $order->organization_id,
                            'order_id' => $order->id,
                            'agent_id' => $order->agent_id,
                            'status' => 'issued',
                            'subtotal' => $cancellationFee,
                            'tax_rate' => $taxInfo['rate'],
                            'tax_amount' => $taxAmount,
                            'tax_details' => [$taxInfo['name'] => ['rate' => $taxInfo['rate'], 'amount' => $taxAmount]],
                            'total' => $cancellationFee + $taxAmount,
                            'paid_amount' => 0.0,
                            'currency' => 'cad',
                            'issued_at' => now(),
                            'notes' => "Late Cancellation Fee - Service #{$orderService->service_id} (Order #{$order->id})",
                            'agent_type' => 'primary',
                        ]);

                        $feeInvoice->items()->create([
                            'order_service_id' => null,
                            'description' => "Late Cancellation Fee (25% of service subtotal)",
                            'quantity' => 1,
                            'unit_price' => $cancellationFee,
                            'amount' => $cancellationFee
                        ]);
                    } else {
                        // Proportional refund on paid invoice
                        $refundAmount = round($netPaid - $cancellationFee, 2);
                        if ($refundAmount > 0.0) {
                            $this->processRefund($serviceInvoice, $refundAmount, "Proportional refund on late service cancel - Order #{$order->id}");
                        }
                    }
                }
            }

            // Update order totals and record fee
            $serviceAmount = $orderService->amount;
            $orderService->delete();

            $order->update([
                'amount' => max(0.0, $order->amount - $serviceAmount),
                'cancellation_fee' => ($order->cancellation_fee ?? 0.0) + $cancellationFee
            ]);

            // Sync main (consolidated) invoices (removes deleted service from consolidated invoices)
            Invoice::syncOrderInvoices($order);

            // If no more services exist, cancel the entire order
            $remainingServicesCount = OrderService::where('order_id', $order->id)->count();
            if ($remainingServicesCount === 0) {
                $order->update([
                    'order_status' => 'Cancelled',
                    'cancelled_at' => now(),
                    'cancelled_by_type' => get_class($user),
                    'cancelled_by_uuid' => $user->uuid,
                    'cancellation_reason' => $request->reason ?? 'All services cancelled'
                ]);
            }

            DB::commit();

            // Dispatch Calendar Event deletion
            if ($deletedSlotId) {
                try {
                    SyncOrderCalendarEvents::dispatch($order->id, [$deletedSlotId]);
                } catch (\Throwable $e) {
                    Log::error('Failed to dispatch SyncOrderCalendarEvents job on service cancellation: ' . $e->getMessage());
                }
            }

            // Trigger in-portal notifications for service cancellation
            try {
                \App\Services\NotificationService::notifyServiceCancellation($order, $orderService, $slot?->vendor, $request->reason);
            } catch (\Throwable $e) {
                Log::error('Failed to create in-portal notification for service cancellation: ' . $e->getMessage());
            }

            // Send notification email (only to agent, admins, and the specific vendor affected)
            $this->sendSingleServiceCancellationEmails($order, $orderService, $slot?->vendor, $cancellationFee, $request->reason);

            return response()->json([
                'success' => true,
                'message' => 'Service booking cancelled successfully.',
                'data' => [
                    'order_uuid' => $order->uuid,
                    'service_uuid' => $serviceUuid,
                    'order_status' => $order->fresh()->order_status,
                    'cancellation_fee' => $cancellationFee
                ]
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Service Cancellation Error: ' . $e->getMessage(), [
                'uuid' => $uuid,
                'service_uuid' => $serviceUuid,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while cancelling the service: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper to get portal settings, fallback to global defaults.
     */
    protected function getPortalSettings(?string $orgUuid): array
    {
        $defaults = [
            'cancellation_threshold_hours' => 24,
            'cancellation_fee_percentage' => 25.00,
            'allow_cancel_after_threshold' => true
        ];

        try {
            $settings = $this->settings->get($orgUuid, 'portal_settings');
            return array_merge($defaults, $settings);
        } catch (\Throwable $e) {
            return $defaults;
        }
    }

    /**
     * Resolve cancellation fee state for entire order.
     */
    protected function resolveCancellationState(Order $order, array $portalSettings): array
    {
        $timezone = config('app.timezone', 'UTC');
        $bookingTime = null;
        $deadline = null;
        $canCancel = true;
        $isFree = true;
        $cancellationFee = 0.0;

        if ($order->slots && $order->slots->isNotEmpty()) {
            $earliestSlot = $order->slots->sortBy(function($slot) {
                return $slot->date . ' ' . $slot->start_time;
            })->first();

            $vendor = $earliestSlot->vendor;
            $province = $order->property->state ?? $order->property->province ?? $order->province ?? 'BC';
            $timezone = \App\Services\GoogleCalendarService::resolveTimezoneFromProvince($province, $vendor->timezone ?? $vendor->workHours->first()?->timezone ?? 'America/Vancouver');

            $bookingTime = Carbon::createFromFormat('Y-m-d H:i:s', "{$earliestSlot->date} {$earliestSlot->start_time}", $timezone);
            $thresholdHours = (int) ($portalSettings['cancellation_threshold_hours'] ?? 24);
            $deadline = $bookingTime->copy()->subHours($thresholdHours);
            
            $now = Carbon::now($timezone);

            if ($now->lt($deadline)) {
                $canCancel = true;
                $isFree = true;
                $cancellationFee = 0.0;
            } else {
                if (($portalSettings['allow_cancel_after_threshold'] ?? true) === false) {
                    $canCancel = false;
                    $isFree = false;
                    $cancellationFee = 0.0;
                } else {
                    $canCancel = true;
                    $isFree = false;
                    $feePercentage = (float) ($portalSettings['cancellation_fee_percentage'] ?? 25.0);
                    $cancellationFee = round(($order->amount * $feePercentage) / 100, 2);
                }
            }
        }

        return [$canCancel, $isFree, $cancellationFee, $bookingTime, $deadline, $timezone];
    }

    /**
     * Resolve cancellation fee state for a single service booking.
     */
    protected function resolveSingleServiceCancellationState(Order $order, OrderService $orderService, array $portalSettings): array
    {
        $timezone = config('app.timezone', 'UTC');
        $bookingTime = null;
        $deadline = null;
        $canCancel = true;
        $isFree = true;
        $cancellationFee = 0.0;

        // Find the slot for this specific service in the order
        $slot = OrderSlot::where('order_id', $order->id)
            ->where('service_id', $orderService->service_id)
            ->first();

        if ($slot) {
            $vendor = $slot->vendor;
            $province = $order->property->state ?? $order->property->province ?? $order->province ?? 'BC';
            $timezone = \App\Services\GoogleCalendarService::resolveTimezoneFromProvince($province, $vendor->timezone ?? $vendor->workHours->first()?->timezone ?? 'America/Vancouver');

            $bookingTime = Carbon::createFromFormat('Y-m-d H:i:s', "{$slot->date} {$slot->start_time}", $timezone);
            $thresholdHours = (int) ($portalSettings['cancellation_threshold_hours'] ?? 24);
            $deadline = $bookingTime->copy()->subHours($thresholdHours);
            
            $now = Carbon::now($timezone);

            if ($now->lt($deadline)) {
                $canCancel = true;
                $isFree = true;
                $cancellationFee = 0.0;
            } else {
                if (($portalSettings['allow_cancel_after_threshold'] ?? true) === false) {
                    $canCancel = false;
                    $isFree = false;
                    $cancellationFee = 0.0;
                } else {
                    $canCancel = true;
                    $isFree = false;
                    $feePercentage = (float) ($portalSettings['cancellation_fee_percentage'] ?? 25.0);
                    $cancellationFee = round(($orderService->amount * $feePercentage) / 100, 2);
                }
            }
        }

        return [$canCancel, $isFree, $cancellationFee, $bookingTime, $deadline, $timezone];
    }

    /**
     * Helper to locate an individual service invoice containing exactly this service.
     */
    protected function findIndividualServiceInvoice(int $orderId, int $orderServiceId): ?Invoice
    {
        return Invoice::where('order_id', $orderId)
            ->whereHas('items', function($q) use ($orderServiceId) {
                $q->where('order_service_id', $orderServiceId);
            })
            ->where('notes', 'like', 'Service Invoice:%')
            ->first();
    }

    /**
     * Authorization checks.
     */
    protected function authorizeCancellation($user, Order $order): bool
    {
        if (!$user) {
            return false;
        }

        $isSuperAdmin = ($user instanceof \App\Models\User) && (
            trim(strtolower($user->email)) === 'todd@tojuco.com' ||
            $user->roles()->where(function($q) {
                $q->where('name', 'Super Admin')
                  ->orWhere('name', 'super admin')
                  ->orWhere('name', 'super-admin');
            })->exists()
        );

        if ($isSuperAdmin) {
            return true;
        }

        if ($user instanceof \App\Models\Vendor) {
            return false;
        }

        if ($user instanceof \App\Models\User && $user->organization_id === $order->organization_id) {
            return true;
        }

        if ($user instanceof \App\Models\Agent) {
            return $user->id === $order->agent_id;
        }

        if ($user instanceof \App\Models\SubAccount) {
            return $user->organization_id === $order->organization_id;
        }

        return false;
    }

    /**
     * Generate the preview response message.
     */
    protected function getCancellationMessage(bool $canCancel, bool $isFree, float $fee, ?Carbon $deadline): string
    {
        if (!$canCancel) {
            return 'Cancellation is no longer available for this booking.';
        }

        if ($isFree) {
            $formattedDeadline = $deadline ? $deadline->format('F j, Y \a\t g:i A') : '';
            return "This booking can be cancelled for free before {$formattedDeadline}.";
        }

        return sprintf("A cancellation fee of $%s applies as you are within the cancellation window.", number_format($fee, 2));
    }

    /**
     * Process Stripe and database refunds for an invoice.
     */
    protected function processRefund(Invoice $invoice, float $refundAmount, string $notes): void
    {
        $stripePayment = $invoice->payments->whereNotNull('stripe_payment_intent_id')->where('status', 'succeeded')->first();
        $refundMethod = 'manual';
        $stripeRefundId = null;

        if ($stripePayment) {
            try {
                $stripe = new StripeClient(config('services.stripe.secret'));
                $refund = $stripe->refunds->create([
                    'payment_intent' => $stripePayment->stripe_payment_intent_id,
                    'amount' => intval($refundAmount * 100),
                    'metadata' => [
                        'invoice_uuid' => $invoice->uuid,
                        'notes' => $notes
                    ]
                ]);
                $refundMethod = 'stripe';
                $stripeRefundId = $refund->id;
            } catch (\Exception $e) {
                Log::error('Stripe Refund error on booking cancel: ' . $e->getMessage());
            }
        }

        $refundItems = [];
        foreach ($invoice->items as $item) {
            $refundItems[] = [
                'order_service_id' => $item->order_service_id,
                'description'      => $item->description,
                'amount'           => $item->amount,
                'unit_price'       => $item->unit_price,
                'quantity'         => $item->quantity,
            ];
        }

        AgentPayment::create([
            'agent_id'       => $invoice->agent_id,
            'order_id'       => $invoice->order_id,
            'invoice_id'     => $invoice->id,
            'amount'         => -$refundAmount,
            'currency'       => $invoice->currency,
            'status'         => 'refunded',
            'payment_method' => $refundMethod,
            'payment_type'   => 'refund',
            'paid_at'        => now(),
            'meta'           => [
                'type' => 'refund',
                'notes' => $notes,
                'stripe_refund_id' => $stripeRefundId,
                'refund_items' => $refundItems,
                'tax_rate' => $invoice->tax_rate,
            ],
        ]);

        $newRefunded = round((float) $invoice->refunded_amount + $refundAmount, 2);
        $remainingPaid = round((float) $invoice->paid_amount - $newRefunded, 2);

        $invoice->update([
            'refunded_amount' => $newRefunded,
            'status' => $remainingPaid <= 0.0 ? 'refunded' : 'partially_refunded'
        ]);

        if ($remainingPaid <= 0.0) {
            foreach ($invoice->items as $item) {
                if ($item->orderService) {
                    $item->orderService->update(['payment_status' => 'REFUNDED']);
                }
            }
        }

        try {
            SyncRefundToQuickBooks::dispatch($invoice->id, $refundAmount);
        } catch (\Throwable $e) {
            Log::error('Failed to dispatch SyncRefundToQuickBooks job on cancellation: ' . $e->getMessage());
        }
    }

    /**
     * Send cancellation emails for the ENTIRE order.
     */
    protected function sendCancellationEmails(Order $order, float $fee, ?string $reason): void
    {
        try {
            $order->cancellation_fee = $fee;
            $order->cancellation_reason = $reason;

            app(\App\Services\EmailDispatchService::class)->dispatch('order_cancelled', $order);
        } catch (\Throwable $e) {
            Log::error('Failed to send order cancellation emails via dispatch service: ' . $e->getMessage());
        }
    }

    /**
     * Send cancellation emails for a SINGLE service cancellation.
     */
    protected function sendSingleServiceCancellationEmails(Order $order, OrderService $orderService, ?Vendor $vendor, float $fee, ?string $reason): void
    {
        try {
            $order->cancellation_fee = $fee;
            $serviceName = $orderService->service->name ?? 'Service';
            $order->cancellation_reason = $reason . " (Cancelled single service: {$serviceName})";

            $recipients = [];
            
            // 1. Admin
            $adminEmail = $order->organization ? $order->organization->contact_email : null;
            if (!$adminEmail) {
                $adminEmail = config('mail.from.address', 'support@bcfpsoftware.com');
            }
            $recipients[] = [
                'email' => $adminEmail,
                'name' => 'Admin',
                'role' => 'admin',
            ];

            // 2. Agent
            if ($order->agent && $order->agent->email) {
                $recipients[] = [
                    'email' => $order->agent->email,
                    'name' => trim($order->agent->first_name . ' ' . $order->agent->last_name),
                    'role' => 'agent',
                    'model' => $order->agent,
                ];
            }

            // 3. Affected Vendor only
            if ($vendor && !empty($vendor->email)) {
                $recipients[] = [
                    'email' => $vendor->email,
                    'name' => trim($vendor->first_name . ' ' . $vendor->last_name),
                    'role' => 'vendor',
                    'model' => $vendor,
                ];
            }

            app(\App\Services\EmailDispatchService::class)->dispatch('order_cancelled', $order, [
                'recipients' => $recipients
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send single service cancellation emails via dispatch service: ' . $e->getMessage());
        }
    }
}
