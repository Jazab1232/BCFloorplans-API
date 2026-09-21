<?php

namespace App\Models;

use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderService extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'order_id',
        'service_id',
        'option_id',
        'feature_sheet_id',
        'feature_sheet_uuid',
        'amount',
        'custom',
        'add_ons',
        'payment_status',
        'media_access',
        'vendor_id',
        'is_completed',
        'vendor_paid',
        'vendor_paid_at',
        'vendor_invoice_id',
    ];

    protected $casts = [
        'is_completed' => 'boolean',
        'media_access' => 'boolean',
        'vendor_paid' => 'boolean',
        'vendor_paid_at' => 'datetime',
        'add_ons' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function featureSheet(): BelongsTo
    {
        return $this->belongsTo(FeatureSheet::class, 'feature_sheet_uuid', 'uuid');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id', 'uuid');
    }

    public function vendorPayment()
    {
        return $this->hasOne(VendorPayment::class, 'order_service_uuid', 'uuid');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(ProductOption::class, 'option_id', 'id');
    }

    public function vendorInvoice(): BelongsTo
    {
        return $this->belongsTo(VendorInvoice::class);
    }

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = Str::uuid()->toString();
            }
        });
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }
}
