<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use App\Models\Vendor;
use App\Models\VendorInvoiceLine;
use App\Models\OrderService;
use App\Traits\BelongsToOrganization;

class VendorInvoice extends Model
{
    use HasFactory, SoftDeletes, BelongsToOrganization;

    protected $fillable = [
        'uuid',
        'organization_id',
        'vendor_id',
        'invoice_number',
        'status', // draft, pending_payment, paid, cancelled
        'subtotal',
        'tax_amount',
        'tax_rate',
        'tax_type',
        'tax_number',
        'travel_amount',
        'total_amount',
        'currency',
        'cycle_start',
        'cycle_end',
        'paid_at',
        'stripe_transfer_id',
        'notes',
        'vendor_details',
        'org_details',
        'tax_details',
        'quickbooks_bill_id',
        'quickbooks_synced_at',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'travel_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'cycle_start' => 'date',
        'cycle_end' => 'date',
        'paid_at' => 'datetime',
        'quickbooks_synced_at' => 'datetime',
        'vendor_details' => 'array',
        'org_details' => 'array',
        'tax_details' => 'array',
    ];

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }

            if (empty($model->invoice_number)) {
                $year = date('Y');
                
                // Get org slug for prefix
                $org = $model->organization ?? Organization::find($model->organization_id);
                $prefix = $org ? strtoupper($org->slug) : 'BCF';

                $lastInvoice = static::where('organization_id', $model->organization_id)
                    ->whereYear('created_at', $year)
                    ->orderBy('id', 'desc')
                    ->first();
                
                $nextNum = 1;
                if ($lastInvoice && preg_match('/-VND-(\d+)$/', $lastInvoice->invoice_number, $matches)) {
                    $nextNum = intval($matches[1]) + 1;
                }
                
                $model->invoice_number = sprintf('INV-%s-%s-VND-%05d', $prefix, $year, $nextNum);
            }
        });
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id', 'uuid');
    }

    public function lines()
    {
        return $this->hasMany(VendorInvoiceLine::class, 'vendor_invoice_id');
    }

    public function orderServices()
    {
        return $this->hasMany(OrderService::class, 'vendor_invoice_id');
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    /**
     * Recalculate subtotal/tax/total from line items.
     */
    public function recalculateTotals()
    {
        $this->subtotal = round((float) $this->lines()->where('type', 'service')->sum('amount'), 2);
        $this->travel_amount = round((float) $this->lines()->where('type', 'travel')->sum('amount'), 2);
        
        $taxRate = (float) ($this->tax_rate ?? 0);
        $taxableServiceAmount = (float) $this->lines()->where('type', 'service')->where('is_taxable', true)->sum('amount');
        $taxableTravelAmount = (float) $this->lines()->where('type', 'travel')->where('is_taxable', true)->sum('amount');

        if ($this->tax_details && is_array($this->tax_details) && count($this->tax_details) > 0) {
            $totalTaxFromDetails = 0;
            foreach ($this->tax_details as $comp) {
                if (is_array($comp) && isset($comp['amount'])) {
                    $totalTaxFromDetails += (float) $comp['amount'];
                }
            }
            $this->tax_amount = round($totalTaxFromDetails, 2);
        } else {
            $this->tax_amount = round(($taxableServiceAmount + $taxableTravelAmount) * ($taxRate / 100), 2);
        }
        
        $this->total_amount = round((float) $this->lines()->sum('amount') + $this->tax_amount, 2);
        $this->save();
    }
}
