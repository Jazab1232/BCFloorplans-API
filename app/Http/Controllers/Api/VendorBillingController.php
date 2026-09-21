<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Vendor;
use App\Models\VendorInvoice;
use App\Models\VendorInvoiceLine;
use App\Models\OrderService;
use App\Models\OrderSlot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Stripe\Stripe;
use Stripe\Transfer;
use Exception;

class VendorBillingController extends Controller
{
    /**
     * Calculate vendor pay amount for an order service based on vendor pay model settings
     * with a 6-level cascading resolution:
     * 1. VendorServiceOption (Vendor's individual override for specific option)
     * 2. VendorService (Vendor's individual override for service)
     * 3. OrganizationService Option Override (Org Admin's override for option)
     * 4. OrganizationService Service Override (Org Admin's override for service)
     * 5. ProductOption Default (Master Service Option Payout)
     * 6. Service Default (Master Service "Vendor Pay Defaults")
     */
    public static function calculateVendorPayAmount($orderService, $vendorId = null): float
    {
        if (!$orderService) {
            return 0.00;
        }

        // Try resolving vendor ID if not directly passed
        if (!$vendorId) {
            $vendorId = $orderService->vendor_id;
            if (!$vendorId) {
                $slot = OrderSlot::where('order_id', $orderService->order_id)
                    ->where('service_id', $orderService->service_id)
                    ->first();
                $vendorId = $slot->vendor_id ?? null;
            }
        }

        $vendor = null;
        if ($vendorId) {
            $vendor = is_numeric($vendorId)
                ? \App\Models\Vendor::find($vendorId)
                : \App\Models\Vendor::where('uuid', $vendorId)->first();
        }

        $service = $orderService->service;
        if (!$service && $orderService->service_id) {
            $service = \App\Models\Service::find($orderService->service_id);
        }

        if (!$service) {
            return (float) ($orderService->amount ?? 0.00);
        }

        $serviceId = $service->id;
        $optionId = $orderService->option_id ?? null;
        $option = null;
        if ($optionId) {
            $option = is_numeric($optionId)
                ? ($orderService->relationLoaded('option') ? $orderService->option : \App\Models\ProductOption::find($optionId))
                : \App\Models\ProductOption::where('uuid', $optionId)->first();
        }

        // 1. Vendor specific models
        $vService = null;
        $vOption = null;
        if ($vendor) {
            $vService = \App\Models\VendorService::where('vendor_id', $vendor->id)
                ->where('service_id', $serviceId)
                ->first();
            if ($vService && $option) {
                $vOption = \App\Models\VendorServiceOption::where('vendor_service_id', $vService->id)
                    ->where('option_id', $option->id)
                    ->first();
            }
        }

        // 2. Org specific models
        $orgId = $orderService->organization_id
            ?? $vendor?->organization_id
            ?? $service->organization_id;
        $orgOverride = null;
        if ($orgId) {
            $orgOverride = \App\Models\OrganizationService::where('organization_id', $orgId)
                ->where('service_id', $serviceId)
                ->first();
        }

        $orgOptOverride = null;
        if ($orgOverride && !empty($orgOverride->options_override) && is_array($orgOverride->options_override) && $option) {
            foreach ($orgOverride->options_override as $optOvr) {
                if ((isset($optOvr['id']) && $optOvr['id'] == $option->id) || (isset($optOvr['uuid']) && $optOvr['uuid'] == $option->uuid)) {
                    $orgOptOverride = $optOvr;
                    break;
                }
            }
        }

        // Helper to pick first non-empty / non-null value across the cascade
        $resolveAttribute = function (...$candidates) {
            foreach ($candidates as $cand) {
                if ($cand !== null && $cand !== '' && $cand !== false) {
                    return $cand;
                }
            }
            return null;
        };

        // Cascading Pay Type
        $payType = $resolveAttribute(
            $vOption?->pay_type,
            $vService?->pay_type,
            $orgOptOverride['vendor_pay_type'] ?? null,
            $orgOverride?->vendor_pay_type ?? null,
            $option?->vendor_pay_type,
            $service->vendor_pay_type,
            'flat'
        );

        // Cascading Vendor Price / Base Payout
        $vendorPriceRaw = $resolveAttribute(
            $vOption?->vendor_price,
            $vService?->vendor_price,
            $orgOptOverride['vendor_price'] ?? null,
            $orgOverride?->vendor_price ?? null,
            $option?->vendor_price,
            $service->vendor_price
        );
        $vendorPrice = $vendorPriceRaw !== null ? (float) $vendorPriceRaw : 0.00;

        // Cascading Sq Ft Rate
        $sqFtRate = $resolveAttribute(
            $vOption?->sq_ft_rate,
            $vService?->sq_ft_rate,
            $orgOptOverride['vendor_sq_ft_rate'] ?? null,
            $orgOverride?->vendor_sq_ft_rate ?? null,
            $option?->vendor_sq_ft_rate,
            $service->vendor_sq_ft_rate
        );

        // Cascading Min Price Guarantee
        $minPrice = $resolveAttribute(
            $vOption?->min_price,
            $vService?->min_price,
            $orgOptOverride['vendor_min_price'] ?? null,
            $orgOverride?->vendor_min_price ?? null,
            $option?->vendor_min_price,
            $service->vendor_min_price
        );

        // Cascading Unit Rate
        $unitRate = $resolveAttribute(
            $vOption?->unit_rate,
            $vService?->unit_rate,
            $orgOptOverride['vendor_unit_rate'] ?? null,
            $orgOverride?->vendor_unit_rate ?? null,
            $option?->vendor_unit_rate,
            $service->vendor_unit_rate
        );

        // Cascading Hourly Rate
        $hourlyRate = $resolveAttribute(
            $vOption?->hourly_rate,
            $vService?->hourly_rate,
            $orgOptOverride['vendor_hourly_rate'] ?? null,
            $orgOverride?->vendor_hourly_rate ?? null,
            $option?->vendor_hourly_rate,
            $service->vendor_hourly_rate
        );

        switch ($payType) {
            case 'per_sq_ft':
                $sqFt = 0;
                if (isset($orderService->order->property->sq_ft) && (float)$orderService->order->property->sq_ft > 0) {
                    $sqFt = (float) $orderService->order->property->sq_ft;
                } elseif (isset($orderService->order->property->square_footage) && (float)$orderService->order->property->square_footage > 0) {
                    $sqFt = (float) $orderService->order->property->square_footage;
                } elseif ($orderService->order && $orderService->order->areas()->exists()) {
                    $sqFt = (float) $orderService->order->areas()->sum('sq_ft');
                }

                $rate = (float) ($sqFtRate ?? 0);
                $min = (float) ($minPrice ?? 0);
                $calculated = round($sqFt * $rate, 2);
                return max($min, $calculated);

            case 'per_unit':
            case 'quantity':
                $qty = (float) ($orderService->quantity ?? 1);
                $rate = (float) ($unitRate ?? $vendorPrice);
                return round($qty * $rate, 2);

            case 'hourly':
            case 'time':
                $durationHours = 1.0;
                if (isset($vOption->adjustment_time) && is_numeric($vOption->adjustment_time)) {
                    $durationHours = (float) $vOption->adjustment_time;
                } elseif (isset($option->service_duration) && (float)$option->service_duration > 0) {
                    $durationHours = round((float)$option->service_duration / 60, 2);
                }
                $rate = (float) ($hourlyRate ?? $vendorPrice);
                return round($durationHours * $rate, 2);

            case 'flat':
            default:
                return $vendorPrice > 0 ? $vendorPrice : (float) $orderService->amount;
        }
    }

