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
        if (empty($matchedServiceIds) && $packageId) {
            $package = $order->package ?? \App\Models\Package::find($packageId);
            if ($package) {
                $matchedServiceIds = $package->services->pluck('id')->toArray();
            }
        }
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

        $matchedTotal = (float) $matchedInInvoice->sum(fn($os) => static::getEffectiveServiceAmount($order, $os));
        if ($matchedTotal <= 0) {
            return null;
        }

        $discountAmount = 0.0;
        $discountType = $packageInfo['package_discount_type'] ?? 'percentage';

        if ($discountType === 'dollar') {
            $order->loadMissing('services');
            $totalOrderMatched = (float) $order->services
                ->filter(fn($os) => in_array($os->service_id, $matchedServiceIds))
                ->sum(fn($os) => static::getEffectiveServiceAmount($order, $os));
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
     */
    public static function resolveAgentDiscountForInvoice(Order $order, $services, float $multiplier = 1.0): ?array
    {
        $discountData = $order->meta['agent_discount'] ?? null;
        if (empty($discountData) && $order->agent) {
            $agent = $order->agent;
            if (!empty($agent->agent_discount)) {
                $discountData = $agent->agent_discount;
            }
        }

        if (empty($discountData)) {
            return null;
        }

        $isActive = filter_var($discountData['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN);
        if (!$isActive) {
            return null;
        }

        // Check expiry date
        if (!empty($discountData['expiry_date'])) {
            $expiry = \Carbon\Carbon::parse($discountData['expiry_date'])->endOfDay();
            if ($expiry->isPast()) {
                return null;
            }
        }

        // Check minimum orders if specified
        if (!empty($discountData['minimum_orders']) && (int)$discountData['minimum_orders'] > 0) {
            $agent = $order->agent ?? \App\Models\Agent::find($order->agent_id);
            $orderCount = $agent ? $agent->orders()->count() : 0;
            if ($orderCount < (int)$discountData['minimum_orders']) {
                return null;
            }
        }

        // Check minimum spend against entire order total if specified
        if (!empty($discountData['minimum_spend']) && (float)$discountData['minimum_spend'] > 0) {
            $order->loadMissing('services');
            $orderBaseTotal = (float) $order->services->sum(fn($os) => static::getEffectiveServiceAmount($order, $os));
            if ($orderBaseTotal <= 0 && isset($order->amount)) {
                $orderBaseTotal = (float)$order->amount;
            }
            if ($orderBaseTotal < (float)$discountData['minimum_spend']) {
                return null;
            }
        }

        $amount = (float)($discountData['amount'] ?? 0);
        if ($amount <= 0) {
            return null;
        }

        $isPercentage = filter_var($discountData['is_percentage'] ?? true, FILTER_VALIDATE_BOOLEAN);

        $servicesCollection = collect($services);
        // Includes standalone area charges (custom fixed charges + excess allowance) if applicable
        $currentServicesTotal = (float) $servicesCollection->sum(fn($os) => static::getEffectiveServiceAmount($order, $os));
        if ($currentServicesTotal <= 0) {
            return null;
        }

        $discountAmount = 0.0;
        if ($isPercentage) {
            $discountAmount = round(($currentServicesTotal * ($amount / 100)) * $multiplier, 2);
        } else {
            $order->loadMissing('services');
            $totalOrderAmount = (float) $order->services->sum(fn($os) => static::getEffectiveServiceAmount($order, $os));
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
            'rate' => $amount,
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
        // Includes standalone area charges (custom fixed charges + excess allowance) if applicable
        $currentServicesTotal = (float) $servicesCollection->sum(fn($os) => static::getEffectiveServiceAmount($order, $os));
        if ($currentServicesTotal <= 0) {
            return null;
        }

        $order->loadMissing('services');
        $totalOrderAmount = (float) $order->services->sum(fn($os) => static::getEffectiveServiceAmount($order, $os));
        $ratio = $totalOrderAmount > 0 ? ($currentServicesTotal / $totalOrderAmount) : 1.0;

        $totalDiscountAmount = 0.0;
        $discountNames = [];

        foreach ($discountsData as $disc) {
            $discountId = $disc['discount_id'] ?? $disc['id'] ?? $disc['uuid'] ?? $disc['code'] ?? null;
            if (!$discountId && empty($disc['value'])) continue;

            $discModel = null;
            if ($discountId) {
                if (is_numeric($discountId)) {
                    $discModel = \App\Models\Discount::find((int)$discountId);
                } elseif (\Illuminate\Support\Str::isUuid((string)$discountId)) {
                    $discModel = \App\Models\Discount::where('uuid', $discountId)->first();
                } else {
                    $discModel = \App\Models\Discount::where('code_key', $discountId)->first();
                }
            }

            // Determine percentage rate vs fixed dollar discount
            $pctRate = null;
            if (!empty($discModel?->percentage) && (float)$discModel->percentage > 0) {
                $pctRate = (float)$discModel->percentage;
            } elseif (!empty($disc['percentage']) && (float)$disc['percentage'] > 0) {
                $pctRate = (float)$disc['percentage'];
            } elseif (!empty($disc['is_percentage']) && filter_var($disc['is_percentage'], FILTER_VALIDATE_BOOLEAN)) {
                $pctRate = (float)($disc['value'] ?? 0);
            }

            $isPercentage = ($pctRate !== null && $pctRate > 0);
            $fixedVal = (float)($disc['value'] ?? $discModel?->amount ?? $discModel?->quantity ?? 0);
            if (!$isPercentage && $fixedVal <= 0) continue;

            // Service-scoped discount: check if this service matches
            $serviceId = $disc['service_id'] ?? null;
            if ($serviceId) {
                $matchingService = $servicesCollection->first(function ($os) use ($serviceId) {
                    return $os->id == $serviceId || $os->service_id == $serviceId || (($os->service?->uuid ?? null) === $serviceId);
                });
                if (!$matchingService) {
                    continue;
                }
                $svcAmount = static::getEffectiveServiceAmount($order, $matchingService);
                $discAmt = $isPercentage
                    ? round($svcAmount * ($pctRate / 100), 2)
                    : min($svcAmount, $fixedVal);
                $totalDiscountAmount += round($discAmt * $multiplier, 2);
            } else {
                // Order-wide discount: distribute proportionally
                $fullDiscAmt = $isPercentage
                    ? round($totalOrderAmount * ($pctRate / 100), 2)
                    : min($totalOrderAmount, $fixedVal);
                $totalDiscountAmount += round($fullDiscAmt * $ratio * $multiplier, 2);
            }

            $name = $discModel?->name ?? $discModel?->code_key ?? $disc['code'] ?? $disc['name'] ?? 'Coupon';
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
     * Determine the winning discount type for the entire order under Option B rules.
     * Option B is an order-level decision: whichever single discount produces the greatest
     * absolute savings across all order services governs every invoice of that order.
     */
    public static function getOrderWinningDiscountType(Order $order): string
    {
        $meta = $order->meta ?? [];
        if (!empty($meta['winning_discount'])) {
            return $meta['winning_discount'];
        }

        $order->loadMissing('services');
        $allServices = $order->services;
        $pkg   = static::resolvePackageDiscountForServices($order, $allServices, 1.0);
        $agent = static::resolveAgentDiscountForInvoice($order, $allServices, 1.0);
        $code  = static::resolveCodeDiscountsForInvoice($order, $allServices, 1.0);

        return static::pickWinningDiscount($pkg, $agent, $code);
    }

    /**
     * Resolve the winning discount for a single service line item under the Per-Service Maximum Discount Engine.
     * Evaluates Candidate Package, Candidate Agent, and Candidate Coupon, and returns the one giving the largest savings.
     * Tie-breaking preference: agent >= package >= code.
     */
    public static function resolveServiceWinningDiscount(Order $order, $orderService, float $multiplier = 1.0): ?array
    {
        // Guard: check if the service item is explicitly marked non-discountable
        $isDiscountable = true;
        if (is_object($orderService)) {
            if (isset($orderService->is_discountable) && !$orderService->is_discountable) {
                $isDiscountable = false;
            } elseif (isset($orderService->service) && is_object($orderService->service) && isset($orderService->service->is_discountable) && !$orderService->service->is_discountable) {
                $isDiscountable = false;
            }
        } elseif (is_array($orderService)) {
            if (isset($orderService['is_discountable']) && !$orderService['is_discountable']) {
                $isDiscountable = false;
            }
        }
        if (!$isDiscountable) {
            return null;
        }

        $pkgCandidate   = static::resolvePackageDiscountForServices($order, collect([$orderService]), $multiplier);
        $agentCandidate = static::resolveAgentDiscountForInvoice($order, collect([$orderService]), $multiplier);
        $codeCandidate  = static::resolveCodeDiscountsForInvoice($order, collect([$orderService]), $multiplier);

        $rawPkg   = $pkgCandidate   ? abs((float)($pkgCandidate['amount']   ?? 0)) : 0.0;
        $rawAgent = $agentCandidate ? abs((float)($agentCandidate['amount'] ?? 0)) : 0.0;
        $rawCode  = $codeCandidate  ? abs((float)($codeCandidate['amount']  ?? 0)) : 0.0;

        $maxVal = max($rawPkg, $rawAgent, $rawCode);
        if ($maxVal <= 0.001) {
            return null;
        }

        // Tie-break priority: Agent >= Package >= Code
        if ($rawAgent >= $maxVal) {
            $agentCandidate['type'] = 'agent_discount';
            return $agentCandidate;
        }
        if ($rawPkg >= $maxVal) {
            $pkgCandidate['type'] = 'package_discount';
            return $pkgCandidate;
        }
        $codeCandidate['type'] = 'code_discount';
        return $codeCandidate;
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

        $isSingleService = collect($services)->count() === 1;

        $preparedItems = [];
        foreach ($services as $os) {
            $isPerSqft = static::isPerSqftRateService($os);
            // On consolidated invoice, roll totalAreaCharges into the per-sqft service line item
            $svcBase = (float)$os->amount;
            if (!$isSingleService && $isPerSqft) {
                $metrics = static::getOrderAreaMetrics($order);
                $svcBase += (float)($metrics['total_area_charges'] ?? 0);
            }
            $amount = $svcBase * $multiplier;
            $serviceModel = $os->service ?? null;
            $itemDescription = ($os->custom ?: ($serviceModel->name ?? 'Service')) . ($multiplier < 1 ? " (" . ($multiplier * 100) . "%)" : "");

            $preparedItems[] = [
                'order_service' => $os,
                'service' => $serviceModel,
                'amount' => $amount,
                'description' => $itemDescription,
            ];
        }

        if ($isSingleService) {
            $singleService = collect($services)->first();
            $metrics = static::getOrderAreaMetrics($order);
            $fixedOther = round((float)($metrics['custom_other_charges'] ?? 0) * $multiplier, 2);
            $excessOther = round((float)($metrics['excess_other_charge'] ?? 0) * $multiplier, 2);
            if ($fixedOther > 0) {
                $preparedItems[] = [
                    'order_service' => $singleService,
                    'service' => $singleService->service,
                    'amount' => $fixedOther,
                    'description' => 'Other Areas fixed charges',
                    'is_extra' => true,
                ];
            }
            if ($excessOther > 0) {
                $preparedItems[] = [
                    'order_service' => $singleService,
                    'service' => $singleService->service,
                    'amount' => $excessOther,
                    'description' => 'Other Areas above free allowance',
                    'is_extra' => true,
                ];
            }

            $winner = static::resolveServiceWinningDiscount($order, $singleService, $multiplier);
            if ($winner) {
                $type = $winner['type'] ?? '';
                $preparedItems[] = [
                    'order_service' => $singleService,
                    'service' => null,
                    'amount' => $winner['amount'],
                    'description' => $winner['description'],
                    'is_taxable' => true,
                    'is_package_discount' => ($type === 'package_discount'),
                    'is_agent_discount'   => ($type === 'agent_discount'),
                    'is_code_discount'    => ($type === 'code_discount'),
                    'package_id'          => $winner['package_id'] ?? null,
                ];
            }
        } else {
            // Consolidated invoice: aggregate winning discounts by category across all services
            $totalPkgDiscount = 0.0;
            $pkgPstDiscount = 0.0;
            $pkgGstDiscount = 0.0;
            $pkgName = 'Package';
            $pkgId = $order->package_id ?? null;

            $totalAgentDiscount = 0.0;
            $agentPstDiscount = 0.0;
            $agentGstDiscount = 0.0;
            $agentName = 'Agent Discount';

            $totalCodeDiscount = 0.0;
            $codePstDiscount = 0.0;
            $codeGstDiscount = 0.0;
            $codeNames = [];

            foreach ($services as $os) {
                $winner = static::resolveServiceWinningDiscount($order, $os, $multiplier);
                if (!$winner) continue;

                $amt = abs((float)($winner['amount'] ?? 0));
                if ($amt <= 0) continue;

                [$hasGst, $hasPst] = \App\Http\Controllers\Api\InvoiceController::resolveServiceTaxFlags($os->service, $order->organization_id);

                $type = $winner['type'] ?? '';
                if ($type === 'package_discount') {
                    $totalPkgDiscount += $amt;
                    if ($hasPst) $pkgPstDiscount += $amt;
                    if ($hasGst) $pkgGstDiscount += $amt;
                    if (!empty($winner['package_name'])) $pkgName = $winner['package_name'];
                    if (!empty($winner['package_id'])) $pkgId = $winner['package_id'];
                } elseif ($type === 'agent_discount') {
                    $totalAgentDiscount += $amt;
                    if ($hasPst) $agentPstDiscount += $amt;
                    if ($hasGst) $agentGstDiscount += $amt;
                    if (!empty($winner['name'])) $agentName = $winner['name'];
                } elseif ($type === 'code_discount') {
                    $totalCodeDiscount += $amt;
                    if ($hasPst) $codePstDiscount += $amt;
                    if ($hasGst) $codeGstDiscount += $amt;
                    $desc = $winner['description'] ?? 'Coupon';
                    if (!in_array($desc, $codeNames)) $codeNames[] = $desc;
                }
            }

            if ($totalPkgDiscount > 0) {
                $pkgInfo = $order->meta['applied_package'] ?? null;
                $pctText = $pkgInfo['package_discount_percentage'] ?? $pkgInfo['package_discount'] ?? null;
                $desc = "Package Discount: {$pkgName}";
                if ($pctText) $desc .= " ({$pctText}%)";
                if ($multiplier < 1) $desc .= " (" . round($multiplier * 100, 1) . "%)";

                $preparedItems[] = [
                    'order_service' => null,
                    'service' => null,
                    'amount' => -round($totalPkgDiscount, 2),
                    'description' => $desc,
                    'is_taxable' => true,
                    'is_package_discount' => true,
                    'package_id' => $pkgId,
                    'pst_applicable_amount' => -round($pkgPstDiscount, 2),
                    'gst_applicable_amount' => -round($pkgGstDiscount, 2),
                    'pst_enabled' => ($pkgPstDiscount > 0),
                    'gst_enabled' => ($pkgGstDiscount > 0),
                ];
            }

            if ($totalAgentDiscount > 0) {
                $agentData = $order->meta['agent_discount'] ?? ($order->agent?->agent_discount ?? []);
                $rate = $agentData['amount'] ?? null;
                $isPct = filter_var($agentData['is_percentage'] ?? true, FILTER_VALIDATE_BOOLEAN);
                $desc = "Agent Discount: {$agentName}";
                if ($rate) $desc .= $isPct ? " ({$rate}%)" : " (\${$rate} OFF)";
                if ($multiplier < 1) $desc .= " (" . round($multiplier * 100, 1) . "%)";

                $preparedItems[] = [
                    'order_service' => null,
                    'service' => null,
                    'amount' => -round($totalAgentDiscount, 2),
                    'description' => $desc,
                    'is_taxable' => true,
                    'is_agent_discount' => true,
                    'pst_applicable_amount' => -round($agentPstDiscount, 2),
                    'gst_applicable_amount' => -round($agentGstDiscount, 2),
                    'pst_enabled' => ($agentPstDiscount > 0),
                    'gst_enabled' => ($agentGstDiscount > 0),
                ];
            }

            if ($totalCodeDiscount > 0) {
                $desc = !empty($codeNames) ? implode(', ', $codeNames) : 'Coupon Discount';
                if ($multiplier < 1) $desc .= " (" . round($multiplier * 100, 1) . "%)";

                $preparedItems[] = [
                    'order_service' => null,
                    'service' => null,
                    'amount' => -round($totalCodeDiscount, 2),
                    'description' => $desc,
                    'is_taxable' => true,
                    'is_code_discount' => true,
                    'pst_applicable_amount' => -round($codePstDiscount, 2),
                    'gst_applicable_amount' => -round($codeGstDiscount, 2),
                    'pst_enabled' => ($codePstDiscount > 0),
                    'gst_enabled' => ($codeGstDiscount > 0),
                ];
            }
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
                $meta = ['type' => 'package_discount', 'package_id' => $prep['package_id'] ?? null];
            } elseif ($isAgentDisc) {
                $meta = ['type' => 'agent_discount'];
            } elseif ($isCodeDisc) {
                $meta = ['type' => 'code_discount'];
            }
            if ($meta) {
                if (isset($prep['pst_applicable_amount'])) {
                    $meta['pst_applicable_amount'] = $prep['pst_applicable_amount'];
                }
                if (isset($prep['gst_applicable_amount'])) {
                    $meta['gst_applicable_amount'] = $prep['gst_applicable_amount'];
                }
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
        $this->load(['items.orderService.service', 'items.orderService.option', 'agent', 'order.property', 'order.services.service']);

        $province = 'BC';
        if (isset($this->order->property->state) && !empty($this->order->property->state)) {
            $province = $this->order->property->state;
        } elseif (isset($this->order->property->province) && !empty($this->order->property->province)) {
            $province = $this->order->property->province;
        } elseif (!empty($this->agent->headquarter_province)) {
            $province = $this->agent->headquarter_province;
        }

        $country = $this->order->property->country ?? 'CA';

        $distinctServices = $this->items->where('is_extra', false)->pluck('order_service_id')->filter()->unique();
        $isServiceInvoice = ($distinctServices->count() === 1 && !str_starts_with($this->notes ?? '', 'Consolidated'));

        $calcItemsInput = [];
        $itemMetaUpdates = [];
        foreach ($this->items as $idx => $item) {
            $qty = $item->quantity ?: 1;
            $price = (float)$item->unit_price;
            $amount = round($qty * $price, 2);

            $service = $item->orderService?->service;
            $metaType = $item->meta['type'] ?? null;
            $desc = $item->description ?? '';
            $isPkgDisc = $metaType === 'package_discount' || str_starts_with($desc, 'Package Discount');
            $isAgentDisc = $metaType === 'agent_discount' || str_starts_with($desc, 'Agent Discount');
            $isCodeDisc = $metaType === 'code_discount' || str_starts_with($desc, 'Coupon Discount') || str_starts_with($desc, 'Promo Discount');
            $isDiscount = $isPkgDisc || $isAgentDisc || $isCodeDisc || $amount < 0;

            $isTaxable = null;
            if ($item->gst_enabled !== null || $item->pst_enabled !== null) {
                $isTaxable = (bool)$item->gst_enabled || (bool)$item->pst_enabled;
            } elseif ($isDiscount) {
                $isTaxable = true;
            }

            $pstApplicable = isset($item->meta['pst_applicable_amount']) ? (float)$item->meta['pst_applicable_amount'] : null;
            $gstApplicable = isset($item->meta['gst_applicable_amount']) ? (float)$item->meta['gst_applicable_amount'] : null;

            // Fallback for legacy discount items that do not yet have pst_applicable_amount in meta:
            if ($isDiscount && $pstApplicable === null && $this->order) {
                $multiplier = (float)($this->split_details['percentage'] ?? 100) / 100;
                if ($multiplier <= 0) $multiplier = 1.0;

                if ($isServiceInvoice) {
                    $orderSvc = $this->order->services->firstWhere('id', $distinctServices->first());
                    if ($orderSvc && $orderSvc->service) {
                        [$hasGst, $hasPst] = \App\Http\Controllers\Api\InvoiceController::resolveServiceTaxFlags($orderSvc->service, $this->organization_id);
                        $pstApplicable = $hasPst ? $amount : 0.0;
                        $gstApplicable = $hasGst ? $amount : 0.0;
                    }
                } else {
                    // Consolidated invoice: aggregate from order services for this discount category
                    $targetType = $isPkgDisc ? 'package_discount' : ($isAgentDisc ? 'agent_discount' : ($isCodeDisc ? 'code_discount' : null));
                    if ($targetType) {
                        $discPst = 0.0;
                        $discGst = 0.0;
                        foreach ($this->order->services as $os) {
                            $winner = static::resolveServiceWinningDiscount($this->order, $os, $multiplier);
                            if (!$winner || ($winner['type'] ?? '') !== $targetType) continue;
                            $wAmt = abs((float)($winner['amount'] ?? 0));
                            if ($wAmt <= 0) continue;
                            [$hasGst, $hasPst] = \App\Http\Controllers\Api\InvoiceController::resolveServiceTaxFlags($os->service, $this->organization_id);
                            if ($hasPst) $discPst += $wAmt;
                            if ($hasGst) $discGst += $wAmt;
                        }
                        $pstApplicable = -round($discPst, 2);
                        $gstApplicable = -round($discGst, 2);
                    }
                }
            }

            $input = [
                'amount' => $amount,
                'service' => $service,
                'is_taxable' => $isTaxable,
                'is_package_discount' => $isPkgDisc,
                'is_agent_discount' => $isAgentDisc,
                'is_code_discount' => $isCodeDisc,
                'description' => $item->description,
                'gst_enabled' => $item->gst_enabled ?? ($gstApplicable !== null ? ($gstApplicable < 0) : true),
                'pst_enabled' => $item->pst_enabled ?? ($pstApplicable !== null ? ($pstApplicable < 0) : false),
            ];

            if ($pstApplicable !== null) {
                $input['pst_applicable_amount'] = $pstApplicable;
                $input['pst_enabled'] = ($pstApplicable < 0);
            }
            if ($gstApplicable !== null) {
                $input['gst_applicable_amount'] = $gstApplicable;
                $input['gst_enabled'] = ($gstApplicable < 0);
            }

            if ($isDiscount && ($pstApplicable !== null || $gstApplicable !== null)) {
                $meta = $item->meta ?? [];
                if ($pstApplicable !== null) $meta['pst_applicable_amount'] = $pstApplicable;
                if ($gstApplicable !== null) $meta['gst_applicable_amount'] = $gstApplicable;
                $itemMetaUpdates[$idx] = $meta;
            }

            $calcItemsInput[] = $input;
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
            $updateData = [
                'amount' => $amount,
                'tax_amount' => $calcItem['tax_amount'] ?? 0.0,
                'gst_amount' => $calcItem['gst_amount'] ?? ($calcItem['tax_breakdown']['GST'] ?? 0.0),
                'pst_amount' => $calcItem['pst_amount'] ?? ($calcItem['tax_breakdown']['PST'] ?? 0.0),
                'gst_enabled' => $calcItem['gst_enabled'] ?? true,
                'pst_enabled' => $calcItem['pst_enabled'] ?? false,
            ];
            if (isset($itemMetaUpdates[$idx])) {
                $updateData['meta'] = $itemMetaUpdates[$idx];
            }
            $item->update($updateData);
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
             ->with(['items.orderService'])
             ->get();

         // Check if the order itself or any main/consolidated invoice is refunded
         $isOrderRefunded = ($order->payment_status === 'REFUNDED') || $invoices->contains(function($inv) {
             if ($inv->status !== 'refunded') return false;
             $notes = strtolower($inv->notes ?? '');
             if (str_contains($notes, 'consolidated') || str_contains($notes, 'main invoice') || str_contains($notes, 'order invoice') || str_contains($notes, 'add-on service invoice')) {
                 return true;
             }
             if (str_contains($notes, 'service invoice:') || str_contains($notes, 'service invoice')) {
                 return false;
             }
             $distinctServices = $inv->items->where('is_extra', false)->pluck('order_service_id')->filter()->unique();
             return $distinctServices->count() > 1;
         });

         if ($isOrderRefunded) {
             foreach ($invoices as $invoice) {
                 if ($invoice->status === 'void') continue;
                 $refAmt = (float)$invoice->refunded_amount > 0 
                     ? (float)$invoice->refunded_amount 
                     : ((float)$invoice->paid_amount > 0 ? (float)$invoice->paid_amount : (float)$invoice->total);
                 $invoice->update([
                     'status' => 'refunded',
                     'refunded_amount' => $refAmt,
                     'paid_amount' => $refAmt,
                 ]);
             }
             if ($order->payment_status !== 'REFUNDED') {
                 $order->update(['payment_status' => 'REFUNDED']);
             }
             return;
         }
         
         foreach ($invoices as $invoice) {
             if ($invoice->status === 'refunded') {
                 continue;
             }

             $allPaid = true;
             $allRefunded = true;
             $hasRefunded = false;
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
                 if ((float)$item->amount <= 0) {
                     continue; // Skip discount / zero / negative items for service status checks
                 }
                 $hasItems = true;

                 $isItemRefunded = ($item->orderService && $item->orderService->payment_status === 'REFUNDED')
                     || (float)$item->refunded_amount > 0
                     || ($invoice->status === 'refunded')
                     || ($order->payment_status === 'REFUNDED');
                 $servicePaidForInvoiceOwner = !empty($invoice->split_details['splits'])
                     ? ($item->order_service_id && $paidServiceIdsForAgent->contains($item->order_service_id))
                     : ($item->orderService && $item->orderService->payment_status === 'PAID');

                 if ($isItemRefunded) {
                     $hasRefunded = true;
                 } else {
                     $allRefunded = false;
                 }

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

             // If all services on this invoice are refunded, mark invoice refunded
             if ($allRefunded) {
                 $refAmt = (float)$invoice->refunded_amount > 0 
                     ? (float)$invoice->refunded_amount 
                     : ((float)$invoice->paid_amount > 0 ? (float)$invoice->paid_amount : (float)$invoice->total);
                 $invoice->update([
                     'status' => 'refunded',
                     'refunded_amount' => $refAmt,
                     'paid_amount' => $refAmt,
                 ]);
                 continue;
             }

             // If all services in this invoice are paid (or all positive items are paid),
             // the calculated paid amount should exactly match the invoice's total (minus any refund).
             if ($allPaid || ($totalPositiveServiceSubtotal > 0 && $paidServiceSubtotal >= $totalPositiveServiceSubtotal)) {
                 $calculatedPaidAmount = (float) $invoice->total;
             } else {
                 // Check if there are actual payments on sibling service invoices for the paid services
                 $paidOrderServiceIds = $invoice->items
                     ->filter(function($item) use ($invoice, $paidServiceIdsForAgent) {
                         if (!$item->order_service_id) return false;
                         return !empty($invoice->split_details['splits'])
                             ? $paidServiceIdsForAgent->contains($item->order_service_id)
                             : ($item->orderService && $item->orderService->payment_status === 'PAID');
                     })
                     ->pluck('order_service_id')
                     ->filter()
                     ->toArray();

                 $actualServicePayments = 0;
                 if (!empty($paidOrderServiceIds)) {
                     // 1. Direct payments explicitly assigned to these order services
                     $servicePayments = AgentPayment::where('order_id', $invoice->order_id)
                         ->whereIn('order_service_id', $paidOrderServiceIds)
                         ->where('status', 'succeeded')
                         ->where('payment_type', '!=', 'refund')
                         ->get()
                         ->unique(fn($p) => $p->stripe_session_id ?: ($p->stripe_payment_intent_id ?: $p->id));

                     $coveredServiceIds = $servicePayments->pluck('order_service_id')->filter()->unique()->toArray();
                     $actualServicePayments += (float) $servicePayments->sum('amount');

                     // 2. For paid services not covered by direct order_service_id payments, sum from sibling service invoices
                     $uncoveredServiceIds = array_diff($paidOrderServiceIds, $coveredServiceIds);
                     if (!empty($uncoveredServiceIds)) {
                         $siblingInvoices = static::where('order_id', $invoice->order_id)
                             ->where('id', '!=', $invoice->id)
                             ->whereIn('status', ['paid', 'partially_paid'])
                             ->whereHas('items', function($q) use ($uncoveredServiceIds) {
                                 $q->whereIn('order_service_id', $uncoveredServiceIds);
                             })
                             ->with('payments')
                             ->get();

                         foreach ($siblingInvoices as $sibInv) {
                             $sibDirectPaid = (float) $sibInv->payments
                                 ->where('status', 'succeeded')
                                 ->where('payment_type', '!=', 'refund')
                                 ->unique(fn($p) => $p->stripe_session_id ?: ($p->stripe_payment_intent_id ?: $p->id))
                                 ->sum('amount');

                             $actualServicePayments += ($sibDirectPaid > 0 ? $sibDirectPaid : (float) $sibInv->paid_amount);
                         }
                     }
                 }

                 if ($actualServicePayments > 0) {
                     $calculatedPaidAmount = round($actualServicePayments, 2);
                 } else {
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
                 }
             }
             
             // Direct payments recorded specifically on this invoice (e.g. Stripe checkout or manual payment)
             $directNetPaid = (float) $invoice->payments()
                 ->where('status', 'succeeded')
                 ->where('payment_type', '!=', 'refund')
                 ->sum('amount');

             // Take the higher of direct payments received on this invoice vs the calculated share of paid services
             $newNetPaid = max($directNetPaid, $calculatedPaidAmount);
             
             // Check if remaining net balance is 2 cents or less when all services are covered (absorbs rounding differences)
             $remainingNet = round((float)$invoice->total - $newNetPaid, 2);
             if ($remainingNet <= 0.02 && $newNetPaid > 0 && ($allPaid || ($totalPositiveServiceSubtotal > 0 && $paidServiceSubtotal >= $totalPositiveServiceSubtotal))) {
                 $newNetPaid = (float)$invoice->total;
             }

             // Add back the refunded amount to get the gross paid amount to store
             $newPaidAmount = round($newNetPaid + (float)$invoice->refunded_amount, 2);
             
             // Cap gross paid_amount at total + refunded_amount to keep net paid capped at total
             $maxAllowedGross = round((float)$invoice->total + (float)$invoice->refunded_amount, 2);
             if ($newPaidAmount > $maxAllowedGross) {
                 $newPaidAmount = $maxAllowedGross;
                 $newNetPaid = round($newPaidAmount - (float)$invoice->refunded_amount, 2);
             }
 
             $isFullyPaid = ($newNetPaid >= (float)$invoice->total) || $allPaid;

             $updateData = [
                 'paid_amount' => $newPaidAmount,
             ];

             if ($isFullyPaid && !in_array($invoice->status, ['paid', 'partially_refunded'])) {
                 $updateData['status'] = ($hasRefunded || (float)$invoice->refunded_amount > 0) ? 'partially_refunded' : 'paid';
                 $updateData['paid_at'] = $invoice->paid_at ?? now();
                 $updateData['paid_amount'] = (float)$invoice->refunded_amount > 0 
                     ? round((float)$invoice->total + (float)$invoice->refunded_amount, 2) 
                     : $invoice->total; // Ensure it's exactly total (plus refunded_amount if applicable)
             } elseif (!$isFullyPaid && $newNetPaid > 0) {
                if ($hasRefunded || (float)$invoice->refunded_amount > 0) {
                    $updateData['status'] = 'partially_refunded';
                } else {
                    $updateData['status'] = 'partially_paid';
                }
             } elseif ($newNetPaid <= 0) {
                 if ($allRefunded || ((float)$invoice->total > 0 && (float)$invoice->refunded_amount >= (float)$invoice->total)) {
                     $updateData['status'] = 'refunded';
                 } elseif ($hasRefunded || (float)$invoice->refunded_amount > 0) {
                     $updateData['status'] = 'partially_refunded';
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
    public static function isPerSqftRateService($os): bool
    {
        if (!$os) {
            return false;
        }
        $option = null;
        if (is_object($os)) {
            if (isset($os->option)) {
                $option = $os->option;
            } elseif (isset($os->option_id) && !empty($os->option_id)) {
                $option = \App\Models\ProductOption::find($os->option_id);
            }
        } elseif (is_array($os)) {
            if (isset($os['option'])) {
                $option = $os['option'];
            } elseif (!empty($os['option_id'])) {
                $option = \App\Models\ProductOption::find($os['option_id']);
            }
        }
        if (!$option) {
            return false;
        }
        $sqFtRate = is_object($option) ? ($option->sq_ft_rate ?? null) : ($option['sq_ft_rate'] ?? null);
        $sqFtRange = is_object($option) ? ($option->sq_ft_range ?? null) : ($option['sq_ft_range'] ?? null);

        return !empty($sqFtRate) && (float)$sqFtRate > 0 && empty($sqFtRange);
    }

    protected static array $orderAreaMetricsCache = [];

    /**
     * Get or calculate cached area metrics for an order.
     */
    public static function getOrderAreaMetrics(Order $order): array
    {
        $cacheKey = $order->id ?? spl_object_hash($order);
        if (isset(static::$orderAreaMetricsCache[$cacheKey])) {
            return static::$orderAreaMetricsCache[$cacheKey];
        }

        if (!$order->relationLoaded('areas')) {
            $order->loadMissing('areas');
        }

        if (!$order->areas || $order->areas->isEmpty()) {
            static::$orderAreaMetricsCache[$cacheKey] = [];
            return [];
        }

        static::$orderAreaMetricsCache[$cacheKey] = app(\App\Services\AreaPricingService::class)
            ->calculateAreaMetrics($order->areas->map(fn($area) => [
                'type' => is_object($area) ? ($area->type ?? '') : ($area['type'] ?? ''),
                'footage' => is_object($area) ? ($area->footage ?? 0) : ($area['footage'] ?? 0),
                'custom_title' => is_object($area) ? ($area->custom_title ?? '') : ($area['custom_title'] ?? ''),
            ])->toArray(), $order->organization_id);

        return static::$orderAreaMetricsCache[$cacheKey];
    }

    /**
     * Get the effective gross amount for an OrderService, including standalone area charges
     * (custom fixed charges + excess allowance charges) if it is a per-sqft rate service.
     */
    public static function getEffectiveServiceAmount(Order $order, $os): float
    {
        $baseAmount = is_object($os) ? (float)($os->amount ?? 0) : (float)($os['amount'] ?? 0);
        if (!static::isPerSqftRateService($os)) {
            return $baseAmount;
        }

        if (!$order->relationLoaded('services')) {
            $order->loadMissing('services.option');
        }

        $allPerSqft = $order->services ? $order->services->filter(fn($s) => static::isPerSqftRateService($s)) : collect();
        $primaryPerSqftId = is_object($allPerSqft->first()) ? $allPerSqft->first()->id : ($allPerSqft->first()['id'] ?? null);
        $osId = is_object($os) ? ($os->id ?? null) : ($os['id'] ?? null);

        // Only attach area charges to the primary per-sqft service to avoid duplicate addition
        if ($osId && $primaryPerSqftId && $osId != $primaryPerSqftId) {
            return $baseAmount;
        }

        $metrics = static::getOrderAreaMetrics($order);
        $totalAreaCharges = (float)($metrics['total_area_charges'] ?? 0);

        return round($baseAmount + $totalAreaCharges, 2);
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

        static::$orderAreaMetricsCache[$order->id ?? spl_object_hash($order)] = $areaMetrics;
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

            // Historical Data Guard: Never recompute or alter paid or refunded invoices
            if (in_array($invoice->status, ['paid', 'refunded']) || (float)$invoice->paid_amount > 0) {
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

                // 4. Sync package/agent/code discount items on consolidated invoice — multi-category line items under Per-Service Best Discount Engine.
                // Evaluates each service's winning discount and aggregates by category.
                $totalPkgDiscount = 0.0;
                $pkgPstDiscount = 0.0;
                $pkgGstDiscount = 0.0;
                $pkgDiscountName = 'Package';
                $pkgId = $order->package_id ?? null;

                $totalAgentDiscount = 0.0;
                $agentPstDiscount = 0.0;
                $agentGstDiscount = 0.0;
                $agentDiscountName = 'Agent Discount';
                $agentRate = null;

                $totalCodeDiscount = 0.0;
                $codePstDiscount = 0.0;
                $codeGstDiscount = 0.0;
                $codeDiscountNames = [];

                foreach ($allOrderServices as $os) {
                    $winner = static::resolveServiceWinningDiscount($order, $os, $multiplier);
                    if (!$winner) continue;

                    $amt = abs((float)($winner['amount'] ?? 0));
                    if ($amt <= 0) continue;

                    [$hasGst, $hasPst] = \App\Http\Controllers\Api\InvoiceController::resolveServiceTaxFlags($os->service, $order->organization_id);

                    $type = $winner['type'] ?? '';
                    if ($type === 'package_discount') {
                        $totalPkgDiscount += $amt;
                        if ($hasPst) $pkgPstDiscount += $amt;
                        if ($hasGst) $pkgGstDiscount += $amt;
                        if (!empty($winner['package_name'])) $pkgDiscountName = $winner['package_name'];
                        if (!empty($winner['package_id'])) $pkgId = $winner['package_id'];
                    } elseif ($type === 'agent_discount') {
                        $totalAgentDiscount += $amt;
                        if ($hasPst) $agentPstDiscount += $amt;
                        if ($hasGst) $agentGstDiscount += $amt;
                        if (!empty($winner['name'])) $agentDiscountName = $winner['name'];
                        if (!empty($winner['rate'])) $agentRate = $winner['rate'];
                    } elseif ($type === 'code_discount') {
                        $totalCodeDiscount += $amt;
                        if ($hasPst) $codePstDiscount += $amt;
                        if ($hasGst) $codeGstDiscount += $amt;
                        $desc = $winner['description'] ?? 'Coupon';
                        if (!in_array($desc, $codeDiscountNames)) $codeDiscountNames[] = $desc;
                    }
                }

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

                // 4.1 Sync Package Discount line
                if ($totalPkgDiscount > 0) {
                    $expectedPkgAmt = -round($totalPkgDiscount, 2);
                    $expectedPkgPst = -round($pkgPstDiscount, 2);
                    $expectedPkgGst = -round($pkgGstDiscount, 2);
                    $expectedPkgPstEnabled = ($pkgPstDiscount > 0);
                    $expectedPkgGstEnabled = ($pkgGstDiscount > 0);
                    $pkgInfo = $order->meta['applied_package'] ?? null;
                    $pctText = $pkgInfo['package_discount_percentage'] ?? $pkgInfo['package_discount'] ?? null;
                    $expectedPkgDesc = "Package Discount: {$pkgDiscountName}";
                    if ($pctText) {
                        $expectedPkgDesc .= " ({$pctText}%)";
                    }
                    if ($multiplier < 1) {
                        $expectedPkgDesc .= " (" . round($multiplier * 100, 1) . "%)";
                    }

                    $expectedMeta = [
                        'type' => 'package_discount',
                        'package_id' => $pkgId,
                        'pst_applicable_amount' => $expectedPkgPst,
                        'gst_applicable_amount' => $expectedPkgGst,
                    ];

                    if ($existingPackageItem) {
                        $currentMeta = $existingPackageItem->meta ?? [];
                        if (
                            (float)$existingPackageItem->amount != $expectedPkgAmt ||
                            (float)$existingPackageItem->unit_price != $expectedPkgAmt ||
                            $existingPackageItem->description != $expectedPkgDesc ||
                            ($currentMeta['pst_applicable_amount'] ?? null) != $expectedPkgPst ||
                            ($currentMeta['gst_applicable_amount'] ?? null) != $expectedPkgGst ||
                            (bool)$existingPackageItem->pst_enabled != $expectedPkgPstEnabled ||
                            (bool)$existingPackageItem->gst_enabled != $expectedPkgGstEnabled
                        ) {
                            $existingPackageItem->update([
                                'unit_price' => $expectedPkgAmt,
                                'amount' => $expectedPkgAmt,
                                'description' => $expectedPkgDesc,
                                'is_extra' => true,
                                'pst_enabled' => $expectedPkgPstEnabled,
                                'gst_enabled' => $expectedPkgGstEnabled,
                                'meta' => $expectedMeta,
                            ]);
                            $hasChanged = true;
                        }
                    } else {
                        $invoice->items()->create([
                            'order_service_id' => null,
                            'description' => $expectedPkgDesc,
                            'quantity' => 1,
                            'unit_price' => $expectedPkgAmt,
                            'amount' => $expectedPkgAmt,
                            'is_extra' => true,
                            'pst_enabled' => $expectedPkgPstEnabled,
                            'gst_enabled' => $expectedPkgGstEnabled,
                            'meta' => $expectedMeta,
                        ]);
                        $hasChanged = true;
                    }
                } elseif ($existingPackageItem) {
                    $existingPackageItem->delete();
                    $hasChanged = true;
                }

                // 4.2 Sync Agent Discount line
                if ($totalAgentDiscount > 0) {
                    $expectedAgentAmt = -round($totalAgentDiscount, 2);
                    $expectedAgentPst = -round($agentPstDiscount, 2);
                    $expectedAgentGst = -round($agentGstDiscount, 2);
                    $expectedAgentPstEnabled = ($agentPstDiscount > 0);
                    $expectedAgentGstEnabled = ($agentGstDiscount > 0);
                    $agentData = $order->meta['agent_discount'] ?? ($order->agent?->agent_discount ?? []);
                    $rateText = $agentData['amount'] ?? $agentRate;
                    $isPct = filter_var($agentData['is_percentage'] ?? true, FILTER_VALIDATE_BOOLEAN);
                    $expectedAgentDesc = "Agent Discount: {$agentDiscountName}";
                    if ($rateText) {
                        $expectedAgentDesc .= $isPct ? " ({$rateText}%)" : " (\${$rateText} OFF)";
                    }
                    if ($multiplier < 1) {
                        $expectedAgentDesc .= " (" . round($multiplier * 100, 1) . "%)";
                    }

                    $expectedMeta = [
                        'type' => 'agent_discount',
                        'pst_applicable_amount' => $expectedAgentPst,
                        'gst_applicable_amount' => $expectedAgentGst,
                    ];

                    if ($existingAgentItem) {
                        $currentMeta = $existingAgentItem->meta ?? [];
                        if (
                            (float)$existingAgentItem->amount != $expectedAgentAmt ||
                            (float)$existingAgentItem->unit_price != $expectedAgentAmt ||
                            $existingAgentItem->description != $expectedAgentDesc ||
                            ($currentMeta['pst_applicable_amount'] ?? null) != $expectedAgentPst ||
                            ($currentMeta['gst_applicable_amount'] ?? null) != $expectedAgentGst ||
                            (bool)$existingAgentItem->pst_enabled != $expectedAgentPstEnabled ||
                            (bool)$existingAgentItem->gst_enabled != $expectedAgentGstEnabled
                        ) {
                            $existingAgentItem->update([
                                'unit_price' => $expectedAgentAmt,
                                'amount' => $expectedAgentAmt,
                                'description' => $expectedAgentDesc,
                                'is_extra' => true,
                                'pst_enabled' => $expectedAgentPstEnabled,
                                'gst_enabled' => $expectedAgentGstEnabled,
                                'meta' => $expectedMeta,
                            ]);
                            $hasChanged = true;
                        }
                    } else {
                        $invoice->items()->create([
                            'order_service_id' => null,
                            'description' => $expectedAgentDesc,
                            'quantity' => 1,
                            'unit_price' => $expectedAgentAmt,
                            'amount' => $expectedAgentAmt,
                            'is_extra' => true,
                            'pst_enabled' => $expectedAgentPstEnabled,
                            'gst_enabled' => $expectedAgentGstEnabled,
                            'meta' => $expectedMeta,
                        ]);
                        $hasChanged = true;
                    }
                } elseif ($existingAgentItem) {
                    $existingAgentItem->delete();
                    $hasChanged = true;
                }

                // 4.3 Sync Coupon Discount line
                if ($totalCodeDiscount > 0) {
                    $expectedCodeAmt = -round($totalCodeDiscount, 2);
                    $expectedCodePst = -round($codePstDiscount, 2);
                    $expectedCodeGst = -round($codeGstDiscount, 2);
                    $expectedCodePstEnabled = ($codePstDiscount > 0);
                    $expectedCodeGstEnabled = ($codeGstDiscount > 0);
                    $codeDesc = !empty($codeDiscountNames) ? implode(', ', $codeDiscountNames) : 'Coupon Discount';
                    if ($multiplier < 1) {
                        $codeDesc .= " (" . round($multiplier * 100, 1) . "%)";
                    }

                    $expectedMeta = [
                        'type' => 'code_discount',
                        'pst_applicable_amount' => $expectedCodePst,
                        'gst_applicable_amount' => $expectedCodeGst,
                    ];

                    if ($existingCodeItem) {
                        $currentMeta = $existingCodeItem->meta ?? [];
                        if (
                            (float)$existingCodeItem->amount != $expectedCodeAmt ||
                            (float)$existingCodeItem->unit_price != $expectedCodeAmt ||
                            $existingCodeItem->description != $codeDesc ||
                            ($currentMeta['pst_applicable_amount'] ?? null) != $expectedCodePst ||
                            ($currentMeta['gst_applicable_amount'] ?? null) != $expectedCodeGst ||
                            (bool)$existingCodeItem->pst_enabled != $expectedCodePstEnabled ||
                            (bool)$existingCodeItem->gst_enabled != $expectedCodeGstEnabled
                        ) {
                            $existingCodeItem->update([
                                'unit_price' => $expectedCodeAmt,
                                'amount' => $expectedCodeAmt,
                                'description' => $codeDesc,
                                'is_extra' => true,
                                'pst_enabled' => $expectedCodePstEnabled,
                                'gst_enabled' => $expectedCodeGstEnabled,
                                'meta' => $expectedMeta,
                            ]);
                            $hasChanged = true;
                        }
                    } else {
                        $invoice->items()->create([
                            'order_service_id' => null,
                            'description' => $codeDesc,
                            'quantity' => 1,
                            'unit_price' => $expectedCodeAmt,
                            'amount' => $expectedCodeAmt,
                            'is_extra' => true,
                            'pst_enabled' => $expectedCodePstEnabled,
                            'gst_enabled' => $expectedCodeGstEnabled,
                            'meta' => $expectedMeta,
                        ]);
                        $hasChanged = true;
                    }
                } elseif ($existingCodeItem) {
                    $existingCodeItem->delete();
                    $hasChanged = true;
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

                // Sync winning discount on service-specific invoice (exactly one winning discount per service)
                $winner = static::resolveServiceWinningDiscount($order, $os, $multiplier);
                $winnerType = $winner['type'] ?? 'none';
                $pkgDiscount   = ($winnerType === 'package_discount') ? $winner : null;
                $agentDiscount = ($winnerType === 'agent_discount')   ? $winner : null;
                $codeDiscount  = ($winnerType === 'code_discount')    ? $winner : null;

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

                [$osHasGst, $osHasPst] = \App\Http\Controllers\Api\InvoiceController::resolveServiceTaxFlags($os->service, $order->organization_id);

                if ($pkgDiscount) {
                    $expectedDiscountAmt = (float)$pkgDiscount['amount'];
                    $expectedDiscountDesc = $pkgDiscount['description'];
                    $discPst = $osHasPst ? $expectedDiscountAmt : 0.0;
                    $discGst = $osHasGst ? $expectedDiscountAmt : 0.0;
                    $meta = [
                        'type' => 'package_discount',
                        'package_id' => $pkgDiscount['package_id'] ?? null,
                        'pst_applicable_amount' => $discPst,
                        'gst_applicable_amount' => $discGst,
                    ];

                    if ($existingPackageItem) {
                        $currentMeta = $existingPackageItem->meta ?? [];
                        if (
                            (float)$existingPackageItem->amount != $expectedDiscountAmt ||
                            (float)$existingPackageItem->unit_price != $expectedDiscountAmt ||
                            $existingPackageItem->description != $expectedDiscountDesc ||
                            ($currentMeta['pst_applicable_amount'] ?? null) != $discPst ||
                            ($currentMeta['gst_applicable_amount'] ?? null) != $discGst ||
                            (bool)$existingPackageItem->pst_enabled != $osHasPst ||
                            (bool)$existingPackageItem->gst_enabled != $osHasGst
                        ) {
                            $existingPackageItem->update([
                                'unit_price' => $expectedDiscountAmt,
                                'amount' => $expectedDiscountAmt,
                                'description' => $expectedDiscountDesc,
                                'is_extra' => true,
                                'pst_enabled' => $osHasPst,
                                'gst_enabled' => $osHasGst,
                                'meta' => $meta,
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
                            'pst_enabled' => $osHasPst,
                            'gst_enabled' => $osHasGst,
                            'meta' => $meta,
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
                } elseif ($agentDiscount) {
                    $expectedDiscountAmt = (float)$agentDiscount['amount'];
                    $expectedDiscountDesc = $agentDiscount['description'];
                    $discPst = $osHasPst ? $expectedDiscountAmt : 0.0;
                    $discGst = $osHasGst ? $expectedDiscountAmt : 0.0;
                    $meta = [
                        'type' => 'agent_discount',
                        'pst_applicable_amount' => $discPst,
                        'gst_applicable_amount' => $discGst,
                    ];

                    if ($existingAgentItem) {
                        $currentMeta = $existingAgentItem->meta ?? [];
                        if (
                            (float)$existingAgentItem->amount != $expectedDiscountAmt ||
                            (float)$existingAgentItem->unit_price != $expectedDiscountAmt ||
                            $existingAgentItem->description != $expectedDiscountDesc ||
                            ($currentMeta['pst_applicable_amount'] ?? null) != $discPst ||
                            ($currentMeta['gst_applicable_amount'] ?? null) != $discGst ||
                            (bool)$existingAgentItem->pst_enabled != $osHasPst ||
                            (bool)$existingAgentItem->gst_enabled != $osHasGst
                        ) {
                            $existingAgentItem->update([
                                'unit_price' => $expectedDiscountAmt,
                                'amount' => $expectedDiscountAmt,
                                'description' => $expectedDiscountDesc,
                                'is_extra' => true,
                                'pst_enabled' => $osHasPst,
                                'gst_enabled' => $osHasGst,
                                'meta' => $meta,
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
                            'pst_enabled' => $osHasPst,
                            'gst_enabled' => $osHasGst,
                            'meta' => $meta,
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
                } elseif ($codeDiscount) {
                    $expectedDiscountAmt = (float)$codeDiscount['amount'];
                    $expectedDiscountDesc = $codeDiscount['description'];
                    $discPst = $osHasPst ? $expectedDiscountAmt : 0.0;
                    $discGst = $osHasGst ? $expectedDiscountAmt : 0.0;
                    $meta = [
                        'type' => 'code_discount',
                        'pst_applicable_amount' => $discPst,
                        'gst_applicable_amount' => $discGst,
                    ];

                    if ($existingCodeItem) {
                        $currentMeta = $existingCodeItem->meta ?? [];
                        if (
                            (float)$existingCodeItem->amount != $expectedDiscountAmt ||
                            (float)$existingCodeItem->unit_price != $expectedDiscountAmt ||
                            $existingCodeItem->description != $expectedDiscountDesc ||
                            ($currentMeta['pst_applicable_amount'] ?? null) != $discPst ||
                            ($currentMeta['gst_applicable_amount'] ?? null) != $discGst ||
                            (bool)$existingCodeItem->pst_enabled != $osHasPst ||
                            (bool)$existingCodeItem->gst_enabled != $osHasGst
                        ) {
                            $existingCodeItem->update([
                                'unit_price' => $expectedDiscountAmt,
                                'amount' => $expectedDiscountAmt,
                                'description' => $expectedDiscountDesc,
                                'is_extra' => true,
                                'pst_enabled' => $osHasPst,
                                'gst_enabled' => $osHasGst,
                                'meta' => $meta,
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
                            'pst_enabled' => $osHasPst,
                            'gst_enabled' => $osHasGst,
                            'meta' => $meta,
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
