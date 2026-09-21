<?php

namespace App\Models;

use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderTotal extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'order_id',
        'order_service_id',
        'discount_id',
        'amount',
        'discount_type',
        'discount_value',
        'sort_order',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'discount_value' => 'decimal:2',
    ];

    // Relationships
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function orderService()
    {
        return $this->belongsTo(OrderService::class);
    }

    public function discount()
    {
        return $this->belongsTo(Discount::class);
    }

    public function isCodeDiscount(): bool
    {
        return $this->isDiscount() && $this->discount_type === 'code';
    }

    public function isQuantityDiscount(): bool
    {
        return $this->isDiscount() && $this->discount_type === 'quantity';
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