    public function indexUninvoiced(Request $request)
    {
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $organizationId = $request->query('organization_id');

        $now = Carbon::now();
        $currentDate = $now->format('Y-m-d');
        $currentTime = $now->format('H:i');

        $serviceQueryModifier = function($query) use ($currentDate, $currentTime, $startDate, $endDate) {
            $query->join('order_slots', function($join) {
                $join->on('order_slots.order_id', '=', 'order_services.order_id')
                     ->on('order_slots.service_id', '=', 'order_services.service_id');
            })
            ->whereNull('order_services.vendor_invoice_id')
            ->where(function($q) use ($currentDate, $currentTime) {
                $q->where('order_slots.date', '<', $currentDate)
                  ->orWhere(function($sub) use ($currentDate, $currentTime) {
                      $sub->where('order_slots.date', '=', $currentDate)
                          ->where('order_slots.start_time', '<=', $currentTime);
                  });
            });

            if ($startDate) {
                $formattedStartDate = Carbon::parse($startDate)->format('Y-m-d');
                $query->where('order_slots.date', '>=', $formattedStartDate);
            }

            if ($endDate) {
                $formattedEndDate = Carbon::parse($endDate)->format('Y-m-d');
                $query->where('order_slots.date', '<=', $formattedEndDate);
            }
        };

        $vendorsQuery = Vendor::with('organization')
            ->whereHas('orderServices', $serviceQueryModifier)
            ->withCount(['orderServices as uninvoiced_services_count' => $serviceQueryModifier]);

        if ($organizationId && $organizationId !== 'all') {
            $vendorsQuery->where('organization_id', $organizationId);
        }

        $vendors = $vendorsQuery->get();

        return response()->json([
            'success' => true,
            'data' => $vendors
        ]);
    }

    /**
     * List all vendor invoices (Admin).
     */
    public function index(Request $request)
    {
        $query = VendorInvoice::with(['vendor.organization', 'organization', 'lines.orderService.service', 'lines.orderService.order.property'])->orderBy('created_at', 'desc');

        if ($request->has('organization_id') && $request->organization_id && $request->organization_id !== 'all') {
            $query->where('organization_id', $request->organization_id);
        }

        if ($request->has('status')) {
            $status = $request->status;
            if ($status === 'approved') {
                $query->where('status', 'pending_payment');
            } else {
                $query->where('status', $status);
            }
        }

        if ($request->has('vendor_uuid')) {
            $query->where('vendor_id', $request->vendor_uuid);
        }

        if ($request->has('start_date') && $request->start_date) {
            $startDate = Carbon::parse($request->start_date)->startOfDay();
            $query->where(function($q) use ($startDate) {
                $q->where('created_at', '>=', $startDate)
                  ->orWhere('paid_at', '>=', $startDate)
                  ->orWhere('cycle_start', '>=', $startDate->toDateString());
            });
        }

        if ($request->has('end_date') && $request->end_date) {
            $endDate = Carbon::parse($request->end_date)->endOfDay();
            $query->where(function($q) use ($endDate) {
                $q->where('created_at', '<=', $endDate)
                  ->orWhere('paid_at', '<=', $endDate)
                  ->orWhere('cycle_end', '<=', $endDate->toDateString());
            });
        }

        return response()->json([
            'success' => true,
            'data' => $query->paginate($request->get('limit', 15))
        ]);
    }

