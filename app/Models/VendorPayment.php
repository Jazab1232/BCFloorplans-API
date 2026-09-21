<?php

namespace App\Models;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VendorPayment extends Model
{
    use HasFactory;

     protected $fillable = [
        'vendor_uuid',
        'order_service_uuid',
        'order_service_uuids',          
        'amount',
        'currency',
        'stripe_transfer_id',
        'stripe_balance_transaction_id', 
        'invoice_url',                   
        'status',
        'notes',
        'metadata',
        'is_bulk'                      
    ];

    protected $casts = [
        'metadata' => 'array',            
        'is_bulk' => 'boolean',
    ];

    /**
     * ✅ Relationship to OrderService
     */
    public function orderService()
    {
        return $this->belongsTo(\App\Models\OrderService::class, 'order_service_uuid', 'uuid');
    }

    /**
     * ✅ Relationship to Vendor
     */
    public function vendor()
    {
        return $this->belongsTo(\App\Models\Vendor::class, 'vendor_uuid', 'uuid');
    }

    /**
     * ✅ Relationship to Order (through OrderService)
     */
    public function order()
    {
        return $this->hasOneThrough(
            \App\Models\Order::class,
            \App\Models\OrderService::class,
            'uuid', // Foreign key on OrderService table
            'id', // Foreign key on Order table
            'order_service_uuid', // Local key on VendorPayment table
            'order_id' // Local key on OrderService table
        );
    }

       protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }
}
