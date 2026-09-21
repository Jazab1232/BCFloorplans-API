<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ProductOption extends Model
{
    protected $fillable = [
        'service_id',
        'title',
        'quantity',
        'sq_ft_range',
        'sq_ft_rate',
        'service_duration',
        'amount',
        'min_price',
        'sort_order',
        'vendor_pay_type',
        'vendor_price',
        'vendor_sq_ft_rate',
        'vendor_min_price',
        'vendor_unit_rate',
        'vendor_hourly_rate',
        'base_duration_mins',
        'base_sq_ft',
        'increment_duration_mins',
        'increment_sq_ft'
    ];

    protected $casts = [
        'uuid' => 'string',
        'sort_order' => 'integer',
        'vendor_price' => 'decimal:2',
        'vendor_sq_ft_rate' => 'decimal:4',
        'vendor_min_price' => 'decimal:2',
        'vendor_unit_rate' => 'decimal:2',
        'vendor_hourly_rate' => 'decimal:2',
        'base_duration_mins' => 'integer',
        'base_sq_ft' => 'integer',
        'increment_duration_mins' => 'integer',
        'increment_sq_ft' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
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