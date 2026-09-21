<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Stripe\Stripe;
use Stripe\Transfer;
use Exception;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Models\Notification;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\VendorPaymentProcessed;

class AdminStripePaymentController extends Controller
{
    /**
     * Create notification for vendor payment
     */
    private function createVendorPaymentNotification($vendor, $services, $transfer, bool $isBulk, ?string $vendorPaymentUuid = null, ?int $vendorPaymentId = null): void
    {
        try {
            // Get authenticated user for creator context
            $authenticatedUser = auth()->user();
            $creatorUuid = $authenticatedUser ? $authenticatedUser->uuid : null;
            $creatorName = $authenticatedUser 
                ? trim($authenticatedUser->first_name . ' ' . $authenticatedUser->last_name) 
                : 'System';
            $creatorEmail = $authenticatedUser ? $authenticatedUser->email : null;

            $vendorName = trim($vendor->first_name . ' ' . $vendor->last_name) ?: 'Unknown Vendor';
            $serviceCount = $services->count();
            $totalAmount = $services->sum('amount');
            
            // Load order services with relations to get full details
            $orderServiceIds = $services->pluck('id')->toArray();
            $orderServices = \App\Models\OrderService::with(['service', 'order.property', 'order.agent'])
                ->whereIn('id', $orderServiceIds)
                ->get();
            
            // Get order and property details (from first service - all should belong to same order in most cases)
            $firstOrderService = $orderServices->first();
            $order = $firstOrderService->order ?? null;
            $property = $order->property ?? null;
            
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
            }
            
            // Build detailed service information
            $serviceDetails = [];
            foreach ($orderServices as $orderService) {
                $serviceName = $orderService->service->name ?? 'Unknown Service';
                $serviceDetails[] = [
                    'uuid' => $orderService->uuid,
                    'service_id' => $orderService->service_id,
                    'service_name' => $serviceName,
                    'amount' => number_format($orderService->amount, 2),
                ];
            }

            // Create structured diff for notification
            $diff = [
                'payment_details' => [
                    'before' => null,
                    'after' => [
                        'vendor_name' => $vendorName,
                        'amount' => number_format($totalAmount, 2),
                        'currency' => 'USD',
                        'transfer_id' => $transfer->id,
                        'payment_type' => $isBulk ? 'Bulk Payment' : 'Single Payment',
                        'payment_method' => 'Stripe Transfer',
                        'service_count' => $serviceCount,
                        'status' => 'Payment Transferred',
                        'timestamp' => now()->format('Y-m-d H:i:s'),
                        'receipt_url' => rtrim(env('STRIPE_DASHBOARD_URL'), '/') . '/' . $transfer->id,
                    ],
                ],
                'metadata' => [
                    'vendor_uuid' => $vendor->uuid,
                    'vendor_email' => $vendor->email ?? null,
                    'vendor_payment_id' => $vendorPaymentId,
                    'vendor_payment_uuid' => $vendorPaymentUuid,
                    'order_id' => $order->id ?? null,
                    'order_uuid' => $order->uuid ?? null,
                    'stripe_transfer_id' => $transfer->id,
                    'stripe_balance_transaction_id' => $transfer->balance_transaction ?? null,
                    'services' => $serviceDetails,
                    'is_bulk' => $isBulk,
                    'creator_uuid' => $creatorUuid,
                    'creator_name' => $creatorName,
                    'creator_email' => $creatorEmail,
                ],
            ];

            // Notify admin and vendor
            \App\Services\NotificationService::notifyPaymentEvent(
                'vendor_payment_success',
                $vendor,
                $diff,
                [
                    'recipient_type' => 'vendor',
                    'vendor_uuid' => $vendor->uuid,
                    'vendor_payment_id' => $vendorPaymentId,
                    'vendor_payment_uuid' => $vendorPaymentUuid,
                    'order_id' => $order->id ?? null,
                    'order_uuid' => $order->uuid ?? null,
                    'property_address' => $propertyAddress,
                    'service_details' => $serviceDetails,
                    'creator_uuid' => $creatorUuid,
                    'creator_name' => $creatorName,
                    'creator_email' => $creatorEmail,
                ]
            );

            Log::info('Vendor payment notification sent', [
                'vendor_uuid' => $vendor->uuid,
                'transfer_id' => $transfer->id,
                'amount' => $totalAmount,
                'is_bulk' => $isBulk,
                'order_uuid' => $order->uuid ?? null,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create vendor payment notification', [
                'vendor_uuid' => $vendor->uuid ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function payVendor(Request $request)
    {
        Log::info("PAY VENDOR API HIT", ['payload' => $request->all()]);

        // ------------------------------
        // VALIDATION
        // ------------------------------
        $request->validate([
            'vendor_uuid'           => 'required|exists:vendors,uuid',
            'amount'                => 'required|numeric|min:1',
            'order_service_uuids'   => 'required|array|min:1',
            'order_service_uuids.*' => 'required|uuid',
        ]);

        $vendor = Vendor::where('uuid', $request->vendor_uuid)->first();

        if (!$vendor->stripe_account_id) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Vendor does not have a connected Stripe account.',
            ], 400);
        }

        $orderServiceUuids = $request->order_service_uuids;
        $isBulk = count($orderServiceUuids) > 1;

        Log::info("Order services provided", [
            'is_bulk'  => $isBulk,
            'services' => $orderServiceUuids
        ]);

        // ------------------------------
        // CHECK ALREADY PAID
        // ------------------------------
        $alreadyPaid = DB::table('order_services')
            ->whereIn('uuid', $orderServiceUuids)
            ->where('vendor_paid', true)
            ->pluck('uuid')
            ->toArray();

        if (!empty($alreadyPaid)) {
            return response()->json([
                'status'  => 'error',
                'message' => "Payment already processed for: " . implode(", ", $alreadyPaid),
            ], 422);
        }

        // ------------------------------
        // FETCH ORDER SERVICES
        // ------------------------------
        $services = DB::table('order_services')
            ->whereIn('uuid', $orderServiceUuids)
            ->get();

        if ($services->isEmpty()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Order services not found.',
            ], 404);
        }

        $serviceUuids = $services->pluck('uuid')->toArray();
        $totalAmount  = (float) $services->sum('amount');

        if (abs($request->amount - $totalAmount) > 0.01) {
            return response()->json([
                'status'  => 'error',
                'message' => "Amount mismatch: expected $totalAmount, received $request->amount",
            ], 422);
        }

        // ------------------------------
        // STRIPE TRANSFER
        // ------------------------------
        Stripe::setApiKey(config('services.stripe.secret'));

        try {
            $transfer = Transfer::create([
                'amount'      => $request->amount * 100,
                'currency'    => 'usd',
                'destination' => $vendor->stripe_account_id,
                'metadata'    => [
                    'vendor_uuid' => $vendor->uuid,
                    'type'        => 'vendor_payout',
                ],
            ]);

            $invoiceUrl = rtrim(env('STRIPE_DASHBOARD_URL'), '/') . '/' . $transfer->id;

        } catch (Exception $e) {

            // store failed payment logs
            foreach ($services as $service) {
                VendorPayment::create([
                    'vendor_uuid'        => $vendor->uuid,
                    'order_service_uuid' => $service->uuid,
                    'amount'             => $service->amount,
                    'status'             => 'failed',
                    'is_bulk'            => $isBulk,
                    'notes'              => $e->getMessage(),
                ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => "Payment failed: " . $e->getMessage(),
            ], 500);
        }

        // ------------------------------
        // STORE DATA IN DB (INSIDE TRY)
        // ------------------------------
        try {

            DB::transaction(function () use ($vendor, $services, $transfer, $invoiceUrl, $serviceUuids, $isBulk) {

                $description = "Payment for services [" . implode(", ", $serviceUuids) . "] via Stripe transfer {$transfer->id}";

                $lastVendorPayment = null;

                foreach ($services as $service) {
                    Log::info("Processing payment for service", ['service_uuid' => $service->uuid], $isBulk ? 'bulk' : 'single');

                    $lastVendorPayment = VendorPayment::create([
                        'vendor_uuid'                    => $vendor->uuid,
                        'order_service_uuid'             => $service->uuid,
                        'amount'                         => $service->amount,
                        'currency'                        => 'usd',
                        'stripe_transfer_id'             => $transfer->id,
                        'stripe_balance_transaction_id'  => $transfer->balance_transaction,
                        'invoice_url'                    => $invoiceUrl,
                        'status'                         => 'success',
                        'is_bulk'                        => $isBulk ? 1 : 0,
                        'notes'                          => $description,
                        'metadata'                       => $transfer->toArray(),
                    ]);
                }

                DB::table('order_services')
                    ->whereIn('uuid', $serviceUuids)
                    ->update([
                        'vendor_paid'    => true,
                        'vendor_paid_at' => now()
                    ]);

                // Create vendor payment notification
                $this->createVendorPaymentNotification(
                    $vendor,
                    $services,
                    $transfer,
                    $isBulk,
                    $lastVendorPayment->uuid ?? null,
                    $lastVendorPayment->id ?? null
                );

                // Send email notification
                try {
                    // Load order services with relationships for email
                    $orderServices = \App\Models\OrderService::with(['service', 'option', 'order.property', 'order.agent'])
                        ->whereIn('id', $services->pluck('id'))
                        ->get();

                    app(\App\Services\EmailDispatchService::class)->dispatch('vendor_payment_processed', $lastVendorPayment, [
                        'data' => [
                            'services' => $orderServices,
                        ]
                    ]);
                } catch (\Exception $emailError) {
                    // Log email errors but don't fail the payment processing
                    Log::error('Failed to send vendor payment emails via dispatch service', [
                        'vendor_uuid' => $vendor->uuid,
                        'error' => $emailError->getMessage(),
                    ]);
                }
            });

        } catch (Exception $e) {

            Log::error("DB Transaction Failed", [
                "error" => $e->getMessage()
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => "Database update failed: " . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'status'      => 'success',
            'message'     => 'Payment transferred successfully.',
            'transfer_id' => $transfer->id,
            'invoice_url' => $invoiceUrl,
            'is_bulk'     => $isBulk,
        ]);
    }

}