    /**
     * Get pending items for a vendor within a date range with accurate vendor pay rate calculation.
     */
    public function getPendingItems(Request $request, $vendorUuid)
    {
        $vendor = Vendor::where('uuid', $vendorUuid)->firstOrFail();
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        $now = Carbon::now();
        $currentDate = $now->format('Y-m-d');
        $currentTime = $now->format('H:i');

        $query = OrderService::select('order_services.*')
            ->join('order_slots', function($join) {
                $join->on('order_slots.order_id', '=', 'order_services.order_id')
                     ->on('order_slots.service_id', '=', 'order_services.service_id');
            })
            ->where('order_services.vendor_id', $vendor->uuid)
            ->whereNull('order_services.vendor_invoice_id')
            ->with(['service', 'option', 'order.property']);

        // Visited filter: scheduled slot date/time must be in the past
        $query->where(function($q) use ($currentDate, $currentTime) {
            $q->where('order_slots.date', '<', $currentDate)
              ->orWhere(function($sub) use ($currentDate, $currentTime) {
                  $sub->where('order_slots.date', '=', $currentDate)
                      ->where('order_slots.start_time', '<=', $currentTime);
              });
        });

        // Pay Period filters
        if ($startDate) {
            $formattedStartDate = Carbon::parse($startDate)->format('Y-m-d');
            $query->where('order_slots.date', '>=', $formattedStartDate);
        }

        if ($endDate) {
            $formattedEndDate = Carbon::parse($endDate)->format('Y-m-d');
            $query->where('order_slots.date', '<=', $formattedEndDate);
        }

        $services = $query->get();

        // Calculate travel and accurate vendor pay for these services
        $items = $services->map(function($service) use ($vendor) {
            // Find the slot for this service in the order
            $slot = OrderSlot::where('order_id', $service->order_id)
                ->where('service_id', $service->service_id)
                ->first();

            $travelCost = 0;
            if ($slot && $slot->distance && $slot->km_price) {
                $travelCost = round((float)$slot->distance * (float)$slot->km_price, 2);
            }

            $vendorPayAmount = static::calculateVendorPayAmount($service, $vendor->id);

            // Resolve rate metadata for UI display using cascading hierarchy
            $option = $service->option;
            $vService = \App\Models\VendorService::where('vendor_id', $vendor->id)
                ->where('service_id', $service->service_id)
                ->first();
            $vOption = ($vService && $option)
                ? \App\Models\VendorServiceOption::where('vendor_service_id', $vService->id)->where('option_id', $option->id)->first()
                : null;

            $orgId = $service->organization_id ?? $vendor->organization_id ?? $service->service?->organization_id;
            $orgOverride = $orgId ? \App\Models\OrganizationService::where('organization_id', $orgId)->where('service_id', $service->service_id)->first() : null;
            $orgOptOverride = null;
            if ($orgOverride && !empty($orgOverride->options_override) && is_array($orgOverride->options_override) && $option) {
                foreach ($orgOverride->options_override as $optOvr) {
                    if ((isset($optOvr['id']) && $optOvr['id'] == $option->id) || (isset($optOvr['uuid']) && $optOvr['uuid'] == $option->uuid)) {
                        $orgOptOverride = $optOvr;
                        break;
                    }
                }
            }

            $resolveAttr = function (...$candidates) {
                foreach ($candidates as $cand) {
                    if ($cand !== null && $cand !== '' && $cand !== false) {
                        return $cand;
                    }
                }
                return null;
            };

            $payType = $resolveAttr($vOption?->pay_type, $vService?->pay_type, $orgOptOverride['vendor_pay_type'] ?? null, $orgOverride?->vendor_pay_type ?? null, $option?->vendor_pay_type, $service->service->vendor_pay_type ?? null, 'flat');
            $sqFtRate = $resolveAttr($vOption?->sq_ft_rate, $vService?->sq_ft_rate, $orgOptOverride['vendor_sq_ft_rate'] ?? null, $orgOverride?->vendor_sq_ft_rate ?? null, $option?->vendor_sq_ft_rate, $service->service->vendor_sq_ft_rate ?? null);
            $minPrice = $resolveAttr($vOption?->min_price, $vService?->min_price, $orgOptOverride['vendor_min_price'] ?? null, $orgOverride?->vendor_min_price ?? null, $option?->vendor_min_price, $service->service->vendor_min_price ?? null);

            $propertySqFt = 0;
            if (isset($service->order->property->sq_ft) && (float)$service->order->property->sq_ft > 0) {
                $propertySqFt = (float)$service->order->property->sq_ft;
            } elseif (isset($service->order->property->square_footage) && (float)$service->order->property->square_footage > 0) {
                $propertySqFt = (float)$service->order->property->square_footage;
            }

            return [
                'service' => $service,
                'vendor_pay_amount' => $vendorPayAmount,
                'pay_type' => $payType,
                'sq_ft_rate' => $sqFtRate,
                'min_price' => $minPrice,
                'property_sq_ft' => $propertySqFt,
                'travel_cost' => $travelCost,
                'slot' => $slot
            ];
        });

        $totalServices = $items->sum('vendor_pay_amount');

        return response()->json([
            'success' => true,
            'data' => [
                'vendor' => $vendor,
                'items' => $items,
                'total_services' => round($totalServices, 2),
                'total_travel' => round($items->sum('travel_cost'), 2),
            ]
        ]);
    }

