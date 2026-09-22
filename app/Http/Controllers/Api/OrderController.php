<?php

namespace App\Http\Controllers\Api;

use App\Models\Order;
use App\Models\Invoice;
use App\Models\Agent;
use App\Models\Vendor;
use App\Models\Service;
use App\Models\Discount;
use App\Models\Property;
use App\Models\OrderSlot;
use App\Models\OrderTotal;
use App\Models\OrderService;
use App\Models\Notification; 
use App\Models\ProductOption;
use App\Models\Package;
use App\Models\FeatureSheet;
use App\Models\PrintRequest;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Arr;
use Carbon\Carbon;
use App\Jobs\SyncOrderCalendarEvents;
use App\Services\PackageDetectionService;
use App\Mail\OrderCreated;
use App\Mail\OrderUpdated;

class OrderController extends Controller
{

    public function index(): JsonResponse
    {
        try {
            $user = auth()->user();
            // Check if user is a vendor
            if ($user instanceof \App\Models\Vendor) {
                // Get orders where the vendor is in the slots
                $orders = Order::with([
                    'agent', 
                    'property',
                    'package',
                    'areas', 
                    'slots' => function($query) use ($user) {
                        $query->where('vendor_id', $user->id)
                            ->with(['vendor.addresses']);
                    },
                    'services.service', 
                    'services.option', 
                    'services.vendorPayment',
                    'logs'
                ])->where(function($query) use ($user) {
                    $query->whereHas('slots', function($q) use ($user) {
                        $q->where('vendor_id', $user->id);
                    })->orWhereHas('services', function($q) use ($user) {
                        $q->where('vendor_id', $user->uuid);
                    });
                });
            } 
            // Check if user is an agent
            else if ($user instanceof \App\Models\Agent) {
                $agent = Agent::where('uuid', $user->uuid)->firstOrFail();
                
                $orders = Order::with([
                    'agent', 
                    'property',
                    'package',
                    'areas', 
                    'slots.vendor.addresses', 
                    'services.service',
                    'services', 
                    'services.option', 
                    'logs'
                ])->where('agent_id', $agent->id);
            } 
            // For other user types (admin, etc.)
            else {
                $orders = Order::with([
                    'organization',
                    'agent', 
                    'property',
                    'package',
                    'areas', 
                    'slots.vendor.organization',
                    'slots.vendor.workHours', 
                    'slots.vendor.company', 
                    'slots.vendor.addresses', 
                    'slots.vendor.additionalBreaks', 
                    'services.service', 
                    'services', 
                    'services.option', 
                    'services.vendorPayment',
                    'logs'
                ]);
            }

            $orders = $orders->get()->map(function($order) {
                return $this->filterOrderNotes($order);
            });

            return response()->json([
                'success' => true,
                'data' => $orders,
                'message' => 'Orders fetched successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Error: ' . $e->getMessage(),
            ]);
        }
    }

