<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ServiceCategory extends Model
{
    // Add allowed types constant
    public const ALLOWED_TYPES = ['area', 'fixed', 'quantity'];

    protected $fillable = [
        'uuid',
        'name',
        'type',
        'add_ons',
        'duration',
        'description',
    ];

    protected $casts = [
        'uuid' => 'string',
        'type' => 'array',
        'duration' => 'boolean',
        'add_ons' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function getRouteKeyName() {
        return 'uuid';
    }

    protected static function booted() {
        static::creating(function ($model) {
            // Prevent overwriting existing UUIDs
            if (empty($model->uuid)) {
                $model->uuid = Str::uuid()->toString();
            }
        });
    }

    // Add relationship to services
    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'category_id');
    }
}