    /**
     * Generate a draft invoice for a vendor.
     */
    public function generateInvoice(Request $request)
    {
        $request->validate([
            'vendor_uuid' => 'required|exists:vendors,uuid',
            'order_service_uuids' => 'nullable|array',
            'order_service_uuids.*' => 'exists:order_services,uuid',
            'cycle_start' => 'nullable|date',
            'cycle_end' => 'nullable|date',
            'notes' => 'nullable|string',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'tax_type' => 'nullable|string|max:50',
            'tax_number' => 'nullable|string|max:255',
            
            // Custom lines/items validation
            'lines' => 'nullable|array',
            'lines.*.description' => 'required_with:lines|string',
            'lines.*.quantity' => 'nullable|numeric|min:0',
            'lines.*.unit_price' => 'required_with:lines|numeric|min:0',
            'lines.*.amount' => 'nullable|numeric',
            'lines.*.type' => 'nullable|string|in:service,travel,adjustment',
            'lines.*.order_service_id' => 'nullable|string|exists:order_services,uuid',
            
            'items' => 'nullable|array',
            'items.*.description' => 'required_with:items|string',
            'items.*.quantity' => 'nullable|numeric|min:0',
            'items.*.unit_price' => 'required_with:items|numeric|min:0',
            'items.*.amount' => 'nullable|numeric',
            'items.*.type' => 'nullable|string|in:service,travel,adjustment',
            'items.*.order_service_uuid' => 'nullable|string|exists:order_services,uuid',
        ]);

        try {
            DB::beginTransaction();

            $vendor = Vendor::where('uuid', $request->vendor_uuid)->firstOrFail();
            
            // Auto-calculate tax settings based on vendor settings
            $vSettings = $vendor->settings;
            $taxRate = 0.00;
            $taxType = null;
            $taxNumber = null;

            if ($vSettings && $vSettings->tax_enabled && !$vSettings->tax_exempt) {
                $taxRate = (float)$vSettings->tax_rate;
                $taxType = $vSettings->tax_type;
                $taxCountry = $vSettings->tax_country;

                if (strtoupper($taxCountry) === 'CA') {
                    $billingAddress = $vendor->addresses()->where('type', 'billing')->first() 
                        ?? $vendor->addresses()->where('type', 'company')->first()
                        ?? $vendor->addresses()->first();

                    $province = $billingAddress ? strtoupper(trim($billingAddress->province)) : '';
                    
                    switch ($province) {
                        case 'AB': case 'ALBERTA':
                        case 'BC': case 'BRITISH COLUMBIA':
                        case 'MB': case 'MANITOBA':
                        case 'SK': case 'SASKATCHEWAN':
                        case 'YT': case 'YUKON':
                        case 'NT': case 'NORTHWEST TERRITORIES':
                        case 'NU': case 'NUNAVUT':
                            $taxRate = 5.00;
                            $taxType = 'GST';
                            break;
                        case 'ON': case 'ONTARIO':
                            $taxRate = 13.00;
                            $taxType = 'HST';
                            break;
                        case 'NB': case 'NEW BRUNSWICK':
                        case 'NL': case 'NEWFOUNDLAND':
                        case 'NS': case 'NOVA SCOTIA':
                        case 'PE': case 'PRINCE EDWARD ISLAND':
                            $taxRate = 15.00;
                            $taxType = 'HST';
                            break;
                        case 'QC': case 'QUEBEC':
                            $taxRate = 14.975;
                            $taxType = 'GST + QST';
                            break;
                        default:
                            $taxRate = 5.00;
                            $taxType = 'GST';
                    }

                    $taxNums = [];
                    if (!empty($vSettings->tax_number_gst_hst)) {
                        $taxNums[] = 'GST/HST: ' . $vSettings->tax_number_gst_hst;
                    }
                    if (!empty($vSettings->tax_number_pst) && in_array($province, ['BC', 'SK', 'MB', 'BRITISH COLUMBIA', 'SASKATCHEWAN', 'MANITOBA'])) {
                        $taxNums[] = 'PST: ' . $vSettings->tax_number_pst;
                    }
                    if (!empty($vSettings->tax_number_qst) && in_array($province, ['QC', 'QUEBEC'])) {
                        $taxNums[] = 'QST: ' . $vSettings->tax_number_qst;
                    }
                    $taxNumber = !empty($taxNums) ? implode(', ', $taxNums) : $vSettings->tax_number;

                } else if (strtoupper($taxCountry) === 'US') {
                    // US state-based default or settings rate
                    // TODO: In future phases, integrate external US Tax API (like TaxJar or Avalara) for dynamic Zip-code level local taxes.
                    $taxRate = (float)$vSettings->tax_rate;
                    $taxType = 'Sales Tax';
                    
                    if (!empty($vSettings->tax_number_us)) {
                        $taxNumber = 'US Tax ID: ' . $vSettings->tax_number_us;
                    } else {
                        $taxNumber = $vSettings->tax_number;
                    }
                }
            }

            // Apply overrides if provided in request
            if ($request->has('tax_rate')) {
                $taxRate = (float)$request->input('tax_rate');
            }
            if ($request->has('tax_type')) {
                $taxType = $request->input('tax_type');
            }
            if ($request->has('tax_number')) {
                $taxNumber = $request->input('tax_number');
            }

            // Snapshot vendor and organization details
            $billingAddress = $vendor->addresses()->where('type', 'billing')->first() 
                ?? $vendor->addresses()->where('type', 'start_location')->first()
                ?? $vendor->addresses()->first();
            $vendorAddressStr = $billingAddress ? trim("{$billingAddress->address_line_1} {$billingAddress->address_line_2}, {$billingAddress->city}, {$billingAddress->province} {$billingAddress->postal_code}") : '';

            $vendorDetailsSnapshot = array_merge([
                'first_name' => $vendor->first_name,
                'last_name' => $vendor->last_name,
                'name' => trim("{$vendor->first_name} {$vendor->last_name}"),
                'company_name' => $vendor->company?->name ?? $vendor->company_name ?? '',
                'email' => $vendor->email,
                'phone' => $vendor->primary_phone ?? $vendor->secondary_phone ?? '',
                'address' => $vendorAddressStr,
                'tax_number' => $taxNumber,
                'tax_type' => $taxType,
                'tax_rate' => $taxRate,
            ], (array) ($request->input('vendor_details') ?? []));

            $org = $vendor->organization ?? \App\Models\Organization::find($vendor->organization_id);
            $orgDetailsSnapshot = array_merge([
                'id' => $org?->id,
                'name' => $org?->name ?? 'BC Floor plans',
                'email' => $org?->contact_email ?? $org?->from_email ?? 'info@bcfloorplans.com',
                'phone' => $org?->phone ?? '',
                'address' => $org?->address ?? '',
            ], (array) ($request->input('org_details') ?? []));

            $invoice = VendorInvoice::create([
                'organization_id' => $vendor->organization_id, // Ensure organization_id matches vendor organization
                'vendor_id' => $vendor->uuid,
                'status' => 'draft',
                'currency' => 'usd', // Defaulting to USD as per Stripe logic seen previously
                'cycle_start' => $request->cycle_start,
                'cycle_end' => $request->cycle_end,
                'notes' => $request->notes,
                'tax_rate' => $taxRate,
                'tax_type' => $taxType,
                'tax_number' => $taxNumber,
                'vendor_details' => $vendorDetailsSnapshot,
                'org_details' => $orgDetailsSnapshot,
                'tax_details' => $request->input('tax_details'),
            ]);

            $subtotal = 0;
            $travelTotal = 0;
            $taxAmount = 0;

            // Determine if custom lines or items are provided
            $inputLines = $request->input('lines') ?? $request->input('items') ?? null;

            if ($inputLines && count($inputLines) > 0) {
                foreach ($inputLines as $lineData) {
                    $qty = isset($lineData['quantity']) ? (float)$lineData['quantity'] : 1;
                    $unitPrice = isset($lineData['unit_price']) ? (float)$lineData['unit_price'] : 0;
                    $amount = isset($lineData['amount']) ? (float)$lineData['amount'] : ($qty * $unitPrice);
                    $type = $lineData['type'] ?? 'service';
                    $isTaxable = isset($lineData['is_taxable']) ? (bool)$lineData['is_taxable'] : true;
                    
                    // Resolve order service ID from UUID if provided
                    $orderServiceUuid = $lineData['order_service_id'] ?? $lineData['order_service_uuid'] ?? null;
                    $orderServiceId = null;
                    if ($orderServiceUuid) {
                        $serviceModel = is_numeric($orderServiceUuid)
                            ? OrderService::find((int) $orderServiceUuid)
                            : (\Illuminate\Support\Str::isUuid($orderServiceUuid) ? OrderService::where('uuid', $orderServiceUuid)->first() : null);
                        if ($serviceModel) {
                            $orderServiceId = $serviceModel->id;
                            // Link service to invoice
                            $serviceModel->update(['vendor_invoice_id' => $invoice->id]);
                        }
                    }

                    // Create line item
                    VendorInvoiceLine::create([
                        'vendor_invoice_id' => $invoice->id,
                        'order_service_id' => $orderServiceId,
                        'description' => $lineData['description'],
                        'quantity' => $qty,
                        'unit_price' => $unitPrice,
                        'amount' => $amount,
                        'type' => $type,
                        'is_taxable' => $isTaxable,
                    ]);

                    if ($type === 'service') {
                        $subtotal += $amount;
                    } elseif ($type === 'travel') {
                        $travelTotal += $amount;
                    }
                }

                $taxAmount = ($subtotal + $travelTotal) * ($taxRate / 100);
            } else {
                // FALLBACK: original automatic calculation logic
                $services = OrderService::whereIn('uuid', $request->order_service_uuids ?? [])->get();

                foreach ($services as $service) {
                    // Link service to invoice
                    $service->update(['vendor_invoice_id' => $invoice->id]);

                    // Calculate vendor pay amount based on pay model (flat, per_sq_ft, per_unit, hourly)
                    $vendorPayAmount = static::calculateVendorPayAmount($service, $vendor->id);

                    // Create service line item
                    $serviceName = $service->service->name ?? 'Service';
                    $order = $service->order;
                    $address = $order?->property?->property_address ?? $order?->property_address ?? '';
                    $orderNum = $order?->id ?? '';
                    $desc = $serviceName;
                    if (!empty($address) && !empty($orderNum)) {
                        $desc .= "\n{$address} (Order #{$orderNum})";
                    } elseif (!empty($address)) {
                        $desc .= "\n{$address}";
                    } elseif (!empty($orderNum)) {
                        $desc .= "\nOrder #{$orderNum}";
                    }

                    VendorInvoiceLine::create([
                        'vendor_invoice_id' => $invoice->id,
                        'order_service_id' => $service->id,
                        'description' => $desc,
                        'quantity' => 1,
                        'unit_price' => $vendorPayAmount,
                        'amount' => $vendorPayAmount,
                        'type' => 'service',
                        'is_taxable' => true,
                    ]);
                    $subtotal += $vendorPayAmount;

                    // Create travel line item if applicable
                    $slot = OrderSlot::where('order_id', $service->order_id)
                        ->where('service_id', $service->service_id)
                        ->first();

                    if ($slot && $slot->distance && $slot->km_price) {
                        $travelCost = (float)$slot->distance * (float)$slot->km_price;
                        if ($travelCost > 0) {
                            VendorInvoiceLine::create([
                                'vendor_invoice_id' => $invoice->id,
                                'order_service_id' => $service->id,
                                'description' => "Travel Cost for " . ($service->service->name ?? 'Service'),
                                'quantity' => 1,
                                'unit_price' => $travelCost,
                                'amount' => $travelCost,
                                'type' => 'travel',
                                'is_taxable' => true,
                            ]);
                            $travelTotal += $travelCost;
                        }
                    }
                }
                
                $taxAmount = ($subtotal + $travelTotal) * ($taxRate / 100);
            }

            // Sum all lines to calculate total_amount correctly (including adjustments if present)
            $totalLinesAmount = VendorInvoiceLine::where('vendor_invoice_id', $invoice->id)->sum('amount');

            $invoice->update([
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'tax_rate' => $taxRate,
                'tax_type' => $taxType,
                'tax_number' => $taxNumber,
                'travel_amount' => $travelTotal,
                'total_amount' => $totalLinesAmount + $taxAmount,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $invoice->load(['vendor.organization', 'organization', 'lines'])
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Failed to generate vendor invoice: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Pay the invoice via Stripe Connect.
     */
    public function payInvoice(Request $request, $uuid)
    {
        $invoice = VendorInvoice::where('uuid', $uuid)->firstOrFail();

        if ($invoice->status === 'paid') {
            return response()->json(['success' => false, 'message' => 'Invoice already paid.'], 422);
        }

        $vendor = $invoice->vendor;
        if (!$vendor || !$vendor->stripe_account_id) {
            return response()->json(['success' => false, 'message' => 'Vendor has no connected Stripe account.'], 400);
        }

        Stripe::setApiKey(config('services.stripe.secret'));

        try {
            DB::beginTransaction();

            $transfer = Transfer::create([
                'amount' => intval($invoice->total_amount * 100),
                'currency' => $invoice->currency,
                'destination' => $vendor->stripe_account_id,
                'metadata' => [
                    'vendor_invoice_uuid' => $invoice->uuid,
                    'invoice_number' => $invoice->invoice_number,
                    'type' => 'vendor_payout',
                ],
            ]);

            $invoice->update([
                'status' => 'paid',
                'paid_at' => now(),
                'stripe_transfer_id' => $transfer->id,
            ]);

            // Dispatch QuickBooks Payout Sync
            \App\Jobs\SyncVendorPayoutToQuickBooks::dispatch($invoice->id);

            // Update all related order services
            OrderService::where('vendor_invoice_id', $invoice->id)
                ->update([
                    'vendor_paid' => true,
                    'vendor_paid_at' => now()
                ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $invoice,
                'message' => 'Payment successful.'
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Stripe Transfer failed for invoice {$invoice->uuid}: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Payment failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Pay the invoice manually (Admin).
     */
    public function payInvoiceManual(Request $request, $uuid)
    {
        $invoice = VendorInvoice::where('uuid', $uuid)->firstOrFail();

        if ($invoice->status === 'paid') {
            return response()->json(['success' => false, 'message' => 'Invoice already paid.'], 422);
        }

        $request->validate([
            'paid_at' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            $paidAt = $request->input('paid_at') ? Carbon::parse($request->input('paid_at')) : now();

            // Format notes to record that it was paid outside Stripe
            $newNotes = $invoice->notes;
            $manualNotes = $request->input('notes');
            if ($manualNotes) {
                $newNotes = trim(($newNotes ? $newNotes . "\n" : "") . "[Manual Payment - " . now()->toDateTimeString() . "]: " . $manualNotes);
            } else {
                $newNotes = trim(($newNotes ? $newNotes . "\n" : "") . "[Manual Payment - " . now()->toDateTimeString() . "]");
            }

            $invoice->update([
                'status' => 'paid',
                'paid_at' => $paidAt,
                'stripe_transfer_id' => 'manual',
                'notes' => $newNotes,
            ]);

            // Dispatch QuickBooks Payout Sync
            \App\Jobs\SyncVendorPayoutToQuickBooks::dispatch($invoice->id);

            // Update all related order services
            OrderService::where('vendor_invoice_id', $invoice->id)
                ->update([
                    'vendor_paid' => true,
                    'vendor_paid_at' => $paidAt
                ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $invoice,
                'message' => 'Invoice marked as paid manually.'
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Manual payment failed for invoice {$invoice->uuid}: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Payment failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Show detailed invoice (Admin).
     */
    public function show($uuid)
    {
        $invoice = VendorInvoice::with(['vendor.organization', 'organization', 'lines.orderService.service', 'lines.orderService.order.property'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $invoice
        ]);
    }

    /**
     * Update an existing vendor invoice (Admin, Super Admin, Org Admin).
     */
    public function update(Request $request, $uuid)
    {
        $invoice = VendorInvoice::where('uuid', $uuid)->firstOrFail();

        if ($invoice->status === 'paid') {
            return response()->json(['success' => false, 'message' => 'Paid invoices cannot be updated.'], 422);
        }

        $request->validate([
            'notes' => 'nullable|string',
            'tax_rate' => 'nullable|numeric',
            'tax_type' => 'nullable|string|max:50',
            'tax_number' => 'nullable|string|max:255',
            'vendor_details' => 'nullable|array',
            'org_details' => 'nullable|array',
            'tax_details' => 'nullable|array',
            'lines' => 'nullable|array',
            'lines.*.description' => 'required_with:lines|string',
            'lines.*.amount' => 'required_with:lines|numeric',
            'lines.*.type' => 'nullable|string|in:service,travel,adjustment',
        ]);

        try {
            DB::beginTransaction();

            $updateData = [];
            if ($request->has('notes')) $updateData['notes'] = $request->notes;
            if ($request->has('tax_type')) $updateData['tax_type'] = $request->tax_type;
            if ($request->has('tax_number')) $updateData['tax_number'] = $request->tax_number;
            if ($request->has('tax_rate')) $updateData['tax_rate'] = (float)$request->tax_rate;
            
            if ($request->has('vendor_details')) {
                $currentVendorDetails = is_array($invoice->vendor_details) ? $invoice->vendor_details : [];
                $updateData['vendor_details'] = array_merge($currentVendorDetails, $request->vendor_details);
            }
            if ($request->has('org_details')) {
                $currentOrgDetails = is_array($invoice->org_details) ? $invoice->org_details : [];
                $updateData['org_details'] = array_merge($currentOrgDetails, $request->org_details);
            }
            if ($request->has('tax_details')) {
                $updateData['tax_details'] = $request->tax_details;
            }

            if (!empty($updateData)) {
                $invoice->update($updateData);
            }

            // Handle line items if provided
            if ($request->has('lines')) {
                $existingLineIds = [];
                foreach ($request->get('lines') as $lineData) {
                    $qty = isset($lineData['quantity']) && (float)$lineData['quantity'] > 0 ? (float)$lineData['quantity'] : 1;
                    $rawAmount = isset($lineData['amount']) && is_numeric($lineData['amount']) ? (float)$lineData['amount'] : null;
                    $rawUnitPrice = isset($lineData['unit_price']) && is_numeric($lineData['unit_price']) ? (float)$lineData['unit_price'] : null;
                    
                    if ($rawAmount !== null && $rawAmount > 0) {
                        $amount = $rawAmount;
                        $unitPrice = ($rawUnitPrice !== null && $rawUnitPrice > 0) ? $rawUnitPrice : ($qty > 0 ? round($amount / $qty, 2) : $amount);
                    } elseif ($rawUnitPrice !== null && $rawUnitPrice > 0) {
                        $unitPrice = $rawUnitPrice;
                        $amount = round($qty * $unitPrice, 2);
                    } else {
                        $unitPrice = $rawUnitPrice ?? 0.00;
                        $amount = $rawAmount ?? 0.00;
                    }

                    $type = $lineData['type'] ?? 'service';
                    $desc = $lineData['description'] ?? 'Item';
                    $isTaxable = isset($lineData['is_taxable']) ? (bool)$lineData['is_taxable'] : true;
                    
                    $line = null;
                    if (!empty($lineData['uuid'])) {
                        $line = VendorInvoiceLine::where('vendor_invoice_id', $invoice->id)->where('uuid', $lineData['uuid'])->first();
                    } elseif (!empty($lineData['id'])) {
                        $line = VendorInvoiceLine::where('vendor_invoice_id', $invoice->id)->where('id', $lineData['id'])->first();
                    } elseif (!empty($lineData['order_service_id'])) {
                        $line = VendorInvoiceLine::where('vendor_invoice_id', $invoice->id)->where('order_service_id', $lineData['order_service_id'])->where('type', $type)->first();
                    }

                    if ($line) {
                        $line->update([
                            'description' => $desc,
                            'quantity' => $qty,
                            'unit_price' => $unitPrice,
                            'amount' => $amount,
                            'type' => $type,
                            'is_taxable' => $isTaxable,
                        ]);
                        $existingLineIds[] = $line->id;
                    } else {
                        $newLine = VendorInvoiceLine::create([
                            'vendor_invoice_id' => $invoice->id,
                            'order_service_id' => $lineData['order_service_id'] ?? null,
                            'description' => $desc,
                            'quantity' => $qty,
                            'unit_price' => $unitPrice,
                            'amount' => $amount,
                            'type' => $type,
                            'is_taxable' => $isTaxable,
                        ]);
                        $existingLineIds[] = $newLine->id;
                    }
                }

                // Delete lines that were removed during edit (only if lines were provided)
                if (!empty($existingLineIds)) {
                    VendorInvoiceLine::where('vendor_invoice_id', $invoice->id)
                        ->whereNotIn('id', $existingLineIds)
                        ->delete();
                }
            }

            // Recalculate all totals
            $invoice->recalculateTotals();

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $invoice->fresh(['vendor.organization', 'organization', 'lines.orderService.service', 'lines.orderService.order.property']),
                'message' => 'Invoice updated successfully.'
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Failed to update vendor invoice: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete an unpaid vendor invoice (Admin, Super Admin, Org Admin)
     * and release all associated order services back to uninvoiced.
     */
    public function destroy(Request $request, $uuid)
    {
        $invoice = VendorInvoice::where('uuid', $uuid)->firstOrFail();

        if ($invoice->status === 'paid') {
            return response()->json(['success' => false, 'message' => 'Paid invoices cannot be deleted.'], 422);
        }

        try {
            DB::beginTransaction();

            // 1. Release all linked order services so they can be billed again
            OrderService::where('vendor_invoice_id', $invoice->id)->update([
                'vendor_invoice_id' => null,
                'vendor_paid' => false,
                'vendor_paid_at' => null,
            ]);

            // 2. Delete all invoice line items
            VendorInvoiceLine::where('vendor_invoice_id', $invoice->id)->delete();

            // 3. Delete the invoice
            $invoice->forceDelete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Invoice deleted successfully. All associated services have been released back to uninvoiced.',
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Failed to delete vendor invoice {$uuid}: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to delete invoice: ' . $e->getMessage()], 500);
        }
    }

    public function exportCsv(Request $request)
    {
        try {
            $user = Auth::user();
            $query = VendorInvoice::with('vendor')->orderBy('created_at', 'desc');

            if ($user instanceof User) {
                // Admin or Org Admin
                if ($user->organization_id) {
                    $query->whereHas('vendor', function($q) use ($user) {
                        $q->where('organization_id', $user->organization_id);
                    });
                }
            } else {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('vendor_uuid')) {
                $query->where('vendor_id', $request->vendor_uuid);
            }

            $invoices = $query->get();

            $filename = "vendor_invoices_" . now()->format('Y-m-d_His') . ".csv";
            $headers = [
                "Content-type"        => "text/csv",
                "Content-Disposition" => "attachment; filename=$filename",
                "Pragma"              => "no-cache",
                "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
                "Expires"             => "0"
            ];

            $columns = ['Invoice #', 'Status', 'Vendor', 'Subtotal', 'Tax', 'Travel', 'Total', 'Cycle Start', 'Cycle End', 'Paid At', 'Stripe Transfer ID'];

            $callback = function() use($invoices, $columns) {
                $file = fopen('php://output', 'w');
                fputcsv($file, $columns);

                foreach ($invoices as $invoice) {
                    fputcsv($file, [
                        $invoice->invoice_number,
                        $invoice->status,
                        $invoice->vendor->business_name ?: ($invoice->vendor->first_name . ' ' . $invoice->vendor->last_name),
                        $invoice->subtotal,
                        $invoice->tax_amount,
                        $invoice->travel_amount,
                        $invoice->total_amount,
                        $invoice->cycle_start?->format('Y-m-d'),
                        $invoice->cycle_end?->format('Y-m-d'),
                        $invoice->paid_at?->format('Y-m-d H:i:s'),
                        $invoice->stripe_transfer_id ?? 'N/A',
                    ]);
                }
                fclose($file);
            };

            return response()->stream($callback, 200, $headers);

        } catch (\Exception $e) {
            Log::error('Vendor Invoice CSV Export failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Export failed'], 500);
        }
    }

    /**
     * Get summary metrics for Vendor Billing Dashboard.
     */
    public function getSummaryMetrics(Request $request)
    {
        $now = Carbon::now();
        $startOfMonth = $now->copy()->startOfMonth();
        $endOfMonth = $now->copy()->endOfMonth();

        $currentDate = $now->format('Y-m-d');
        $currentTime = $now->format('H:i');
        $organizationId = $request->query('organization_id');

        $unbilledQuery = OrderService::join('order_slots', function($join) {
                $join->on('order_slots.order_id', '=', 'order_services.order_id')
                     ->on('order_slots.service_id', '=', 'order_services.service_id');
            })
            ->whereNull('order_services.vendor_invoice_id')
            ->whereNotNull('order_services.vendor_id')
            ->where(function($q) use ($currentDate, $currentTime) {
                $q->where('order_slots.date', '<', $currentDate)
                  ->orWhere(function($sub) use ($currentDate, $currentTime) {
                      $sub->where('order_slots.date', '=', $currentDate)
                          ->where('order_slots.start_time', '<=', $currentTime);
                  });
            });

        if ($organizationId && $organizationId !== 'all') {
            $unbilledQuery->where('order_services.organization_id', $organizationId);
        }

        $unbilledServices = $unbilledQuery->with(['service', 'order.property'])->get();

        $unbilledTotal = 0;
        foreach ($unbilledServices as $service) {
            $unbilledTotal += static::calculateVendorPayAmount($service, $service->vendor_id);
        }

        $invoiceQuery = VendorInvoice::query();
        if ($organizationId && $organizationId !== 'all') {
            $invoiceQuery->where('organization_id', $organizationId);
        }

        $pendingInvoicesTotal = (float) (clone $invoiceQuery)->whereIn('status', ['draft', 'pending_payment'])->sum('total_amount');
        $totalOutstanding = round($unbilledTotal + $pendingInvoicesTotal, 2);

        $approvedPayouts = (float) (clone $invoiceQuery)->where('status', 'pending_payment')->sum('total_amount');

        $paidThisMonth = (float) (clone $invoiceQuery)->where('status', 'paid')
            ->whereBetween('paid_at', [$startOfMonth, $endOfMonth])
            ->sum('total_amount');

        return response()->json([
            'success' => true,
            'data' => [
                'total_outstanding' => $totalOutstanding,
                'approved_payouts'  => round($approvedPayouts, 2),
                'paid_this_month'   => round($paidThisMonth, 2),
            ]
        ]);
    }

    /**
     * Update status of vendor invoice (e.g. to approved / pending_payment or paid).
     */
    public function updateStatus(Request $request, string $uuid)
    {
        $invoice = VendorInvoice::where('uuid', $uuid)->firstOrFail();

        $request->validate([
            'status' => 'required|string|in:draft,pending_payment,approved,paid,cancelled',
            'paid_at' => 'nullable|date',
            'payment_method' => 'nullable|string',
            'transaction_reference' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $status = $request->status;
        if ($status === 'approved') {
            $status = 'pending_payment';
        }

        try {
            DB::beginTransaction();

            $updateData = ['status' => $status];

            if ($status === 'paid') {
                $paidAt = $request->input('paid_at') ? Carbon::parse($request->input('paid_at')) : now();
                $updateData['paid_at'] = $paidAt;
                $updateData['stripe_transfer_id'] = $request->input('transaction_reference') ?: 'manual';

                if ($request->input('notes')) {
                    $updateData['notes'] = trim(($invoice->notes ? $invoice->notes . "\n" : "") . "[Paid - " . $paidAt->toDateTimeString() . "]: " . $request->input('notes'));
                }

                // Update related order services
                OrderService::where('vendor_invoice_id', $invoice->id)
                    ->update([
                        'vendor_paid' => true,
                        'vendor_paid_at' => $paidAt
                    ]);

                // Dispatch QuickBooks Payout Sync if job class exists
                if (class_exists(\App\Jobs\SyncVendorPayoutToQuickBooks::class)) {
                    \App\Jobs\SyncVendorPayoutToQuickBooks::dispatch($invoice->id);
                }
            }

            $invoice->update($updateData);

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $invoice->fresh(['vendor', 'lines']),
                'message' => "Invoice status updated to {$status}."
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to update status for vendor invoice {$invoice->uuid}: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
