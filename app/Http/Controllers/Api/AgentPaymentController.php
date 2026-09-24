<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Stripe\StripeClient;
use Stripe\Invoice;
use Illuminate\Support\Str;
use Exception;
use Illuminate\Support\Facades\Log;
use App\Models\AgentPayment;
use App\Models\Agent;
use App\Models\Order;
use App\Models\OrderService;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Api\StripeAgentWebhookController;
class AgentPaymentController extends Controller
{
    public function createCheckoutSession(Request $request)
    {   
        $request->validate([
            'agent_uuid' => 'required|string',
            'amount' => 'required|numeric|min:0.5',
            'currency' => 'required|string',
            'order_id' => 'required|exists:orders,id',
            'description' => 'nullable|string',
            'service_id' => 'nullable|exists:order_services,uuid',
            'invoice_uuid' => 'nullable|exists:invoices,uuid',
            'payment_type' => 'nullable|string|in:full,service',
            'payment_mode' => 'nullable|string|in:on_behalf,self', // Split invoice: on_behalf = co-agent money, self = agent money
            'payer_uuid' => 'nullable|string', // The agent or admin actually making the payment
            'url' => 'nullable|string'
        ]);

        $amount = number_format((float)$request->amount, 2, '.', '');
        $currency = $request->currency ?? 'usd';
        $serviceId = $request->service_id;
        $invoiceUuid = $request->invoice_uuid;
        $paymentType = $request->payment_type ?? 'full';
        $paymentMode = $request->payment_mode; // null for non-split, 'on_behalf' or 'self' for split
        $payerUuid = $request->payer_uuid; // Who is actually paying
        $url = $request->url;


        $stripe = new StripeClient(config('services.stripe.secret'));
        
        try {
            $authenticatedUser = auth()->user();
            $creatorUuid = $authenticatedUser ? $authenticatedUser->uuid : null;
            $creatorName = $authenticatedUser 
                ? trim($authenticatedUser->first_name . ' ' . $authenticatedUser->last_name) 
                : 'System';
            $creatorEmail = $authenticatedUser ? $authenticatedUser->email : null;

            $metadata = [
                'agent_uuid' => $request->agent_uuid,
                'order_id' => $request->order_id ?? '',
                'invoice_uuid' => $invoiceUuid,
                'amount' => $amount,
                'currency' => $currency,
                'payment_type' => $paymentType,
                'payment_mode' => $paymentMode ?? '',
                'payer_uuid' => $payerUuid ?? $creatorUuid ?? '',
                'creator_uuid' => $creatorUuid,
                'creator_name' => $creatorName,
                'creator_email' => $creatorEmail,
            ];

            if ($paymentType === 'service' && $serviceId) {
                $metadata['service_id'] = $serviceId;
            }


            $baseUrl = env('FRONTEND_URL', 'http://localhost:3000');
            
            // If $url is already absolute (starts with http), use it as is.
            // Otherwise, treat it as relative and prepend the base URL.
            if (!empty($url) && (preg_match('~^https?://~i', $url))) {
                $fullUrl = $url;
            } else {
                $path = !empty($url) ? '/' . ltrim($url, '/') : '/dashboard/thank-you';
                $fullUrl = rtrim($baseUrl, '/') . $path;
            }

            // Recalculate connector based on the final fullUrl
            $connector = str_contains($fullUrl, '?') ? '&' : '?';
            $successUrl = $fullUrl . "{$connector}session_id={CHECKOUT_SESSION_ID}";
            $cancelUrl = $fullUrl;

            $session = $stripe->checkout->sessions->create([
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => $currency,
                        'product_data' => [
                            'name' => $request->description ?? 'Agent Payment',
                        ],
                        'unit_amount' => intval($amount * 100),
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'invoice_creation' => ['enabled' => true ],
                'metadata' => $metadata, // Updated metadata with new variables
                'payment_intent_data' => [
                'metadata' => $metadata],
            ]);

          
            return response()->json([
                'success' => true,
                'url' => $session->url,
            ]);

        } catch (Exception $e) {
            Log::error('Stripe Checkout create error: ' . $e->getMessage());

            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // public function getSessionDetails(Request $request)
    //     {
    //         $id = $request->query('session_id');

    //         if (!$id) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'Missing Stripe session ID.',
    //             ], 400);
    //         }

    //         try {
    //             Log::info("Stripe Session ID (from query): " . $id);

    //             $stripe = new StripeClient(config('services.stripe.secret'));
    //             $session = $stripe->checkout->sessions->retrieve($id);
    //             Log::info("Stripe Session Data: " . json_encode($session));

    //             $paymentIntent = $session->payment_intent
    //                 ? $stripe->paymentIntents->retrieve($session->payment_intent)
    //                 : null;

    //             $metadata = $session->metadata ?? [];
    //             $status = $session->payment_status ?? 'pending';
    //             $amount = ($session->amount_total ?? 0) / 100;
    //             $currency = strtolower($session->currency ?? 'usd');
    //             $agentUuid = $metadata['agent_uuid'] ?? null;
    //             $orderId = $metadata['order_id'] ?? null;
    //             $serviceId = $metadata['service_id'] ?? null;
    //             $paymentType = $metadata['payment_type'] ?? 'full';

    //             // Resolve references
    //             $agentId = $agentUuid ? Agent::where('uuid', $agentUuid)->value('id') : null;
    //             $orderServiceId = $serviceId ? OrderService::where('uuid', $serviceId)->value('id') : null;
    //             $resolvedOrderId = is_numeric($orderId)
    //                 ? (int) $orderId
    //                 : Order::where('uuid', $orderId)->value('id');

    //             //  Check if this session already marked as paid before
    //             $existingPayment = AgentPayment::where('stripe_session_id', $session->id)
    //                 ->whereNotNull('paid_at')
    //                 ->first();

    //             if ($existingPayment) {
    //                 Log::info('Payment already processed for session: ' . $session->id);
    //                 return response()->json([
    //                     'success' => true,
    //                     'message' => 'Payment already processed.',
    //                     'data' => [
    //                         'payment_id' => $existingPayment->id,
    //                         'status' => $existingPayment->status,
    //                         'amount' => $existingPayment->amount,
    //                         'currency' => $existingPayment->currency,
    //                         'agent_id' => $existingPayment->agent_id,
    //                         'order_id' => $existingPayment->order_id,
    //                     ],
    //                 ]);
    //             }

    //             // Create or update payment record
    //             $payment = AgentPayment::updateOrCreate(
    //                 ['stripe_session_id' => $session->id],
    //                 [
    //                     'uuid' => (string) Str::uuid(),
    //                     'agent_id' => $agentId,
    //                     'order_service_id' => $orderServiceId,
    //                     'order_id' => $resolvedOrderId,
    //                     'amount' => $amount,
    //                     'currency' => $currency,
    //                     'session_created_at' => $session->created
    //                         ? date('Y-m-d H:i:s', $session->created)
    //                         : now(),
    //                     'status' => $session->payment_status === 'paid' ? 'succeeded' : 'processing',
    //                     'payment_method' => $session->payment_method_types[0] ?? 'card',
    //                     'payment_type' => ($metadata['payment_type'] ?? 'full') === 'service' ? 'partial' : 'full',
    //                     'stripe_payment_intent_id' => $session->payment_intent,
    //                     'stripe_customer_id' => $session->customer,
    //                     'meta' => [
    //                         'metadata' => $metadata,
    //                         'source' => 'checkout_session',
    //                     ],
    //                 ]
    //             );

    //             // Retrieve invoice if payment is paid
    //             if ($session->payment_status === 'paid') {
    //                 try {
    //                     $invoiceUrl = null;
    //                     if (!empty($session->invoice)) {
    //                         $invoice = $stripe->invoices->retrieve($session->invoice);
    //                         $invoiceUrl = $invoice->hosted_invoice_url ?? null;
    //                         $paymentTime = $invoice->status_transitions->paid_at ?? null;

    //                         $payment->paid_at = $paymentTime
    //                             ? date('Y-m-d H:i:s', $paymentTime)
    //                             : now();
    //                         $payment->stripe_receipt_url = $invoiceUrl ?? $payment->stripe_receipt_url;
    //                         $payment->save();
    //                     } else {
    //                         $payment->paid_at = now();
    //                         $payment->save();
    //                     }

    //                     //  Update order after confirming this session wasn’t processed before
    //                     StripeAgentWebhookController::updateOrderAfterPayment(
    //                         $payment->order_id,
    //                         $payment->amount,
    //                         $payment->payment_type,
    //                         $payment->order_service_id
    //                     );

    //                     Log::info('Order updated successfully for paid session: ' . $session->id);
    //                 } catch (\Exception $e) {
    //                     Log::error('Error updating order for session ' . $session->id . ': ' . $e->getMessage());
    //                 }
    //             }

    //             return response()->json([
    //                 'success' => true,
    //                 'message' => 'Payment record created/updated successfully.',
    //                 'data' => [
    //                     'payment_id' => $payment->id,
    //                     'status' => $payment->status,
    //                     'amount' => $payment->amount,
    //                     'currency' => $payment->currency,
    //                     'agent_id' => $payment->agent_id,
    //                     'order_id' => $payment->order_id,
    //                 ],
    //             ]);
    //         } catch (\Exception $e) {
    //             Log::error('Stripe session processing error: ' . $e->getMessage());
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'Unable to process Stripe session.',
    //                 'error' => $e->getMessage(),
    //             ], 500);
    //         }
    //     }
 public function getSessionDetails(Request $request)
{
    $id = $request->query('session_id');

    if (!$id) {
        return response()->json([
            'success' => false,
            'message' => 'Missing Stripe session ID.',
        ], 400);
    }

    try {
        Log::info("Processing Stripe Session ID: " . $id);

        $stripe = new StripeClient(config('services.stripe.secret'));
        $session = $stripe->checkout->sessions->retrieve($id);

        $metadata = $session->metadata ?? [];
        $status = $session->payment_status ?? 'pending';
        $amount = ($session->amount_total ?? 0) / 100;
        $currency = strtolower($session->currency ?? 'usd');
        $agentUuid = $metadata['agent_uuid'] ?? null;
        $orderId = $metadata['order_id'] ?? null;
        $serviceId = $metadata['service_id'] ?? null;
        $paymentType = $metadata['payment_type'] ?? 'full';
        $paymentMode = !empty($metadata['payment_mode']) ? $metadata['payment_mode'] : null;
        $payerUuid = !empty($metadata['payer_uuid']) ? $metadata['payer_uuid'] : null;
        $invoiceUuid = !empty($metadata['invoice_uuid']) ? $metadata['invoice_uuid'] : null;

        // Resolve references
        $agentId = $agentUuid ? Agent::where('uuid', $agentUuid)->value('id') : null;
        $orderServiceId = $serviceId ? OrderService::where('uuid', $serviceId)->value('id') : null;
        $resolvedOrderId = is_numeric($orderId)
            ? (int) $orderId
            : Order::where('uuid', $orderId)->value('id');

        // Resolve payer agent for split invoice tracking
        $payerAgentId = $payerUuid ? Agent::where('uuid', $payerUuid)->value('id') : null;

        // Resolve invoice ID from UUID
        $invoiceId = $invoiceUuid ? \App\Models\Invoice::where('uuid', $invoiceUuid)->value('id') : null;

        // Determine the effective agent_id for the payment record based on payment_mode
        // 'self' = payer takes ownership of the expense (agent's money)
        // 'on_behalf' = paying for the co-agent (co-agent's money)
        $effectiveAgentId = $agentId;
        if ($paymentMode === 'self' && $payerAgentId) {
            $effectiveAgentId = $payerAgentId;
        }

        // Check if this session already processed
        $existingPayment = AgentPayment::where('stripe_session_id', $session->id)
            ->whereNotNull('paid_at')
            ->first();

        if ($existingPayment) {
            Log::info('Payment already processed for session: ' . $session->id);
            return response()->json([
                'success' => true,
                'message' => 'Payment already processed.',
                'data' => [
                    'payment_id' => $existingPayment->id,
                    'status' => $existingPayment->status,
                    'amount' => $existingPayment->amount,
                    'currency' => $existingPayment->currency,
                    'payment_mode' => $existingPayment->payment_mode,
                    'quickbooks_synced' => !empty($existingPayment->quickbooks_invoice_id),
                    'quickbooks_invoice_id' => $existingPayment->quickbooks_invoice_id,
                ],
            ]);
        }

        // Start transaction for ALL database operations
        DB::beginTransaction();

        try {
            // Create or update payment record
            $payment = AgentPayment::updateOrCreate(
                ['stripe_session_id' => $session->id],
                [
                    'uuid' => (string) Str::uuid(),
                    'agent_id' => $effectiveAgentId,
                    'paid_by_agent_id' => $payerAgentId,
                    'order_service_id' => $orderServiceId,
                    'order_id' => $resolvedOrderId,
                    'invoice_id' => $invoiceId,
                    'amount' => $amount,
                    'currency' => $currency,
                    'session_created_at' => $session->created
                        ? date('Y-m-d H:i:s', $session->created)
                        : now(),
                    'status' => $session->payment_status === 'paid' ? 'succeeded' : 'processing',
                    'payment_method' => $session->payment_method_types[0] ?? 'card',
                    'payment_type' => ($metadata['payment_type'] ?? 'full') === 'service' ? 'partial' : 'full',
                    'payment_mode' => $paymentMode,
                    'stripe_payment_intent_id' => $session->payment_intent,
                    'stripe_customer_id' => $session->customer,
                    'meta' => [
                        'metadata' => $metadata,
                        'source' => 'checkout_session',
                    ],
                ]
            );

            // Handle paid status
            if ($session->payment_status === 'paid') {
                // Get invoice URL if available
                $invoiceUrl = null;
                if (!empty($session->invoice)) {
                    $invoice = $stripe->invoices->retrieve($session->invoice);
                    $invoiceUrl = $invoice->hosted_invoice_url ?? null;
                    $paymentTime = $invoice->status_transitions->paid_at ?? null;

                    $payment->paid_at = $paymentTime
                        ? date('Y-m-d H:i:s', $paymentTime)
                        : now();
                    $payment->stripe_receipt_url = $invoiceUrl ?? $payment->stripe_receipt_url;
                } else {
                    $payment->paid_at = now();
                }
                
                $payment->save();

                // Update internal Invoice record if present
                if ($invoiceId && $session->payment_status === 'paid') {
                    $invoiceRecord = \App\Models\Invoice::find($invoiceId);
                    // Check if this payment was already processed via webhook
                    // If we just created the payment record now, or it wasn't paid yet, we should update the invoice
                    $isNewPayment = $payment->wasRecentlyCreated || $payment->status !== 'succeeded';

                    if ($invoiceRecord && $isNewPayment) {
                        $newPaid = (float)$invoiceRecord->paid_amount + $amount;
                        $isFullyPaid = $newPaid >= (float)$invoiceRecord->total;

                        $invoiceRecord->update([
                            'paid_amount' => $newPaid,
                            'status' => $isFullyPaid ? 'paid' : 'partially_paid',
                            'paid_at' => $isFullyPaid ? now() : $invoiceRecord->paid_at,
                        ]);
                    }
                }

                // Update order status, sync invoices, and dispatch in-portal & email notifications
                StripeAgentWebhookController::updateOrderAfterPayment(
                    $payment->order_id,
                    $payment->amount,
                    $payment->payment_type,
                    $payment->order_service_id,
                    $payment
                );
                
                Log::info('Order updated successfully for paid session: ' . $session->id);
                Log::info('Order ID: ' . $payment->order_id);
            }

            // COMMIT transaction for BOTH paid and non-paid payments
            DB::commit();

            // AFTER successful commit, dispatch QuickBooks job for paid payments
            if ($session->payment_status == 'paid' && $payment->quickbooks_invoice_id == null && $payment->order_id) {
                $this->dispatchQuickBooksJob($payment);
            }
            else{
                Log::info('QuickBooks job not dispatched. Either payment not paid or already synced.', [
                    'payment_id' => $payment->id,
                    'payment_status' => $session->payment_status,
                    'quickbooks_invoice_id' => $payment->quickbooks_invoice_id,
                ]);
            }

        } catch (\Exception $e) {
            // ROLLBACK on any database error
            DB::rollBack();
            
            Log::error('Database transaction failed for session ' . $session->id . ': ' . $e->getMessage());
            throw $e; // Re-throw to outer catch
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment record created/updated successfully.',
            'data' => [
                'payment_id' => $payment->id,
                'status' => $payment->status,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'agent_id' => $payment->agent_id,
                'order_id' => $payment->order_id,
                'quickbooks_synced' => !empty($payment->quickbooks_invoice_id),
                'quickbooks_invoice_id' => $payment->quickbooks_invoice_id ?? null,
            ],
        ]);
        
    } catch (\Exception $e) {
        Log::error('Stripe session processing error: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Unable to process Stripe session.',
            'error' => $e->getMessage(),
        ], 500);
    }
}

/**
 * Dispatch QuickBooks job (separate method for clarity)
 */
private function dispatchQuickBooksJob($payment)
{
    Log::info('QuickBooks creation started for payment: ' . $payment->id);
    
    dispatch(function() use ($payment) {
        try {
            $order = Order::with('agent', 'services')->find($payment->order_id);
            
            if (!$order) {
                Log::warning('Order not found for QB sync', [
                    'payment_id' => $payment->id
                ]);
                return;
            }

            // Check if QuickBooks is connected
            $organization = \App\Models\Organization::whereNotNull('qb_access_token')
                ->whereNotNull('qb_realm_id')
                ->first();
            
            Log::info('QuickBooks organization check', [
                'org_found' => $organization ? true : false,
                'payment_id' => $payment->id
            ]);

            if (!$organization) {
                Log::info('QuickBooks not connected, skipping sync', [
                    'payment_id' => $payment->id
                ]);
                return;
            }

            $quickBooksService = app(\App\Services\QuickBooksService::class);
            Log::info('Starting QuickBooks invoice creation', [
                'order_id' => $order->id,
                'payment_id' => $payment->id
            ]);
            
            $qbInvoice = $quickBooksService->createInvoiceForOrder($order, $payment);
            
            Log::info('QuickBooks invoice creation attempt finished', [
                'order_id' => $order->id,
                'payment_id' => $payment->id
            ]);
            
            if ($qbInvoice) {
                Log::info('QuickBooks invoice created successfully', [
                    'order_id' => $order->id,
                    'payment_id' => $payment->id,
                    'qb_invoice_id' => $qbInvoice['invoice_id']
                ]);
                
                $quickbookData = [
                    'quickbooks_invoice_id' => $qbInvoice['invoice_id'],
                    'quickbooks_payment_id' => $qbInvoice['payment_id'],
                    'quickbooks_txn_id'     => $qbInvoice['doc_number'],
                    'quickbooks_synced_at'  => $qbInvoice['synced_at'],
                ];

                $payment->update($quickbookData);
            } else {
                Log::warning('QuickBooks invoice creation returned null', [
                    'order_id' => $order->id,
                    'payment_id' => $payment->id
                ]);
            }
            
        } catch (\Exception $qbError) {
            Log::error('QuickBooks sync error (non-fatal): ' . $qbError->getMessage(), [
                'payment_id' => $payment->id,
                'error_trace' => $qbError->getTraceAsString()
            ]);
        }
    })->afterResponse()->onQueue('quickbooks');
}

    /**
     * Create notification and dispatch email for agent payment success
     */
    private function createAgentPaymentNotification($payment, $session = null): void
    {
        try {
            $order = Order::find($payment->order_id);
            if (!$order && $payment->invoice_id) {
                $order = $payment->invoice?->order;
            }

            if ($order) {
                StripeAgentWebhookController::createWebhookPaymentNotification($payment, $order);
            }
        } catch (\Throwable $e) {
            Log::error('Failed in createAgentPaymentNotification: ' . $e->getMessage());
        }
    }
}



