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
                    if ($item->orderService && $item->orderService->payment_status !== 'PAID') {
                        $item->orderService->update(['payment_status' => 'PAID']);
                    }
                }
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
                $splits[] = ['name' => $ca['name'], 'email' => $ca['email'], 'percentage' => $percent, 'type' => 'co-agent'];
                $totalCoPercent += $percent;
            }
        }
        $primaryPercent = max(0, 100 - $totalCoPercent);
        $splits[] = ['name' => "{$agent->first_name} {$agent->last_name}", 'email' => $agent->email, 'percentage' => $primaryPercent, 'type' => 'primary'];

        $resolvedSplits = [];
        foreach ($splits as $split) {
            $targetAgentId = $agent->id;
            if ($split['type'] === 'co-agent' && !empty($split['email'])) {
                $coAgent = Agent::where('email', $split['email'])->first();
                if (!$coAgent) {
                    $roleId = \App\Models\Role::whereRaw('LOWER(name) LIKE ?', ['agent%'])->value('id');
                    $nameParts = explode(' ', $split['name'] ?? 'Co Agent', 2);
                    $coAgent = Agent::create([
                        'uuid' => (string) \Illuminate\Support\Str::uuid(),
                        'organization_id' => $agent->organization_id,
                        'first_name' => $nameParts[0],
                        'last_name' => $nameParts[1] ?? '',
                        'email' => $split['email'],
                        'password' => bcrypt(\Illuminate\Support\Str::random(16)),
                        'role_id' => $roleId,
                        'status' => true,
                        'requires_payment' => true,
                        'payment_status' => 'GOOD',
                        'company_name' => $agent->company_name, // Sync with primary agent's company/org
                    ]);
                }
                $targetAgentId = $coAgent->id;
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

        $order->load(['agent', 'property']);
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

        return $primaryInvoice;
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

            $items[] = [
                'order_service_id' => $os->id,
                'description' => $prep['description'],
                'quantity' => 1,
                'unit_price' => $prep['amount'],
                'amount' => $prep['amount'],
                'tax_amount' => $itemTax,
                'gst_amount' => $calcItem['gst_amount'] ?? ($calcItem['tax_breakdown']['GST'] ?? 0.0),
                'pst_amount' => $calcItem['pst_amount'] ?? ($calcItem['tax_breakdown']['PST'] ?? 0.0),
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
        $this->loadMissing(['items.orderService.service', 'agent', 'order.property']);

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
            $isTaxable = null;
            if ($item->gst_enabled !== null || $item->pst_enabled !== null) {
                $isTaxable = (bool)$item->gst_enabled || (bool)$item->pst_enabled;
            }

            $calcItemsInput[] = [
                'amount' => $amount,
                'service' => $service,
                'is_taxable' => $isTaxable,
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
             $paidSubtotal = 0;
             $allPaid = true;
             $hasItems = false;
 
             foreach ($invoice->items as $item) {
                 $hasItems = true;
                 if ($item->orderService && $item->orderService->payment_status === 'PAID') {
                     $paidSubtotal += (float)$item->amount;
                 } else {
                     $allPaid = false;
                 }
             }
 
             if (!$hasItems) continue;
 
             // Calculate paid total including tax
             $calculatedPaidAmount = round($paidSubtotal * (1 + ($invoice->tax_rate / 100)), 2);
             
             // We take the MAX of calculated net paid amount and existing net paid amount
             $existingNetPaid = round((float)$invoice->paid_amount - (float)$invoice->refunded_amount, 2);
             $newNetPaid = max($existingNetPaid, $calculatedPaidAmount);
             
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
     * Synchronize consolidated and service-specific invoices with the current state of order services.
     * Useful when services are added, removed, or prices changed (e.g. square footage updates).
     */
    public static function syncOrderInvoices(Order $order)
    {
        $order->load(['services.service']);
        
        $activeInvoices = static::where('order_id', $order->id)
            ->where('status', '!=', 'paid')
            ->where('status', '!=', 'void')
            ->where('status', '!=', 'refunded')
            ->where('refunded_amount', '=', 0)
            ->with(['items'])
            ->get();

        $orderServices = $order->services->filter(function($os) {
            return $os->payment_status !== 'PAID';
        });
        $orderServiceIds = $orderServices->pluck('id')->toArray();

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

                // 1. Add or Update items
                foreach ($orderServices as $os) {
                    $expectedAmount = round((float)$os->amount * $multiplier, 2);
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

                // 2. Remove items no longer in order (only for base items)
                foreach ($currentItems as $osId => $item) {
                    if (!in_array($osId, $orderServiceIds)) {
                        $item->delete();
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

                if ($os->payment_status !== 'PAID') {
                    $expectedAmount = round((float)$os->amount * $multiplier, 2);
                    $itemDescription = ($os->custom ?: ($os->service->name ?? 'Service')) . ($multiplier < 1 ? " (" . ($multiplier * 100) . "%)" : "");

                    if ((float)$baseItem->amount != $expectedAmount || (float)$baseItem->unit_price != $expectedAmount || $baseItem->description != $itemDescription) {
                        $baseItem->update([
                            'unit_price' => $expectedAmount,
                            'amount' => $expectedAmount,
                            'description' => $itemDescription
                        ]);
                        $invoice->recalculateTotals();
                    }
                }
            }
        }

        // Sync order and invoice statuses after recalculating
        static::syncOrderStatus($order);
    }
}
