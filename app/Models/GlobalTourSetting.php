<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class GlobalTourSetting extends Model
{
    protected $table = 'global_tour_settings';

    protected $fillable = [
        'uuid',
        'area',
        'type',
        'charge',
        'discount',
        'is_percentage',
        'status',
        'sort_order',
        'organization_id'
    ];

    protected $casts = [
        'is_percentage' => 'boolean',
        'status' => 'boolean',
        'sort_order' => 'integer',
        'charge' => 'decimal:2',
        'discount' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->uuid = (string) Str::uuid();
        });
    }
}