    public function show($uuid): JsonResponse
    {
        try {
            $order = Order::with(['agent', 'property', 'package', 'areas', 'services.service', 'services.option', 'slots.vendor.workHours', 'slots.vendor.company', 'slots.vendor.addresses', 'totals', 'logs'])->where('uuid', $uuid)->first();

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'data' => null,
                    'message' => 'Order not found',
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $this->filterOrderNotes($order),
                'message' => 'Order details fetched',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Error: ' . $e->getMessage(),
            ]);
        }
    }

    public function store(Request $request): JsonResponse
    {
        if ($request->has('lock_materials')) {
            $lockVal = $request->input('lock_materials');
            if ($lockVal === 'true' || $lockVal === '1') {
                $request->merge(['lock_materials' => true]);
            } elseif ($lockVal === 'false' || $lockVal === '0') {
                $request->merge(['lock_materials' => false]);
            }
        }
        if ($request->has('split_invoice')) {
            $splitVal = $request->input('split_invoice');
            if ($splitVal === 'true' || $splitVal === '1') {
                $request->merge(['split_invoice' => true]);
            } elseif ($splitVal === 'false' || $splitVal === '0') {
                $request->merge(['split_invoice' => false]);
            }
        }
        if ($request->has('release_media_before_payment')) {
            $releaseMedia = $request->input('release_media_before_payment');
            $request->merge([
                'release_media_before_payment' => filter_var($releaseMedia, FILTER_VALIDATE_BOOLEAN),
            ]);
        }

        $validator = Validator::make($request->all(), [
            'agent_id' => 'required|exists:agents,uuid',
            'property_id' => 'required|exists:properties,uuid',
            'amount' => 'required|numeric',
            'order_status' => 'in:Processing,In Progress,Cancelled,Pending,Completed,On Hold',
            'payment_status' => 'in:PAID,UNPAID,PARTIAL',
            'lock_materials' => 'nullable|boolean',
            'co_agents' => 'nullable|array',
            'co_agents.*.name' => 'required_with:co_agents|string|max:255',
            'co_agents.*.email' => 'required_with:co_agents|email',
            'co_agents.*.number' => 'nullable',
            'co_agents.*.percentage' => 'nullable|numeric',
            'split_invoice' => 'nullable|boolean',
            'release_media_before_payment' => 'nullable|boolean',
            'notes' => 'nullable|array',
            'notes.*.name' => 'nullable|string',
            'notes.*.note' => 'nullable|string',
            'notes.*.date' => 'nullable|string',
            'notes.*.internal' => 'nullable',
            'notes.*.is_internal' => 'nullable',
            'services' => 'required|array',
            'services.*.service_id' => 'required',
            'services.*.option_id' => 'nullable|exists:product_options,uuid',
            'services.*.amount' => 'required|numeric',
            'services.*.custom' => 'nullable|string',
            'services.*.vendor_id' => 'nullable|exists:vendors,uuid',
            'services.*.feature_sheet_id' => 'nullable',
            'services.*.feature_sheet_uuid' => 'nullable|string',
            'discounts' => 'nullable|array',
            'discounts.*.discount_id' => 'required_with:discounts|exists:discounts,uuid',
            'discounts.*.type' => 'required_with:discounts|in:quantity,code,manual',
            'discounts.*.value' => 'required_with:discounts|numeric|min:0',
            'discounts.*.service_id' => 'nullable', // If discount applies to specific service
            'agent_discount' => 'nullable|array',
            'slots' => 'nullable|array',
            'slots.*.service_id' => 'required_with:slots',
            'slots.*.vendor_id' => 'required_with:slots|exists:vendors,uuid',
            'slots.*.show_all_vendors' => 'sometimes|boolean',
            'slots.*.schedule_override' => 'sometimes|boolean',
            'slots.*.recommend_time' => 'sometimes|boolean',
            'slots.*.travel' => 'nullable|string',
            'slots.*.start_time' => 'required_with:slots|string',
            'slots.*.end_time' => 'required_with:slots|string',
            'slots.*.est_time' => 'nullable|string',
            'slots.*.distance' => 'nullable|string',
            'slots.*.km_price' => 'nullable|string',
            'slots.*.date' => 'required_with:slots',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $data = $validator->validated();
            
            $agent = Agent::where('uuid', $request->agent_id)->first();
            $property = Property::where('uuid', $request->property_id)->first();

            $data['agent_id'] = $agent->id;
            $data['property_id'] = $property->id;
            $data['property_address'] = $property->address;
            $data['property_location'] = $property->city . ', ' . $property->province;
            $data['co_agents'] = $data['co_agents'] ?? null;
            $incomingNotes = $data['notes'] ?? [];
            $user = auth()->user();
            $canManageInternalNotes = $user instanceof \App\Models\User || $user instanceof \App\Models\Vendor;
            if (!$canManageInternalNotes && !empty($incomingNotes)) {
                $incomingNotes = array_map(function ($note) {
                    unset($note['internal']);
                    unset($note['is_internal']);
                    return $note;
                }, array_filter($incomingNotes, function ($note) {
                    $isInternal = (isset($note['internal']) && ($note['internal'] === 'true' || $note['internal'] === true || $note['internal'] === '1' || $note['internal'] === 1)) 
                        || (isset($note['is_internal']) && ($note['is_internal'] === true || $note['is_internal'] === 'true'));
                    return !$isInternal;
                }));
            }
            $data['notes'] = array_values($incomingNotes);
            $data['split_invoice'] = $data['split_invoice'] ?? false;
            $data['lock_materials'] = $data['lock_materials'] ?? true;
            $data['release_media_before_payment'] = $data['release_media_before_payment'] ?? false;

            $meta = [];

            // Save raw discounts snapshot
            if ($request->has('discounts')) {
                $meta['discounts'] = $request->discounts;
            }

            // Save agent discount snapshot (object or array)
            if ($request->has('agent_discount')) {
                $meta['agent_discount'] = $request->agent_discount;
            }

            // Note: Package info will be added after package detection
            if (!empty($meta)) {
                $data['meta'] = $meta;
            }
            // Create the order
            $data['organization_id'] = $agent->organization_id;
            $order = Order::create($data);

            // Create order services and calculate initial totals
            $orderServices = [];
            $serviceAmounts = [];
            $totalAmount = 0;

            foreach ($request->services as $service) {
                $serviceInput = $service['service_id'];
                $serviceModel = is_numeric($serviceInput)
                    ? Service::find((int) $serviceInput)
                    : (Str::isUuid($serviceInput) ? Service::where('uuid', $serviceInput)->first() : null);

                if (!$serviceModel) {
                    continue;
                }

                $optionModel = isset($service['option_id']) ? 
                    ProductOption::where('uuid', $service['option_id'])->first() : null;
                $matchedSlot = collect($request->slots ?? [])
                    ->firstWhere('service_id', $service['service_id']); 

                $serviceVendorUuid = null;
                if (!empty($matchedSlot['vendor_id'])) {
                    $serviceVendorUuid = Vendor::where('uuid', $matchedSlot['vendor_id'])->value('uuid');
                } elseif (!empty($service['vendor_id'])) {
                    $serviceVendorUuid = Str::isUuid($service['vendor_id'])
                        ? $service['vendor_id']
                        : Vendor::where('id', $service['vendor_id'])->value('uuid');
                }

                $featureSheetId = null;
                $featureSheetUuid = $service['feature_sheet_uuid'] ?? null;
                if (!empty($service['feature_sheet_id'])) {
                    $featureSheetId = is_numeric($service['feature_sheet_id'])
                        ? (int)$service['feature_sheet_id']
                        : FeatureSheet::where('uuid', $service['feature_sheet_id'])->value('id');
                } elseif ($featureSheetUuid) {
                    $featureSheetId = FeatureSheet::where('uuid', $featureSheetUuid)->value('id');
                }
                if (!$featureSheetUuid && $featureSheetId) {
                    $featureSheetUuid = FeatureSheet::where('id', $featureSheetId)->value('uuid');
                }

                $orderService = OrderService::create([
                    'order_id' => $order->id,
                    'service_id' => $serviceModel->id,
                    'option_id' => $optionModel?->id,
                    'feature_sheet_id' => $featureSheetId,
                    'feature_sheet_uuid' => $featureSheetUuid,
                    'amount' => $service['amount'],
                    'custom' => $service['custom'] ?? null,
                    'add_ons' => !empty($service['add_ons']) ? $service['add_ons'] : null,
                    'vendor_id' => $serviceVendorUuid,
                    'payment_status' => 'UNPAID',
                    'is_completed' => false,
                ]);

                $orderServices[$serviceModel->id] = $orderService;
                $serviceAmounts[$serviceModel->id] = $service['amount'];
                $totalAmount += $service['amount'];
            }

            // Detect applicable packages
            Log::info('=== PACKAGE DETECTION STARTED ===', [
                'order_id' => $order->id,
                'total_amount' => $totalAmount,
                'service_ids' => array_keys($orderServices),
                'service_names' => collect($orderServices)->map(fn($os) => $os->service->name ?? 'Unknown')->values()->toArray(),
            ]);
            
            $packageDetectionService = app(PackageDetectionService::class);
            $serviceIds = array_keys($orderServices);
            $applicablePackages = $packageDetectionService->detectApplicablePackages($serviceIds, $order->organization_id);
            
            Log::info('Package detection results', [
                'applicable_packages_count' => count($applicablePackages),
                'applicable_packages' => $applicablePackages->map(fn($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'discount' => $p->discount,
                    'status' => $p->status ? 'active' : 'inactive',
                ])->toArray(),
            ]);
            
            $bestPackage = $packageDetectionService->getBestPackage($applicablePackages);

            // Log package detection results and apply if found
            if ($bestPackage) {
                $packageDiscountAmount = $packageDetectionService->calculatePackageDiscount($bestPackage, $totalAmount);
                
                Log::info('✅ PACKAGE APPLIED', [
                    'package_id' => $bestPackage->id,
                    'package_name' => $bestPackage->name,
                    'package_discount_percentage' => $bestPackage->discount,
                    'calculated_discount_amount' => $packageDiscountAmount,
                    'original_total' => $totalAmount,
                    'package_status' => $bestPackage->status ? 'active' : 'inactive',
                ]);
                
                $packageDetectionService->logPackageDetection($serviceIds, $bestPackage);
                
                // Store package ID in order
                $order->update(['package_id' => $bestPackage->id]);
                
                // Also store package info in order meta for additional details
                $packageInfo = $packageDetectionService->getPackageInfo($bestPackage, $serviceIds, $packageDiscountAmount);
                
                $currentMeta = $order->meta ?? [];
                $currentMeta['applied_package'] = $packageInfo;
                $order->update(['meta' => $currentMeta]);
            } else {
                Log::info('❌ NO PACKAGE APPLIED', [
                    'reason' => count($applicablePackages) > 0 ? 'No best package selected' : 'No applicable packages found',
                    'total_packages_checked' => \App\Models\Package::where('status', true)->count(),
                    'service_ids_provided' => $serviceIds,
                    'order_total' => $totalAmount,
                ]);
            }

            // Process discounts
            Log::info('=== DISCOUNT PROCESSING STARTED ===', [
                'has_package' => $bestPackage !== null,
                'manual_discounts_count' => count($data['discounts'] ?? []),
            ]);
            
            $discountDetails = [];
            
            // Add package discount first (if applicable)
            if ($bestPackage) {
                $packageDiscountAmount = $packageDetectionService->calculatePackageDiscount($bestPackage, $totalAmount);
                
                $discountDetails[] = [
                    'model' => null,
                    'type' => 'package',
                    'value' => $packageDiscountAmount,
                    'service_id' => null,
                    'package' => $bestPackage,
                ];
                
                Log::info('Package discount added to processing queue', [
                    'package_discount_amount' => $packageDiscountAmount,
                    'package_name' => $bestPackage->name,
                ]);
            }
            
            if (isset($data['discounts'])) {
                foreach ($data['discounts'] as $discount) {
                    $discountModel = Discount::where('uuid', $discount['discount_id'])->first();
                    
                    $discSvcInput = $discount['service_id'] ?? null;
                    $discSvc = null;
                    if ($discSvcInput) {
                        $discSvc = is_numeric($discSvcInput)
                            ? Service::find((int) $discSvcInput)
                            : (Str::isUuid($discSvcInput) ? Service::where('uuid', $discSvcInput)->first() : null);
                    }

                    $discountDetails[] = [
                        'model' => $discountModel,
                        'type' => $discount['type'],
                        'value' => $discount['value'],
                        'service_id' => $discSvc?->id,
                    ];
                }
            }

            // Calculate totals with discounts
            $discountAmount = 0;
            $orderTotals = [];

            // First apply service-specific discounts
            foreach ($discountDetails as $discount) {
                if ($discount['service_id']) {
                    if (isset($orderServices[$discount['service_id']])) {
                        $serviceAmount = $serviceAmounts[$discount['service_id']];
                        $discountValue = $this->calculateDiscountValue($serviceAmount, $discount['value'], $discount['type']);
                        
                        $orderTotals[] = [
                            'uuid' => Str::uuid()->toString(),
                            'order_id' => $order->id,
                            'order_service_id' => $orderServices[$discount['service_id']]->id ?? null,
                            'discount_id' => $discount['model']->id ?? null,
                            'amount' => $serviceAmount - $discountValue,
                            'discount_type' => $discount['type'] ?? null,
                            'discount_value' => $discount['value'] ?? null,
                            'sort_order' => 0,
                        ];

                        $discountAmount += $discountValue;
                        $serviceAmounts[$discount['service_id']] -= $discountValue;
                    }
                }
            }

            // Then apply order-wide discounts (including package)
            foreach ($discountDetails as $discount) {
                if (!$discount['service_id']) {
                    // Package discount is already calculated, others need calculation
                    if ($discount['type'] === 'package') {
                        $discountValue = $discount['value']; // Already calculated
                    } else {
                        $discountValue = $this->calculateDiscountValue($totalAmount, $discount['value'], $discount['type']);
                    }
                    
                    $orderTotals[] = [
                        'uuid' => Str::uuid()->toString(),
                        'order_id' => $order->id,
                        'order_service_id' => null,
                        'discount_id' => $discount['model']->id ?? null,
                        'amount' => -$discountValue,
                        'discount_type' => $discount['type'] ?? null,
                        'discount_value' => $discount['value'] ?? null,
                        'sort_order' => $discount['type'] === 'package' ? 0 : 1,
                    ];

                    $discountAmount += $discountValue;
                }
            }

            // Create service totals (after discounts)
            foreach ($orderServices as $serviceId => $orderService) {
                $orderTotals[] = [
                    'uuid' => Str::uuid()->toString(),
                    'order_id' => $order->id,
                    'order_service_id' => $orderService->id ?? null,
                    'discount_id' => null,
                    'amount' => $serviceAmounts[$serviceId],
                    'discount_type' => null,
                    'discount_value' => null,
                    'sort_order' => 2,
                ];
            }

            // Create the final order total
            $finalAmount = $totalAmount - $discountAmount;
            $orderTotals[] = [
                'uuid' => Str::uuid()->toString(),
                'order_id' => $order->id,
                'order_service_id' => null,
                'discount_id' => null,
                'amount' => $finalAmount,
                'discount_type' => null,
                'discount_value' => null,
                'sort_order' => 3,
            ];

            // Update the order amount with the discounted total
            $order->update(['amount' => $finalAmount]);

            // Create all order totals
            OrderTotal::insert($orderTotals);

            // Validate Twilight slots constraint
            if (!empty($request->slots)) {
                $twilightError = $this->validateTwilightSlots($request->slots, $property);
                if ($twilightError) {
                    return $twilightError;
                }

                // Validate next_booking_slot_only constraint
                $slotsByVendorAndDate = [];
                foreach ($request->slots as $slot) {
                    $slotsByVendorAndDate[$slot['vendor_id']][$slot['date']][] = [
                        'start_time' => Carbon::parse($slot['start_time'])->format('H:i'),
                        'end_time' => Carbon::parse($slot['end_time'])->format('H:i'),
                    ];
                }

                foreach ($slotsByVendorAndDate as $vendorUuid => $dates) {
                    $slotVendor = Vendor::where('uuid', $vendorUuid)->first();
                    $vendorSettings = $slotVendor?->settings;

                    if ($vendorSettings && $vendorSettings->next_booking_slot_only) {
                        foreach ($dates as $date => $groupSlots) {
                            [$isValid, $nextAvailableTime] = $this->validateConsecutiveBooking($slotVendor, $date, $groupSlots);
                            
                            if (!$isValid) {
                                return response()->json([
                                    'success' => false,
                                    'message' => "Vendor {$slotVendor->first_name} requires consecutive booking. Next available slot starts at {$nextAvailableTime}.",
                                    'vendor_uuid' => $slotVendor->uuid,
                                    'next_available_time' => $nextAvailableTime
                                ], 422);
                            }
                        }
                    }
                }

                // Create order slots
                foreach ($request->slots as $slot) {
                    $slotService = Service::where('uuid', $slot['service_id'])->first();
                    $slotVendor = Vendor::where('uuid', $slot['vendor_id'])->first();

                    if (!$slotService || !$slotVendor) {
                        continue;
                    }
                    
                    $address = '';
                    $location = '';
                    $vendorAddress = $slotVendor->addresses()->where('type', 'start_location')->first();
                    if ($vendorAddress && !empty(trim($vendorAddress->address_line_1))) {
                        $address = $vendorAddress->address_line_1;
                        $location = implode(', ', array_filter([
                            $vendorAddress->city,
                            $vendorAddress->province
                        ]));
                    }

                    OrderSlot::create([
                        'order_id' => $order->id,
                        'service_id' => $slotService->id,
                        'vendor_id' => $slotVendor->id,
                        'show_all_vendors' => $slot['show_all_vendors'] ?? true,
                        'schedule_override' => $slot['schedule_override'] ?? true,
                        'recommend_time' => $slot['recommend_time'] ?? true,
                        'travel' => $slot['travel'] ?? null,
                        'start_time' => $slot['start_time'],
                        'end_time' => $slot['end_time'],
                        'est_time' => $slot['est_time'] ?? null,
                        'distance' => $slot['distance'] ?? null,
                        'km_price' => $slot['km_price'] ?? null,
                        'date' => $slot['date'] ?? null,
                        'address' => $address,
                        'location' => $location,
                    ]);
                }
            }
            
            $user = auth()->user();

            // Collect all vendor UUIDs from slots
            $vendorUuids = collect($request->slots ?? [])
                    ->map(fn($slot) => $slot['vendor_id'] ?? null)
                    ->filter() // remove nulls just in case
                    ->unique()
                    ->values()
                    ->toArray();

            
            DB::commit();

            // Auto-generate invoices (Consolidated + Individual)
            try {
                Invoice::generateForOrder($order);
            } catch (\Throwable $e) {
                Log::error('Failed to auto-generate invoices for order ' . $order->uuid . ': ' . $e->getMessage());
                // Non-blocking for the order creation response
            }

        // Send email notifications after successful order creation
        try {
            $order->load(['agent', 'services.service', 'services.option', 'slots.service', 'slots.vendor']);
            
            // 1. Dispatch order created event (sent to admin, agent)
            app(\App\Services\EmailDispatchService::class)->dispatch('order_created', $order);

            // 2. Dispatch slot booked event for each slot assigned to a vendor
            if ($order->slots && $order->slots->isNotEmpty()) {
                foreach ($order->slots as $slot) {
                    if ($slot->vendor_id) {
                        app(\App\Services\EmailDispatchService::class)->dispatch('slot_booked', $slot);
                    }
                }
            }
        } catch (\Exception $e) {
            // Log email errors but don't fail the order creation
            Log::error('Failed to send order creation emails via dispatch service', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        // ✅ Immediate Google Calendar sync (only if slots exist)
        if (!empty($request->slots)) {
            try {
                SyncOrderCalendarEvents::dispatchSync($order->id);
            } catch (\Throwable $e) {
                Log::error('Failed to execute SyncOrderCalendarEvents job', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $this->filterOrderNotes($order->load(['services', 'slots', 'totals'])),
            'message' => 'Order created successfully',
        ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Order creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    
    /**
     * Calculate discount value based on type
     */
    private function calculateDiscountValue(float $amount, float $value, string $type): float
    {
        return match($type) {
            'quantity' => $value, // Fixed amount discount
            'code' => $value,    // Fixed amount discount
            'manual' => $amount * ($value / 100), // Percentage discount
            'package' => $value, // Package discount is already calculated
            default => 0,
        };
    }


  public function update(Request $request, $uuid)
{
    if ($request->has('lock_materials')) {
        $lockVal = $request->input('lock_materials');
        if ($lockVal === 'true' || $lockVal === '1') {
            $request->merge(['lock_materials' => true]);
        } elseif ($lockVal === 'false' || $lockVal === '0') {
            $request->merge(['lock_materials' => false]);
        }
    }
    if ($request->has('split_invoice')) {
        $splitVal = $request->input('split_invoice');
        if ($splitVal === 'true' || $splitVal === '1') {
            $request->merge(['split_invoice' => true]);
        } elseif ($splitVal === 'false' || $splitVal === '0') {
            $request->merge(['split_invoice' => false]);
        }
    }
    if ($request->has('release_media_before_payment')) {
        $releaseMedia = $request->input('release_media_before_payment');
        $request->merge([
            'release_media_before_payment' => filter_var($releaseMedia, FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    $validator = Validator::make($request->all(), [
        'agent_id' => 'sometimes|exists:agents,uuid',
        'property_id' => 'sometimes|exists:properties,uuid',
        'amount' => 'sometimes|numeric',
        'order_status' => 'sometimes|in:Processing,In Progress,Cancelled,Pending,Completed,On Hold',
        'payment_status' => 'sometimes|in:PAID,UNPAID,PARTIAL',
        'lock_materials' => 'nullable|boolean',
        'co_agents' => 'nullable|array',
        'co_agents.*.name' => 'required_with:co_agents|string|max:255',
        'co_agents.*.email' => 'required_with:co_agents|email',
        'co_agents.*.number' => 'nullable',
        'co_agents.*.percentage' => 'nullable|numeric',
        'split_invoice' => 'nullable|boolean',
        'release_media_before_payment' => 'nullable|boolean',
        'notes' => 'nullable|array',
        'notes.*.name' => 'nullable|string',
        'notes.*.note' => 'nullable|string',
        'notes.*.date' => 'nullable|string',
        'notes.*.internal' => 'nullable',
        'notes.*.is_internal' => 'nullable',
        'services' => 'sometimes|array',
        'services.*.service_id' => 'required_with:services',
        'services.*.option_id' => 'nullable|exists:product_options,uuid',
        'services.*.amount' => 'required_with:services|numeric',
        'services.*.custom' => 'nullable|string',
        'services.*.vendor_id' => 'nullable|exists:vendors,uuid',
        'services.*.uuid' => 'nullable|uuid',
        'discounts' => 'nullable|array',
        'discounts.*.discount_id' => 'required_with:discounts|exists:discounts,uuid',
        'discounts.*.type' => 'required_with:discounts|in:quantity,code,manual',
        'discounts.*.value' => 'required_with:discounts|numeric|min:0',
        'discounts.*.service_id' => 'nullable',
        'agent_discount' => 'nullable|array',
        'slots' => 'sometimes|nullable|array',
        'slots.*.service_id' => 'required_with:slots',
        'slots.*.vendor_id' => 'required_with:slots|exists:vendors,uuid',
        'slots.*.start_time' => 'required_with:slots|string',
        'slots.*.end_time' => 'required_with:slots|string',
        'slots.*.custom_duration' => 'nullable|numeric|min:15',
        'slots.*.custom_end_time' => 'nullable|string',
        'slots.*.buffer_minutes' => 'nullable|numeric|min:0',
        'slots.*.est_time' => 'nullable|string',
        'slots.*.distance' => 'nullable|string',
        'slots.*.km_price' => 'nullable|string',
        'slots.*.date' => 'required_with:slots',
        'slots.*.uuid' => 'nullable|uuid',
        'areas' => 'sometimes|array',
        'areas.*.uuid' => 'nullable|uuid',
        'areas.*.type' => 'required_with:areas|string|max:20',
        'areas.*.footage' => 'nullable|integer|min:0',
        'areas.*.custom_title' => 'nullable|string|max:255',
        'is_add_service' => 'nullable|boolean',
        'update_invoice' => 'nullable|boolean',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => $validator->errors(),
        ], 422);
    }

    try {
        DB::beginTransaction();
        
        $order = Order::with(['services', 'slots', 'totals', 'areas'])
            ->where('uuid', $uuid)
            ->lockForUpdate()
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Order not found',
            ], 404);
        }

        // Track all changes for notifications
        $changes = [
            'services_added' => [],
            'services_removed' => [],
            'services_modified' => [],
            'slots_added' => [],
            'slots_removed' => [],
            'slots_modified' => [],
            'price_changed' => false,
            'old_amount' => $order->amount,
            'new_amount' => null,
            'vendor_uuids' => [],
            'package_changed' => false,
            'old_package' => null,
            'new_package' => null,
        ];

        

        // Update basic order fields
        $orderData = $this->prepareOrderData($request, $order);
        $mediaSettingsChanged = false;
        if (!empty($orderData)) {
            $order->update($orderData);
            $mediaSettingsChanged = $order->wasChanged('release_media_before_payment') || $order->wasChanged('payment_status');
        }

        // Process services if provided
        if ($request->filled('services')) {
            $serviceResult = $this->processServices($order, $request->services);
            $changes = array_merge($changes, $serviceResult['changes']);
            $serviceAmounts = $serviceResult['amounts'];
            $totalAmount = $serviceResult['total'];
            
            // Get new service IDs and existing package info first
            $packageDetectionService = app(PackageDetectionService::class);
            $newServiceIds = array_keys($serviceResult['services']);
            $existingPackageInfo = $order->meta['applied_package'] ?? null;
            
            // Detect package changes after service modifications
            Log::info('=== PACKAGE RE-EVALUATION STARTED (ORDER UPDATE) ===', [
                'order_id' => $order->id,
                'order_uuid' => $order->uuid,
                'new_service_ids' => $newServiceIds,
                'new_total_amount' => $totalAmount,
                'existing_package' => $existingPackageInfo ? [
                    'package_id' => $existingPackageInfo['package_id'] ?? null,
                    'package_name' => $existingPackageInfo['package_name'] ?? null,
                    'discount_amount' => $existingPackageInfo['package_discount_amount'] ?? null,
                ] : null,
            ]);
            
            $packageEvaluation = $packageDetectionService->evaluatePackageForUpdate($newServiceIds, $existingPackageInfo);
            
            Log::info('Package evaluation results', [
                'evaluation_result' => $packageEvaluation,
                'package_changed' => $packageEvaluation['changed'],
                'should_apply_package' => $packageEvaluation['should_apply'],
                'recommended_package' => $packageEvaluation['package'] ? [
                    'id' => $packageEvaluation['package']->id,
                    'name' => $packageEvaluation['package']->name,
                    'discount' => $packageEvaluation['package']->discount,
                ] : null,
            ]);
            
            if ($packageEvaluation['changed']) {
                $changes['package_changed'] = true;
                $changes['old_package'] = $existingPackageInfo['package_name'] ?? null;
                $changes['new_package'] = $packageEvaluation['package']?->name;
                
                Log::info('⚠️ PACKAGE CHANGE DETECTED', [
                    'old_package' => $changes['old_package'],
                    'new_package' => $changes['new_package'],
                    'change_reason' => $packageEvaluation['reason'] ?? 'Not specified',
                ]);
                
                // Update package in database and meta
                if ($packageEvaluation['should_apply']) {
                    $packageDiscountAmount = $packageDetectionService->calculatePackageDiscount(
                        $packageEvaluation['package'],
                        $totalAmount
                    );
                    $packageInfo = $packageDetectionService->getPackageInfo(
                        $packageEvaluation['package'],
                        $newServiceIds,
                        $packageDiscountAmount
                    );
                    
                    // Update package_id in order
                    $order->update(['package_id' => $packageEvaluation['package']->id]);
                    
                    $currentMeta = $order->meta ?? [];
                    $currentMeta['applied_package'] = $packageInfo;
                    $order->update(['meta' => $currentMeta]);
                    
                    Log::info('✅ NEW PACKAGE APPLIED (UPDATE)', [
                        'order_uuid' => $order->uuid,
                        'package_id' => $packageEvaluation['package']->id,
                        'package_name' => $packageEvaluation['package']->name,
                        'package_discount_percentage' => $packageEvaluation['package']->discount,
                        'calculated_discount_amount' => $packageDiscountAmount,
                        'new_total' => $totalAmount,
                    ]);
                } else {
                    // Remove package from order
                    $order->update(['package_id' => null]);
                    
                    $currentMeta = $order->meta ?? [];
                    unset($currentMeta['applied_package']);
                    $order->update(['meta' => $currentMeta]);
                    
                    Log::info('❌ PACKAGE REMOVED (UPDATE)', [
                        'order_uuid' => $order->uuid,
                        'removed_package' => $changes['old_package'],
                        'removal_reason' => $packageEvaluation['reason'] ?? 'Services no longer match package requirements',
                    ]);
                }
            } else {
                Log::info('✅ PACKAGE UNCHANGED (UPDATE)', [
                    'order_uuid' => $order->uuid,
                    'current_package' => $existingPackageInfo['package_name'] ?? 'No package',
                    'services_still_match' => true,
                ]);
            }
        }

        // Process discounts and recalculate totals if needed
        if ($request->filled('discounts') || !empty($changes['services_added']) || !empty($changes['services_removed']) || !empty($changes['services_modified'])) {
            $priceResult = $this->recalculateTotals($order, $request->discounts ?? [], $serviceAmounts ?? [], $totalAmount ?? 0);
            $changes['new_amount'] = $priceResult['final_amount'];
            $changes['price_changed'] = ($changes['old_amount'] != $changes['new_amount']);
        }

        // Process slots if provided
        $deletedSlotIds = [];
        if ($request->has('slots') && is_array($request->input('slots'))) {
            if (!empty($request->slots)) {
                $twilightError = $this->validateTwilightSlots($request->slots, $order->property);
                if ($twilightError) {
                    return $twilightError;
                }

                // Validate next_booking_slot_only constraint for new or modified slots
                $slotsByVendorAndDate = [];
                foreach ($request->slots as $slot) {
                    $slotsByVendorAndDate[$slot['vendor_id']][$slot['date']][] = [
                        'start_time' => Carbon::parse($slot['start_time'])->format('H:i'),
                        'end_time' => Carbon::parse($slot['end_time'])->format('H:i'),
                    ];
                }

                foreach ($slotsByVendorAndDate as $vendorUuid => $dates) {
                    $slotVendor = Vendor::where('uuid', $vendorUuid)->first();
                    $vendorSettings = $slotVendor?->settings;

                    if ($vendorSettings && $vendorSettings->next_booking_slot_only) {
                        foreach ($dates as $date => $groupSlots) {
                            [$isValid, $nextAvailableTime] = $this->validateConsecutiveBooking($slotVendor, $date, $groupSlots, $uuid);
                            
                            if (!$isValid) {
                                return response()->json([
                                    'success' => false,
                                    'message' => "Vendor {$slotVendor->first_name} requires consecutive booking. Next available slot starts at {$nextAvailableTime}.",
                                    'vendor_uuid' => $slotVendor->uuid,
                                    'next_available_time' => $nextAvailableTime
                                ], 422);
                            }
                        }
                    }
                }
            }

            $slotResult = $this->processSlots($order, $request->slots);
            $changes = array_merge($changes, $slotResult['changes']);
            $changes['vendor_uuids'] = array_merge($changes['vendor_uuids'], $slotResult['vendor_uuids']);

            // Track deleted slot IDs
            $deletedSlotIds = $slotResult['deleted_slot_ids'] ?? [];
        }

        // Create a new invoice for added services
        if (!empty($changes['services_added'])) {
            $newOrderServices = $order->services()->whereIn('uuid', array_column($changes['services_added'], 'uuid'))->get();
            if ($newOrderServices->isNotEmpty()) {
                Invoice::generateForNewServices($order, $newOrderServices);
            }
        }

        $updateInvoice = $request->has('update_invoice')
            ? filter_var($request->input('update_invoice'), FILTER_VALIDATE_BOOLEAN)
            : true;

        // Upgrade/Downgrade logic
        if ($updateInvoice && !empty($changes['services_modified'])) {
            foreach ($changes['services_modified'] as $modified) {
                    $orderService = $modified['order_service'];
                    $oldAmount = $modified['old_amount'];
                    $newAmount = $modified['new_amount'];
                    $difference = $newAmount - $oldAmount;

                    if ($difference == 0) continue;

                    // Find invoices containing this service
                    $invoices = Invoice::where('order_id', $order->id)
                        ->whereHas('items', function($query) use ($orderService) {
                            $query->where('order_service_id', $orderService->id);
                        })->get();

                    $anyPaid = $invoices->where('status', 'paid')->isNotEmpty();

                    if ($anyPaid) {
                        // If already paid, create ONE small invoice for the difference (if upgrade)
                        if ($difference > 0) {
                             $agent = $order->agent;
                             $province = $agent->headquarter_province ?? $order->property->province ?? 'ON';
                             $taxInfo = (new \App\Http\Controllers\Api\InvoiceController)->getCanadaTaxRate($province);

                             $subtotal = $difference;
                             $taxAmount = round($subtotal * ($taxInfo['rate'] / 100), 2);

                             $newInvoice = Invoice::create([
                                 'order_id' => $order->id,
                                 'agent_id' => $order->agent_id,
                                 'status' => 'issued',
                                 'subtotal' => $subtotal,
                                 'tax_rate' => $taxInfo['rate'],
                                 'tax_amount' => $taxAmount,
                                 'tax_details' => [$taxInfo['name'] => ['rate' => $taxInfo['rate'], 'amount' => $taxAmount]],
                                 'total' => $subtotal + $taxAmount,
                                 'paid_amount' => 0,
                                 'currency' => 'cad',
                                 'issued_at' => now(),
                                 'notes' => "Upgrade for " . $modified['service_name'] . " (Difference)",
                             ]);

                             $newInvoice->items()->create([
                                 'order_service_id' => $orderService->id,
                                 'description' => "Upgrade: " . $modified['service_name'] . " (from $" . $oldAmount . " to $" . $newAmount . ")",
                                 'quantity' => 1,
                                 'unit_price' => $difference,
                                 'amount' => $difference
                             ]);
                        }
                    }

                    // Always update unpaid invoices if they exist
                    foreach ($invoices as $invoice) {
                        if ($invoice->status !== 'paid') {
                            $item = $invoice->items()->where('order_service_id', $orderService->id)->first();
                            if ($item) {
                                $item->update([
                                    'unit_price' => $newAmount,
                                    'amount' => $newAmount
                                ]);
                                $invoice->recalculateTotals();
                            }
                        }
                    }
                }
            }

        // Process areas if provided
        if ($request->filled('areas')) {
            $this->processAreas($order, $request->areas, $updateInvoice);
        }

        // Always sync invoices to reflect any additions/removals/price changes if update_invoice is enabled
        if ($updateInvoice) {
            Invoice::syncOrderInvoices($order);
        }

        // Create notification only if there are meaningful changes
        if ($this->hasSignificantChanges($changes)) {
            $this->createOrderNotification($order, $changes);
        }

        DB::commit();

        // Trigger background reprocessing of photos if release_media_before_payment or payment_status changed
        if (!empty($mediaSettingsChanged)) {
            try {
                $tourFiles = \App\Models\TourFile::where('type', 'photo')
                    ->whereHas('tour', function ($q) use ($order) {
                        $q->where('order_id', $order->id);
                    })->get();

                foreach ($tourFiles as $tourFile) {
                    \App\Jobs\ProcessUploadedImage::dispatch($tourFile)->onQueue('image-processing');
                    Log::info('Queued image reprocessing after order update', [
                        'file_id' => $tourFile->id,
                        'order_id' => $order->id,
                        'release_media' => $order->release_media_before_payment,
                        'payment_status' => $order->payment_status,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('Failed to queue image reprocessing after order update', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Send email notifications after successful order update
        if ($this->hasSignificantChanges($changes)) {
            try {
                $order->load(['agent', 'services.service', 'services.option', 'slots.service', 'slots.vendor']);
                
                // Prepare readable changes for email
                $emailChanges = [
                    'services_added' => array_column($changes['services_added'] ?? [], 'service_name'),
                    'services_removed' => array_column($changes['services_removed'] ?? [], 'service_name'),
                    'slots_added' => array_map(function($slot) {
                        return ($slot['service_name'] ?? 'Service') . ' - ' . ($slot['date'] ?? '') . ' ' . ($slot['time'] ?? '');
                    }, $changes['slots_added'] ?? []),
                    'slots_modified' => array_map(function($slot) {
                        return ($slot['service_name'] ?? 'Service') . ' - ' . ($slot['date'] ?? '') . ' ' . ($slot['time'] ?? '');
                    }, $changes['slots_modified'] ?? []),
                    'price_changed' => $changes['price_changed'] ?? false,
                    'old_amount' => $changes['old_amount'] ?? 0,
                    'new_amount' => $changes['new_amount'] ?? $order->amount,
                ];

                // Build a textual changes summary array
                $changesSummary = [];
                if (!empty($emailChanges['services_added'])) {
                    $changesSummary[] = "Added services: " . implode(', ', $emailChanges['services_added']);
                }
                if (!empty($emailChanges['services_removed'])) {
                    $changesSummary[] = "Removed services: " . implode(', ', $emailChanges['services_removed']);
                }
                if (!empty($emailChanges['slots_added'])) {
                    $changesSummary[] = "Added slots: " . implode(', ', $emailChanges['slots_added']);
                }
                if (!empty($emailChanges['slots_modified'])) {
                    $changesSummary[] = "Modified slots: " . implode(', ', $emailChanges['slots_modified']);
                }
                if ($emailChanges['price_changed']) {
                    $changesSummary[] = "Price changed from $" . number_format($emailChanges['old_amount'], 2) . " to $" . number_format($emailChanges['new_amount'], 2);
                }

                app(\App\Services\EmailDispatchService::class)->dispatch('order_updated', $order, [
                    'data' => [
                        'changes_summary' => $changesSummary,
                    ]
                ]);
            } catch (\Exception $e) {
                // Log email errors but don't fail the order update
                Log::error('Failed to send order update emails via dispatch service', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        // ✅ Immediate Google Calendar sync
        try {
            SyncOrderCalendarEvents::dispatchSync($order->id, $deletedSlotIds);
        } catch (\Throwable $e) {
            Log::error('Failed to execute SyncOrderCalendarEvents job', [
                'order_id' => $order->id,
                'deleted_slots' => $deletedSlotIds,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('Order updated successfully', [
            'order_uuid' => $uuid,
            'changes' => $changes,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->filterOrderNotes($order->fresh(['agent', 'property', 'package', 'services.service', 'services.option', 'slots', 'totals', 'areas'])),
            'message' => 'Order updated successfully',
            'changes' => $changes, // Return changes for frontend reference
        ]);

    } catch (\Throwable $e) {
        DB::rollBack();
        
        Log::error('Order update failed', [
            'order_uuid' => $uuid,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => 'Error: ' . $e->getMessage(),
        ], 500);
    }
}

/**
 * Prepare basic order data from request
 */
private function prepareOrderData(Request $request, $order = null): array
{
    $data = [];

    if ($request->filled('agent_id')) {
        $data['agent_id'] = Agent::where('uuid', $request->agent_id)->value('id');
    }
    $meta = [];

            // Save raw discounts snapshot
    if ($request->has('discounts')) {
        $meta['discounts'] = $request->discounts;
    }
            // Save agent discount snapshot (object or array)
    if ($request->has('agent_discount')) {
        $meta['agent_discount'] = $request->agent_discount;
    }

    if (!empty($meta)) {
         $data['meta'] = $meta;
    }

    if ($request->filled('property_id')) {
        $property = Property::where('uuid', $request->property_id)->firstOrFail();
        $data['property_id'] = $property->id;
        $data['property_address'] = $property->address;
        $data['property_location'] = $property->city . ', ' . $property->province;
    }

    foreach (['order_status', 'payment_status'] as $field) {
        if ($request->filled($field)) {
            $data[$field] = $request->$field;
        }
    }

    if ($request->filled('co_agents')) {
        $data['co_agents'] = $request->co_agents;
    }

    if ($request->has('notes')) {
        $incomingNotes = $request->notes ?? [];
        $existingNotes = $order ? ($order->notes ?? []) : [];

        $user = auth()->user();
        $canManageInternalNotes = $user instanceof \App\Models\User || $user instanceof \App\Models\Vendor;

        if (!$canManageInternalNotes) {
            // Preserve existing internal notes from the database
            $internalNotes = array_filter($existingNotes, function ($note) {
                return (isset($note['internal']) && ($note['internal'] === 'true' || $note['internal'] === true || $note['internal'] === '1' || $note['internal'] === 1)) 
                    || (isset($note['is_internal']) && ($note['is_internal'] === true || $note['is_internal'] === 'true'));
            });

            // Strip any internal flag from incoming notes to prevent non-admins/non-vendors from adding/spoofing internal notes
            $nonInternalIncomingNotes = array_map(function ($note) {
                unset($note['internal']);
                unset($note['is_internal']);
                return $note;
            }, array_filter($incomingNotes, function ($note) {
                $isInternal = (isset($note['internal']) && ($note['internal'] === 'true' || $note['internal'] === true || $note['internal'] === '1' || $note['internal'] === 1)) 
                    || (isset($note['is_internal']) && ($note['is_internal'] === true || $note['is_internal'] === 'true'));
                return !$isInternal;
            }));

            $data['notes'] = array_merge(array_values($internalNotes), array_values($nonInternalIncomingNotes));
        } else {
            // Admins and Vendors can submit notes directly (including internal notes)
            $data['notes'] = $incomingNotes;
        }
    }

    if ($request->has('split_invoice')) {
        $data['split_invoice'] = $request->split_invoice;
    }

    if ($request->has('lock_materials')) {
        $data['lock_materials'] = $request->lock_materials;
    }

    if ($request->has('release_media_before_payment')) {
        $data['release_media_before_payment'] = $request->boolean('release_media_before_payment');
    }

    return $data;
}


/**
 * Process services with proper change detection
 */
private function processServices($order, array $incomingServices): array
{
    $changes = [
        'services_added' => [],
        'services_removed' => [],
        'services_modified' => [],
    ];

    $serviceAmounts = [];
    $totalAmount = 0;
    $orderServices = [];

    // Index existing services by UUID
    $existingServices = $order->services->keyBy('uuid');
    $processedUuids = [];

    foreach ($incomingServices as $incoming) {
        $serviceModel = Service::where('uuid', $incoming['service_id'])->firstOrFail();
        
        $optionId = null;
        if (!empty($incoming['option_id'])) {
            $optionId = ProductOption::where('uuid', $incoming['option_id'])->value('id');
        }

        $featureSheetId = null;
        $featureSheetUuid = $incoming['feature_sheet_uuid'] ?? null;
        if (!empty($incoming['feature_sheet_id'])) {
            $featureSheetId = is_numeric($incoming['feature_sheet_id'])
                ? (int)$incoming['feature_sheet_id']
                : FeatureSheet::where('uuid', $incoming['feature_sheet_id'])->value('id');
        } elseif ($featureSheetUuid) {
            $featureSheetId = FeatureSheet::where('uuid', $featureSheetUuid)->value('id');
        }
        if (!$featureSheetUuid && $featureSheetId) {
            $featureSheetUuid = FeatureSheet::where('id', $featureSheetId)->value('uuid');
        }

        $payload = [
            'service_id' => $serviceModel->id,
            'option_id' => $optionId,
            'feature_sheet_id' => $featureSheetId,
            'feature_sheet_uuid' => $featureSheetUuid,
            'amount' => $incoming['amount'],
            'custom' => $incoming['custom'] ?? null,
            'add_ons' => !empty($incoming['add_ons']) ? $incoming['add_ons'] : null,
        ];

        if (array_key_exists('vendor_id', $incoming)) {
            $incomingVendorUuid = null;
            if (!empty($incoming['vendor_id'])) {
                $incomingVendorUuid = Str::isUuid($incoming['vendor_id'])
                    ? $incoming['vendor_id']
                    : Vendor::where('id', $incoming['vendor_id'])->value('uuid');
            }
            $payload['vendor_id'] = $incomingVendorUuid;
        }

        // EXISTING SERVICE - Update only if changed
        if (!empty($incoming['uuid']) && $existingServices->has($incoming['uuid'])) {
            $existing = $existingServices[$incoming['uuid']];
            $processedUuids[] = $incoming['uuid'];

            // Compare only the fields that matter
            $hasChanged = (
                $existing->amount != $payload['amount'] ||
                $existing->option_id != $payload['option_id'] ||
                $existing->feature_sheet_uuid != $payload['feature_sheet_uuid'] ||
                $existing->custom != $payload['custom'] ||
                $existing->add_ons != $payload['add_ons'] ||
                (array_key_exists('vendor_id', $payload) && $existing->vendor_id != $payload['vendor_id'])
            );

            $changes['services_modified'][] = [
                'order_service' => $existing,
                'service_name' => $serviceModel->name,
                'old_amount' => $existing->amount,
                'new_amount' => $payload['amount'],
            ];
            $existing->update($payload);

            // Sync with associated PrintRequest if this is a feature sheet service
            $targetSheetUuid = $payload['feature_sheet_uuid'] ?? $existing->feature_sheet_uuid;
            if ($targetSheetUuid) {
                $printReq = PrintRequest::where('feature_sheet_id', $targetSheetUuid)
                    ->where('status', '!=', 'Cancelled')
                    ->latest()
                    ->first();
                if ($printReq) {
                    $optModel = $payload['option_id'] ? ProductOption::find($payload['option_id']) : null;
                    $copies = $optModel?->quantity;
                    if (!$copies && $optModel?->title) {
                        preg_match('/\d+/', $optModel->title, $m);
                        $copies = !empty($m[0]) ? (int)$m[0] : null;
                    }
                    $printReqUpdate = ['amount' => $payload['amount']];
                    if ($copies) {
                        $printReqUpdate['copies'] = $copies;
                    }
                    if ($optModel) {
                        $printReqUpdate['option_id'] = $optModel->uuid;
                    }
                    $printReq->update($printReqUpdate);
                }
            }

            $orderServices[$serviceModel->id] = $existing;
        }
        // NEW SERVICE
        else {
            $created = $order->services()->create(
                array_merge($payload, ['uuid' => (string) Str::uuid()])
            );

            $orderServices[$serviceModel->id] = $created;
            $changes['services_added'][] = [
                'uuid' => $created->uuid,
                'service_name' => $serviceModel->name,
                'amount' => $payload['amount'],
            ];
        }

        $serviceAmounts[$serviceModel->id] = $incoming['amount'];
        $totalAmount += $incoming['amount'];
    }

    // REMOVED SERVICES - Delete services not in incoming request
    foreach ($existingServices as $existing) {
        if (!in_array($existing->uuid, $processedUuids)) {
            $serviceName = Service::find($existing->service_id)->name ?? 'Unknown Service';
            $changes['services_removed'][] = [
                'service_name' => $serviceName,
                'amount' => $existing->amount,
            ];
            $existing->delete();
        }
    }

    return [
        'changes' => $changes,
        'amounts' => $serviceAmounts,
        'total' => $totalAmount,
        'services' => $orderServices,
    ];
}




/**
 * Process slots with proper change detection
 */
// private function processSlots($order, array $incomingSlots): array
// {
//     $changes = [
//         'slots_added' => [],
//         'slots_removed' => [],
//         'slots_modified' => [],
//     ];
//     $vendorUuids = [];

//     // Index existing slots by UUID
//     $existingSlots = $order->slots->keyBy('uuid');
//     $processedUuids = [];

//     foreach ($incomingSlots as $incoming) {
//         $serviceId = Service::where('uuid', $incoming['service_id'])->value('id');
//         $vendorUuid = $incoming['vendor_id'];
//         $vendorId = Vendor::where('uuid', $vendorUuid)->value('id');

//         if (!$serviceId || !$vendorId) {
//             continue;
//         }

//         $vendorUuids[] = $vendorUuid;

//         $vendorAddress = Vendor::find($vendorId)
//             ?->addresses()
//             ->where('type', 'start_location')
//             ->first();

//         $payload = [
//             'service_id' => $serviceId,
//             'vendor_id' => $vendorId,
//             'start_time' => $incoming['start_time'],
//             'end_time' => $incoming['end_time'],
//             'date' => $incoming['date'],
//             'address' => $vendorAddress->address_line_1 ?? '',
//             'location' => implode(', ', array_filter([
//                 $vendorAddress->city ?? null,
//                 $vendorAddress->province ?? null,
//             ])),
//             'travel' => $incoming['travel'] ?? null,
//             'est_time' => $incoming['est_time'] ?? null,
//             'distance' => $incoming['distance'] ?? null,
//             'km_price' => $incoming['km_price'] ?? null,
//         ];

//         // EXISTING SLOT - Update only if changed
//         if (!empty($incoming['uuid']) && $existingSlots->has($incoming['uuid'])) {
//             $existing = $existingSlots[$incoming['uuid']];
//             $processedUuids[] = $incoming['uuid'];

//             // Check if meaningful fields changed
//             $hasChanged = (
//                 $existing->start_time != $payload['start_time'] ||
//                 $existing->end_time != $payload['end_time'] ||
//                 $existing->date != $payload['date'] ||
//                 $existing->vendor_id != $payload['vendor_id']
//             );

//             if ($hasChanged) {
//                 $existing->update($payload);
//                 $serviceName = Service::find($serviceId)->name ?? 'Unknown';
//                 $vendorName = Vendor::find($vendorId)->name ?? 'Unknown';
                
//                 $changes['slots_modified'][] = [
//                     'service_name' => $serviceName,
//                     'vendor_name' => $vendorName,
//                     'date' => $payload['date'],
//                     'time' => $payload['start_time'] . ' - ' . $payload['end_time'],
//                 ];
//             }
//         }
//         // NEW SLOT
//         else {
//             $order->slots()->create(
//                 array_merge($payload, ['uuid' => (string) Str::uuid()])
//             );

//             $serviceName = Service::find($serviceId)->name ?? 'Unknown';
//             $vendorName = Vendor::find($vendorId)->name ?? 'Unknown';
            
//             $changes['slots_added'][] = [
//                 'service_name' => $serviceName,
//                 'vendor_name' => $vendorName,
//                 'date' => $payload['date'],
//                 'time' => $payload['start_time'] . ' - ' . $payload['end_time'],
//             ];
//         }
//     }

//     // REMOVED SLOTS
//     foreach ($existingSlots as $existing) {
//         if (!in_array($existing->uuid, $processedUuids)) {
//             $serviceName = Service::find($existing->service_id)->name ?? 'Unknown';
//             $vendorName = Vendor::find($existing->vendor_id)->name ?? 'Unknown';
            
//             $changes['slots_removed'][] = [
//                 'service_name' => $serviceName,
//                 'vendor_name' => $vendorName,
//                 'date' => $existing->date,
//                 'time' => $existing->start_time . ' - ' . $existing->end_time,
//             ];
            
//             $existing->delete();
//         }
//     }

//     return [
//         'changes' => $changes,
//         'vendor_uuids' => array_unique($vendorUuids),
//     ];
// }
private function processSlots($order, array $incomingSlots): array
{
    $changes = [
        'slots_added' => [],
        'slots_removed' => [],
        'slots_modified' => [],
    ];
    $vendorUuids = [];
    $deletedSlotIds = []; // ✅ ADD THIS

    // Index existing slots by UUID
    $existingSlots = $order->slots->keyBy('uuid');
    $processedUuids = [];

    foreach ($incomingSlots as $incoming) {
        $serviceId = !empty($incoming['service_id']) ? Service::where('uuid', $incoming['service_id'])->value('id') : null;
        $vendorUuid = $incoming['vendor_id'] ?? null;
        $vendorId = $vendorUuid ? Vendor::where('uuid', $vendorUuid)->value('id') : null;

        if (!$serviceId || !$vendorId) {
            continue;
        }

        $vendorUuids[] = $vendorUuid;

        $vendorAddress = Vendor::find($vendorId)
            ?->addresses()
            ->where('type', 'start_location')
            ->first();

        $kmPrice = $incoming['km_price'] ?? null;
        if (is_null($kmPrice) || $kmPrice === '') {
            $vendorSetting = \App\Models\VendorSetting::where('vendor_id', $vendorId)->first();
            if ($vendorSetting && !is_null($vendorSetting->payment_per_km)) {
                $kmPrice = (float)$vendorSetting->payment_per_km;
            } else {
                $orgId = $order->organization_id;
                $orgUuid = \App\Models\Organization::find($orgId)?->uuid;
                if ($orgUuid) {
                    $orgSetting = \App\Models\Setting::where('org_id', $orgUuid)->where('key', 'default_travel_rate')->first();
                    if ($orgSetting && !is_null($orgSetting->value)) {
                        $val = $orgSetting->value;
                        $kmPrice = is_array($val) ? (isset($val['rate']) ? (float)$val['rate'] : (isset($val[0]) ? (float)$val[0] : 0.50)) : (float)$val;
                    } else {
                        $globalSetting = \App\Models\Setting::whereNull('org_id')->where('key', 'default_travel_rate')->first();
                        if ($globalSetting && !is_null($globalSetting->value)) {
                            $val = $globalSetting->value;
                            $kmPrice = is_array($val) ? (isset($val['rate']) ? (float)$val['rate'] : (isset($val[0]) ? (float)$val[0] : 0.50)) : (float)$val;
                        } else {
                            $kmPrice = 0.50;
                        }
                    }
                } else {
                    $kmPrice = 0.50;
                }
            }
        }

        $customDuration = isset($incoming['custom_duration']) && is_numeric($incoming['custom_duration']) ? (int)$incoming['custom_duration'] : null;
        $customEndTime = $incoming['custom_end_time'] ?? null;
        $bufferMinutes = isset($incoming['buffer_minutes']) && is_numeric($incoming['buffer_minutes']) ? (int)$incoming['buffer_minutes'] : 0;

        $endTime = $incoming['end_time'];
        if ($customEndTime) {
            $endTime = $customEndTime;
        } elseif ($customDuration && !empty($incoming['start_time'])) {
            try {
                $endTime = Carbon::parse($incoming['start_time'])->addMinutes($customDuration)->format('H:i');
            } catch (\Throwable $e) {
                $endTime = $incoming['end_time'];
            }
        }

        $payload = [
            'service_id' => $serviceId,
            'vendor_id' => $vendorId,
            'start_time' => $incoming['start_time'],
            'end_time' => $endTime,
            'custom_duration' => $customDuration,
            'custom_end_time' => $customEndTime,
            'buffer_minutes' => $bufferMinutes,
            'date' => $incoming['date'],
            'address' => $vendorAddress->address_line_1 ?? '',
            'location' => implode(', ', array_filter([
                $vendorAddress->city ?? null,
                $vendorAddress->province ?? null,
            ])),
            'travel' => $incoming['travel'] ?? null,
            'est_time' => $incoming['est_time'] ?? null,
            'distance' => $incoming['distance'] ?? null,
            'km_price' => $kmPrice,
        ];

        // EXISTING SLOT - Update only if changed
        if (!empty($incoming['uuid']) && $existingSlots->has($incoming['uuid'])) {
            $existing = $existingSlots[$incoming['uuid']];
            $processedUuids[] = $incoming['uuid'];

            // Check if meaningful fields changed
            $hasChanged = (
                $existing->start_time != $payload['start_time'] ||
                $existing->end_time != $payload['end_time'] ||
                $existing->date != $payload['date'] ||
                $existing->vendor_id != $payload['vendor_id'] ||
                $existing->custom_duration != $payload['custom_duration'] ||
                $existing->custom_end_time != $payload['custom_end_time'] ||
                $existing->buffer_minutes != $payload['buffer_minutes']
            );

            if ($hasChanged) {
                $existing->update($payload);
                $serviceName = Service::find($serviceId)->name ?? 'Unknown';
                $vendorName = Vendor::find($vendorId)->name ?? 'Unknown';
                
                $changes['slots_modified'][] = [
                    'service_name' => $serviceName,
                    'vendor_name' => $vendorName,
                    'date' => $payload['date'],
                    'time' => $payload['start_time'] . ' - ' . $payload['end_time'],
                ];
            }
        }
        // NEW SLOT
        else {
            $order->slots()->create(
                array_merge($payload, ['uuid' => (string) Str::uuid()])
            );

            $serviceName = Service::find($serviceId)->name ?? 'Unknown';
            $vendorName = Vendor::find($vendorId)->name ?? 'Unknown';
            
            $changes['slots_added'][] = [
                'service_name' => $serviceName,
                'vendor_name' => $vendorName,
                'date' => $payload['date'],
                'time' => $payload['start_time'] . ' - ' . $payload['end_time'],
            ];
        }
    }

    // REMOVED SLOTS
    foreach ($existingSlots as $existing) {
        if (!in_array($existing->uuid, $processedUuids)) {
            $serviceName = Service::find($existing->service_id)->name ?? 'Unknown';
            $vendorName = Vendor::find($existing->vendor_id)->name ?? 'Unknown';
            
            $changes['slots_removed'][] = [
                'service_name' => $serviceName,
                'vendor_name' => $vendorName,
                'date' => $existing->date,
                'time' => $existing->start_time . ' - ' . $existing->end_time,
            ];
            
            // ✅ CAPTURE SLOT ID BEFORE DELETION (for calendar cleanup)
            $deletedSlotIds[] = $existing->id;
            
            $existing->delete();
        }
    }

    return [
        'changes' => $changes,
        'vendor_uuids' => array_unique($vendorUuids),
        'deleted_slot_ids' => $deletedSlotIds, // ✅ ADD THIS
    ];
}


/**
 * Recalculate order totals
 */
private function recalculateTotals($order, array $discounts, array $serviceAmounts, float $totalAmount): array
{
    Log::info("=== RECALCULATE TOTALS STARTED ===", [
        'order_uuid' => $order->uuid,
        'total_amount' => $totalAmount,
        'discounts_count' => count($discounts)
    ]);

    $order->totals()->delete();

    $discountAmount = 0;
    $orderTotals = [];

    // Check for package discount in meta
    $packageInfo = $order->meta['applied_package'] ?? null;
    
    Log::info("📦 PACKAGE DISCOUNT PROCESSING", [
        'order_uuid' => $order->uuid,
        'has_package_in_meta' => !is_null($packageInfo),
        'package_info' => $packageInfo
    ]);
    
    if ($packageInfo) {
        $packageDetectionService = app(PackageDetectionService::class);
        $package = Package::find($packageInfo['package_id']);
        
        if ($package && $package->status) {
            $packageDiscountAmount = $packageDetectionService->calculatePackageDiscount($package, $totalAmount);
            
            Log::info("✅ PACKAGE DISCOUNT APPLIED (RECALCULATE)", [
                'order_uuid' => $order->uuid,
                'package_id' => $package->id,
                'package_name' => $package->name,
                'discount_amount' => $packageDiscountAmount,
                'total_before_discount' => $totalAmount
            ]);
            
            $orderTotals[] = [
                'uuid' => (string) Str::uuid(),
                'order_id' => $order->id,
                'discount_id' => null,
                'amount' => -$packageDiscountAmount,
                'discount_type' => 'package',
                'discount_value' => $packageDiscountAmount,
                'sort_order' => 0,
                'order_service_id' => null,
            ];
            
            $discountAmount += $packageDiscountAmount;
        } else {
            Log::warning("❌ PACKAGE DISCOUNT SKIPPED (RECALCULATE)", [
                'order_uuid' => $order->uuid,
                'package_id' => $packageInfo['package_id'],
                'package_found' => !is_null($package),
                'package_active' => $package ? $package->status : null,
                'reason' => !$package ? 'Package not found' : 'Package inactive'
            ]);
        }
    } else {
        Log::info("ℹ️ NO PACKAGE IN META (RECALCULATE)", [
            'order_uuid' => $order->uuid,
            'order_meta' => $order->meta
        ]);
    }

    foreach ($discounts as $discount) {
        $discountModel = Discount::where('uuid', $discount['discount_id'])->firstOrFail();

        $val = $this->calculateDiscountValue(
            $totalAmount,
            $discount['value'],
            $discount['type']
        );

        $discountAmount += $val;

        $orderTotals[] = [
            'uuid' => (string) Str::uuid(),
            'order_id' => $order->id,
            'discount_id' => $discountModel->id,
            'amount' => -$val,
            'discount_type' => $discount['type'],
            'discount_value' => $discount['value'],
            'sort_order' => 1,
            'order_service_id' => null,
        ];
    }

    $finalAmount = $totalAmount - $discountAmount;

    Log::info("📊 RECALCULATE TOTALS SUMMARY", [
        'order_uuid' => $order->uuid,
        'total_amount' => $totalAmount,
        'total_discount' => $discountAmount,
        'final_amount' => $finalAmount,
        'order_totals_count' => count($orderTotals)
    ]);

    $orderTotals[] = [
        'uuid' => (string) Str::uuid(),
        'order_id' => $order->id,
        'amount' => $finalAmount,
        'sort_order' => 3,
        'discount_id' => null,
        'discount_type' => null,
        'discount_value' => null,
        'order_service_id' => null,
    ];

    if (!empty($orderTotals)) {
        OrderTotal::insert($orderTotals);
        Log::info("✅ ORDER TOTALS INSERTED", [
            'order_uuid' => $order->uuid,
            'totals_count' => count($orderTotals)
        ]);
    }

    $order->update(['amount' => $finalAmount]);

    Log::info("=== RECALCULATE TOTALS COMPLETED ===", [
        'order_uuid' => $order->uuid,
        'final_amount' => $finalAmount
    ]);

    return ['final_amount' => $finalAmount];
}






/**
 * Process areas
 */
private function processAreas($order, array $incomingAreas, bool $updateInvoice = true): void
{
    $existingAreas = $order->areas->keyBy('uuid');
    $processedUuids = [];

    foreach ($incomingAreas as $area) {
        $data = [
            'type' => $area['type'],
            'footage' => $area['footage'] ?? 0,
            'custom_title' => $area['custom_title'] ?? null,
        ];

        if (!empty($area['uuid']) && $existingAreas->has($area['uuid'])) {
            $existingAreas[$area['uuid']]->update($data);
            $processedUuids[] = $area['uuid'];
        } else {
            $order->areas()->create(array_merge($data, ['uuid' => (string) Str::uuid()]));
        }
    }

    // Remove areas not in incoming request
    foreach ($existingAreas as $existing) {
        if (!in_array($existing->uuid, $processedUuids)) {
            $existing->delete();
        }
    }

    // Recalculate billable square footage, service prices, and sync master/partial invoices if update_invoice is enabled
    if ($updateInvoice) {
        app(\App\Services\AreaPricingService::class)->recalculateOrderForAreaUpdate($order);
    }

    // Automatically sync total square footage to the linked property
    if ($order->property_id) {
        $property = \App\Models\Property::find($order->property_id);
        if ($property) {
            $totalFootage = $order->areas()
                ->where(function ($q) {
                    $q->whereIn('type', ['Finished', 'Subtotal'])
                      ->orWhereNotIn('type', ['Other', 'Garage', 'Patio', 'Balcony', 'Deck', 'Unfinished']);
                })
                ->sum('footage');
            if ($totalFootage > 0) {
                $property->update(['square_footage' => (int) $totalFootage]);
            }
        }
    }
}

/**
 * Check if there are significant changes worth notifying
 */
private function hasSignificantChanges(array $changes): bool
{
    return !empty($changes['services_added']) ||
           !empty($changes['services_removed']) ||
           !empty($changes['services_modified']) ||
           !empty($changes['slots_added']) ||
           !empty($changes['slots_removed']) ||
           !empty($changes['slots_modified']) ||
           $changes['price_changed'] ||
           !empty($changes['package_changed']);
}

/**
 * Create notifications for order update
 * Converts manual change tracking into NotificationService-compatible format
 */
private function createOrderNotification($order, array $changes)
{
    // Build diff in the format NotificationService expects
    $diff = [];

    // Convert services changes to diff format
    if (!empty($changes['services_added']) || !empty($changes['services_removed']) || !empty($changes['services_modified'])) {
        $diff['services'] = [];
        
        // Services added
        foreach ($changes['services_added'] as $added) {
            $diff['services']['added_' . uniqid()] = [
                'before' => null,
                'after' => [
                    'service_name' => $added['service_name'],
                    'amount' => $added['amount'],
                ],
            ];
        }
        
        // Services removed
        foreach ($changes['services_removed'] as $removed) {
            $diff['services']['removed_' . uniqid()] = [
                'before' => [
                    'service_name' => $removed['service_name'],
                    'amount' => $removed['amount'],
                ],
                'after' => null,
            ];
        }
        
        // Services modified
        foreach ($changes['services_modified'] as $modified) {
            $diff['services']['modified_' . uniqid()] = [
                'before' => [
                    'service_name' => $modified['service_name'],
                    'amount' => $modified['old_amount'],
                ],
                'after' => [
                    'service_name' => $modified['service_name'],
                    'amount' => $modified['new_amount'],
                ],
                'changed_fields' => ['amount'],
            ];
        }
    }

    // Convert slots changes to diff format
    if (!empty($changes['slots_added']) || !empty($changes['slots_removed']) || !empty($changes['slots_modified'])) {
        $diff['slots'] = [];
        
        // Slots added
        foreach ($changes['slots_added'] as $added) {
            $diff['slots']['added_' . uniqid()] = [
                'before' => null,
                'after' => [
                    'service_name' => $added['service_name'],
                    'vendor_name' => $added['vendor_name'],
                    'date' => $added['date'],
                    'time' => $added['time'],
                ],
            ];
        }
        
        // Slots removed
        foreach ($changes['slots_removed'] as $removed) {
            $diff['slots']['removed_' . uniqid()] = [
                'before' => [
                    'service_name' => $removed['service_name'],
                    'vendor_name' => $removed['vendor_name'],
                    'date' => $removed['date'],
                    'time' => $removed['time'],
                ],
                'after' => null,
            ];
        }
        
        // Slots modified
        foreach ($changes['slots_modified'] as $modified) {
            $diff['slots']['modified_' . uniqid()] = [
                'before' => $modified,
                'after' => $modified,
                'changed_fields' => ['time'],
            ];
        }
    }

    // Add amount change if price changed
    if (!empty($changes['price_changed']) && $changes['price_changed'] === true) {
        $diff['amount'] = [
            'before' => $changes['old_amount'],
            'after' => $changes['new_amount'],
        ];
    }

    // Add package change if package was added/removed/changed
    if (!empty($changes['package_changed'])) {
        $diff['package'] = [
            'before' => $changes['old_package'],
            'after' => $changes['new_package'],
        ];
        
        // Add context about package change
        if ($changes['new_package'] && !$changes['old_package']) {
            $diff['package']['change_type'] = 'package_applied';
            $diff['package']['message'] = "Package '{$changes['new_package']}' has been automatically applied to this order";
        } elseif (!$changes['new_package'] && $changes['old_package']) {
            $diff['package']['change_type'] = 'package_removed';
            $diff['package']['message'] = "Package '{$changes['old_package']}' has been removed - services no longer match package requirements";
        } else {
            $diff['package']['change_type'] = 'package_changed';
            $diff['package']['message'] = "Package changed from '{$changes['old_package']}' to '{$changes['new_package']}'";
        }
    }

    // Call NotificationService with the constructed diff
    try {
        \App\Services\NotificationService::notifyModelChange(
            $order->fresh(['services', 'slots', 'agent', 'property']),
            'update',
            $diff
        );
        
        Log::info('Order update notification sent', [
            'order_uuid' => $order->uuid,
            'changes' => array_keys($diff),
        ]);
    } catch (\Exception $e) {
        Log::error('Failed to send order update notification', [
            'order_uuid' => $order->uuid,
            'error' => $e->getMessage(),
        ]);
    }
}



    public function destroy($uuid): JsonResponse
    {
        try {
            $order = Order::where('uuid', $uuid)->first();

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'data' => null,
                    'message' => 'Order not found',
                ]);
            }

            $order->delete();

            return response()->json([
                'success' => true,
                'data' => null,
                'message' => 'Order deleted successfully',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Error: ' . $e->getMessage(),
            ]);
        }
    }

    public function IsOrderServiceCompleted(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_uuid' => 'required|exists:orders,uuid',
            'orderservice_uuid' => 'required|exists:order_services,uuid',
            'is_completed' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => $validator->errors(),
            ], 422);
        }

        $order = Order::where('uuid', $request->order_uuid)->first();
        $orderService = OrderService::where('uuid', $request->orderservice_uuid)
                                    ->where('order_id', $order->id)
                                    ->first();

        if (!$orderService) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Order service not found for this order',
            ], 404);
        }

        $orderService->update(['is_completed' => $request->is_completed]);

        return response()->json([
            'success' => true,
            'data' => [
                'is_completed' => $orderService->is_completed,
            ],
            'message' => 'Service marked as completed: ' . ($orderService->is_completed ? 'true' : 'false'),
        ]);
    }

    public function updateOrderServiceMediaAccess(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_uuid' => 'required|exists:orders,uuid',
            'orderservice_uuid' => 'required|exists:order_services,uuid',
            'media_access' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => $validator->errors(),
            ], 422);
        }

        $order = Order::where('uuid', $request->order_uuid)->first();
        $orderService = OrderService::where('uuid', $request->orderservice_uuid)
                                    ->where('order_id', $order->id)
                                    ->first();

        if (!$orderService) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Order service not found for this order',
            ], 404);
        }

        $orderService->update(['media_access' => $request->media_access]);

        return response()->json([
            'success' => true,
            'data' => [
                'orderservice_uuid' => $orderService->uuid,
                'media_access' => $orderService->media_access,
            ],
            'message' => 'Media access updated successfully: ' . ($orderService->media_access ? 'unlocked' : 'locked'),
        ]);
    }


    // public function getOrderTourData($uuid): JsonResponse
    // {
    //     try {
    //         $order = Order::where('uuid', $uuid)->with('property','tours.files','tours.files.service.category','tours.snapshots','agent:id,uuid,first_name,last_name,email,primary_phone,company_name,avatar,company_logo,company_banner,website')->first();

    //         if (!$order) {
    //             return response()->json([
    //                 'success' => false,
    //                 'data' => null,
    //                 'message' => 'Order not found',
    //             ], 404);
    //         }

            

    //         return response()->json([
    //             'success' => true,
    //             'data' => $order,
    //             'message' => 'Tour data retrieved successfully',
    //         ]);
    //     } catch (\Throwable $e) {
    //         return response()->json([
    //             'success' => false,
    //             'data' => null,
    //             'message' => 'Error: ' . $e->getMessage(),
    //         ], 500);
    //     }
    // }
    public function getOrderTourData($uuid): JsonResponse
    {
        try {
            $order = Order::where('uuid', $uuid)
                ->with([
                    'property',
                    'tours',
                    'agent:id,uuid,first_name,last_name,email,primary_phone,company_name,avatar,company_logo,company_banner,website'
                ])
                ->first();

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'data' => null,
                    'message' => 'Order not found',
                ], 404);
            }

            //  Block if any tour is not published
            $hasUnpublishedTour = $order->tours->contains(function ($tour) {
                return !$tour->is_publish;
            });

            if ($hasUnpublishedTour) {
                return response()->json([
                    'success' => false,
                    'data' => null,
                    'message' => 'Tour is not published by its owner or agent yet.',
                ], 403);
            }

            // Load heavy relations ONLY after publish check
            $order->load([
                'tours.files' => function($query) {
                    $query->where('is_hidden', false);
                },
                'tours.files.service.category',
                'tours.snapshots' => function($query) {
                    $query->where('is_hidden', false);
                },
                'tours.links' => function($query) {
                    $query->where('is_hidden', false);
                },
            ]);

            $isPaidOrder = $order->payment_status === 'PAID' || (bool) $order->release_media_before_payment;
            foreach ($order->tours as $tour) {
                if ($tour->links) {
                    $tour->links->transform(function ($link) use ($isPaidOrder) {
                        $isPaid = $link->service_id ? $link->is_paid : ($link->is_paid || $isPaidOrder);
                        if (!$isPaid) {
                            $link->link = null;
                        }
                        return $link;
                    });
                }
                if ($tour->files) {
                    $tour->files->transform(function ($file) use ($isPaidOrder) {
                        $isFloorPlan = $file->type === 'pdf' || 
                            ($file->service && (
                                stripos($file->service->name, 'floor plan') !== false ||
                                stripos($file->service->name, 'floorplan') !== false
                            ));
                        $isPaid = ($file->service_id ? $file->is_paid : ($file->is_paid || $isPaidOrder)) || !empty($file->is_complimentary);
                        if ($isFloorPlan && !$isPaid) {
                            $file->file_path = null;
                            $file->variants = [];
                            $file->variant_urls = null;
                            $file->download_url = null;
                            $file->url = null;
                        }
                        return $file;
                    });
                }
            }

            return response()->json([
                'success' => true,
                'data' => $order,
                'message' => 'Tour data retrieved successfully',
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Something went wrong while fetching tour data',
            ], 500);
        }
    }


    public function getAllOrderSlots(): JsonResponse
    {
        try {
            $today = Carbon::today()->toDateString();

            $slots = OrderSlot::with([ 'service', 'vendor'])
                ->whereDate('date', '>=', $today)
                ->orderBy('date', 'asc')
                ->get();

            $orgId = app()->bound('current_organization_id') ? app('current_organization_id') : null;
            $orgDetails = null;

            if ($slots->isEmpty() && $orgId) {
                $org = \App\Models\Organization::find($orgId);
                if ($org) {
                    $portalSettings = null;
                    try {
                        $portalSettings = app(\App\Services\SettingsService::class)->get($org->uuid, 'portal_settings');
                    } catch (\Throwable $e) {
                        // fallback
                    }

                    if ($portalSettings && ($portalSettings['show_org_details_on_empty_schedule'] ?? false)) {
                        $orgDetails = [
                            'name' => $org->name,
                            'contact_email' => $org->contact_email,
                            'contact_phone' => $org->contact_phone,
                            'address_line_1' => $org->address_line_1,
                            'city' => $org->city,
                            'province' => $org->province,
                        ];
                    }
                }
            }

            return response()->json([
                'success' => true,
                'data' => $slots,
                'org_details' => $orgDetails,
                'message' => 'All order slots retrieved successfully',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function getTwilightWindow(Request $request): JsonResponse
    {
        try {
            $date = $request->query('date', now()->toDateString());
            $propertyId = $request->query('property_id');
            $address = $request->query('address');
            $lat = $request->query('latitude') ? (float)$request->query('latitude') : null;
            $lng = $request->query('longitude') ? (float)$request->query('longitude') : null;

            $timezone = 'America/Vancouver';

            if ($propertyId) {
                $property = Property::where('uuid', $propertyId)->first();
                if ($property && !empty($property->province)) {
                    $timezone = match (strtoupper(trim($property->province))) {
                        'AB', 'ALBERTA' => 'America/Edmonton',
                        'ON', 'ONTARIO', 'QC', 'QUEBEC' => 'America/Toronto',
                        default => 'America/Vancouver',
                    };
                }
            }

            $twilightService = app(\App\Services\TwilightCalculatorService::class);
            $window = $twilightService->calculateSunsetWindow($date, $lat, $lng, $timezone);

            return response()->json([
                'success' => true,
                'data' => $window,
                'message' => 'Twilight window retrieved successfully',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function validateTwilightSlots(?array $slots = [], $property = null): ?JsonResponse
    {
        if (empty($slots)) {
            return null;
        }

        $twilightService = app(\App\Services\TwilightCalculatorService::class);
        $timezone = 'America/Vancouver';
        $lat = null;
        $lng = null;

        if ($property && !empty($property->province)) {
            $timezone = match (strtoupper(trim($property->province))) {
                'AB', 'ALBERTA' => 'America/Edmonton',
                'ON', 'ONTARIO', 'QC', 'QUEBEC' => 'America/Toronto',
                default => 'America/Vancouver',
            };
        }

        foreach ($slots as $slot) {
            if (empty($slot['service_id'])) continue;
            $serviceModel = Service::where('uuid', $slot['service_id'])->first();
            if (!$serviceModel || !$twilightService->isTwilightService($serviceModel)) {
                continue;
            }

            $date = $slot['date'];
            $startTime = Carbon::parse($slot['start_time'])->format('H:i');
            $window = $twilightService->calculateSunsetWindow($date, $lat, $lng, $timezone);

            if (!$twilightService->isSlotInTwilightWindow($startTime, $window)) {
                return response()->json([
                    'success' => false,
                    'message' => "Twilight shoots must be scheduled strictly within the sunset window ({$window['formatted_window']}).",
                ], 422);
            }

            $slotVendor = Vendor::where('uuid', $slot['vendor_id'])->first();
            if ($slotVendor && !$twilightService->isVendorAvailableForTwilight($slotVendor, $date, $window)) {
                return response()->json([
                    'success' => false,
                    'message' => "Vendor {$slotVendor->first_name} {$slotVendor->last_name} is not scheduled to work during evening twilight on this date.",
                ], 422);
            }
        }

        return null;
    }

    /**
     * Get the next available start time for a vendor on a specific date
     * based on their work hours and existing order slots.
     */
    private function getNextAvailableStartTime(Vendor $vendor, $date, $excludeSlotUuid = null): ?string
    {
        $dayOfWeek = strtolower(Carbon::parse($date)->format('D'));
        $workHours = $vendor->workHours;
        
        if (!$workHours || !$workHours->work_days) {
            return null;
        }

        // Find work day config
        $dayConfig = collect($workHours->work_days)->firstWhere('day', $dayOfWeek);
        if (!$dayConfig || ($dayConfig['is_off'] ?? false)) {
            return null;
        }

        $startTime = $dayConfig['start_time'] ?? '09:00';

        // Check existing slots
        $query = OrderSlot::where('vendor_id', $vendor->id)
            ->whereDate('date', $date);
            
        if ($excludeSlotUuid) {
            $query->where('uuid', '!=', $excludeSlotUuid);
        }

        $lastSlot = $query->orderBy('end_time', 'desc')->first();

        if ($lastSlot) {
            return Carbon::parse($lastSlot->end_time)->format('H:i');
        }

        return Carbon::parse($startTime)->format('H:i');
    }

    /**
     * Validate consecutive booking for a vendor on a specific date
     * Returns [true, null] if valid, or [false, nextAvailableTime] if invalid.
     */
    private function validateConsecutiveBooking(Vendor $vendor, $date, array $requestSlots, $excludeOrderUuid = null): array
    {
        $dayOfWeek = strtolower(Carbon::parse($date)->format('D'));
        $workHours = $vendor->workHours;
        
        if (!$workHours || !$workHours->work_days) {
            return [true, null];
        }

        $dayConfig = collect($workHours->work_days)->firstWhere('day', $dayOfWeek);
        if (!$dayConfig || ($dayConfig['is_off'] ?? false)) {
            return [true, null];
        }

        $workStartTime = Carbon::parse($dayConfig['start_time'] ?? '09:00')->format('H:i');

        // Get existing slots from DB
        $dbSlotsQuery = OrderSlot::where('vendor_id', $vendor->id)
            ->whereDate('date', $date);
            
        if ($excludeOrderUuid) {
            $dbSlotsQuery->whereHas('order', function($q) use ($excludeOrderUuid) {
                $q->where('uuid', '!=', $excludeOrderUuid);
            });
        }

        $dbSlots = $dbSlotsQuery->get()->map(fn($s) => [
            'start_time' => Carbon::parse($s->start_time)->format('H:i'),
            'end_time' => Carbon::parse($s->end_time)->format('H:i'),
        ])->toArray();

        // Combine with request slots
        $allSlots = array_merge($dbSlots, $requestSlots);
        
        if (empty($allSlots)) {
            return [true, null];
        }

        // Sort by start time
        usort($allSlots, function($a, $b) {
            return strcmp($a['start_time'], $b['start_time']);
        });

        // If the day was empty, the first slot in the request MUST start at work start time
        if (empty($dbSlots)) {
            if ($allSlots[0]['start_time'] !== $workStartTime) {
                return [false, $workStartTime];
            }
        }

        // Check for gaps
        for ($i = 0; $i < count($allSlots) - 1; $i++) {
            if ($allSlots[$i]['end_time'] !== $allSlots[$i+1]['start_time']) {
                return [false, $allSlots[$i]['end_time']];
            }
        }

        return [true, null];
    }

    /**
     * Filter internal notes out of the order object if the user is not an admin.
     */
    private function filterOrderNotes($order)
    {
        if ($order) {
            $order->notes = $order->filtered_notes;

            $user = auth()->user();
            $isAdmin = $user && ($user instanceof \App\Models\User);
            $isVendor = $user && ($user instanceof \App\Models\Vendor);
            $isPaidOrder = $order->payment_status === 'PAID' || (bool) $order->release_media_before_payment;

            // Protect square footage measurements for unpaid agents when materials are locked
            // Vendors who measure and work on the order, as well as Admins, should always see the areas
            if (!$isAdmin && !$isVendor && !$isPaidOrder && $order->lock_materials) {
                $hasUnpaidFloorPlan = false;
                if ($order->relationLoaded('services')) {
                    foreach ($order->services as $os) {
                        $sName = $os->service ? strtolower($os->service->name) : '';
                        if (str_contains($sName, 'floor plan') || str_contains($sName, 'floorplan')) {
                            if ($os->payment_status !== 'PAID') {
                                $hasUnpaidFloorPlan = true;
                                break;
                            }
                        }
                    }
                }

                if ($hasUnpaidFloorPlan || !$order->relationLoaded('services')) {
                    $order->setRelation('areas', collect([]));
                }
            }
        }
        return $order;
    }

    /**
     * Reassign a single booking slot to a target vendor.
     */
    public function reassignSlot(Request $request, $slotUuid): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'vendor_id' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $slot = is_numeric($slotUuid)
                ? OrderSlot::where('id', $slotUuid)->firstOrFail()
                : OrderSlot::where('uuid', $slotUuid)->firstOrFail();

            $oldVendor = $slot->vendor;
            $oldEventId = $slot->google_event_id;

            // Notify old vendor of cancellation/unassignment
            if ($oldVendor && $oldVendor->email) {
                try {
                    app(\App\Services\EmailDispatchService::class)->dispatch('slot_cancelled', $slot, [
                        'recipients' => [[
                            'email' => $oldVendor->email,
                            'name' => trim($oldVendor->first_name . ' ' . $oldVendor->last_name),
                            'role' => 'vendor',
                            'model' => $oldVendor
                        ]]
                    ]);
                } catch (\Throwable $e) {
                    Log::warning("Failed to dispatch slot_cancelled email for old vendor during reassign: " . $e->getMessage());
                }
            }

            if ($oldVendor && $oldEventId && $oldVendor->google_access_token && class_exists(\App\Services\GoogleCalendarService::class)) {
                try {
                    $calendarService = app(\App\Services\GoogleCalendarService::class);
                    $calendarService->deleteEvent($oldVendor, $oldEventId);
                    $slot->google_event_id = null;
                } catch (\Throwable $e) {
                    Log::warning("Failed to delete google calendar event for old vendor during reassign: " . $e->getMessage());
                }
            }

            $newVendor = is_numeric($request->vendor_id)
                ? Vendor::where('id', $request->vendor_id)->firstOrFail()
                : Vendor::where('uuid', $request->vendor_id)->firstOrFail();

            $slot->vendor_id = $newVendor->id;

            if ($request->has('custom_duration')) {
                $slot->custom_duration = is_numeric($request->custom_duration) ? (int)$request->custom_duration : null;
            }
            if ($request->has('custom_end_time')) {
                $slot->custom_end_time = $request->custom_end_time;
            }
            if ($request->has('buffer_minutes')) {
                $slot->buffer_minutes = is_numeric($request->buffer_minutes) ? (int)$request->buffer_minutes : 0;
            }
            if ($request->filled('start_time')) {
                $slot->start_time = $request->start_time;
            }
            if ($request->filled('end_time')) {
                $slot->end_time = $request->end_time;
            } elseif ($slot->custom_duration && $slot->start_time) {
                try {
                    $slot->end_time = Carbon::parse($slot->start_time)->addMinutes($slot->custom_duration)->format('H:i');
                } catch (\Throwable $e) {
                    // keep existing end_time
                }
            }

            $slot->save();

            // Notify new vendor of booking assignment
            if ($newVendor && $newVendor->email) {
                try {
                    app(\App\Services\EmailDispatchService::class)->dispatch('slot_booked', $slot, [
                        'recipients' => [[
                            'email' => $newVendor->email,
                            'name' => trim($newVendor->first_name . ' ' . $newVendor->last_name),
                            'role' => 'vendor',
                            'model' => $newVendor
                        ]]
                    ]);
                } catch (\Throwable $e) {
                    Log::warning("Failed to dispatch slot_booked email for new vendor during reassign: " . $e->getMessage());
                }
            }

            // Notify agent that their appointment slot has updated with new vendor
            if ($slot->order && $slot->order->agent && $slot->order->agent->email) {
                try {
                    app(\App\Services\EmailDispatchService::class)->dispatch('slot_booked', $slot, [
                        'recipients' => [[
                            'email' => $slot->order->agent->email,
                            'name' => trim($slot->order->agent->first_name . ' ' . $slot->order->agent->last_name),
                            'role' => 'agent',
                            'model' => $slot->order->agent
                        ]]
                    ]);
                } catch (\Throwable $e) {
                    Log::warning("Failed to dispatch slot_booked email for agent during reassign: " . $e->getMessage());
                }
            }

            if (class_exists(SyncOrderCalendarEvents::class) && $slot->order) {
                try {
                    dispatch(new SyncOrderCalendarEvents($slot->order));
                } catch (\Throwable $e) {
                    Log::warning("Failed to dispatch calendar sync on slot reassign: " . $e->getMessage());
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Slot reassigned successfully',
                'data' => $slot->fresh(['vendor', 'service', 'order'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to reassign slot: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Swap vendors between two booking slots.
     */
    public function swapSlots(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'slot1_uuid' => 'required',
                'slot2_uuid' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $slot1 = null;
            $slot2 = null;

            $s1 = is_numeric($request->slot1_uuid)
                ? OrderSlot::where('id', $request->slot1_uuid)->firstOrFail()
                : OrderSlot::where('uuid', $request->slot1_uuid)->firstOrFail();

            $s2 = is_numeric($request->slot2_uuid)
                ? OrderSlot::where('id', $request->slot2_uuid)->firstOrFail()
                : OrderSlot::where('uuid', $request->slot2_uuid)->firstOrFail();

            $vendor1 = $s1->vendor;
            $vendor2 = $s2->vendor;
            $event1Id = $s1->google_event_id;
            $event2Id = $s2->google_event_id;

            // Notify both vendors of cancellation/unassignment
            if ($vendor1 && $vendor1->email) {
                try {
                    app(\App\Services\EmailDispatchService::class)->dispatch('slot_cancelled', $s1, [
                        'recipients' => [[
                            'email' => $vendor1->email,
                            'name' => trim($vendor1->first_name . ' ' . $vendor1->last_name),
                            'role' => 'vendor',
                            'model' => $vendor1
                        ]]
                    ]);
                } catch (\Throwable $e) {
                    Log::warning("Failed to dispatch slot_cancelled email for slot 1 vendor during swap: " . $e->getMessage());
                }
            }

            if ($vendor2 && $vendor2->email) {
                try {
                    app(\App\Services\EmailDispatchService::class)->dispatch('slot_cancelled', $s2, [
                        'recipients' => [[
                            'email' => $vendor2->email,
                            'name' => trim($vendor2->first_name . ' ' . $vendor2->last_name),
                            'role' => 'vendor',
                            'model' => $vendor2
                        ]]
                    ]);
                } catch (\Throwable $e) {
                    Log::warning("Failed to dispatch slot_cancelled email for slot 2 vendor during swap: " . $e->getMessage());
                }
            }

            if (class_exists(\App\Services\GoogleCalendarService::class)) {
                $calendarService = app(\App\Services\GoogleCalendarService::class);

                if ($vendor1 && $event1Id && $vendor1->google_access_token) {
                    try {
                        $calendarService->deleteEvent($vendor1, $event1Id);
                        $s1->google_event_id = null;
                    } catch (\Throwable $e) {
                        Log::warning("Failed to delete google calendar event for slot 1 vendor: " . $e->getMessage());
                    }
                }

                if ($vendor2 && $event2Id && $vendor2->google_access_token) {
                    try {
                        $calendarService->deleteEvent($vendor2, $event2Id);
                        $s2->google_event_id = null;
                    } catch (\Throwable $e) {
                        Log::warning("Failed to delete google calendar event for slot 2 vendor: " . $e->getMessage());
                    }
                }
            }

            DB::transaction(function () use (&$slot1, &$slot2, $s1, $s2, $vendor1, $vendor2) {
                $slot1 = $s1;
                $slot2 = $s2;

                $slot1->vendor_id = $vendor2->id;
                $slot2->vendor_id = $vendor1->id;

                $slot1->save();
                $slot2->save();
            });

            // Notify both vendors of their new bookings
            if ($vendor2 && $vendor2->email) {
                try {
                    app(\App\Services\EmailDispatchService::class)->dispatch('slot_booked', $slot1, [
                        'recipients' => [[
                            'email' => $vendor2->email,
                            'name' => trim($vendor2->first_name . ' ' . $vendor2->last_name),
                            'role' => 'vendor',
                            'model' => $vendor2
                        ]]
                    ]);
                } catch (\Throwable $e) {
                    Log::warning("Failed to dispatch slot_booked email for new slot 1 vendor during swap: " . $e->getMessage());
                }
            }

            if ($vendor1 && $vendor1->email) {
                try {
                    app(\App\Services\EmailDispatchService::class)->dispatch('slot_booked', $slot2, [
                        'recipients' => [[
                            'email' => $vendor1->email,
                            'name' => trim($vendor1->first_name . ' ' . $vendor1->last_name),
                            'role' => 'vendor',
                            'model' => $vendor1
                        ]]
                    ]);
                } catch (\Throwable $e) {
                    Log::warning("Failed to dispatch slot_booked email for new slot 2 vendor during swap: " . $e->getMessage());
                }
            }

            // Notify agent that their slots have updated with the swapped vendors
            if ($slot1->order && $slot1->order->agent && $slot1->order->agent->email) {
                try {
                    app(\App\Services\EmailDispatchService::class)->dispatch('slot_booked', $slot1, [
                        'recipients' => [[
                            'email' => $slot1->order->agent->email,
                            'name' => trim($slot1->order->agent->first_name . ' ' . $slot1->order->agent->last_name),
                            'role' => 'agent',
                            'model' => $slot1->order->agent
                        ]]
                    ]);
                } catch (\Throwable $e) {
                    Log::warning("Failed to dispatch slot_booked email for agent on slot 1 during swap: " . $e->getMessage());
                }
            }

            if ($slot2->order && $slot2->order->agent && $slot2->order->agent->email) {
                try {
                    app(\App\Services\EmailDispatchService::class)->dispatch('slot_booked', $slot2, [
                        'recipients' => [[
                            'email' => $slot2->order->agent->email,
                            'name' => trim($slot2->order->agent->first_name . ' ' . $slot2->order->agent->last_name),
                            'role' => 'agent',
                            'model' => $slot2->order->agent
                        ]]
                    ]);
                } catch (\Throwable $e) {
                    Log::warning("Failed to dispatch slot_booked email for agent on slot 2 during swap: " . $e->getMessage());
                }
            }

            if (class_exists(SyncOrderCalendarEvents::class)) {
                try {
                    if ($slot1 && $slot1->order) dispatch(new SyncOrderCalendarEvents($slot1->order));
                    if ($slot2 && $slot2->order) dispatch(new SyncOrderCalendarEvents($slot2->order));
                } catch (\Throwable $e) {
                    Log::warning("Failed to dispatch calendar sync on slot swap: " . $e->getMessage());
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Slots swapped successfully',
                'data' => [
                    'slot1' => $slot1->fresh(['vendor', 'service', 'order']),
                    'slot2' => $slot2->fresh(['vendor', 'service', 'order']),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to swap slots: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update order slot date, start time, end time, and assigned vendor.
     */
    public function updateSlotTime(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            if (!$user || !($user instanceof \App\Models\User)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Only administrators can update slot times.'
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'order_uuid' => 'required|string|exists:orders,uuid',
                'order_service_id' => 'required|string',
                'service_uuid' => 'required|string|exists:services,uuid',
                'slot_uuid' => 'required|string|exists:order_slots,uuid',
                'vendor_uuid' => 'required|string|exists:vendors,uuid',
                'date' => 'required|date_format:Y-m-d',
                'start_time' => 'required|date_format:H:i:s',
                'end_time' => 'required|date_format:H:i:s',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            // Retrieve order (automatically scoped to current organization via BelongsToOrganization trait)
            $order = Order::where('uuid', $request->order_uuid)->firstOrFail();

            // Retrieve slot and verify it belongs to this order
            $slotQuery = OrderSlot::where('order_id', $order->id);
            if (is_numeric($request->slot_uuid)) {
                $slotQuery->where('id', $request->slot_uuid);
            } else {
                $slotQuery->where('uuid', $request->slot_uuid);
            }
            $slot = $slotQuery->firstOrFail();

            // Retrieve OrderService and verify it belongs to this order
            $orderServiceQuery = OrderService::where('order_id', $order->id);
            if (is_numeric($request->order_service_id)) {
                $orderServiceQuery->where('id', $request->order_service_id);
            } else {
                $orderServiceQuery->where('uuid', $request->order_service_id);
            }
            $orderService = $orderServiceQuery->firstOrFail();

            // Retrieve Service
            $serviceQuery = Service::query();
            if (is_numeric($request->service_uuid)) {
                $serviceQuery->where('id', $request->service_uuid);
            } else {
                $serviceQuery->where('uuid', $request->service_uuid);
            }
            $service = $serviceQuery->firstOrFail();

            // Retrieve Vendor
            $vendorQuery = Vendor::query();
            if (is_numeric($request->vendor_uuid)) {
                $vendorQuery->where('id', $request->vendor_uuid);
            } else {
                $vendorQuery->where('uuid', $request->vendor_uuid);
            }
            $vendor = $vendorQuery->firstOrFail();

            // Check that the OrderService matches the Service specified
            if ($orderService->service_id !== $service->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'The order service does not match the service specified.'
                ], 400);
            }

            // Update the Slot
            $slot->vendor_id = $vendor->id;
            $slot->service_id = $service->id;
            $slot->date = $request->date;
            $slot->start_time = $request->start_time;
            $slot->end_time = $request->end_time;
            $slot->save();

            // Update the OrderService
            $orderService->vendor_id = $vendor->uuid;
            $orderService->service_id = $service->id;
            $orderService->save();

            // Trigger calendar sync
            if (class_exists(SyncOrderCalendarEvents::class)) {
                try {
                    dispatch(new SyncOrderCalendarEvents($order->id));
                } catch (\Throwable $e) {
                    Log::warning("Failed to dispatch calendar sync on updateSlotTime: " . $e->getMessage());
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Order slot and service updated successfully',
                'data' => [
                    'slot' => $slot->fresh(['vendor', 'service', 'order']),
                    'order_service' => $orderService->fresh(['vendor', 'service', 'order'])
                ]
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Resource not found or access denied.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update slot time: ' . $e->getMessage()
            ], 500);
        }
    }
}

