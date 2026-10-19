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
            'package:id,name,discount,discount_type',
        ]);

        if (!$isAdmin) {
            if ($isAgent) {
                $query->forAgent($user);
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

        $orderIds = $orders->pluck('id')->all();

        // Batch load all invoices with items to eliminate N+1 queries over remote RDS
        $allInvoices = \App\Models\Invoice::with('items')
            ->whereIn('order_id', $orderIds)
            ->get()
            ->groupBy('order_id');

        // Batch load all payments to eliminate N+1 queries over remote RDS
        $allPayments = AgentPayment::whereIn('order_id', $orderIds)
            ->orderBy('paid_at', 'desc')
            ->get()
            ->groupBy('order_id');

        $billingData = $orders->map(function ($order) use ($user, $isAgent, $allInvoices, $allPayments) {
            // Check if current user is a co-agent on this order
            $isCoAgentOnOrder = $isAgent && ($order->agent_id !== $user->id);
            $coAgentMultiplier = 1.0;

            if ($isCoAgentOnOrder && $order->split_invoice) {
                $coAgents = is_array($order->co_agents) ? $order->co_agents : json_decode($order->co_agents ?? '[]', true) ?? [];
                $myInfo = collect($coAgents)->first(function($ca) use ($user) {
                    return (isset($ca['agent_id']) && (string) $ca['agent_id'] === (string) $user->id)
                        || ($ca['agent_uuid'] ?? $ca['uuid'] ?? null) === $user->uuid
                        || strtolower($ca['email'] ?? '') === strtolower($user->email ?? '');
                });
                $percent = (float)($myInfo['percentage'] ?? $myInfo['split'] ?? 100);
                if ($percent > 0 && $percent < 100) {
                    $coAgentMultiplier = $percent / 100;
                }
            }

            // In-memory payment resolution
            $rawPayments = $allPayments->get($order->id, collect());
            $payments = $isCoAgentOnOrder
                ? $rawPayments->filter(fn($p) => $p->agent_id == $user->id)
                : $rawPayments;

            // In-memory invoice resolution
            $rawInvoices = $allInvoices->get($order->id, collect());
            $orderInvoices = $rawInvoices->filter(fn($inv) => $inv->status !== 'void');
            if ($isCoAgentOnOrder) {
                $coAgentInvoices = $orderInvoices->filter(fn($inv) => $inv->agent_id == $user->id);
                if ($coAgentInvoices->isNotEmpty()) {
                    $orderInvoices = $coAgentInvoices;
                }
            }
            if ($orderInvoices->isEmpty()) {
                $orderInvoices = $rawInvoices;
            }

            // Find primary / consolidated invoice
            $primaryInvoice = $orderInvoices->first(function ($inv) {
                return !empty($inv->notes) && (stripos($inv->notes, 'consolidated') !== false || stripos($inv->notes, 'add-on service invoice') !== false);
            }) ?? $orderInvoices->first(function ($inv) {
                return $inv->agent_type === 'primary' && empty($inv->split_details) && (empty($inv->notes) || stripos($inv->notes, 'service invoice') === false);
            }) ?? $orderInvoices->first();

            $taxRate = $primaryInvoice && (float)$primaryInvoice->tax_rate > 0
                ? (float)$primaryInvoice->tax_rate
                : ($orderInvoices->isNotEmpty() ? (float)$orderInvoices->first()->tax_rate : 13.0);

            if ($primaryInvoice && (float)$primaryInvoice->subtotal > 0 && (float)$primaryInvoice->total > 0) {
                $totalAmount = (float)$primaryInvoice->subtotal;
                $grandTotal = (float)$primaryInvoice->total;
                $taxAmount = (float)$primaryInvoice->tax_amount;
            } elseif ($orderInvoices->isNotEmpty()) {
                $serviceInvoices = $orderInvoices->filter(fn($inv) => !empty($inv->notes) && stripos($inv->notes, 'service invoice') !== false);
                if ($serviceInvoices->isNotEmpty()) {
                    $totalAmount = (float)$serviceInvoices->sum('subtotal');
                    $grandTotal = (float)$serviceInvoices->sum('total');
                    $taxAmount = (float)$serviceInvoices->sum('tax_amount');
                } else {
                    $orderSubtotal = (float)($order->amount ?? $order->services->sum('amount'));
                    $totalAmount = (float)($orderSubtotal * $coAgentMultiplier);
                    $taxAmount = round($totalAmount * ($taxRate / 100), 2);
                    $grandTotal = round($totalAmount + $taxAmount, 2);
                }
            } else {
                $orderSubtotal = (float)($order->amount ?? $order->services->sum('amount'));
                $totalAmount = (float)($orderSubtotal * $coAgentMultiplier);
                $taxAmount = round($totalAmount * ($taxRate / 100), 2);
                $grandTotal = round($totalAmount + $taxAmount, 2);
            }
            
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
            $services = $order->services->map(function ($service) use ($order, $payments, $orderStatus, $coAgentMultiplier, $orderInvoices) {
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
                        $isPaid = $relatedPayments->sum('amount') >= ($service->amount * $coAgentMultiplier);
                        $status = $isPaid ? 'paid' : 'unpaid';
                    }
                }

                // Check for service-specific invoice to obtain discounted base amount
                $serviceInvoice = $orderInvoices->first(function ($inv) use ($service) {
                    if (!empty($inv->notes) && stripos($inv->notes, 'consolidated') !== false) {
                        return false;
                    }
                    return $inv->items && $inv->items->contains(function ($item) use ($service) {
                        return (int)$item->order_service_id === (int)$service->id;
                    });
                });

                $serviceBaseAmount = (float)($service->amount * $coAgentMultiplier);
                if ($serviceInvoice && (float)$serviceInvoice->subtotal > 0) {
                    $serviceDiscountedAmount = (float)$serviceInvoice->subtotal;
                } else {
                    $appliedPkg = $order->package ? [
                        'discount' => $order->package->discount,
                        'discount_type' => $order->package->discount_type,
                    ] : ($order->meta['applied_package'] ?? null);

                    if ($appliedPkg) {
                        $disc = (float)($appliedPkg['discount'] ?? $appliedPkg['package_discount'] ?? 0);
                        $type = strtolower($appliedPkg['discount_type'] ?? $appliedPkg['package_discount_type'] ?? 'percentage');
                        if ($disc > 0 && $type === 'percentage') {
                            $serviceDiscountedAmount = round($serviceBaseAmount * (1 - ($disc / 100)), 2);
                        } else {
                            $serviceDiscountedAmount = $serviceBaseAmount;
                        }
                    } else {
                        $serviceDiscountedAmount = $serviceBaseAmount;
                    }
                }

                return [
                    'service_id' => $service->service_id,
                    'order_service_uuid' => $service->uuid ?? null,
                    'service_name' => $service->service->name ?? 'Unknown',
                    'original_amount' => $serviceBaseAmount,
                    'amount' => $serviceDiscountedAmount,
                    'discounted_amount' => $serviceDiscountedAmount,
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
                'grand_total' => $grandTotal,
                'tax_amount' => $taxAmount,
                'tax_rate' => $taxRate,
                'total_paid' => $totalPaid,
                'total_refunded' => $totalRefunded,
                'remaining_amount' => $remainingAmount,
                'package' => $order->package ? [
                    'id' => $order->package->id,
                    'name' => $order->package->name,
                    'discount' => $order->package->discount,
                    'discount_type' => $order->package->discount_type,
                ] : ($order->meta['applied_package'] ?? null),
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
