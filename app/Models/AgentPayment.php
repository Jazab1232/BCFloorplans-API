<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use App\Models\Order;

class AgentPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'agent_id',
        'paid_by_agent_id',
        'order_service_id',
        'order_id',
        'invoice_id',
        'amount',
        'currency',
        'payment_method',
        'status',
        'stripe_session_id',
        'stripe_payment_intent_id',
        'stripe_receipt_url',
        'session_created_at',
        'paid_at',
        'payment_type',
        'payment_mode', // on_behalf or self
        'stripe_customer_id',
        'meta',
        'quickbooks_txn_id',
        'quickbooks_invoice_id',
        'quickbooks_synced_at',
        'quickbooks_payment_id',
    ];

    protected $casts = [
        'meta' => 'array',
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'quickbooks_synced_at' => 'datetime',
        
    ];

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

   
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    public function orderService()
    {
        return $this->belongsTo(\App\Models\OrderService::class, 'order_service_id', 'id');
    }

    public function agent()
    {
        return $this->belongsTo(\App\Models\Agent::class, 'agent_id', 'id');
    }

    /**
     * The agent who actually made the payment (for split invoice tracking).
     * If null, the agent_id agent paid for themselves.
     */
    public function paidByAgent()
    {
        return $this->belongsTo(\App\Models\Agent::class, 'paid_by_agent_id', 'id');
    }

    public function invoice()
    {
        return $this->belongsTo(\App\Models\Invoice::class, 'invoice_id', 'id');
    }
}
