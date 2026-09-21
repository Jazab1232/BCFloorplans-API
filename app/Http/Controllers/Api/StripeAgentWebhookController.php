<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use App\Models\AgentPayment;
use App\Models\OrderService;
use App\Models\Agent;
use App\Models\Service;
use Illuminate\Support\Str;
use App\Models\Order;
use Stripe\Invoice;
use Stripe\Webhook;
class StripeAgentWebhookController extends Controller
{
     public function handleWebhook(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $secret = config('services.stripe.webhook_secret')  ?? 'whsec_9okG7sf9uApkaQ3AI6aJDqTCVIcDQfPj';

        try {
            Stripe::setApiKey(config('services.stripe.secret'));

            $event =Webhook::constructEvent(
                $payload,
                $sigHeader,
                $secret
            );

            Log::info(' Stripe event received', [
                'type' => $event->type,
                'created_at' => now(),
                'object_id' => $event->data->object->id ?? null,
            ]);
        } catch (\UnexpectedValueException $e) {
            return response('Invalid payload', 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            return response('Invalid signature', 400);
        }

        //  We only handle checkout.session.completed
        if ($event->type === 'checkout.session.completed') {
            $payment_done = $this->handleCheckoutSessionCompleted($event->data->object);
            if ($payment_done === false) {
                return response('Failed to process payment', 500);
            }
            else {
                
            }
        } else {
            Log::info('Unhandled Stripe event type: ' . $event->type);
        }

        return response('Webhook handled', 200);
    }

    protected function handleCheckoutSessionCompleted($session)
    {
        try {
            $metadata = (object) ($session->metadata ?? []);
            $paidAmount = isset($session->amount_total) ? $session->amount_total / 100 : 0;
            Log::info(' Handling checkout.session.completed', ['session_id' => $session->id, 'metadata' => $metadata]);

            //  Resolve agent
            $agent = isset($metadata->agent_uuid)
                ? Agent::where('uuid', $metadata->agent_uuid)->first()
                : null;

            if (!$agent) {
                Log::warning('Agent not found for session: ' . ($metadata->agent_uuid ?? 'N/A'));
                return;
            }

            //  Resolve optional service_id
            $serviceId = null;

            $service = isset($metadata->service_id)
                ? OrderService::where('uuid', $metadata->service_id)->first()
                : null;
           
            $invoiceRecord = isset($metadata->invoice_uuid)
                ? \App\Models\Invoice::where('uuid', $metadata->invoice_uuid)->first()
                : null;
           
            //  Check if already exists (idempotent)
            $payment = AgentPayment::where('stripe_session_id', $session->id)
                ->orWhere('stripe_payment_intent_id', $session->payment_intent)
                ->first();

            if (!$payment) {
                $payment = AgentPayment::create([
                    'uuid' => (string) Str::uuid(),
                    'agent_id' => $agent->id,
                    'order_service_id' => $service ? $service->id : null,
                    'invoice_id' => $invoiceRecord ? $invoiceRecord->id : null,
                    'order_id' => $metadata->order_id ?? null,
                    'amount' => $paidAmount ?? 0,
                    'currency' => strtolower($metadata->currency ?? 'usd'),
                    'session_created_at' => $session->created ? date('Y-m-d H:i:s', $session->created) : null,
                    'status' => $session->payment_status ==="paid" ? 'succeeded' : 'processing',
                    'payment_method' => $session->payment_method_types[0] ?? 'card',
                    'payment_type' => $metadata->payment_type === 'service' ? 'partial' : 'full',
                    'stripe_session_id' => $session->id,
                    'stripe_payment_intent_id' => $session->payment_intent,
                    'stripe_customer_id' => $session->customer,
                    'meta' => [
                        'metadata' => $metadata,
                        'source' => 'checkout_session',
                    ],
                ]);

                Log::info(' Payment created from checkout.session.completed', [
                    'session_id' => $session->id,
                    'payment_id' => $payment->id,
                ]);
            }

            // Fetch invoice info if available
            $invoiceUrl = null;
            $invoicePdf = null;

            if (!empty($session->invoice)) {
                try {
                    $invoice = Invoice::retrieve($session->invoice);
                    $invoiceUrl = $invoice->hosted_invoice_url ?? null;
                    $invoicePdf = $invoice->invoice_pdf ?? null;
                } catch (\Exception $e) {
                    Log::error('Error retrieving invoice for session ' . $session->id . ': ' . $e->getMessage());
                }
            }

            // Update payment record with invoice links
            $meta = is_array($payment->meta)
                ? $payment->meta
                : (json_decode($payment->meta, true) ?? []);

            $meta = array_merge($meta, [
                'invoice_id' => $session->invoice ?? null,
                'invoice_url' => $invoiceUrl,
                'invoice_pdf' => $invoicePdf,
            ]);

            $payment->update([
                'status' => 'succeeded',
                'paid_at' => $payment->paid_at ?? now(),
                'meta' => $meta,
                'stripe_receipt_url' => $invoiceUrl ?? $payment->stripe_receipt_url,
            ]);

            // Sync with our internal Invoice record if present
            if ($invoiceRecord && $session->payment_status === "paid") {
                // Check if this payment was already processed (idempotency)
                // If the payment record already existed and was paid, we might have already updated the invoice
                $alreadyPaid = $payment->wasRecentlyCreated === false && $payment->status === 'succeeded' && $payment->paid_at !== null;
                
                if (!$alreadyPaid) {
                    $newPaid = (float)$invoiceRecord->paid_amount + $paidAmount;
                    $isFullyPaid = $newPaid >= (float)$invoiceRecord->total;
                    
                    $invoiceRecord->update([
                        'paid_amount' => $newPaid,
                        'status' => $isFullyPaid ? 'paid' : 'partially_paid',
                        'paid_at' => $isFullyPaid ? now() : $invoiceRecord->paid_at,
                    ]);
                }

                // Dispatch QuickBooks Sync
                \App\Jobs\SyncInvoiceToQuickBooks::dispatch($invoiceRecord->id);

                // Propagate payment status to all tied services natively to handle repeat orders
                // We always do this check even if already paid to ensure consistency
                $isFullyPaid = (float)$invoiceRecord->paid_amount >= (float)$invoiceRecord->total;
                if ($isFullyPaid) {
                    $invoiceRecord->load('items.orderService');
                    foreach ($invoiceRecord->items as $item) {
                        if ($item->orderService) {
                            $item->orderService->update(['payment_status' => 'PAID']);
                        }
                    }
                }
                
                // Cascading sync: If one invoice is paid, others might be covered
                if ($invoiceRecord->order) {
                    \App\Models\Invoice::syncOrderStatus($invoiceRecord->order);
                }
            }

            //  Update related order and order services after successful payment
            if (!empty($metadata->order_id)) {
                $this->updateOrderAfterPayment(
                    $metadata->order_id,
                    $paidAmount ?? 0,
                    $metadata->payment_type ?? 'full',
                    $metadata->service_id ?? null,
                    $payment  // Pass payment record to extract creator info
                );
            }

            Log::info(' Checkout session finalized successfully', [
                'session_id' => $session->id,
                'invoice_url' => $invoiceUrl,
                'creator_name' => $metadata->creator_name ?? 'System',
            ]);
            return true;

        } catch (\Throwable $e) {
            Log::error(' Error in handleCheckoutSessionCompleted: ' . $e->getMessage(), [
                'session_id' => $session->id ?? null,
            ]);
            return false;
        }
    }

//  Update Order and OrderService tables after successful payment.
     public static function updateOrderAfterPayment($orderId, $amount, $paymentType, $serviceUuid = null, $payment = null)
    {
            try {
                Log::info(' updateOrderAfterPayment called', [
                    'order_id' => $orderId,
                    'amount' => $amount,
                    'payment_type' => $paymentType,
                    'service_uuid' => $serviceUuid,
                    'has_payment_object' => $payment ? true : false,
                ]);

                DB::transaction(function () use ($orderId, $amount, $paymentType, $serviceUuid, $payment) {
                    $order = Order::where('id', intval($orderId))
                        ->first();

                    
                    if (!$order) {
                        Log::warning(' Order not found when updating after payment', ['order_id' => $orderId]);
                        return;
                    }
                    if ($order->paid_amount >= $order->amount ) {
                        Log::warning("Order {$order->id} already fully paid. Skipping amount update.");
                        if($order->payment_status !== 'PAID'){
                            $order->update(['payment_status' => 'PAID']);
                            Log::info(' Order payment status updated to PAID', ['order_id' => $order->id]);
                        }
                        \App\Models\Invoice::syncOrderInvoices($order);
                        \App\Models\Invoice::syncOrderStatus($order);
                        return;
                    }

                    $previousPaidAmount = (float) $order->paid_amount;
                    $newPaidAmount = $previousPaidAmount + (float) $amount;
                    
                    // Cap paid_amount at order total
                    if ($newPaidAmount > (float)$order->amount) {
                        $newPaidAmount = (float)$order->amount;
                    }

                    $order->update(['paid_amount' => $newPaidAmount]);
                    $order->refresh();

                    Log::info(' Order paid_amount updated', [
                        'order_id' => $order->id,
                        'previous_paid_amount' => $previousPaidAmount,
                        'added_amount' => $amount,
                        'new_paid_amount' => $newPaidAmount,
                        'order_total' => $order->amount,
                    ]);

                    // Check if order is now fully paid
                    $isOrderFullyPaid = $newPaidAmount >= (float)$order->amount;

                    if ($isOrderFullyPaid && $order->payment_status !== 'PAID') {
                        $order->update(['payment_status' => 'PAID']);
                        Log::info(' Order payment status updated to PAID', ['order_id' => $order->id]);
                    } elseif (!$isOrderFullyPaid && $newPaidAmount > 0 && $order->payment_status !== 'PARTIAL') {
                        $order->update(['payment_status' => 'PARTIAL']);
                        Log::info(' Order payment status updated to PARTIAL', ['order_id' => $order->id]);
                    }

                    // Handle Service/Partial Payment
                    if (($paymentType === 'service' || $paymentType === 'partial') && $serviceUuid) {
                        $query = OrderService::query();
                        if (is_numeric($serviceUuid)) {
                            $query->where('id', intval($serviceUuid));
                        } else {
                            $query->where('uuid', $serviceUuid);
                        }

                        $updatedCount = $query->update(['payment_status' => 'PAID']);

                        Log::info(' Specific order service marked as PAID', [
                            'order_id' => $order->id,
                            'service_identifier' => $serviceUuid,
                            'updated_rows' => $updatedCount,
                        ]);
                    } 
                    // Handle Full Payment - only if actually fully paid or if specifically intended as full
                    elseif ($paymentType === 'full' && $isOrderFullyPaid) {
                        OrderService::where('order_id', $order->id)
                            ->update(['payment_status' => 'PAID']);
                        
                        Log::info(' All order services marked as PAID for full payment', [
                            'order_id' => $order->id,
                        ]);
                    }

                    // Always sync related invoices to reflect new paid_amount and status
                    \App\Models\Invoice::syncOrderInvoices($order);
                    \App\Models\Invoice::syncOrderStatus($order);

                    // Check if any feature sheets associated with this order are ready for printing
                    try {
                        $featureSheets = \App\Models\FeatureSheet::where('order_id', $order->uuid)->get();
                        foreach ($featureSheets as $fs) {
                            \App\Http\Controllers\Api\PrintRequestController::checkAndNotifyPrintReady($fs);
                        }
                    } catch (\Throwable $e) {
                        Log::warning('Failed to check print readiness after payment: ' . $e->getMessage());
                    }

                    // Trigger background reprocessing of photos to generate unwatermarked variants once paid
                    try {
                        $paidServiceId = null;
                        if (($paymentType === 'service' || $paymentType === 'partial') && $serviceUuid) {
                            if (is_numeric($serviceUuid)) {
                                $paidServiceId = intval($serviceUuid);
                            } else {
                                $paidServiceId = OrderService::where('uuid', $serviceUuid)->value('service_id');
                            }
                        }

                        $tourFilesQuery = \App\Models\TourFile::where('type', 'photo')
                            ->whereHas('tour', function ($q) use ($order) {
                                $q->where('order_id', $order->id);
                            });

                        if ($paidServiceId) {
                            $tourFilesQuery->where('service_id', $paidServiceId);
                        }

                        $tourFiles = $tourFilesQuery->get();

                        foreach ($tourFiles as $tourFile) {
                            \App\Jobs\ProcessUploadedImage::dispatch($tourFile)->afterCommit();
                            Log::info('Queued unwatermarked image reprocessing after payment commit', [
                                'file_id' => $tourFile->id,
                                'order_id' => $order->id,
                            ]);
                        }
                    } catch (\Throwable $e) {
                        Log::error('Failed to queue unwatermarked image reprocessing after payment', [
                            'order_id' => $order->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                    
                    // If payment object is provided from webhook, create notification with creator context
                    if ($payment) {
                        self::createWebhookPaymentNotification($payment, $order);
                    }
                });
            } catch (\Throwable $e) {
                Log::error(' Error updating order after payment: ' . $e->getMessage(), [
                    'order_id' => $orderId,
                    'trace' => $e->getTraceAsString(),
                ]);
            }
    }

    /**
     * Create payment notification from webhook or payment completion with creator context
     */
    public static function createWebhookPaymentNotification($payment, $order): void
    {
        try {
            $agent = Agent::find($payment->agent_id);
            if (!$agent) {
                Log::warning('Agent not found for webhook payment notification', ['payment_id' => $payment->id]);
                return;
            }

            // Extract creator information from payment metadata
            $paymentMeta = is_array($payment->meta) ? $payment->meta : (json_decode($payment->meta, true) ?? []);
            $sessionMetadata = $paymentMeta['metadata'] ?? [];
            
            $creatorUuid = $sessionMetadata['creator_uuid'] ?? $paymentMeta['creator_uuid'] ?? null;
            $creatorName = $sessionMetadata['creator_name'] ?? $paymentMeta['creator_name'] ?? 'System';
            $creatorEmail = $sessionMetadata['creator_email'] ?? $paymentMeta['creator_email'] ?? null;

            $agentName = trim($agent->first_name . ' ' . $agent->last_name);
            $orderNumber = $order->uuid ?? 'Unknown Order';
            
            // Load order with relations
            $order->load(['property', 'services.service']);
            $property = $order->property;
            
            // Build property address
            $propertyAddress = null;
            if ($property) {
                $addressParts = array_filter([
                    $property->suite,
                    $property->address,
                    $property->city,
                    $property->province,
                    $property->postal_code,
                    $property->country,
                ]);
                $propertyAddress = implode(', ', $addressParts);
            } elseif (!empty($order->property_address)) {
                $propertyAddress = $order->property_address;
            }
            
            // Build service details
            $serviceDetails = [];
            $serviceCount = 0;
            $serviceName = null;
            $isPartial = false;

            // Check for attached invoice
            $invoiceRecord = null;
            if ($payment->invoice_id) {
                $invoiceRecord = \App\Models\Invoice::with('items.orderService.service')->find($payment->invoice_id);
            }
            
            if ($payment->order_service_id) {
                $orderService = \App\Models\OrderService::with('service')
                    ->find($payment->order_service_id);
                if ($orderService) {
                    $serviceName = $orderService->service->name ?? 'Unknown Service';
                    $serviceDetails[] = [
                        'uuid' => $orderService->uuid,
                        'service_id' => $orderService->service_id,
                        'service_name' => $serviceName,
                        'amount' => number_format($orderService->amount, 2),
                    ];
                    $serviceCount = 1;
                    $isPartial = true;
                }
            } elseif ($invoiceRecord && $invoiceRecord->items->isNotEmpty()) {
                foreach ($invoiceRecord->items as $item) {
                    $sName = $item->orderService?->service?->name ?? $item->description;
                    if ($sName) {
                        $serviceDetails[] = [
                            'uuid' => $item->orderService?->uuid ?? $item->uuid,
                            'service_id' => $item->orderService?->service_id ?? null,
                            'service_name' => $sName,
                            'amount' => number_format($item->amount, 2),
                        ];
                    }
                }
                $serviceCount = count($serviceDetails);
                $orderServiceCount = $order->services ? $order->services->count() : 0;
                if ($orderServiceCount > 0 && $invoiceRecord->items->count() < $orderServiceCount) {
                    $isPartial = true;
                }
                if ($serviceCount === 1) {
                    $serviceName = $serviceDetails[0]['service_name'];
                }
            } else {
                foreach ($order->services as $orderService) {
                    $serviceDetails[] = [
                        'uuid' => $orderService->uuid,
                        'service_id' => $orderService->service_id,
                        'service_name' => $orderService->service->name ?? 'Unknown Service',
                        'amount' => number_format($orderService->amount, 2),
                    ];
                }
                $serviceCount = $order->services->count();
            }
            
            if ($payment->payment_type === 'partial' || $payment->payment_type === 'service') {
                $isPartial = true;
            }

            $paymentTypeText = $isPartial ? 'Partial (Service)' : 'Full Order';

            // Determine scope label
            if ($isPartial) {
                if ($serviceName) {
                    $paymentScope = "Service: {$serviceName}";
                } elseif (!empty($serviceDetails)) {
                    $paymentScope = "Services: " . implode(', ', array_column($serviceDetails, 'service_name'));
                } else {
                    $paymentScope = "Partial Service Payment";
                }
            } else {
                $paymentScope = "Full Order";
            }

            $invoiceNumber = $invoiceRecord ? ($invoiceRecord->invoice_number ?? ('INV-' . $invoiceRecord->id)) : ($payment->invoice_id ? ('INV-' . $payment->invoice_id) : null);

            $paidByAdmin = !empty($paymentMeta['paid_by_admin']);
            if (!$paidByAdmin && !empty($creatorName) && $creatorName !== 'System') {
                if (strtolower(trim($creatorName)) !== strtolower(trim($agentName))) {
                    $paidByAdmin = true;
                }
            }

            // Create structured diff for notification
            $diff = [
                'payment_details' => [
                    'before' => null,
                    'after' => [
                        'agent_name' => $agentName,
                        'order_uuid' => $orderNumber,
                        'amount' => number_format($payment->amount, 2),
                        'currency' => strtoupper($payment->currency),
                        'payment_type' => $paymentTypeText,
                        'payment_scope' => $paymentScope,
                        'payment_method' => ucfirst($payment->payment_method),
                        'service_count' => $serviceCount,
                        'status' => 'Payment Received',
                        'timestamp' => $payment->paid_at ? $payment->paid_at->format('Y-m-d H:i:s') : now()->format('Y-m-d H:i:s'),
                        'receipt_url' => $payment->stripe_receipt_url,
                    ],
                ],
                'metadata' => [
                    'agent_uuid' => $agent->uuid,
                    'agent_email' => $agent->email,
                    'agent_payment_id' => $payment->id,
                    'agent_payment_uuid' => $payment->uuid,
                    'order_id' => $order->id,
                    'order_uuid' => $orderNumber,
                    'order_service_id' => $payment->order_service_id,
                    'stripe_payment_intent_id' => $payment->stripe_payment_intent_id,
                    'stripe_session_id' => $payment->stripe_session_id,
                    'payment_id' => $payment->id,
                    'is_quickbooks_synced' => !empty($payment->quickbooks_invoice_id),
                    'quickbooks_invoice_id' => $payment->quickbooks_invoice_id,
                    'invoice_id' => $invoiceRecord?->id ?? $payment->invoice_id,
                    'invoice_uuid' => $invoiceRecord?->uuid,
                    'invoice_number' => $invoiceNumber,
                    'services' => $serviceDetails,
                    'payment_scope' => $paymentScope,
                    'service_name' => $serviceName,
                    'is_partial' => $isPartial,
                    'paid_by_admin' => $paidByAdmin,
                    'creator_uuid' => $creatorUuid,
                    'creator_name' => $creatorName,
                    'creator_email' => $creatorEmail,
                ],
            ];

            // 1. Notify via NotificationService (with idempotency guard to prevent duplicates)
            if (empty($paymentMeta['notification_sent_at'])) {
                \App\Services\NotificationService::notifyPaymentEvent(
                    'agent_payment_success',
                    $order,
                    $diff,
                    [
                        'recipient_type' => 'admin',
                        'agent_uuid' => $agent->uuid,
                        'agent_name' => $agentName,
                        'agent_email' => $agent->email,
                        'payment_type' => 'agent_to_admin',
                        'agent_payment_id' => $payment->id,
                        'agent_payment_uuid' => $payment->uuid,
                        'property_address' => $propertyAddress,
                        'service_details' => $serviceDetails,
                        'payment_scope' => $paymentScope,
                        'service_name' => $serviceName,
                        'invoice_id' => $invoiceRecord?->id ?? $payment->invoice_id,
                        'invoice_uuid' => $invoiceRecord?->uuid,
                        'invoice_number' => $invoiceNumber,
                        'is_partial' => $isPartial,
                        'paid_by_admin' => $paidByAdmin,
                        'creator_uuid' => $creatorUuid,
                        'creator_name' => $creatorName,
                        'creator_email' => $creatorEmail,
                    ]
                );

                $paymentMeta['notification_sent_at'] = now()->toISOString();
                $payment->update(['meta' => $paymentMeta]);

                Log::info('Agent payment in-portal notification created successfully', [
                    'agent_uuid' => $agent->uuid,
                    'order_uuid' => $orderNumber,
                    'payment_id' => $payment->id,
                    'creator_name' => $creatorName,
                    'scope' => $paymentScope,
                ]);
            } else {
                Log::info('In-portal notification already sent for payment, skipping duplicate', [
                    'payment_id' => $payment->id,
                ]);
            }

            // 2. Dispatch email notification to both agent and admins via EmailDispatchService
            try {
                app(\App\Services\EmailDispatchService::class)->dispatch('agent_payment_received', $payment);
            } catch (\Throwable $emailError) {
                Log::error('Failed to dispatch agent_payment_received email', [
                    'payment_id' => $payment->id,
                    'error' => $emailError->getMessage(),
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Failed to create webhook payment notification', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    }


