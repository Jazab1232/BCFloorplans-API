<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Traits\BelongsToOrganization;

class Invoice extends Model
{
    use HasFactory, BelongsToOrganization;

    protected $fillable = [
        'uuid',
        'organization_id',
        'invoice_number',
        'order_id',
        'agent_id',
        'status', // draft, issued, partially_paid, paid, void, refunded
        'subtotal',
        'tax_rate',
        'tax_amount',
        'tax_details', // JSON for Canada GST/HST/PST breakdown
        'total',
        'paid_amount',
        'refunded_amount',
        'currency',
        'due_date',
        'issued_at',
        'paid_at',
        'notes',
        'split_details', // JSON for co-agent splits
        'agent_type', // primary or co-agent
        'created_by',
        'quickbooks_invoice_id',
        'quickbooks_synced_at',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'refunded_amount' => 'decimal:2',
        'due_date' => 'date',
        'issued_at' => 'datetime',
        'paid_at' => 'datetime',
        'quickbooks_synced_at' => 'datetime',
        'split_details' => 'array',
        'tax_details' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(AgentPayment::class);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($invoice) {
            if (empty($invoice->uuid)) {
                $invoice->uuid = (string) Str::uuid();
            }

            // Generate CRA-style invoice number if not set
            if (empty($invoice->invoice_number)) {
                $year = date('Y');
                
                // Get org slug for prefix
                $org = $invoice->organization ?? Organization::find($invoice->organization_id);
                $prefix = $org ? strtoupper($org->slug) : 'BCF';

                // Get the last invoice of the current year for THIS organization
                $lastInvoice = static::where('organization_id', $invoice->organization_id)
                    ->whereYear('created_at', $year)
                    ->orderBy('id', 'desc')
                    ->first();
                
                $nextNum = 1;
                if ($lastInvoice && preg_match('/-(\d+)$/', $lastInvoice->invoice_number, $matches)) {
                    $nextNum = intval($matches[1]) + 1;
                }
                
                $invoice->invoice_number = sprintf('INV-%s-%s-%05d', $prefix, $year, $nextNum);
            }
        });

