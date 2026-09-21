<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorServiceOption extends Model
{
    protected $table = 'vendor_service_options';

    protected $fillable = [
        'uuid',
        'vendor_service_id',
        'option_id',
        'vendor_price',
        'pay_type',
        'sq_ft_rate',
        'min_price',
        'unit_rate',
        'hourly_rate',
        'vendor_adjustment_time',
        'adjustment_time'
    ];

    protected $casts = [
        'vendor_price' => 'decimal:2',
        'sq_ft_rate' => 'decimal:4',
        'min_price' => 'decimal:2',
        'unit_rate' => 'decimal:2',
        'hourly_rate' => 'decimal:2',
    ];

    protected $hidden = ['id'];

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = Str::uuid()->toString();
            }
        });
    }

    // Relationships
    public function vendorService()
    {
        return $this->belongsTo(VendorService::class);
    }

    public function productOption()
    {
        return $this->belongsTo(ProductOption::class);
    }
}
