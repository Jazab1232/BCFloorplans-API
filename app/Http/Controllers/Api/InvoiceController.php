<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Agent;
use App\Models\OrderService;
use App\Models\AgentPayment;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;
use Illuminate\Support\Str;
use App\Models\Role;
use Carbon\Carbon;

class InvoiceController extends Controller
{
    // Canadian Tax logic by Province abbreviation
    public function getCanadaTaxRate($province)
    {
        $prov = strtoupper(trim($province));
        switch ($prov) {
            case 'ON':
            case 'ONTARIO':
                return ['rate' => 13.0, 'name' => 'HST'];
            case 'NB':
            case 'NEW BRUNSWICK':
            case 'NL':
            case 'NEWFOUNDLAND':
            case 'NS':
            case 'NOVA SCOTIA':
            case 'PE':
            case 'PRINCE EDWARD ISLAND':
                return ['rate' => 15.0, 'name' => 'HST'];
            default:
                // AB, BC, MB, QC, SK, NT, NU, YT (5% GST minimum)
                return ['rate' => 5.0, 'name' => 'GST'];
        }
    }

    /**
     * Resolve effective GST and PST flags for a service in an organization context.
     */
    public static function resolveServiceTaxFlags($service, $orgId = null): array
    {
        if (!$service) {
            return [true, false]; // Default: GST enabled, PST disabled
        }

        $gstEnabled = (bool) ($service->gst_enabled ?? true);
        $pstEnabled = (bool) ($service->pst_enabled ?? false);

        if ($orgId && isset($service->id)) {
            $orgOverride = \App\Models\OrganizationService::where('organization_id', $orgId)
                ->where('service_id', $service->id)
                ->first();

            if ($orgOverride) {
                if ($orgOverride->gst_enabled !== null) {
                    $gstEnabled = (bool) $orgOverride->gst_enabled;
                }
                if ($orgOverride->pst_enabled !== null) {
                    $pstEnabled = (bool) $orgOverride->pst_enabled;
                }
            }
        }

        return [$gstEnabled, $pstEnabled];
    }

