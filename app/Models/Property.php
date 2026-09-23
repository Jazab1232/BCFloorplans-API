<?php

namespace App\Models;

use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use App\Traits\BelongsToOrganization;
use App\Traits\LogsActivity;

class Property extends Model
{
    use HasFactory, BelongsToOrganization, LogsActivity;

    protected $fillable = [
        'organization_id',
        'agent_id',
        'listing_price',
        'mls_number',
        'bedrooms',
        'bathrooms',
        'square_footage',
        'lot_size',
        'year_constructed',
        'parking_spots',
        'property_type',
        'property_status',
        'heading',
        'description',
        'suite',
        'address',
        'city',
        'province',
        'postal_code',
        'country',
        'tour_activated',
        'publish_date',
        'property_website',
        'mls_property',
        'occupancy',
        'media_creator_access',
        'instructions',
        'animals_on_property',
        'co_agents',
        'latitude',
        'longitude',
        'map_zoom',
        'map_type',
        'map_center_lat',
        'map_center_lng',
        'send_statistics_email',
        'statistics_email_frequency',
        'statistics_email_recipients',
    ];

    protected $casts = [
        'co_agents' => 'array',
        'latitude' => 'float',
        'longitude' => 'float',
        'map_zoom' => 'integer',
        'map_center_lat' => 'float',
        'map_center_lng' => 'float',
        'tour_activated' => 'boolean',
        'animals_on_property' => 'boolean',
        'send_statistics_email' => 'boolean',
        'statistics_email_recipients' => 'array',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
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

    public function orders(): HasMany {
        return $this->hasMany(Order::class);
    }

    public function getStateAttribute(): ?string
    {
        return $this->province ?? null;
    }
}
