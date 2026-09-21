<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Vendor;
use App\Models\OrderService;
use App\Models\OrderSlot;
use App\Models\VendorInvoiceLine;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class VendorEarningsController extends Controller
{
    /**
     * Get earnings for the authenticated vendor.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        
        if (!($user instanceof Vendor)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        return $this->getEarnings($request, $user->uuid);
    }

    /**
     * Get earnings for a specific vendor (Admin).
     */
    public function show(Request $request, $uuid)
    {
        // Admin middleware should be applied in routes, but we can double check here if needed
        return $this->getEarnings($request, $uuid);
    }

    /**
     * Core logic to fetch and calculate earnings.
     */
    private function getEarnings(Request $request, $vendorUuid)
    {
        $vendor = Vendor::where('uuid', $vendorUuid)->firstOrFail();
        $vendorId = $vendor->id;

        $period = $request->query('period', 'all');
        $startDate = null;
        $endDate = null;

        switch ($period) {
            case 'this_month':
                $startDate = Carbon::now()->startOfMonth();
                $endDate = Carbon::now()->endOfMonth();
                break;
            case 'last_month':
                $startDate = Carbon::now()->subMonth()->startOfMonth();
                $endDate = Carbon::now()->subMonth()->endOfMonth();
                break;
            case 'this_year':
                $startDate = Carbon::now()->startOfYear();
                $endDate = Carbon::now()->endOfYear();
                break;
            case 'custom_range':
                $startDate = $request->query('start_date') ? Carbon::parse($request->query('start_date'))->startOfDay() : null;
                $endDate = $request->query('end_date') ? Carbon::parse($request->query('end_date'))->endOfDay() : null;
                break;
            case 'all':
            default:
                break;
        }

        // 1. Get all completed, invoiced, or paid order services for the vendor
        $query = OrderService::where(function($q) use ($vendorUuid, $vendorId) {
                $q->where('order_services.vendor_id', $vendorUuid)
                  ->orWhere('order_services.vendor_id', (string)$vendorId)
                  ->orWhere('order_services.vendor_id', (int)$vendorId);
            })
            ->leftJoin('orders', 'order_services.order_id', '=', 'orders.id')
            ->where(function($q) {
                $q->where('order_services.is_completed', true)
                  ->orWhere('orders.order_status', 'Completed')
                  ->orWhere('order_services.vendor_paid', true)
                  ->orWhereNotNull('order_services.vendor_invoice_id');
            })
            ->with(['service', 'option', 'order.property', 'vendorInvoice']);

        // We filter by OrderSlot date if possible, otherwise updated_at
        // Left joining with OrderSlot to avoid discarding services that are missing slot assignments
        $query->leftJoin('order_slots', function($join) use ($vendorId, $vendorUuid) {
            $join->on('order_services.order_id', '=', 'order_slots.order_id')
                 ->on('order_services.service_id', '=', 'order_slots.service_id');
            if ($vendorId) {
                $join->where(function($sq) use ($vendorId, $vendorUuid) {
                    $sq->where('order_slots.vendor_id', $vendorId)
                       ->orWhere('order_slots.vendor_id', $vendorUuid);
                });
            }
        })->select('order_services.*', 'order_slots.date as slot_date', 'order_slots.distance', 'order_slots.km_price');

        if ($startDate) {
            $query->where(function($q) use ($startDate) {
                $q->where('order_slots.date', '>=', $startDate->toDateString())
                  ->orWhere(function($sub) use ($startDate) {
                      $sub->whereNull('order_slots.date')
                          ->where('order_services.updated_at', '>=', $startDate);
                  });
            });
        }
        if ($endDate) {
            $query->where(function($q) use ($endDate) {
                $q->where('order_slots.date', '<=', $endDate->toDateString())
                  ->orWhere(function($sub) use ($endDate) {
                      $sub->whereNull('order_slots.date')
                          ->where('order_services.updated_at', '<=', $endDate);
                  });
            });
        }

        $orderServices = $query->get();

        // 2. Fetch all invoice lines for these services to get adjusted amounts
        $serviceIds = $orderServices->pluck('id')->toArray();
        $invoiceLines = VendorInvoiceLine::whereIn('order_service_id', $serviceIds)->get()->groupBy('order_service_id');

        $items = $orderServices->map(function($os) use ($invoiceLines, $vendorId) {
            $lines = $invoiceLines->get($os->id, collect());
            
            // Service Earning
            $serviceLine = $lines->where('type', 'service')->first();
            $calculatedPay = \App\Http\Controllers\Api\VendorBillingController::calculateVendorPayAmount($os, $vendorId);
            $serviceEarning = $serviceLine ? (float)$serviceLine->amount : ($calculatedPay > 0 ? $calculatedPay : (float)$os->amount);

            // Travel Earning
            $travelLine = $lines->where('type', 'travel')->first();
            $travelEarning = 0;
            if ($travelLine) {
                $travelEarning = (float)$travelLine->amount;
            } else if ($os->distance && $os->km_price) {
                $travelEarning = (float)$os->distance * (float)$os->km_price;
            }

            $status = 'pending';
            if ($os->vendor_paid) {
                $status = 'paid';
            } else if ($os->vendor_invoice_id) {
                $status = 'invoiced';
            }

            return [
                'uuid' => $os->uuid,
                'date' => $os->slot_date,
                'order_uuid' => $os->order->uuid ?? null,
                'property_address' => $os->order->property_address ?? ($os->order->property->address ?? 'N/A'),
                'service_name' => $os->service->name ?? 'Unknown Service',
                'service_amount' => $serviceEarning,
                'travel_amount' => $travelEarning,
                'total_amount' => $serviceEarning + $travelEarning,
                'status' => $status,
                'invoice_number' => $os->vendorInvoice->invoice_number ?? null,
                'paid_at' => $os->vendor_paid_at,
            ];
        });

        // 3. Aggregate totals
        $summary = [
            'total_earned' => $items->sum('total_amount'),
            'total_services' => $items->sum('service_amount'),
            'total_travel' => $items->sum('travel_amount'),
            'count' => $items->count(),
        ];

        // 4. Monthly breakdown (handling null slot dates safely)
        $breakdown = $items->groupBy(function($item) {
            return $item['date'] ? Carbon::parse($item['date'])->format('Y-m') : 'Unscheduled';
        })->map(function($monthItems, $month) {
            return [
                'month' => $month,
                'total' => $monthItems->sum('total_amount'),
                'services' => $monthItems->sum('service_amount'),
                'travel' => $monthItems->sum('travel_amount'),
                'count' => $monthItems->count(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => $summary,
                'breakdown' => $breakdown,
                'items' => $items,
                'filters' => [
                    'period' => $period,
                    'start_date' => $startDate ? $startDate->toDateString() : null,
                    'end_date' => $endDate ? $endDate->toDateString() : null,
                ]
            ]
        ]);
    }
}
