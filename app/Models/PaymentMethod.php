<?php

namespace App\Models;

use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentMethod extends Model
{
    protected $fillable = [
        'uuid',
        'user_id',
        'payable_type',
        'payable_id',
        'type',
        'last_four',
        'cardholder_name',
        'is_primary',
        'expiry_date'
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'is_primary' => 'boolean',
    ];

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = Str::uuid()->toString();
            }

            if ($model->user_id && !$model->payable_type) {
                $model->payable_type = User::class;
                $model->payable_id = $model->user_id;
            }
        });

        static::saved(function ($model) {
            if ($model->payable_type === User::class && $model->user_id !== $model->payable_id) {
                $model->user_id = $model->payable_id;
                $model->saveQuietly();
            }
            
            // Set user_id to null for non-User payable types
            if (($model->payable_type === Agent::class || $model->payable_type === Vendor::class) && $model->user_id !== null) {
                $model->user_id = null;
                $model->saveQuietly();
            }
        });
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where(function($q) use ($userId) {
            $q->where('user_id', $userId)
              ->orWhere(function($q2) use ($userId) {
                  $q2->where('payable_type', User::class)
                     ->where('payable_id', $userId);
              });
        });
    }

    public function scopeForAgent($query, $agentId)
    {
        return $query->where('payable_type', Agent::class)
                     ->where('payable_id', $agentId);
    }

    public function scopeForVendor($query, $vendorId)
    {
        return $query->where('payable_type', Vendor::class)
                     ->where('payable_id', $vendorId);
    }

    public function getUserIdAttribute()
    {
        if ($this->payable_type === User::class) {
            return $this->payable_id;
        }
        
        return $this->attributes['user_id'] ?? null;
    }
}