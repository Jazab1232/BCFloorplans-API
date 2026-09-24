<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Models\AgentPayment;

class BillingController extends Controller
{
    public function getBillings(Request $request)
    {
        $user = Auth::user();

        // Determine user type
        $isAgent = $user instanceof \App\Models\Agent;
        $isVendor = $user instanceof \App\Models\Vendor;
        $isSubAccount = $user instanceof \App\Models\SubAccount;
        $isAdmin = !$isAgent && !$isVendor && !$isSubAccount;

        Log::info('Billing Debug:', [
            'user_type' => get_class($user),
            'user_id' => $user->id ?? null,
            'is_admin' => $isAdmin,
        ]);

        $query = Order::with([
            'organization:id,name',
            'agent:id,first_name,last_name,uuid',
            'property:id,address,city',
            'services:id,uuid,order_id,service_id,amount,payment_status,media_access',
            'services.service:id,name',
            'slots:id,order_id,vendor_id,date,address,location,start_time,end_time,service_id',
            'slots.vendor:id,first_name,last_name,uuid',
        ]);

        if (!$isAdmin) {
            if ($isAgent) {
                $query->where('agent_id', $user->id);
            } elseif ($isSubAccount) {
                $canViewAll = $user->canViewAllAgentOrders();
                if ($canViewAll) {
                    $query->where(function ($q) use ($user) {
                        $q->where('agent_id', $user->agent_id)
                          ->orWhere('co_agents', 'like', '%' . $user->primary_email . '%')
                          ->orWhere('co_agents', 'like', '%' . $user->uuid . '%');
                    });
                } else {
                    $subUuid = (string)$user->uuid;
                    $subEmail = (string)$user->primary_email;
                    $query->where(function ($q) use ($subUuid, $subEmail) {
                        $q->whereJsonContains('co_agents', ['agent_uuid' => $subUuid])
                          ->orWhereJsonContains('co_agents', ['uuid' => $subUuid])
                          ->orWhereJsonContains('co_agents', ['email' => $subEmail])
                          ->orWhereJsonContains('co_agents', ['primary_email' => $subEmail])
                          ->orWhere('co_agents', 'like', '%' . $subEmail . '%')
                          ->orWhere('co_agents', 'like', '%' . $subUuid . '%');
                    });
                }
            } elseif ($isVendor && method_exists($user, 'agents')) {
                $agentIds = $user->agents()->pluck('id');
                $query->whereIn('agent_id', $agentIds);
            }
        }

        $orders = $query->get();

        if ($orders->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [],
                'message' => 'No billing data found.',
            ]);
        }

        $billingData = $orders->map(function ($order) {
            $payments = AgentPayment::where('order_id', $order->id)
                ->orderBy('paid_at', 'desc')
                ->get();

            // Order-level total, paid, and refunds
            $totalAmount = (float)$order->services->sum('amount');
            
            // Retrieve invoices to calculate tax rate and refund/cancellation status
            $orderInvoices = \App\Models\Invoice::where('order_id', $order->id)->where('status', '!=', 'void')->get();
            if ($orderInvoices->isEmpty()) {
                $orderInvoices = \App\Models\Invoice::where('order_id', $order->id)->get();
            }
            $taxRate = $orderInvoices->isNotEmpty() ? (float)$orderInvoices->first()->tax_rate : 13.0;

            $grandTotal = round($totalAmount * (1 + $taxRate / 100), 2);
            
            // Gross refunds
            $totalRefunded = abs((float)$payments->filter(fn($p) => $p->status === 'refunded' || (float)$p->amount < 0)->sum('amount'));
            // Net Paid (succeeded payments minus refunds)
            $totalPaid = (float)$payments->sum('amount');

            $effectiveGrandTotal = max(0.0, $grandTotal - $totalRefunded);
            $remainingAmount = max(0.0, $effectiveGrandTotal - $totalPaid);

            if ($totalRefunded >= $grandTotal || ($orderInvoices->isNotEmpty() && $orderInvoices->every(fn($inv) => $inv->status === 'refunded'))) {
                $orderStatus = 'refunded';
            } elseif ($totalPaid >= $effectiveGrandTotal) {
                $orderStatus = 'paid';
            } elseif ($totalPaid > 0) {
                $orderStatus = 'partial';
            } else {
                $orderStatus = 'unpaid';
            }

            // Service-level data
            $services = $order->services->map(function ($service) use ($payments, $orderStatus) {
                // Check if any payment covered this service
                $relatedPayments = $payments->filter(function ($p) use ($service) {
                    return $p->order_service_id == $service->id;
                });
                
                $osStatus = strtolower($service->payment_status ?? 'unpaid');
                if ($orderStatus === 'paid') {
                    $status = $osStatus === 'refunded' ? 'refunded' : ($osStatus === 'cancelled' ? 'cancelled' : 'paid');
                } else {
                    if (in_array($osStatus, ['paid', 'refunded', 'cancelled'])) {
                        $status = $osStatus;
                    } else {
                        $isPaid = $relatedPayments->sum('amount') >= $service->amount;
                        $status = $isPaid ? 'paid' : 'unpaid';
                    }
                }

                return [
                    'service_id' => $service->service_id,
                    'order_service_uuid' => $service->uuid ?? null,
                    'service_name' => $service->service->name ?? 'Unknown',
                    'amount' => (float) $service->amount,
                    'status' => $status,
                    'media_access' => $service->media_access,
                    'related_invoices' => $relatedPayments->map(fn($p) => [
                        'invoice_url' => $p->stripe_receipt_url ?? null,
                        'payment_id' => $p->payment_id ?? null,
                        'order_service_uuid' => $p->order_service_uuid ?? null,
                        'amount' => (float) $p->amount,
                        'payment_method' => $p->payment_method ?? null,
                        'status' => $p->status,
                        'paid_at' => $p->paid_at,
                        'session_id' => $p->stripe_session_id ?? null,
                    ]),
                ];
            });
            
            return [
                'order_id' => $order->id,
                'order_uuid' => $order->uuid ?? null,
                'lock_materials' => (bool) $order->lock_materials,
                'organization' => $order->organization ? [
                    'id' => $order->organization->id,
                    'name' => $order->organization->name,
                ] : null,
                'organization_id' => $order->organization_id,
                'agent_name' => trim(($order->agent->first_name ?? '') . ' ' . ($order->agent->last_name ?? '')),
                'agent_uuid' => $order->agent->uuid ?? null,
                'property_address' => $order->property_address ?: ($order->property->address ?? null),
                'property_location' => $order->property_location ?: ($order->property->city ?? null),
                'total_amount' => $totalAmount,
                'total_paid' => $totalPaid,
                'total_refunded' => $totalRefunded,
                'remaining_amount' => $remainingAmount,
                'status' => $orderStatus,
                'created_at' => $order->created_at,
                'slots' => $order->slots->map(fn($slot) => [
                    'service_id' => $slot->service_id,
                    'slot_date' => $slot->date,
                    'start_time' => $slot->start_time,
                    'end_time' => $slot->end_time,
                    'address' => $slot->address,
                    'location' => $slot->location,      
                    'vendor_id' => $slot->vendor_id,
                    'vendor_name' => $slot->vendor ? trim($slot->vendor->first_name . ' ' . $slot->vendor->last_name) : null,
                    'vendor_uuid' => $slot->vendor ? $slot->vendor->uuid : null
                ]),
                'services' => $services,
                'invoices' => $payments->map(fn($p) => [
                    'order_service_uuid' => $p->order_service_uuid ?? null,
                    'invoice_url' => $p->stripe_receipt_url ?? null,
                    'payment_id' => $p->payment_id ?? null,
                    'amount' => (float) $p->amount,
                    'payment_method' => $p->payment_method ?? null,
                    'status' => $p->status,
                    'paid_at' => $p->paid_at,
                    'session_id' => $p->stripe_session_id ?? null,
                    'service_ids' => $p->order_service_id ?? null,
                    'created_at' => $p->session_created_at,
                ]),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $billingData,
        ]);
    }
}
