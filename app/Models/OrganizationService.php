<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationService extends Model
{
    protected $table = 'organization_services';

    protected $fillable = [
        'organization_id',
        'service_id',
        'is_enabled',
        'price_override',
        'options_override',
        'add_ons_override',
        'gst_enabled',
        'pst_enabled',
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
        'is_enabled' => 'boolean',
        'gst_enabled' => 'boolean',
        'pst_enabled' => 'boolean',
        'price_override' => 'decimal:2',
        'options_override' => 'array',
        'add_ons_override' => 'array',
        'vendor_price' => 'decimal:2',
        'vendor_sq_ft_rate' => 'decimal:4',
        'vendor_min_price' => 'decimal:2',
        'vendor_unit_rate' => 'decimal:2',
        'vendor_hourly_rate' => 'decimal:2',
        'base_duration_mins' => 'integer',
        'base_sq_ft' => 'integer',
        'increment_duration_mins' => 'integer',
        'increment_sq_ft' => 'integer',
    ];

    /**
     * Relationship to the Organization.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Relationship to the Service.
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