    /**
     * Calculate Canadian Place-of-Supply tax breakdown for a specific line item amount based on property province.
     */
    public static function calculateLineItemTax($province, $amount, $gstEnabled = true, $pstEnabled = false): array
    {
        $prov = strtoupper(trim((string) $province));
        $amount = (float) $amount;

        $gstRate = 0.0;
        $pstRate = 0.0;
        $hstRate = 0.0;

        $gstAmount = 0.0;
        $pstAmount = 0.0;
        $hstAmount = 0.0;

        $taxType = 'GST';

        switch ($prov) {
            case 'ON':
            case 'ONTARIO':
                if ($gstEnabled) {
                    $hstRate = 13.0;
                    $hstAmount = round($amount * 0.13, 2);
                }
                $taxType = 'HST';
                break;

            case 'NB':
            case 'NEW BRUNSWICK':
            case 'NL':
            case 'NEWFOUNDLAND':
            case 'NS':
            case 'NOVA SCOTIA':
            case 'PE':
            case 'PRINCE EDWARD ISLAND':
                if ($gstEnabled) {
                    $hstRate = 15.0;
                    $hstAmount = round($amount * 0.15, 2);
                }
                $taxType = 'HST';
                break;

            case 'BC':
            case 'BRITISH COLUMBIA':
                if ($gstEnabled) {
                    $gstRate = 5.0;
                    $gstAmount = round($amount * 0.05, 2);
                }
                if ($pstEnabled) {
                    $pstRate = 7.0;
                    $pstAmount = round($amount * 0.07, 2);
                }
                $taxType = ($gstEnabled && $pstEnabled) ? 'GST + PST' : ($pstEnabled ? 'PST' : 'GST');
                break;

            case 'SK':
            case 'SASKATCHEWAN':
                if ($gstEnabled) {
                    $gstRate = 5.0;
                    $gstAmount = round($amount * 0.05, 2);
                }
                if ($pstEnabled) {
                    $pstRate = 6.0;
                    $pstAmount = round($amount * 0.06, 2);
                }
                $taxType = ($gstEnabled && $pstEnabled) ? 'GST + PST' : ($pstEnabled ? 'PST' : 'GST');
                break;

            case 'MB':
            case 'MANITOBA':
                if ($gstEnabled) {
                    $gstRate = 5.0;
                    $gstAmount = round($amount * 0.05, 2);
                }
                if ($pstEnabled) {
                    $pstRate = 7.0; // RST
                    $pstAmount = round($amount * 0.07, 2);
                }
                $taxType = ($gstEnabled && $pstEnabled) ? 'GST + RST' : ($pstEnabled ? 'RST' : 'GST');
                break;

            case 'QC':
            case 'QUEBEC':
                if ($gstEnabled) {
                    $gstRate = 5.0;
                    $gstAmount = round($amount * 0.05, 2);
                }
                if ($pstEnabled) {
                    $pstRate = 9.975; // QST
                    $pstAmount = round($amount * 0.09975, 2);
                }
                $taxType = ($gstEnabled && $pstEnabled) ? 'GST + QST' : ($pstEnabled ? 'QST' : 'GST');
                break;

            default:
                // AB, NT, NU, YT (GST 5% only)
                if ($gstEnabled) {
                    $gstRate = 5.0;
                    $gstAmount = round($amount * 0.05, 2);
                }
                $taxType = 'GST';
                break;
        }

        $totalTaxAmount = round($gstAmount + $pstAmount + $hstAmount, 2);
        $totalTaxRate = $gstRate + $pstRate + $hstRate;

        return [
            'gst_rate' => $gstRate,
            'pst_rate' => $pstRate,
            'hst_rate' => $hstRate,
            'total_tax_rate' => $totalTaxRate,
            'gst_amount' => $gstAmount,
            'pst_amount' => $pstAmount,
            'hst_amount' => $hstAmount,
            'total_tax_amount' => $totalTaxAmount,
            'tax_type' => $taxType,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $user   = Auth::user();
            $isAgent = $user instanceof Agent;

            $query = Invoice::with([
                'order:id,uuid,property_id,order_status,payment_status',
                'order.property:id,address,city,province,postal_code',
                'agent:id,uuid,first_name,last_name,email',
                'items',
                'items.orderService:id,uuid,service_id,amount',
                'items.orderService.service:id,name',
                'payments:id,uuid,amount,status,paid_at,stripe_receipt_url,payment_method',
            ]);

            if ($isAgent) {
                $query->where(function($q) use ($user) {
                    $q->where('agent_id', $user->id)
                      ->orWhereHas('order', function($oq) use ($user) {
                          $oq->where('agent_id', $user->id);
                      });
                });
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
            if ($request->filled('order_uuid')) {
                $order = Order::where('uuid', $request->order_uuid)->first();
                if ($order) $query->where('order_id', $order->id);
            }

            $invoices = $query->orderByDesc('created_at')->get();

            return response()->json([
                'success' => true,
                'data'    => $invoices,
            ]);
        } catch (\Throwable $e) {
            Log::error('InvoiceController@index error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function show(string $uuid): JsonResponse
    {
        try {
            $invoice = Invoice::with([
                'order:id,uuid,property_id,order_status,payment_status,amount,created_at',
                'order.property:id,address,suite,city,province,postal_code,country',
                'agent:id,uuid,first_name,last_name,email',
                'items',
                'items.orderService:id,uuid,service_id,amount',
                'items.orderService.service:id,name',
                'payments:id,uuid,amount,status,paid_at,stripe_receipt_url,payment_method',
            ])->where('uuid', $uuid)->firstOrFail();

            $this->authorizeInvoiceAccess($invoice);

            return response()->json([
                'success' => true,
                'data'    => $invoice,
            ]);
        } catch (\Throwable $e) {
            Log::error('InvoiceController@show error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'order_uuid'             => 'required|exists:orders,uuid',
            'service_uuids'          => 'nullable|array',
            'service_uuids.*'        => 'string|exists:order_services,uuid',
            'due_date'               => 'nullable|date',
            'notes'                  => 'nullable|string',
            'tax_rate_override'      => 'nullable|numeric|min:0|max:100',
            'items'                  => 'nullable|array',
            'items.*.description'    => 'required_with:items|string',
            'items.*.quantity'       => 'nullable|integer|min:1',
            'items.*.unit_price'     => 'required_with:items|numeric|min:0',
            'items.*.order_service_uuid' => 'nullable|string|exists:order_services,uuid',
            'split_details'          => 'nullable|array',
        ]);

        try {
            DB::beginTransaction();
            $user  = Auth::user();
            $order = Order::where('uuid', $request->order_uuid)
                ->with(['services.service', 'agent', 'property'])
                ->firstOrFail();

            $agent = $order->agent;
            $lineItems = [];

            if ($request->filled('items')) {
                foreach ($request->items as $item) {
                    $qty       = $item['quantity'] ?? 1;
                    $unitPrice = (float) $item['unit_price'];
                    $osId      = null;

                    if (!empty($item['order_service_uuid'])) {
                        $os   = OrderService::where('uuid', $item['order_service_uuid'])->first();
                        $osId = $os?->id;
                    }

                    $lineItems[] = [
                        'order_service_id' => $osId,
                        'description'      => $item['description'],
                        'quantity'         => $qty,
                        'unit_price'       => $unitPrice,
                        'amount'           => $qty * $unitPrice,
                    ];
                }
            } else {
                $serviceUuids  = $request->service_uuids ?? null;
                $servicesQuery = $order->services()->with('service');

                if ($serviceUuids) {
                    $servicesQuery->whereIn('uuid', $serviceUuids);
                }

                $orderServices = $servicesQuery->get();
                if ($orderServices->isEmpty()) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => 'No services found to invoice.'], 422);
                }

                foreach ($orderServices as $os) {
                    $addOns = is_array($os->add_ons) ? $os->add_ons : [];
                    $addOnTotal = collect($addOns)->sum(fn($a) => (float)($a['amount'] ?? 0));
                    $baseAmount = max(0, (float) $os->amount - $addOnTotal);
                    $serviceName = $os->service->name ?? 'Service';

                    $lineItems[] = [
                        'order_service_id' => $os->id,
                        'description'      => $serviceName,
                        'quantity'         => 1,
                        'unit_price'       => $baseAmount,
                        'amount'           => $baseAmount,
                    ];

                    foreach ($addOns as $addOn) {
                        $addOnTitle = $addOn['title'] ?? 'Add-On';
                        $addOnAmount = (float)($addOn['amount'] ?? 0);
                        $lineItems[] = [
                            'order_service_id' => $os->id,
                            'description'      => "{$serviceName} - Add On - {$addOnTitle}",
                            'quantity'         => 1,
                            'unit_price'       => $addOnAmount,
                            'amount'           => $addOnAmount,
                        ];
                    }
                }
            }

            // Calculate Canada Tax Rates
            $province = $agent->headquarter_province ?? $order->property->province ?? 'ON';
            $taxInfo = $this->getCanadaTaxRate($province);

            $taxRate = $request->filled('tax_rate_override') ? (float) $request->tax_rate_override : $taxInfo['rate'];
            
            $subtotal  = collect($lineItems)->sum('amount');
            $taxAmount = $taxRate > 0 ? round($subtotal * ($taxRate / 100), 2) : 0;
            $total     = $subtotal + $taxAmount;

            $taxDetails = [
                $taxInfo['name'] => [
                    'rate' => $taxRate,
                    'amount' => $taxAmount
                ]
            ];

            $splitDetails = $request->split_details;
            $createdInvoices = [];

            if (!empty($splitDetails)) {
                $splitGroupId = (string) Str::uuid();
                
                // 1. Resolve agents and calculate shares
                $resolvedSplits = [];
                foreach ($splitDetails as $split) {
                    $targetAgentId = null;
                    if ($split['type'] === 'primary') {
                        $targetAgentId = $agent->id;
                    } else {
                        // Co-agent: Find or Create
                        $coAgent = Agent::where('email', $split['email'])->first();
                        if (!$coAgent) {
                            $roleId = Role::whereRaw('LOWER(name) LIKE ?', ['agent%'])->value('id');
                            // Split name into first/last if possible
                            $nameParts = explode(' ', $split['name'] ?? 'Co Agent', 2);
                            $firstName = $nameParts[0];
                            $lastName = $nameParts[1] ?? '';
                            
                            $coAgent = Agent::create([
                                'uuid' => (string) Str::uuid(),
                                'organization_id' => $agent->organization_id,
                                'first_name' => $firstName,
                                'last_name' => $lastName,
                                'email' => $split['email'],
                                'password' => bcrypt(Str::random(16)),
                                'role_id' => $roleId,
                                'status' => true,
                                'requires_payment' => true,
                                'payment_status' => 'GOOD',
                                'company_name' => $agent->company_name,
                            ]);
                        }
                        $targetAgentId = $coAgent->id;
                    }
                    
                    $resolvedSplits[] = [
                        'agent_id' => $targetAgentId,
                        'percentage' => (float)($split['percentage'] ?? 0),
                        'name' => $split['name'] ?? '',
                        'email' => $split['email'] ?? '',
                        'type' => $split['type'] ?? 'co-agent'
                    ];
                }

                // 2. Create invoices for each split
                foreach ($resolvedSplits as $split) {
                    $multiplier = $split['percentage'] / 100;
                    if ($multiplier <= 0) continue;

                    $sSubtotal = round($subtotal * $multiplier, 2);
                    $sTaxAmount = round($taxAmount * $multiplier, 2);
                    $sTotal = $sSubtotal + $sTaxAmount;

                    $invoice = Invoice::create([
                        'order_id'      => $order->id,
                        'agent_id'      => $split['agent_id'],
                        'status'        => $sTotal <= 0.0 ? 'paid' : 'issued',
                        'subtotal'      => $sSubtotal,
                        'tax_rate'      => $taxRate,
                        'tax_amount'    => $sTaxAmount,
                        'tax_details'   => [
                            $taxInfo['name'] => [
                                'rate' => $taxRate,
                                'amount' => $sTaxAmount
                            ]
                        ],
                        'total'         => $sTotal,
                        'paid_amount'   => 0,
                        'currency'      => 'cad',
                        'due_date'      => $request->due_date ? Carbon::parse($request->due_date) : null,
                        'issued_at'     => now(),
                        'paid_at'       => $sTotal <= 0.0 ? now() : null,
                        'notes'         => $request->notes . " (Split: {$split['percentage']}%)",
                        'split_details' => [
                            'group_id' => $splitGroupId,
                            'splits'   => $resolvedSplits
                        ],
                        'agent_type'    => $split['type'] ?? 'co-agent',
                        'created_by'    => $user->uuid ?? null,
                    ]);

                    foreach ($lineItems as $item) {
                        InvoiceItem::create([
                            'invoice_id' => $invoice->id,
                            'order_service_id' => $item['order_service_id'],
                            'description' => $item['description'] . " ({$split['percentage']}%)",
                            'quantity' => $item['quantity'],
                            'unit_price' => round($item['unit_price'] * $multiplier, 2),
                            'amount' => round($item['amount'] * $multiplier, 2),
                        ]);
                    }
                    $createdInvoices[] = $invoice;
                }
                
                DB::commit();
                
                // Load relationships for all created invoices
                foreach ($createdInvoices as $inv) {
                    $inv->load(['items', 'items.orderService.service', 'agent:id,uuid,first_name,last_name,email', 'order:id,uuid']);
                }

                return response()->json([
                    'success' => true,
                    'data'    => $createdInvoices,
                    'message' => count($createdInvoices) . ' split invoices created successfully.',
                ], 201);

            } else {
                $invoice = Invoice::create([
                    'order_id'      => $order->id,
                    'agent_id'      => $agent->id,
                    'status'        => $total <= 0.0 ? 'paid' : 'issued',
                    'subtotal'      => $subtotal,
                    'tax_rate'      => $taxRate,
                    'tax_amount'    => $taxAmount,
                    'tax_details'   => $taxDetails,
                    'total'         => $total,
                    'paid_amount'   => 0,
                    'currency'      => 'cad',
                    'due_date'      => $request->due_date ? Carbon::parse($request->due_date) : null,
                    'issued_at'     => now(),
                    'paid_at'       => $total <= 0.0 ? now() : null,
                    'notes'         => $request->notes,
                    'split_details' => $request->split_details,
                    'agent_type'    => 'primary',
                    'created_by'    => $user->uuid ?? null,
                ]);

                foreach ($lineItems as $item) {
                    InvoiceItem::create(array_merge($item, ['invoice_id' => $invoice->id]));
                }

                DB::commit();

                $invoice->load(['items', 'items.orderService.service', 'agent:id,uuid', 'order:id,uuid']);

                return response()->json([
                    'success' => true,
                    'data'    => $invoice,
                    'message' => 'Invoice created successfully.',
                ], 201);
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('InvoiceController@store error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $request->validate([
            'due_date'                   => 'nullable|date',
            'notes'                      => 'nullable|string',
            'status'                     => 'nullable|string',
            'split_details'              => 'nullable|array',
            'tax_rate_override'          => 'nullable|numeric|min:0|max:100',
            'items'                      => 'nullable|array',
            'items.*.uuid'               => 'nullable|string',
            'items.*.description'        => 'required_with:items|string',
            'items.*.quantity'           => 'nullable|numeric|min:0.01',
            'items.*.unit_price'         => 'required_with:items|numeric|min:0',
            'items.*.gst_enabled'        => 'nullable|boolean',
            'items.*.pst_enabled'        => 'nullable|boolean',
            'items.*.order_service_uuid' => 'nullable|string|exists:order_services,uuid',
            'items.*.order_service_id'   => 'nullable',
        ]);

        try {
            DB::beginTransaction();
            $invoice = Invoice::where('uuid', $uuid)->firstOrFail();
            $this->authorizeInvoiceAccess($invoice);

            if (in_array($invoice->status, ['paid', 'void', 'refunded']) && (float)$invoice->total > 0.0) {
                return response()->json([
                    'success' => false,
                    'message' => "Cannot edit an invoice with status '{$invoice->status}'. Paid, voided, or refunded invoices cannot be modified."
                ], 422);
            }

            // Update scalar fields
            if ($request->has('due_date')) $invoice->due_date = $request->due_date ? Carbon::parse($request->due_date) : null;
            if ($request->has('notes')) $invoice->notes = $request->notes;
            if ($request->has('split_details')) $invoice->split_details = $request->split_details;
            if ($request->has('status') && !in_array($request->status, ['paid', 'void', 'refunded'])) {
                $invoice->status = $request->status;
            }

            $invoice->save();

            // Reconcile items if provided
            if ($request->has('items')) {
                $itemUuidsSent = collect($request->items)->pluck('uuid')->filter()->toArray();
                
                // Delete items NOT sent and NOT matched by UUID
                $invoice->items()->whereNotIn('uuid', $itemUuidsSent)->delete();

                foreach ($request->items as $itemData) {
                    $qty = isset($itemData['quantity']) ? (float)$itemData['quantity'] : 1;
                    $price = (float)$itemData['unit_price'];
                    $amount = round($qty * $price, 2);
                    $gstEnabled = isset($itemData['gst_enabled']) ? (bool)$itemData['gst_enabled'] : true;
                    $pstEnabled = isset($itemData['pst_enabled']) ? (bool)$itemData['pst_enabled'] : false;
                    
                    $osId = null;
                    if (!empty($itemData['order_service_uuid'])) {
                        $os = OrderService::where('uuid', $itemData['order_service_uuid'])->first();
                        $osId = $os?->id;
                    } elseif (!empty($itemData['order_service_id'])) {
                        $osId = is_numeric($itemData['order_service_id']) ? (int)$itemData['order_service_id'] : OrderService::where('uuid', $itemData['order_service_id'])->value('id');
                    }

                    $itemPayload = [
                        'description'      => $itemData['description'],
                        'quantity'         => $qty,
                        'unit_price'       => $price,
                        'amount'           => $amount,
                        'gst_enabled'      => $gstEnabled,
                        'pst_enabled'      => $pstEnabled,
                        'order_service_id' => $osId,
                    ];

                    if (!empty($itemData['uuid']) && $invoice->items()->where('uuid', $itemData['uuid'])->exists()) {
                        // Update existing
                        $invoice->items()->where('uuid', $itemData['uuid'])->update($itemPayload);
                    } else {
                        // Create new
                        $invoice->items()->create($itemPayload);
                    }
                }

                // Refresh model and recalculate totals with Canadian Place-of-Supply tax breakdown
                $invoice->refresh();
                $invoice->recalculateTotals();

                // Sync status based on new totals and paid amounts
                if ($invoice->order) {
                    Invoice::syncOrderStatus($invoice->order);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'data'    => $invoice->fresh([
                    'order:id,uuid,property_id,order_status,payment_status,amount,created_at',
                    'order.property:id,address,suite,city,province,postal_code,country',
                    'agent:id,uuid,first_name,last_name,email',
                    'items',
                    'items.orderService:id,uuid,service_id,amount',
                    'items.orderService.service:id,name',
                    'payments:id,uuid,amount,status,paid_at,stripe_receipt_url,payment_method',
                ]),
                'message' => 'Invoice updated successfully.',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('InvoiceController@update error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function syncExtraItems(Request $request, string $uuid): JsonResponse
    {
        $request->validate([
            'extra_items' => 'present|array',
            'extra_items.*.description' => 'required_with:extra_items|string',
            'extra_items.*.quantity' => 'nullable|integer|min:1',
            'extra_items.*.unit_price' => 'required_with:extra_items|numeric|min:0',
            'extra_items.*.order_service_id' => 'required_with:extra_items|exists:order_services,id',
        ]);

        try {
            DB::beginTransaction();

            $targetInvoice = Invoice::where('uuid', $uuid)->firstOrFail();
            $this->authorizeInvoiceAccess($targetInvoice);

            if (!in_array($targetInvoice->status, ['draft', 'issued'])) {
                return response()->json(['success' => false, 'message' => "Cannot modify a {$targetInvoice->status} invoice."], 422);
            }

            // Find all draft/issued invoices for this order to keep them in sync
            $invoices = Invoice::where('order_id', $targetInvoice->order_id)
                ->whereIn('status', ['draft', 'issued'])
                ->get();

            foreach ($invoices as $invoice) {
                // Determine multiplier if it's a split invoice
                $multiplier = 1.0;
                if (!empty($invoice->split_details['splits'])) {
                    foreach ($invoice->split_details['splits'] as $split) {
                        if ($split['agent_id'] == $invoice->agent_id) {
                            $multiplier = (float)$split['percentage'] / 100;
                            break;
                        }
                    }
                }

                $isServiceInvoice = str_contains($invoice->notes ?? '', 'Service Invoice:');
                $allowedServiceIds = [];
                if ($isServiceInvoice) {
                    $allowedServiceIds = $invoice->items()->where('is_extra', false)->pluck('order_service_id')->toArray();
                }

                // Clear old extra items
                $invoice->items()->where('is_extra', true)->delete();

                // Insert new extra items
                foreach ($request->extra_items as $itemData) {
                    $osId = $itemData['order_service_id'] ?? null;
                    
                    // Skip if this is a service-specific invoice and the extra item doesn't belong to its service
                    if ($isServiceInvoice && $osId && !in_array($osId, $allowedServiceIds)) {
                        continue;
                    }

                    $qty = $itemData['quantity'] ?? 1;
                    $unitPrice = round((float)$itemData['unit_price'] * $multiplier, 2);

                    $invoice->items()->create([
                        'description' => $itemData['description'] . ($multiplier < 1 ? " (" . ($multiplier * 100) . "%)" : ""),
                        'quantity' => $qty,
                        'unit_price' => $unitPrice,
                        'amount' => $qty * $unitPrice,
                        'order_service_id' => $osId,
                        'is_extra' => true
                    ]);
                }

                $invoice->recalculateTotals();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'data'    => $targetInvoice->fresh()->load('items'),
                'message' => 'Extra items synced successfully.',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('InvoiceController@syncExtraItems error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function void(string $uuid): JsonResponse
    {
        try {
            $invoice = Invoice::where('uuid', $uuid)->firstOrFail();
            if (in_array($invoice->status, ['paid', 'void', 'refunded'])) {
                return response()->json(['success' => false, 'message' => "Cannot void a {$invoice->status} invoice."], 422);
            }
            DB::beginTransaction();
            $invoice->update(['status' => 'void']);

            // Reversal logic for split invoices
            if (isset($invoice->split_details['group_id'])) {
                Invoice::where('order_id', $invoice->order_id)
                    ->where('id', '!=', $invoice->id)
                    ->where('split_details->group_id', $invoice->split_details['group_id'])
                    ->whereIn('status', ['issued', 'draft']) // Only void unpaid ones
                    ->update(['status' => 'void']);
            }

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Invoice voided.', 'data' => $invoice]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function markPaid(Request $request, string $uuid): JsonResponse
    {
        $request->validate([
            'amount'         => 'required|numeric|min:0.01',
            'payment_method' => 'nullable|string',
            'notes'          => 'nullable|string',
            'payment_mode'   => 'nullable|string|in:on_behalf,self', // Split invoice tracking
            'payer_uuid'     => 'nullable|string',                   // Who is actually paying
        ]);

        try {
            $invoice = Invoice::where('uuid', $uuid)->with('items.orderService')->firstOrFail();

            if (in_array($invoice->status, ['void', 'refunded'])) {
                return response()->json(['success' => false, 'message' => "Cannot record payment for {$invoice->status} invoice."], 422);
            }

            DB::beginTransaction();

            $amount = (float) $request->amount;
            $paymentMode = $request->payment_mode;
            $payerAgentId = $request->payer_uuid
                ? Agent::where('uuid', $request->payer_uuid)->value('id')
                : null;

            // Determine agent_id based on payment mode
            // 'self' = payer takes ownership (agent's money)
            // 'on_behalf' or null = invoice owner's expense (co-agent's money)
            $effectiveAgentId = $invoice->agent_id;
            if ($paymentMode === 'self' && $payerAgentId) {
                $effectiveAgentId = $payerAgentId;
            }

            $authUser = auth()->user();
            $creatorName = $authUser ? trim($authUser->first_name . ' ' . $authUser->last_name) : 'Admin';
            $creatorEmail = $authUser ? $authUser->email : null;
            $creatorUuid = $authUser ? $authUser->uuid : null;
            $isPaidByAdmin = $authUser && !($authUser instanceof Agent);

            // Determine if payment is for a specific service or full order
            $paymentType = 'full';
            $serviceUuid = null;
            $orderServiceId = null;
            if ($invoice->items->count() === 1 && $invoice->items->first()->order_service_id) {
                $paymentType = 'service';
                $orderServiceId = $invoice->items->first()->order_service_id;
                $serviceUuid = $invoice->items->first()->orderService?->uuid;
            }

            $payment = AgentPayment::create([
                'agent_id'         => $effectiveAgentId,
                'paid_by_agent_id' => $payerAgentId,
                'order_id'         => $invoice->order_id,
                'invoice_id'       => $invoice->id,
                'order_service_id' => $orderServiceId,
                'amount'           => $amount,
                'currency'         => $invoice->currency,
                'status'           => 'succeeded',
                'payment_method'   => $request->payment_method ?? 'manual',
                'payment_type'     => $paymentType,
                'payment_mode'     => $paymentMode,
                'paid_at'          => now(),
                'meta'             => [
                    'type'          => 'manual_payment',
                    'notes'         => $request->notes ?? null,
                    'creator_uuid'  => $creatorUuid,
                    'creator_name'  => $creatorName,
                    'creator_email' => $creatorEmail,
                    'paid_by_admin' => $isPaidByAdmin,
                ],
            ]);

            $newPaid = (float) $invoice->paid_amount + $amount;
            $isFullyPaid = $newPaid >= (float) $invoice->total;
            
            $invoice->update([
                'paid_amount' => $newPaid,
                'status'      => $isFullyPaid ? 'paid' : 'partially_paid',
                'paid_at'     => $isFullyPaid ? now() : $invoice->paid_at,
            ]);

            // Sync with Order, in-portal notifications, email notifications, and other invoices
            \App\Http\Controllers\Api\StripeAgentWebhookController::updateOrderAfterPayment(
                $invoice->order_id,
                $amount,
                $paymentType,
                $serviceUuid,
                $payment
            );

            DB::commit();

            // Dispatch QuickBooks Sync
            \App\Jobs\SyncInvoiceToQuickBooks::dispatch($invoice->id);

            return response()->json(['success' => true, 'message' => 'Payment recorded.', 'data' => $invoice->fresh(['payments', 'items'])]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function refund(Request $request, string $uuid): JsonResponse
    {
        $request->validate([
            'amount' => 'required_without:items|numeric|min:0.01',
            'notes' => 'nullable|string',
            'items' => 'nullable|array',
            'items.*.description' => 'required_with:items|string',
            'items.*.amount' => 'required_with:items|numeric|min:0',
            'items.*.tax_amount' => 'nullable|numeric|min:0',
            'items.*.quantity' => 'nullable|integer|min:1',
            'items.*.unit_price' => 'nullable|numeric',
            'items.*.order_service_id' => 'nullable|integer',
            'items.*.revoke_media_access' => 'nullable|boolean',
            'revoke_media_access' => 'nullable|boolean',
        ]);

        try {
            $revokeMediaAccess = $request->has('revoke_media_access')
                ? filter_var($request->input('revoke_media_access'), FILTER_VALIDATE_BOOLEAN)
                : true;

            $invoice = Invoice::where('uuid', $uuid)->with(['payments', 'items.orderService', 'agent', 'order.property'])->firstOrFail();

            if (in_array($invoice->status, ['draft', 'issued', 'void'])) {
                return response()->json(['success' => false, 'message' => "Cannot refund a {$invoice->status} invoice."], 422);
            }

            $refundItems = $request->input('items', []);
            $calculatedSubtotal = 0;
            $calculatedTax = 0;

            if (!empty($refundItems)) {
                foreach ($refundItems as $ri) {
                    $calculatedSubtotal += (float) ($ri['amount'] ?? 0);
                    $calculatedTax += (float) ($ri['tax_amount'] ?? 0);
                }
                $refundAmount = round($calculatedSubtotal + $calculatedTax, 2);
            } else {
                $refundAmount = (float) $request->amount;
            }

            $refundableBalance = round((float)$invoice->paid_amount - (float)$invoice->refunded_amount, 2);
            if ($refundAmount > $refundableBalance) {
                return response()->json(['success' => false, 'message' => "Refund amount (\${$refundAmount}) exceeds refundable balance (\${$refundableBalance})."], 422);
            }

            DB::beginTransaction();

            // Try to find a Stripe payment to refund against
            $stripePayment = $invoice->payments->whereNotNull('stripe_payment_intent_id')->where('status', 'succeeded')->first();
            $refundMethod = 'manual';
            $stripeRefundId = null;

            if ($stripePayment) {
                try {
                    $stripe = new StripeClient(config('services.stripe.secret'));
                    $refund = $stripe->refunds->create([
                        'payment_intent' => $stripePayment->stripe_payment_intent_id,
                        'amount' => intval($refundAmount * 100),
                        'metadata' => ['invoice_uuid' => $invoice->uuid, 'notes' => $request->notes]
                    ]);
                    $refundMethod = 'stripe';
                    $stripeRefundId = $refund->id;
                } catch (\Exception $e) {
                    DB::rollBack();
                    Log::error('Stripe Refund error: ' . $e->getMessage());
                    return response()->json(['success' => false, 'message' => 'Stripe error: ' . $e->getMessage()], 500);
                }
            }

            // Create negative payment record for accounting/QuickBooks sync
            $refundPayment = AgentPayment::create([
                'agent_id'       => $invoice->agent_id,
                'order_id'       => $invoice->order_id,
                'invoice_id'     => $invoice->id,
                'amount'         => -$refundAmount, // Negative amount for refund
                'currency'       => $invoice->currency,
                'status'         => 'refunded',
                'payment_method' => $refundMethod,
                'payment_type'   => 'refund',
                'paid_at'        => now(),
                'meta'           => [
                    'type'             => 'refund',
                    'notes'            => $request->notes,
                    'stripe_refund_id' => $stripeRefundId,
                    'refund_items'     => $refundItems,
                    'subtotal'         => $calculatedSubtotal,
                    'tax_amount'       => $calculatedTax,
                    'tax_rate'         => $invoice->tax_rate,
                ],
            ]);

            $newRefunded = round((float) $invoice->refunded_amount + $refundAmount, 2);
            $remainingPaid = round((float) $invoice->paid_amount - $newRefunded, 2);

            $invoice->update([
                'refunded_amount' => $newRefunded,
                'status' => $remainingPaid <= 0 ? 'refunded' : 'partially_refunded'
            ]);

            // Update order services and their media_access
            if ($remainingPaid > 0) {
                $updatedAnyService = false;
                if (!empty($refundItems)) {
                    foreach ($refundItems as $ri) {
                        if (!empty($ri['order_service_id'])) {
                            $itemRevoke = isset($ri['revoke_media_access'])
                                ? filter_var($ri['revoke_media_access'], FILTER_VALIDATE_BOOLEAN)
                                : $revokeMediaAccess;
                            $os = \App\Models\OrderService::find($ri['order_service_id']);
                            if ($os) {
                                $os->update([
                                    'payment_status' => 'REFUNDED',
                                    'media_access' => $itemRevoke ? false : true,
                                ]);
                                $updatedAnyService = true;
                            }
                        }
                    }
                }

                if (!$updatedAnyService) {
                    foreach ($invoice->items as $item) {
                        if ($item->orderService) {
                            $itemTotal = round((float)$item->amount + (float)$item->tax_amount, 2);
                            if (abs($refundAmount - $itemTotal) < 0.05) {
                                $item->orderService->update([
                                    'payment_status' => 'REFUNDED',
                                    'media_access' => $revokeMediaAccess ? false : true,
                                ]);
                            }
                        }
                    }
                }
            } else {
                // Fully refunded invoice
                foreach ($invoice->items as $item) {
                    if ($item->orderService) {
                        $item->orderService->update([
                            'payment_status' => 'REFUNDED',
                            'media_access' => $revokeMediaAccess ? false : true,
                        ]);
                    }
                }

                if ($invoice->order) {
                    $otherUnrefundedInvoices = Invoice::where('order_id', $invoice->order_id)
                        ->where('id', '!=', $invoice->id)
                        ->whereNotIn('status', ['refunded', 'void'])
                        ->exists();

                    if (!$otherUnrefundedInvoices) {
                        $invoice->order->update([
                            'lock_materials' => $revokeMediaAccess ? true : false,
                        ]);
                    }
                }
            }

            DB::commit();

            // Dispatch QuickBooks Refund Sync
            \App\Jobs\SyncRefundToQuickBooks::dispatch($invoice->id, $refundAmount);

            return response()->json([
                'success' => true, 
                'message' => 'Refund processed successfully.', 
                'data' => [
                    'invoice' => $invoice->fresh(['payments', 'items']),
                    'refund_receipt' => $this->formatRefundReceipt($refundPayment, $invoice)
                ]
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get all refund receipts for a given invoice.
     */
    public function getRefundReceipts(string $uuid): JsonResponse
    {
        try {
            $invoice = Invoice::where('uuid', $uuid)->with(['agent', 'order.property'])->firstOrFail();
            $refundPayments = AgentPayment::where('invoice_id', $invoice->id)
                ->where(function($q) {
                    $q->where('payment_type', 'refund')->orWhere('status', 'refunded');
                })
                ->orderByDesc('created_at')
                ->get();

            $receipts = $refundPayments->map(function ($payment) use ($invoice) {
                return $this->formatRefundReceipt($payment, $invoice);
            });

            return response()->json([
                'status' => true,
                'message' => 'Refund receipts retrieved successfully',
                'data' => $receipts
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Failed to retrieve refund receipts', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Format an AgentPayment refund record into a structured Refund Receipt.
     */
    protected function formatRefundReceipt(AgentPayment $payment, Invoice $invoice): array
    {
        $meta = is_array($payment->meta) ? $payment->meta : json_decode($payment->meta, true) ?? [];
        $refundAmount = abs((float) $payment->amount);
        $refundItems = $meta['refund_items'] ?? [];
        
        $subtotal = isset($meta['subtotal']) ? (float)$meta['subtotal'] : $refundAmount;
        $taxAmount = isset($meta['tax_amount']) ? (float)$meta['tax_amount'] : 0.00;

        return [
            'receipt_uuid'       => $payment->uuid,
            'receipt_number'     => 'REF-' . str_replace('INV-', '', $invoice->invoice_number) . '-' . $payment->id,
            'invoice_number'     => $invoice->invoice_number,
            'invoice_uuid'       => $invoice->uuid,
            'order_id'           => $invoice->order_id,
            'agent'              => [
                'name'  => trim(($invoice->agent->first_name ?? '') . ' ' . ($invoice->agent->last_name ?? '')),
                'email' => $invoice->agent->email ?? null,
            ],
            'property_address'   => $invoice->order && $invoice->order->property ? $invoice->order->property->address : null,
            'refunded_at'        => $payment->paid_at ?? $payment->created_at,
            'payment_method'     => $payment->payment_method,
            'stripe_refund_id'   => $meta['stripe_refund_id'] ?? null,
            'notes'              => $meta['notes'] ?? null,
            'subtotal'           => round($subtotal, 2),
            'tax_amount'         => round($taxAmount, 2),
            'tax_rate'           => $meta['tax_rate'] ?? $invoice->tax_rate,
            'total_refunded'     => round($refundAmount, 2),
            'items'              => $refundItems,
        ];
    }

    public function exportCsv(Request $request)
    {
        try {
            $user = Auth::user();
            
            $query = Invoice::with(['order.property', 'agent', 'items']);

            if ($user instanceof Agent) {
                // Agents only see their own (including their splits)
                $query->where('agent_id', $user->id);
            } elseif ($user instanceof User) {
                // Check if Org Admin
                if ($user->organization_id) {
                    $query->whereHas('agent', function($q) use ($user) {
                        $q->where('organization_id', $user->organization_id);
                    });
                }
                // If Super Admin (no org_id), they see everything
            } else {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            $invoices = $query->orderByDesc('created_at')->get();

            $filename = "invoices_" . now()->format('Y-m-d_His') . ".csv";
            $headers = [
                "Content-type"        => "text/csv",
                "Content-Disposition" => "attachment; filename=$filename",
                "Pragma"              => "no-cache",
                "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
                "Expires"             => "0"
            ];

            $columns = ['Invoice #', 'Status', 'Agent', 'Property', 'Subtotal', 'Tax', 'Total', 'Paid', 'Issued At', 'Due Date'];

            $callback = function() use($invoices, $columns) {
                $file = fopen('php://output', 'w');
                fputcsv($file, $columns);

                foreach ($invoices as $invoice) {
                    fputcsv($file, [
                        $invoice->invoice_number,
                        $invoice->status,
                        $invoice->agent->first_name . ' ' . $invoice->agent->last_name,
                        $invoice->order->property->address ?? 'N/A',
                        $invoice->subtotal,
                        $invoice->tax_amount,
                        $invoice->total,
                        $invoice->paid_amount,
                        $invoice->issued_at?->format('Y-m-d'),
                        $invoice->due_date?->format('Y-m-d'),
                    ]);
                }
                fclose($file);
            };

            return response()->stream($callback, 200, $headers);

        } catch (\Exception $e) {
            Log::error('Invoice CSV Export failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Export failed'], 500);
        }
    }

    private function authorizeInvoiceAccess(Invoice $invoice): void
    {
        $user = Auth::user();
        if ($user instanceof Agent) {
            // Agent can see their own invoices OR invoices for orders they own (primary agent)
            $isOwner = $invoice->agent_id === $user->id;
            $isOrderOwner = $invoice->order && $invoice->order->agent_id === $user->id;

            if (!$isOwner && !$isOrderOwner) {
                abort(403, 'You do not have access to this invoice.');
            }
        }
    }
}
