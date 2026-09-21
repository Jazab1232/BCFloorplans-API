<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use App\Models\VendorInvoice;
use App\Models\OrderService;

class VendorInvoiceLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'vendor_invoice_id',
        'order_service_id',
        'description',
        'quantity',
        'unit_price',
        'amount',
        'type', // service, travel, adjustment
        'is_taxable',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
        'is_taxable' => 'boolean',
    ];

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function vendorInvoice()
    {
        return $this->belongsTo(VendorInvoice::class);
    }

    public function orderService()
    {
        return $this->belongsTo(OrderService::class);
    }
}
