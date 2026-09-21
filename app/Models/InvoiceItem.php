<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class InvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'invoice_id',
        'order_service_id',
        'is_extra',
        'description',
        'quantity',
        'unit_price',
        'amount', // Qty * Unit Price
        'tax_amount',
        'gst_amount',
        'pst_amount',
        'gst_enabled',
        'pst_enabled',
    ];

    protected $casts = [
        'is_extra' => 'boolean',
        'gst_enabled' => 'boolean',
        'pst_enabled' => 'boolean',
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'gst_amount' => 'decimal:2',
        'pst_amount' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function orderService(): BelongsTo
    {
        return $this->belongsTo(OrderService::class);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($item) {
            if (empty($item->uuid)) {
                $item->uuid = (string) Str::uuid();
            }
            if (!isset($item->amount)) {
                $item->amount = $item->quantity * $item->unit_price;
            }
        });
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }
}