        static::saved(function ($invoice) {
            if ($invoice->status === 'paid' && ($invoice->wasRecentlyCreated || $invoice->isDirty('status'))) {
                $invoice->loadMissing('items.orderService');
                foreach ($invoice->items as $item) {
                    if ($item->orderService && !$item->is_extra && $invoice->order) {
                        static::syncOrderServicePaymentStatus($invoice->order, $item->orderService);
                    }
                }

                // Auto-activate Matterport renewals if this invoice is paid
                \App\Http\Controllers\Api\InvoiceController::activateMatterportRenewalForInvoice($invoice, 'payment');
            }
        });
    }


    public function getRouteKeyName()
    {
        return 'uuid';
    }

    /**
     * Generate both consolidated and service invoices for an order.
     */
    public static function generateForOrder(Order $order)
    {
        $order->load(['services.service', 'agent', 'property']);
        $agent = $order->agent;
        
        // 1. Determine Tax Info
        $province = $agent->headquarter_province ?? $order->property->province ?? 'ON';
        $taxInfo = (new \App\Http\Controllers\Api\InvoiceController)->getCanadaTaxRate($province);

        // 2. Resolve Splits
        $resolvedSplits = static::resolveSplits($order);

        // 3. Create Consolidated Invoice(s)
        if (!empty($resolvedSplits)) {
            $splitGroupId = (string) \Illuminate\Support\Str::uuid();
            foreach ($resolvedSplits as $split) {
                static::createInvoice(
                    $order, 
                    $agent, 
                    $order->services, 
                    $taxInfo, 
                    $split['percentage'] / 100, 
                    "Consolidated Invoice ({$split['percentage']}%)",
                    ['group_id' => $splitGroupId, 'splits' => $resolvedSplits],
                    $split['type'] ?? 'co-agent',
                    $split['agent_id']
                );
            }
        } else {
            static::createInvoice($order, $agent, $order->services, $taxInfo, 1.0, 'Consolidated Invoice', null, 'primary');
        }

        // 4. Create Service-Specific Invoices
        foreach ($order->services as $os) {
            if (!empty($resolvedSplits)) {
                $serviceSplitGroupId = (string) \Illuminate\Support\Str::uuid();
                foreach ($resolvedSplits as $split) {
                    static::createInvoice(
                        $order, 
                        $agent, 
                        collect([$os]), 
                        $taxInfo, 
                        $split['percentage'] / 100, 
                        ($os->service->name ?? 'Service') . " Invoice ({$split['percentage']}%)",
                        ['group_id' => $serviceSplitGroupId, 'splits' => $resolvedSplits],
                        $split['type'] ?? 'co-agent',
                        $split['agent_id']
                    );
                }
            } else {
                static::createInvoice($order, $agent, collect([$os]), $taxInfo, 1.0, "Service Invoice: " . ($os->service->name ?? 'Service'), null, 'primary');
            }
        }

        // Add fixed-area and allowance-excess lines to consolidated invoices.
        static::syncOrderInvoices($order);
    }

    /**
     * Resolve split details and ensure co-agent accounts exist.
     */
    public static function resolveSplits(Order $order)
    {
        $splitInvoice = $order->split_invoice ?? false;
        $coAgents = is_array($order->co_agents) ? $order->co_agents : json_decode($order->co_agents, true) ?? [];
        $agent = $order->agent;
        
        if (!$splitInvoice || empty($coAgents)) {
            return [];
        }

        $splits = [];
        $totalCoPercent = 0;
        foreach ($coAgents as $ca) {
            $percent = (float)($ca['percentage'] ?? 0);
            if ($percent > 0) {
                $splits[] = [
                    'name' => $ca['name'] ?? 'Co-Agent',
                    'email' => $ca['email'] ?? '',
                    'percentage' => $percent,
                    'type' => 'co-agent',
                    'agent_id' => $ca['agent_id'] ?? null,
                    'agent_uuid' => $ca['agent_uuid'] ?? null,
                ];
                $totalCoPercent += $percent;
            }
        }
        $primaryPercent = max(0, 100 - $totalCoPercent);
        $splits[] = ['name' => "{$agent->first_name} {$agent->last_name}", 'email' => $agent->email, 'percentage' => $primaryPercent, 'type' => 'primary', 'agent_id' => $agent->id];

        $resolvedSplits = [];
        foreach ($splits as $split) {
            $targetAgentId = $agent->id;
            if ($split['type'] === 'co-agent' && !empty($split['email'])) {
                if (!empty($split['agent_id'])) {
                    $targetAgentId = $split['agent_id'];
                } else {
                    $coAgent = app(\App\Services\CoAgentService::class)->resolveOrCreateCoAgent(
                        $split['email'],
                        $split['name'] ?? 'Co-Agent',
                        null,
                        $agent,
                        $order->property->address ?? null,
                        array_filter(['agent_uuid' => $split['agent_uuid'] ?? null]),
                        false // Never send listing linked notification during invoice split generation
                    );
                    $targetAgentId = $coAgent->id;
                }
            }
            $resolvedSplits[] = array_merge($split, ['agent_id' => $targetAgentId]);
        }
        
        return $resolvedSplits;
    }

    /**
     * Generate an invoice for specifically provided services (e.g. add-ons).
     */
    public static function generateForNewServices(Order $order, $orderServices)
    {
        if ($orderServices->isEmpty()) return null;

        $order->load(['services.service', 'agent', 'property']);
        $agent = $order->agent;
        $province = $agent->headquarter_province ?? $order->property->province ?? 'ON';
        $taxInfo = (new \App\Http\Controllers\Api\InvoiceController)->getCanadaTaxRate($province);

        // Resolve Splits
        $resolvedSplits = static::resolveSplits($order);
        
        // 1. Check if there is an active consolidated invoice
        // If there is, syncOrderInvoices will update it. If NOT (e.g. they are all paid), we must create a consolidated Add-on invoice.
        $hasActiveConsolidated = static::where('order_id', $order->id)
            ->whereIn('status', ['draft', 'issued'])
            ->get()
            ->filter(function($inv) {
                return !str_contains($inv->notes ?? '', 'Service Invoice:');
            })->isNotEmpty();

        $primaryInvoice = null;

        if (!$hasActiveConsolidated) {
            if (!empty($resolvedSplits)) {
                $splitGroupId = (string) \Illuminate\Support\Str::uuid();
                $createdInvoices = [];
                foreach ($resolvedSplits as $split) {
                    $createdInvoices[] = static::createInvoice(
                        $order, 
                        $agent, 
                        $orderServices, 
                        $taxInfo, 
                        $split['percentage'] / 100, 
                        "Add-on Service Invoice ({$split['percentage']}%)",
                        ['group_id' => $splitGroupId, 'splits' => $resolvedSplits],
                        $split['type'] ?? 'co-agent',
                        $split['agent_id']
                    );
                }
                $primaryInvoice = $createdInvoices[0];
            } else {
                $primaryInvoice = static::createInvoice($order, $agent, $orderServices, $taxInfo, 1.0, "Add-on Service Invoice", null, 'primary');
            }
        }

        // 2. ALWAYS Create Individual Service-Specific Invoices for the new services
        foreach ($orderServices as $os) {
            if (!empty($resolvedSplits)) {
                $serviceSplitGroupId = (string) \Illuminate\Support\Str::uuid();
                foreach ($resolvedSplits as $split) {
                    static::createInvoice(
                        $order, 
                        $agent, 
                        collect([$os]), 
                        $taxInfo, 
                        $split['percentage'] / 100, 
                        ($os->service->name ?? 'Service') . " Invoice ({$split['percentage']}%)",
                        ['group_id' => $serviceSplitGroupId, 'splits' => $resolvedSplits],
                        $split['type'] ?? 'co-agent',
                        $split['agent_id']
                    );
                }
            } else {
                static::createInvoice($order, $agent, collect([$os]), $taxInfo, 1.0, "Service Invoice: " . ($os->service->name ?? 'Service'), null, 'primary');
            }
        }

        // Sync order and invoice statuses and area charges
        static::syncOrderInvoices($order);

        return $primaryInvoice;
    }

    /**
     * Resolve package discount for the given services being invoiced on an order.
     */
    public static function resolvePackageDiscountForServices(Order $order, $services, float $multiplier = 1.0): ?array
    {
        $packageInfo = $order->meta['applied_package'] ?? null;
        $packageId = $packageInfo['package_id'] ?? $order->package_id;

        if (!$packageId && empty($packageInfo)) {
            return null;
        }

        if (empty($packageInfo) && $packageId) {
            $package = $order->package ?? \App\Models\Package::find($packageId);
            if (!$package || !$package->status) {
                return null;
            }
            $matchedIds = $package->services->pluck('id')->toArray();
            $packageInfo = [
                'package_id' => $package->id,
                'package_name' => $package->name,
                'package_discount' => $package->discount,
                'package_discount_type' => $package->discount_type,
                'package_discount_percentage' => $package->discount_type === 'dollar' ? null : $package->discount,
                'package_discount_amount' => $package->discount_type === 'dollar' ? $package->discount : null,
                'matched_service_ids' => $matchedIds,
            ];
        }

        $matchedServiceIds = $packageInfo['matched_service_ids'] ?? [];
        if (empty($matchedServiceIds)) {
            return null;
        }

        $servicesCollection = collect($services);
        $matchedInInvoice = $servicesCollection->filter(function($os) use ($matchedServiceIds) {
            return in_array($os->service_id, $matchedServiceIds);
        });

        if ($matchedInInvoice->isEmpty()) {
            return null;
        }

        $matchedTotal = (float) $matchedInInvoice->sum(fn($os) => (float)$os->amount);
        if ($matchedTotal <= 0) {
            return null;
        }

        $discountAmount = 0.0;
        $discountType = $packageInfo['package_discount_type'] ?? 'percentage';

        if ($discountType === 'dollar') {
            $order->loadMissing('services');
            $totalOrderMatched = (float) $order->services
                ->filter(fn($os) => in_array($os->service_id, $matchedServiceIds))
                ->sum(fn($os) => (float)$os->amount);
            $ratio = $totalOrderMatched > 0 ? ($matchedTotal / $totalOrderMatched) : 0;
            $fullDiscount = (float) ($packageInfo['package_discount_amount'] ?? $packageInfo['package_discount'] ?? 0);
            $discountAmount = round($fullDiscount * $ratio * $multiplier, 2);
        } else {
            $pct = (float) ($packageInfo['package_discount_percentage'] ?? $packageInfo['package_discount'] ?? 0);
            $discountAmount = round(($matchedTotal * ($pct / 100)) * $multiplier, 2);
        }

        if ($discountAmount <= 0) {
            return null;
        }

        $packageName = $packageInfo['package_name'] ?? 'Package';
        $pctText = ($discountType !== 'dollar' && isset($packageInfo['package_discount_percentage'])) 
            ? $packageInfo['package_discount_percentage'] 
            : ($packageInfo['package_discount'] ?? null);
            
        $desc = "Package Discount: {$packageName}";
        if ($pctText && $discountType !== 'dollar') {
            $desc .= " ({$pctText}%)";
        }
        if ($multiplier < 1) {
            $desc .= " (" . round($multiplier * 100, 1) . "%)";
        }

        return [
            'package_id' => $packageInfo['package_id'] ?? $packageId,
            'package_name' => $packageName,
            'amount' => -abs($discountAmount),
            'description' => $desc,
        ];
    }

    /**
     * Resolve agent discount for the given services being invoiced on an order.
     *
     * IMPORTANT: Only reads agent_discount from order->meta (snapshotted at order time).
     * Does NOT fall back to the live agent profile to prevent the agent discount from
     * appearing on invoices when a package discount was selected as the winner instead.
     * The snapshot in order->meta['agent_discount'] is only written when agent discount
     * was actually applied (i.e., it won over any package discount).
     */
    public static function resolveAgentDiscountForInvoice(Order $order, $services, float $multiplier = 1.0): ?array
    {
        $discountData = $order->meta['agent_discount'] ?? null;

        if (empty($discountData)) {
            return null;
        }

        $isActive = filter_var($discountData['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN);
        if (!$isActive) {
            return null;
        }

        $amount = (float)($discountData['amount'] ?? 0);
        if ($amount <= 0) {
            return null;
        }

        $isPercentage = filter_var($discountData['is_percentage'] ?? true, FILTER_VALIDATE_BOOLEAN);

        $servicesCollection = collect($services);
        // os->amount stores the already-calculated service total (e.g. 2500 sqft × $0.10 = $250).
        // option->amount is the per-unit RATE ($0.10), NOT the total — never use it as the base.
        $currentServicesTotal = (float) $servicesCollection->sum(fn($os) => (float)$os->amount);
        if ($currentServicesTotal <= 0) {
            return null;
        }

        $discountAmount = 0.0;
        if ($isPercentage) {
            $discountAmount = round(($currentServicesTotal * ($amount / 100)) * $multiplier, 2);
        } else {
            $order->loadMissing('services');
            $totalOrderAmount = (float) $order->services->sum(fn($os) => (float)$os->amount);
            $ratio = $totalOrderAmount > 0 ? ($currentServicesTotal / $totalOrderAmount) : 0;
            $fullDiscount = (float)($discountData['calculated_discount_amount'] ?? $amount);
            $discountAmount = round($fullDiscount * $ratio * $multiplier, 2);
        }

        if ($discountAmount <= 0) {
            return null;
        }

        $discountName = $discountData['name'] ?? 'Agent Discount';
        $desc = "Agent Discount: {$discountName}";
        if ($isPercentage) {
            $desc .= " ({$amount}%)";
        } else {
            $desc .= " (\${$amount} OFF)";
        }
        if ($multiplier < 1) {
            $desc .= " (" . round($multiplier * 100, 1) . "%)";
        }

        return [
            'name' => $discountName,
            'amount' => -abs($discountAmount),
            'description' => $desc,
            'is_percentage' => $isPercentage,
        ];
    }

    /**
     * Resolve coupon/code discounts for the given services being invoiced on an order.
     * Code discounts apply additively on top of package/agent discounts.
     */
    public static function resolveCodeDiscountsForInvoice(Order $order, $services, float $multiplier = 1.0): ?array
    {
        $discountsData = $order->meta['discounts'] ?? [];
        if (empty($discountsData)) {
            // Also check OrderTotal records if meta['discounts'] is missing
            $orderTotals = \App\Models\OrderTotal::where('order_id', $order->id)
                ->whereNotNull('discount_id')
                ->with('discount')
                ->get();
            if ($orderTotals->isNotEmpty()) {
                $discountsData = $orderTotals->map(function ($ot) {
                    return [
                        'discount_id' => $ot->discount?->uuid,
                        'value' => (float)$ot->discount_value,
                        'type' => $ot->discount_type,
                    ];
                })->filter(fn($d) => !empty($d['discount_id']))->values()->toArray();
            }
        }

        if (empty($discountsData)) {
            return null;
        }

        $servicesCollection = collect($services);
        // os->amount stores the already-calculated service total (e.g. 2500 sqft × $0.10 = $250).
        // option->amount is the per-unit RATE ($0.10), NOT the total — never use it as the base.
        $currentServicesTotal = (float) $servicesCollection->sum(fn($os) => (float)$os->amount);
        if ($currentServicesTotal <= 0) {
            return null;
        }

        $order->loadMissing('services');
        $totalOrderAmount = (float) $order->services->sum(fn($os) => (float)$os->amount);
        $ratio = $totalOrderAmount > 0 ? ($currentServicesTotal / $totalOrderAmount) : 1.0;

        $totalDiscountAmount = 0.0;
        $discountNames = [];

        foreach ($discountsData as $disc) {
            $discountId = $disc['discount_id'] ?? null;
            if (!$discountId) continue;

            $discModel = is_numeric($discountId)
                ? \App\Models\Discount::find((int)$discountId)
                : \App\Models\Discount::where('uuid', $discountId)->first();

            $val = (float)($disc['value'] ?? $discModel?->percentage ?? $discModel?->quantity ?? 0);
            $type = $disc['type'] ?? $discModel?->type ?? 'manual';

            // Service-scoped discount: check if this service matches
            $serviceId = $disc['service_id'] ?? null;
            if ($serviceId) {
                $matchingService = $servicesCollection->first(function ($os) use ($serviceId) {
                    return $os->id == $serviceId || $os->service_id == $serviceId || ($os->service?->uuid === $serviceId);
                });
                if (!$matchingService) {
                    continue;
                }
                // Use os->amount (the calculated total) — option->amount is just the per-unit rate
                $svcAmount = (float)$matchingService->amount;
                $discAmt = match ($type) {
                    'quantity', 'code' => min($svcAmount, $val),
                    'manual' => round($svcAmount * ($val / 100), 2),
                    default => 0,
                };
                $totalDiscountAmount += round($discAmt * $multiplier, 2);
            } else {
                // Order-wide discount: distribute proportionally
                $fullDiscAmt = match ($type) {
                    'quantity', 'code' => min($totalOrderAmount, $val),
                    'manual' => round($totalOrderAmount * ($val / 100), 2),
                    default => 0,
                };
                $totalDiscountAmount += round($fullDiscAmt * $ratio * $multiplier, 2);
            }

            $name = $discModel?->name ?? $discModel?->code_key ?? 'Coupon';
            if (!in_array($name, $discountNames)) {
                $discountNames[] = $name;
            }
        }

        if ($totalDiscountAmount <= 0) {
            return null;
        }

        $nameStr = implode(', ', $discountNames);
        $desc = "Coupon Discount: {$nameStr}";
        if ($multiplier < 1) {
            $desc .= " (" . round($multiplier * 100, 1) . "%)";
        }

        return [
            'amount' => -abs($totalDiscountAmount),
            'description' => $desc,
            'names' => $discountNames,
        ];
    }

    /**
     * Given resolved package discount, agent discount, and code/coupon discount (any may be null),
     * return which discount should appear on the invoice: 'package', 'agent', 'code', or 'none'.
     *
     * Rule: Only ONE discount ever applies to an order/invoice — whichever discount gives
     * the LARGEST absolute savings amount wins.
     * Tie-breaking preference: agent >= package >= code.
     */
    private static function pickWinningDiscount(?array $pkgDiscount, ?array $agentDisc, ?array $codeDisc = null): string
    {
        $rawPkg   = $pkgDiscount ? abs((float)($pkgDiscount['amount'] ?? 0)) : 0.0;
        $rawAgent = $agentDisc   ? abs((float)($agentDisc['amount']   ?? 0)) : 0.0;
        $rawCode  = $codeDisc    ? abs((float)($codeDisc['amount']    ?? 0)) : 0.0;

        $maxVal = max($rawPkg, $rawAgent, $rawCode);
        if ($maxVal <= 0) {
            return 'none';
        }

        // Check in order of preference
        if ($rawAgent >= $maxVal) {
            return 'agent';
        }
        if ($rawPkg >= $maxVal) {
            return 'package';
        }
        return 'code';
    }

    public static function createInvoice($order, $agent, $services, $taxInfo, $multiplier, $note, $splitDetails = null, $agentType = 'primary', $targetAgentId = null)
    {
        $province = 'BC';
        if (isset($order->property) && !empty($order->property->state)) {
            $province = $order->property->state;
        } elseif (isset($order->property) && !empty($order->property->province)) {
            $province = $order->property->province;
        } elseif (!empty($order->province)) {
            $province = $order->province;
        }

        $preparedItems = [];
        foreach ($services as $os) {
            // os->amount stores the already-calculated service total (e.g. 2500 sqft × $0.10 = $250).
            // option->amount is the per-unit RATE — NEVER use it as the invoice line amount.
            $amount = (float)$os->amount * $multiplier;
            $serviceModel = $os->service ?? null;
            $itemDescription = ($os->custom ?: ($serviceModel->name ?? 'Service')) . ($multiplier < 1 ? " (" . ($multiplier * 100) . "%)" : "");

            $preparedItems[] = [
                'order_service' => $os,
                'service' => $serviceModel,
                'amount' => $amount,
                'description' => $itemDescription,
            ];
        }

        $pkgDiscount = static::resolvePackageDiscountForServices($order, $services, $multiplier);
        $agentDisc   = static::resolveAgentDiscountForInvoice($order, $services, $multiplier);
        $codeDisc    = static::resolveCodeDiscountsForInvoice($order, $services, $multiplier);
        $winner      = static::pickWinningDiscount($pkgDiscount, $agentDisc, $codeDisc);

        if ($winner === 'package' && $pkgDiscount) {
            $preparedItems[] = [
                'order_service' => (collect($services)->count() === 1 ? collect($services)->first() : null),
                'service' => null,
                'amount' => $pkgDiscount['amount'],
                'description' => $pkgDiscount['description'],
                'is_taxable' => true,
                'is_package_discount' => true,
                'package_id' => $pkgDiscount['package_id'],
            ];
        }

        if ($winner === 'agent' && $agentDisc) {
            $preparedItems[] = [
                'order_service' => (collect($services)->count() === 1 ? collect($services)->first() : null),
                'service' => null,
                'amount' => $agentDisc['amount'],
                'description' => $agentDisc['description'],
                'is_taxable' => true,
                'is_agent_discount' => true,
            ];
        }

        if ($winner === 'code' && $codeDisc) {
            $preparedItems[] = [
                'order_service' => (collect($services)->count() === 1 ? collect($services)->first() : null),
                'service' => null,
                'amount' => $codeDisc['amount'],
                'description' => $codeDisc['description'],
                'is_taxable' => true,
                'is_code_discount' => true,
            ];
        }

        $country = $order->property->country ?? 'CA';
        $taxCalc = \App\Services\TaxCalculationService::calculateTaxes(
            $order->organization_id,
            $preparedItems,
            $province,
            $country
        );

        $items = [];
        foreach ($preparedItems as $idx => $prep) {
            $calcItem = $taxCalc['items'][$idx] ?? [];
            $itemTax = $calcItem['tax_amount'] ?? 0.0;
            $os = $prep['order_service'];
            $isPkgDisc = !empty($prep['is_package_discount']);
            $isAgentDisc = !empty($prep['is_agent_discount']);
            $isCodeDisc = !empty($prep['is_code_discount']);

            $meta = null;
            if ($isPkgDisc) {
                $meta = ['type' => 'package_discount', 'package_id' => $prep['package_id']];
            } elseif ($isAgentDisc) {
                $meta = ['type' => 'agent_discount'];
            } elseif ($isCodeDisc) {
                $meta = ['type' => 'code_discount'];
            }

            $items[] = [
                'order_service_id' => $os?->id,
                'description' => $prep['description'],
                'quantity' => 1,
                'unit_price' => $prep['amount'],
                'amount' => $prep['amount'],
                'tax_amount' => $itemTax,
                'gst_amount' => $calcItem['gst_amount'] ?? ($calcItem['tax_breakdown']['GST'] ?? 0.0),
                'pst_amount' => $calcItem['pst_amount'] ?? ($calcItem['tax_breakdown']['PST'] ?? 0.0),
                'gst_enabled' => $calcItem['gst_enabled'] ?? true,
                'pst_enabled' => $calcItem['pst_enabled'] ?? false,
                'is_extra' => $isPkgDisc || $isAgentDisc || $isCodeDisc,
                'meta' => $meta,
            ];
        }

        $invoice = static::create([
            'organization_id' => $order->organization_id,
            'order_id' => $order->id,
            'agent_id' => $targetAgentId ?? $agent->id,
            'status' => 'issued',
            'subtotal' => $taxCalc['subtotal'],
            'tax_rate' => $taxCalc['effective_tax_rate'],
            'tax_amount' => $taxCalc['total_tax_amount'],
            'tax_details' => $taxCalc['tax_details'],
            'total' => $taxCalc['total'],
            'paid_amount' => 0,
            'currency' => 'cad',
            'issued_at' => now(),
            'notes' => $note,
            'split_details' => $splitDetails,
            'agent_type' => $agentType,
        ]);

        foreach ($items as $item) {
            $invoice->items()->create($item);
        }

        return $invoice;
    }

    /**
     * Recalculate subtotal/tax/total from line items.
     */
    public function recalculateTotals()
    {
        $this->load(['items.orderService.service', 'items.orderService.option', 'agent', 'order.property']);

        $province = 'BC';
        if (isset($this->order->property->state) && !empty($this->order->property->state)) {
            $province = $this->order->property->state;
        } elseif (isset($this->order->property->province) && !empty($this->order->property->province)) {
            $province = $this->order->property->province;
        } elseif (!empty($this->agent->headquarter_province)) {
            $province = $this->agent->headquarter_province;
        }

        $country = $this->order->property->country ?? 'CA';

        $calcItemsInput = [];
        foreach ($this->items as $item) {
            $qty = $item->quantity ?: 1;
            $price = (float)$item->unit_price;
            $amount = round($qty * $price, 2);

            $service = $item->orderService?->service;
            $isPkgDisc = ($item->meta['type'] ?? null) === 'package_discount' || str_starts_with($item->description ?? '', 'Package Discount');
            $isTaxable = null;
            if ($item->gst_enabled !== null || $item->pst_enabled !== null) {
                $isTaxable = (bool)$item->gst_enabled || (bool)$item->pst_enabled;
            } elseif ($isPkgDisc) {
                $isTaxable = true;
            }

            $calcItemsInput[] = [
                'amount' => $amount,
                'service' => $service,
                'is_taxable' => $isTaxable,
                'is_package_discount' => $isPkgDisc,
                'description' => $item->description,
                'gst_enabled' => $item->gst_enabled,
                'pst_enabled' => $item->pst_enabled,
            ];
        }

        $taxCalc = \App\Services\TaxCalculationService::calculateTaxes(
            $this->organization_id,
            $calcItemsInput,
            $province,
            $country
        );

        foreach ($this->items as $idx => $item) {
            $calcItem = $taxCalc['items'][$idx] ?? [];
            $amount = $calcItem['amount'] ?? round(($item->quantity ?: 1) * (float)$item->unit_price, 2);
            $item->update([
                'amount' => $amount,
                'tax_amount' => $calcItem['tax_amount'] ?? 0.0,
                'gst_amount' => $calcItem['gst_amount'] ?? ($calcItem['tax_breakdown']['GST'] ?? 0.0),
                'pst_amount' => $calcItem['pst_amount'] ?? ($calcItem['tax_breakdown']['PST'] ?? 0.0),
                'gst_enabled' => $calcItem['gst_enabled'] ?? true,
                'pst_enabled' => $calcItem['pst_enabled'] ?? false,
            ]);
        }

        $subtotal = $taxCalc['subtotal'];
        $totalTaxAmount = $taxCalc['total_tax_amount'];
        $grandTotal = $taxCalc['total'];

        $paidAmount = (float)$this->paid_amount;
        $status = $this->status;
        if ($paidAmount >= $grandTotal && $grandTotal > 0) {
            $status = ((float)$this->refunded_amount > 0) ? 'partially_refunded' : 'paid';
        } elseif ($paidAmount > 0) {
            $status = 'partially_paid';
        } elseif (!in_array($status, ['draft', 'void', 'refunded'])) {
            $status = 'issued';
        }

        $this->update([
            'subtotal' => $subtotal,
            'tax_rate' => $taxCalc['effective_tax_rate'],
            'tax_amount' => $totalTaxAmount,
            'tax_details' => $taxCalc['tax_details'],
            'total' => $grandTotal,
            'status' => $status,
        ]);

        if ($this->order_id && $this->order) {
            $hasConsolidatedNote = str_starts_with($this->notes ?? '', 'Consolidated') || 
                                   str_starts_with($this->notes ?? '', 'Add-on Service Invoice');
            $distinctServices = $this->items->where('is_extra', false)->pluck('order_service_id')->filter()->unique();
            $isConsolidated = $hasConsolidatedNote || $distinctServices->count() > 1;

            if ($isConsolidated && $this->agent_type === 'primary' && empty($this->split_details['splits'])) {
                $this->order->update(['amount' => $subtotal]);
            }
        }

        return $this;
    }

    /**
     * Check if other invoices for this order are now technically "paid" because their services were paid.
     * Also updates paid_amount for partially paid invoices.
     */
     public static function syncOrderStatus(Order $order)
     {
         $invoices = static::where('order_id', $order->id)
             ->where('status', '!=', 'void')
             ->where('status', '!=', 'refunded')
             ->with(['items.orderService'])
             ->get();
         
         foreach ($invoices as $invoice) {
             $allPaid = true;
             $hasItems = false;
             $paidServiceIdsForAgent = collect();

             if (!empty($invoice->split_details['splits'])) {
                 $paidServiceIdsForAgent = InvoiceItem::where('is_extra', false)
                     ->whereNotNull('order_service_id')
                     ->whereHas('invoice', function ($query) use ($invoice) {
                         $query->where('order_id', $invoice->order_id)
                             ->where('agent_id', $invoice->agent_id)
                             ->where('status', 'paid');
                     })
                     ->pluck('order_service_id');
             }

             $paidServiceSubtotal = 0;
             $paidTax = 0;
             $totalPositiveServiceSubtotal = 0;
             $totalGlobalDiscount = 0;

             // Pre-calculate positive service total and global discount total (discount items without order_service_id)
             foreach ($invoice->items as $item) {
                 $amt = (float)$item->amount;
                 if ($amt > 0 && $item->order_service_id) {
                     $totalPositiveServiceSubtotal += $amt;
                 } elseif ($amt < 0 && !$item->order_service_id) {
                     $totalGlobalDiscount += $amt; // Negative number
                 }
             }
 
             foreach ($invoice->items as $item) {
                 $hasItems = true;
                 $servicePaidForInvoiceOwner = !empty($invoice->split_details['splits'])
                     ? ($item->order_service_id && $paidServiceIdsForAgent->contains($item->order_service_id))
                     : ($item->orderService && $item->orderService->payment_status === 'PAID');

                 if ($servicePaidForInvoiceOwner) {
                     $paidServiceSubtotal += (float)$item->amount;
                     $itemTax = (float)$item->tax_amount;
                     if ($itemTax == 0 && (float)$item->amount != 0) {
                         $itemTax = round((float)$item->amount * ($invoice->tax_rate / 100), 2);
                     }
                     $paidTax += $itemTax;
                 } else {
                     $allPaid = false;
                 }
             }
 
             if (!$hasItems) continue;

             // If this invoice has global discount items (like Agent Discount or Coupon Discount),
             // proportionally allocate the discount and its tax reduction to the paid services.
             $allocatedDiscount = 0;
             if ($totalPositiveServiceSubtotal > 0 && $totalGlobalDiscount < 0 && $paidServiceSubtotal > 0) {
                 $discountRatio = $paidServiceSubtotal / $totalPositiveServiceSubtotal;
                 $allocatedDiscount = $totalGlobalDiscount * $discountRatio; // negative
                 
                 // Also scale tax proportionally to the discounted base
                 if ($paidServiceSubtotal > 0) {
                     $taxScale = ($paidServiceSubtotal + $allocatedDiscount) / $paidServiceSubtotal;
                     $paidTax = round($paidTax * $taxScale, 2);
                 }
             }

             $paidSubtotal = round($paidServiceSubtotal + $allocatedDiscount, 2);
             $calculatedPaidAmount = round($paidSubtotal + $paidTax, 2);
             
             // Direct payments recorded specifically on this invoice (e.g. Stripe checkout or manual payment)
             $directNetPaid = (float) $invoice->payments()
                 ->where('status', 'succeeded')
                 ->where('payment_type', '!=', 'refund')
                 ->sum('amount');

             // Take the higher of direct payments received on this invoice vs the calculated share of paid services
             $newNetPaid = max($directNetPaid, $calculatedPaidAmount);
             
             // Add back the refunded amount to get the gross paid amount to store
             $newPaidAmount = round($newNetPaid + (float)$invoice->refunded_amount, 2);
             
             // Cap gross paid_amount at total + refunded_amount to keep net paid capped at total
             $maxAllowedGross = round((float)$invoice->total + (float)$invoice->refunded_amount, 2);
             if ($newPaidAmount > $maxAllowedGross) {
                 $newPaidAmount = $maxAllowedGross;
                 $newNetPaid = round($newPaidAmount - (float)$invoice->refunded_amount, 2);
             }
 
             $isFullyPaid = ($newNetPaid >= (float)$invoice->total) || $allPaid;
             $wasGrossPaid = (float)$invoice->paid_amount >= (float)$invoice->total || $invoice->status === 'paid' || $invoice->status === 'partially_refunded';

             $updateData = [
                 'paid_amount' => $newPaidAmount,
             ];

             if ($isFullyPaid && !in_array($invoice->status, ['paid', 'partially_refunded'])) {
                 $updateData['status'] = (float)$invoice->refunded_amount > 0 ? 'partially_refunded' : 'paid';
                 $updateData['paid_at'] = $invoice->paid_at ?? now();
                 $updateData['paid_amount'] = (float)$invoice->refunded_amount > 0 
                     ? round((float)$invoice->total + (float)$invoice->refunded_amount, 2) 
                     : $invoice->total; // Ensure it's exactly total (plus refunded_amount if applicable)
             } elseif (!$isFullyPaid && $newNetPaid > 0) {
                 if ((float)$invoice->refunded_amount > 0 || $wasGrossPaid) {
                     $updateData['status'] = 'partially_refunded';
                 } else {
                     $updateData['status'] = 'partially_paid';
                 }
             } elseif ($newNetPaid <= 0) {
                 if ((float)$invoice->refunded_amount > 0) {
                     $updateData['status'] = 'refunded';
                 } else {
                     $updateData['status'] = 'issued';
                 }
             }

             $invoice->update($updateData);
         }
     }

    /**
     * Mark a shared order service paid only after each billed agent's share is paid.
     */
    public static function syncOrderServicePaymentStatus(Order $order, OrderService $orderService): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($order, $orderService): void {
            $orderService = OrderService::whereKey($orderService->id)->lockForUpdate()->first();
            if (!$orderService || $orderService->payment_status === 'REFUNDED') {
                return;
            }

            $serviceInvoices = static::where('order_id', $order->id)
                ->whereNotIn('status', ['void', 'refunded'])
                ->whereHas('items', function ($query) use ($orderService) {
                    $query->where('order_service_id', $orderService->id)
                        ->where('is_extra', false);
                })
                ->get();

            $agentIds = $serviceInvoices->pluck('agent_id')->filter()->unique();
            if ($agentIds->isEmpty()) {
                return;
            }

            $allAgentSharesPaid = $agentIds->every(function ($agentId) use ($order, $orderService) {
                return static::where('order_id', $order->id)
                    ->where('agent_id', $agentId)
                    ->where('status', 'paid')
                    ->whereHas('items', function ($query) use ($orderService) {
                        $query->where('order_service_id', $orderService->id)
                            ->where('is_extra', false);
                    })
                    ->exists();
            });

            $paymentStatus = $allAgentSharesPaid ? 'PAID' : 'UNPAID';
            if ($orderService->payment_status !== $paymentStatus) {
                $orderService->update(['payment_status' => $paymentStatus]);
            }
        });
    }

    /**
     * Check if an OrderService uses a per-square-foot rate option (not range).
     */
    public static function isPerSqftRateService(?OrderService $os): bool
    {
        if (!$os || !$os->option) {
            return false;
        }
        return !empty($os->option->sq_ft_rate) && (float)$os->option->sq_ft_rate > 0 && empty($os->option->sq_ft_range);
    }

    /**
     * Synchronize consolidated and service-specific invoices with the current state of order services.
     * Useful when services are added, removed, or prices changed (e.g. square footage updates).
     */
    public static function syncOrderInvoices(Order $order)
    {
        $order->load(['services.option', 'services.service', 'areas']);
        $areaMetrics = app(\App\Services\AreaPricingService::class)
            ->calculateAreaMetrics($order->areas->map(fn($area) => [
                'type' => $area->type,
                'footage' => $area->footage,
                'custom_title' => $area->custom_title,
            ])->toArray(), $order->organization_id);

        $totalAreaCharges = (float)($areaMetrics['total_area_charges'] ?? 0);

        $activeInvoices = static::where('order_id', $order->id)
            ->where('status', '!=', 'paid')
            ->where('status', '!=', 'void')
            ->where('status', '!=', 'refunded')
            ->where('refunded_amount', '=', 0)
            ->with(['items'])
            ->get();

        $allOrderServices = $order->services->filter(fn($os) => ($os->status ?? 'active') !== 'cancelled');
        $allOrderServiceIds = $allOrderServices->pluck('id')->toArray();

        foreach ($activeInvoices as $invoice) {
            // Skip upgrade / difference invoices
            if (str_starts_with($invoice->notes ?? '', 'Upgrade for')) {
                continue;
            }

            // Determine multiplier (for split invoices)
            $multiplier = 1.0;
            if (!empty($invoice->split_details['splits'])) {
                foreach ($invoice->split_details['splits'] as $split) {
                    if ($split['agent_id'] == $invoice->agent_id) {
                        $multiplier = (float)$split['percentage'] / 100;
                        break;
                    }
                }
            }

            $hasConsolidatedNote = str_starts_with($invoice->notes ?? '', 'Consolidated') || 
                                   str_starts_with($invoice->notes ?? '', 'Add-on Service Invoice');
            $hasServiceNote = str_contains($invoice->notes ?? '', 'Service Invoice:');

            $nonExtraItems = $invoice->items->where('is_extra', false);
            $distinctServiceIds = $nonExtraItems->pluck('order_service_id')->filter()->unique();

            $isConsolidated = $hasConsolidatedNote || (!$hasServiceNote && $distinctServiceIds->count() > 1);

            if ($isConsolidated) {
                $currentItems = $nonExtraItems->keyBy('order_service_id');
                $hasChanged = false;

                // 1. Add or Update items (consolidated invoice contains all order services)
                foreach ($allOrderServices as $os) {
                    $isPerSqftRate = static::isPerSqftRateService($os);
                    // os->amount stores the calculated total (e.g. 2500 sqft × $0.10 = $250).
                    // option->amount is only the per-unit RATE — never use it as the invoice amount.
                    $effectiveServiceAmount = $isPerSqftRate 
                        ? ((float)$os->amount + $totalAreaCharges)
                        : (float)$os->amount;

                    $expectedAmount = round($effectiveServiceAmount * $multiplier, 2);
                    $itemDescription = ($os->custom ?: ($os->service->name ?? 'Service')) . ($multiplier < 1 ? " (" . ($multiplier * 100) . "%)" : "");

                    if ($currentItems->has($os->id)) {
                        $item = $currentItems[$os->id];
                        if ((float)$item->amount != $expectedAmount || (float)$item->unit_price != $expectedAmount || $item->description != $itemDescription) {
                            $item->update([
                                'unit_price' => $expectedAmount,
                                'amount' => $expectedAmount,
                                'description' => $itemDescription
                            ]);
                            $hasChanged = true;
                        }
                    } else {
                        // Add missing service to consolidated invoice
                        $invoice->items()->create([
                            'order_service_id' => $os->id,
                            'description' => $itemDescription,
                            'quantity' => 1,
                            'unit_price' => $expectedAmount,
                            'amount' => $expectedAmount
                        ]);
                        $hasChanged = true;
                    }
                }

                // 2. Remove items no longer in order (only for services actually removed from the order)
                foreach ($currentItems as $osId => $item) {
                    if (!in_array($osId, $allOrderServiceIds)) {
                        $item->delete();
                        $hasChanged = true;
                    }
                }

                // 3. Clean up any standalone area charge items on consolidated invoice
                $consolidatedAreaItems = $invoice->items()
                    ->where('is_extra', true)
                    ->whereIn('description', ['Other Areas fixed charges', 'Other Areas above free allowance'])
                    ->get();
                if ($consolidatedAreaItems->isNotEmpty()) {
                    foreach ($consolidatedAreaItems as $cItem) {
                        $cItem->delete();
                        $hasChanged = true;
                    }
                }

                // 4. Sync package/agent/code discount item on consolidated invoice — only the SINGLE WINNING discount shows.
                // Rule: whichever gives the largest absolute saving wins (agent >= package >= code on tie).
                $pkgDiscount   = static::resolvePackageDiscountForServices($order, $allOrderServices, $multiplier);
                $agentDiscount = static::resolveAgentDiscountForInvoice($order, $allOrderServices, $multiplier);
                $codeDiscount  = static::resolveCodeDiscountsForInvoice($order, $allOrderServices, $multiplier);
                $winner        = static::pickWinningDiscount($pkgDiscount, $agentDiscount, $codeDiscount);

                $existingPackageItem = $invoice->items
                    ->filter(function($i) {
                        return ($i->meta['type'] ?? null) === 'package_discount' || str_starts_with($i->description ?? '', 'Package Discount');
                    })
                    ->first();

                $existingAgentItem = $invoice->items
                    ->filter(function($i) {
                        return ($i->meta['type'] ?? null) === 'agent_discount' || str_starts_with($i->description ?? '', 'Agent Discount');
                    })
                    ->first();

                $existingCodeItem = $invoice->items
                    ->filter(function($i) {
                        return ($i->meta['type'] ?? null) === 'code_discount' || str_starts_with($i->description ?? '', 'Coupon Discount');
                    })
                    ->first();

                // Apply winner and delete losers
                if ($winner === 'package' && $pkgDiscount) {
                    $expectedDiscountAmt  = (float)$pkgDiscount['amount'];
                    $expectedDiscountDesc = $pkgDiscount['description'];

                    if ($existingPackageItem) {
                        if ((float)$existingPackageItem->amount != $expectedDiscountAmt || (float)$existingPackageItem->unit_price != $expectedDiscountAmt || $existingPackageItem->description != $expectedDiscountDesc) {
                            $existingPackageItem->update([
                                'unit_price' => $expectedDiscountAmt,
                                'amount' => $expectedDiscountAmt,
                                'description' => $expectedDiscountDesc,
                                'is_extra' => true,
                                'meta' => ['type' => 'package_discount', 'package_id' => $pkgDiscount['package_id']],
                            ]);
                            $hasChanged = true;
                        }
                    } else {
                        $invoice->items()->create([
                            'order_service_id' => null,
                            'description' => $expectedDiscountDesc,
                            'quantity' => 1,
                            'unit_price' => $expectedDiscountAmt,
                            'amount' => $expectedDiscountAmt,
                            'is_extra' => true,
                            'meta' => ['type' => 'package_discount', 'package_id' => $pkgDiscount['package_id']],
                        ]);
                        $hasChanged = true;
                    }
                    if ($existingAgentItem) {
                        $existingAgentItem->delete();
                        $hasChanged = true;
                    }
                    if ($existingCodeItem) {
                        $existingCodeItem->delete();
                        $hasChanged = true;
                    }
                } elseif ($winner === 'agent' && $agentDiscount) {
                    $expectedDiscountAmt  = (float)$agentDiscount['amount'];
                    $expectedDiscountDesc = $agentDiscount['description'];

                    if ($existingAgentItem) {
                        if ((float)$existingAgentItem->amount != $expectedDiscountAmt || (float)$existingAgentItem->unit_price != $expectedDiscountAmt || $existingAgentItem->description != $expectedDiscountDesc) {
                            $existingAgentItem->update([
                                'unit_price' => $expectedDiscountAmt,
                                'amount' => $expectedDiscountAmt,
                                'description' => $expectedDiscountDesc,
                                'is_extra' => true,
                                'meta' => ['type' => 'agent_discount'],
                            ]);
                            $hasChanged = true;
                        }
                    } else {
                        $invoice->items()->create([
                            'order_service_id' => null,
                            'description' => $expectedDiscountDesc,
                            'quantity' => 1,
                            'unit_price' => $expectedDiscountAmt,
                            'amount' => $expectedDiscountAmt,
                            'is_extra' => true,
                            'meta' => ['type' => 'agent_discount'],
                        ]);
                        $hasChanged = true;
                    }
                    if ($existingPackageItem) {
                        $existingPackageItem->delete();
                        $hasChanged = true;
                    }
                    if ($existingCodeItem) {
                        $existingCodeItem->delete();
                        $hasChanged = true;
                    }
                } elseif ($winner === 'code' && $codeDiscount) {
                    $expectedDiscountAmt  = (float)$codeDiscount['amount'];
                    $expectedDiscountDesc = $codeDiscount['description'];

                    if ($existingCodeItem) {
                        if ((float)$existingCodeItem->amount != $expectedDiscountAmt || (float)$existingCodeItem->unit_price != $expectedDiscountAmt || $existingCodeItem->description != $expectedDiscountDesc) {
                            $existingCodeItem->update([
                                'unit_price' => $expectedDiscountAmt,
                                'amount' => $expectedDiscountAmt,
                                'description' => $expectedDiscountDesc,
                                'is_extra' => true,
                                'meta' => ['type' => 'code_discount'],
                            ]);
                            $hasChanged = true;
                        }
                    } else {
                        $invoice->items()->create([
                            'order_service_id' => null,
                            'description' => $expectedDiscountDesc,
                            'quantity' => 1,
                            'unit_price' => $expectedDiscountAmt,
                            'amount' => $expectedDiscountAmt,
                            'is_extra' => true,
                            'meta' => ['type' => 'code_discount'],
                        ]);
                        $hasChanged = true;
                    }
                    if ($existingPackageItem) {
                        $existingPackageItem->delete();
                        $hasChanged = true;
                    }
                    if ($existingAgentItem) {
                        $existingAgentItem->delete();
                        $hasChanged = true;
                    }
                } else {
                    // No discount applicable — remove all if present
                    if ($existingPackageItem) {
                        $existingPackageItem->delete();
                        $hasChanged = true;
                    }
                    if ($existingAgentItem) {
                        $existingAgentItem->delete();
                        $hasChanged = true;
                    }
                    if ($existingCodeItem) {
                        $existingCodeItem->delete();
                        $hasChanged = true;
                    }
                }

                if ($hasChanged) {
                    $invoice->recalculateTotals();
                }
            } else {
                // Service-specific invoice
                $baseItem = $invoice->items->where('is_extra', false)->first();
                if (!$baseItem || !$baseItem->order_service_id) {
                    continue;
                }

                $os = $order->services->firstWhere('id', $baseItem->order_service_id);
                if (!$os) {
                    // Service was removed from order, void this service invoice
                    $invoice->update(['status' => 'void']);
                    continue;
                }

                $serviceInvoiceChanged = false;

                if ($os->payment_status !== 'PAID') {
                    // os->amount stores the calculated total (e.g. 2500 sqft × $0.10 = $250).
                    // option->amount is only the per-unit RATE — never use it as the invoice amount.
                    $expectedAmount = round((float)$os->amount * $multiplier, 2);
                    $itemDescription = ($os->custom ?: ($os->service->name ?? 'Service')) . ($multiplier < 1 ? " (" . ($multiplier * 100) . "%)" : "");

                    if ((float)$baseItem->amount != $expectedAmount || (float)$baseItem->unit_price != $expectedAmount || $baseItem->description != $itemDescription) {
                        $baseItem->update([
                            'unit_price' => $expectedAmount,
                            'amount' => $expectedAmount,
                            'description' => $itemDescription
                        ]);
                        $serviceInvoiceChanged = true;
                    }
                }

                // Check if this service is a per-sqft rate service
                $isPerSqftRate = static::isPerSqftRateService($os);
                if ($isPerSqftRate) {
                    $serviceInvoiceChanged = static::syncAreaChargeItems(
                        $invoice,
                        $areaMetrics,
                        $multiplier,
                        $os->id
                    ) || $serviceInvoiceChanged;
                } else {
                    // Clean up any stray area charge items if this is NOT a per-sqft rate service
                    $strayItems = $invoice->items()
                        ->where('is_extra', true)
                        ->whereIn('description', ['Other Areas fixed charges', 'Other Areas above free allowance'])
                        ->get();
                    if ($strayItems->isNotEmpty()) {
                        foreach ($strayItems as $stray) {
                            $stray->delete();
                        }
                        $serviceInvoiceChanged = true;
                    }
                }

                // Sync package/agent/code discount item on service-specific invoice — only the SINGLE WINNING discount shows.
                $pkgDiscount   = static::resolvePackageDiscountForServices($order, collect([$os]), $multiplier);
                $agentDiscount = static::resolveAgentDiscountForInvoice($order, collect([$os]), $multiplier);
                $codeDiscount  = static::resolveCodeDiscountsForInvoice($order, collect([$os]), $multiplier);
                $winner        = static::pickWinningDiscount($pkgDiscount, $agentDiscount, $codeDiscount);

                $existingPackageItem = $invoice->items
                    ->filter(function($i) {
                        return ($i->meta['type'] ?? null) === 'package_discount' || str_starts_with($i->description ?? '', 'Package Discount');
                    })
                    ->first();

                $existingAgentItem = $invoice->items
                    ->filter(function($i) {
                        return ($i->meta['type'] ?? null) === 'agent_discount' || str_starts_with($i->description ?? '', 'Agent Discount');
                    })
                    ->first();

                $existingCodeItem = $invoice->items
                    ->filter(function($i) {
                        return ($i->meta['type'] ?? null) === 'code_discount' || str_starts_with($i->description ?? '', 'Coupon Discount');
                    })
                    ->first();

                if ($winner === 'package' && $pkgDiscount) {
                    $expectedDiscountAmt = (float)$pkgDiscount['amount'];
                    $expectedDiscountDesc = $pkgDiscount['description'];

                    if ($existingPackageItem) {
                        if ((float)$existingPackageItem->amount != $expectedDiscountAmt || (float)$existingPackageItem->unit_price != $expectedDiscountAmt || $existingPackageItem->description != $expectedDiscountDesc) {
                            $existingPackageItem->update([
                                'unit_price' => $expectedDiscountAmt,
                                'amount' => $expectedDiscountAmt,
                                'description' => $expectedDiscountDesc,
                                'is_extra' => true,
                                'meta' => ['type' => 'package_discount', 'package_id' => $pkgDiscount['package_id']],
                            ]);
                            $serviceInvoiceChanged = true;
                        }
                    } else {
                        $invoice->items()->create([
                            'order_service_id' => $os->id,
                            'description' => $expectedDiscountDesc,
                            'quantity' => 1,
                            'unit_price' => $expectedDiscountAmt,
                            'amount' => $expectedDiscountAmt,
                            'is_extra' => true,
                            'meta' => ['type' => 'package_discount', 'package_id' => $pkgDiscount['package_id']],
                        ]);
                        $serviceInvoiceChanged = true;
                    }
                    if ($existingAgentItem) {
                        $existingAgentItem->delete();
                        $serviceInvoiceChanged = true;
                    }
                    if ($existingCodeItem) {
                        $existingCodeItem->delete();
                        $serviceInvoiceChanged = true;
                    }
                } elseif ($winner === 'agent' && $agentDiscount) {
                    $expectedDiscountAmt = (float)$agentDiscount['amount'];
                    $expectedDiscountDesc = $agentDiscount['description'];

                    if ($existingAgentItem) {
                        if ((float)$existingAgentItem->amount != $expectedDiscountAmt || (float)$existingAgentItem->unit_price != $expectedDiscountAmt || $existingAgentItem->description != $expectedDiscountDesc) {
                            $existingAgentItem->update([
                                'unit_price' => $expectedDiscountAmt,
                                'amount' => $expectedDiscountAmt,
                                'description' => $expectedDiscountDesc,
                                'is_extra' => true,
                                'meta' => ['type' => 'agent_discount'],
                            ]);
                            $serviceInvoiceChanged = true;
                        }
                    } else {
                        $invoice->items()->create([
                            'order_service_id' => $os->id,
                            'description' => $expectedDiscountDesc,
                            'quantity' => 1,
                            'unit_price' => $expectedDiscountAmt,
                            'amount' => $expectedDiscountAmt,
                            'is_extra' => true,
                            'meta' => ['type' => 'agent_discount'],
                        ]);
                        $serviceInvoiceChanged = true;
                    }
                    if ($existingPackageItem) {
                        $existingPackageItem->delete();
                        $serviceInvoiceChanged = true;
                    }
                    if ($existingCodeItem) {
                        $existingCodeItem->delete();
                        $serviceInvoiceChanged = true;
                    }
                } elseif ($winner === 'code' && $codeDiscount) {
                    $expectedDiscountAmt = (float)$codeDiscount['amount'];
                    $expectedDiscountDesc = $codeDiscount['description'];

                    if ($existingCodeItem) {
                        if ((float)$existingCodeItem->amount != $expectedDiscountAmt || (float)$existingCodeItem->unit_price != $expectedDiscountAmt || $existingCodeItem->description != $expectedDiscountDesc) {
                            $existingCodeItem->update([
                                'unit_price' => $expectedDiscountAmt,
                                'amount' => $expectedDiscountAmt,
                                'description' => $expectedDiscountDesc,
                                'is_extra' => true,
                                'meta' => ['type' => 'code_discount'],
                            ]);
                            $serviceInvoiceChanged = true;
                        }
                    } else {
                        $invoice->items()->create([
                            'order_service_id' => $os->id,
                            'description' => $expectedDiscountDesc,
                            'quantity' => 1,
                            'unit_price' => $expectedDiscountAmt,
                            'amount' => $expectedDiscountAmt,
                            'is_extra' => true,
                            'meta' => ['type' => 'code_discount'],
                        ]);
                        $serviceInvoiceChanged = true;
                    }
                    if ($existingPackageItem) {
                        $existingPackageItem->delete();
                        $serviceInvoiceChanged = true;
                    }
                    if ($existingAgentItem) {
                        $existingAgentItem->delete();
                        $serviceInvoiceChanged = true;
                    }
                } else {
                    if ($existingPackageItem) {
                        $existingPackageItem->delete();
                        $serviceInvoiceChanged = true;
                    }
                    if ($existingAgentItem) {
                        $existingAgentItem->delete();
                        $serviceInvoiceChanged = true;
                    }
                    if ($existingCodeItem) {
                        $existingCodeItem->delete();
                        $serviceInvoiceChanged = true;
                    }
                }

                if ($serviceInvoiceChanged) {
                    $invoice->recalculateTotals();
                }
            }
        }

        // Sync order and invoice statuses after recalculating
        static::syncOrderStatus($order);
    }

    /**
     * Sync fixed Other Area and allowance-excess charges on invoices,
     * attached to the area OrderService.
     */
    private static function syncAreaChargeItems(Invoice $invoice, array $metrics, float $multiplier, ?int $orderServiceId = null): bool
    {
        $descriptions = [
            'fixed' => 'Other Areas fixed charges',
            'allowance' => 'Other Areas above free allowance',
        ];
        $amounts = [
            'fixed' => round((float) ($metrics['custom_other_charges'] ?? 0) * $multiplier, 2),
            'allowance' => round((float) ($metrics['excess_other_charge'] ?? 0) * $multiplier, 2),
        ];
        $existing = $invoice->items()
            ->where('is_extra', true)
            ->whereIn('description', array_values($descriptions))
            ->get()
            ->keyBy(function ($item) use ($descriptions) {
                return array_search($item->description, $descriptions, true);
            });

        $changed = false;
        foreach ($amounts as $key => $amount) {
            $item = $existing->get($key);
            if ($amount <= 0) {
                if ($item) {
                    $item->delete();
                    $changed = true;
                }
                continue;
            }

            $description = $descriptions[$key];
            if (!$item) {
                $invoice->items()->create([
                    'order_service_id' => $orderServiceId,
                    'is_extra' => true,
                    'description' => $description,
                    'quantity' => 1,
                    'unit_price' => $amount,
                    'amount' => $amount,
                ]);
                $changed = true;
                continue;
            }

            $updates = [];
            if ((float) $item->unit_price !== $amount || (float) $item->amount !== $amount) {
                $updates['unit_price'] = $amount;
                $updates['amount'] = $amount;
            }
            if ($orderServiceId && $item->order_service_id !== $orderServiceId) {
                $updates['order_service_id'] = $orderServiceId;
            }
            if (!empty($updates)) {
                $item->update($updates);
                $changed = true;
            }
        }

        return $changed;
    }
}
