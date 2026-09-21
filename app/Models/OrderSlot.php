<?php

namespace App\Models;

use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderSlot extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'order_id',
        'service_id',
        'vendor_id',
        'show_all_vendors',
        'schedule_override',
        'recommend_time',
        'travel',
        'start_time',
        'end_time',
        'est_time',
        'distance',
        'km_price',
        'address',
        'location',
        'date',
        'custom_duration',
        'custom_end_time',
        'buffer_minutes',
        'google_event_id',
        'agent_google_event_id',
    ];

    protected $casts = [
        'show_all_vendors' => 'boolean',
        'schedule_override' => 'boolean',
        'recommend_time' => 'boolean',
        'custom_duration' => 'integer',
        'buffer_minutes' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
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