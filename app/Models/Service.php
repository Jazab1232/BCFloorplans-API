<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{HasMany, BelongsTo};
use Illuminate\Support\Facades\Storage;
use App\Traits\BelongsToOrganization;

class Service extends Model
{
    use BelongsToOrganization;

    public bool $orgScopeIncludesGlobal = true;

    protected $fillable = [
        'uuid',
        'organization_id',
        'name',
        'type',
        'category_id',
        'thumbnail',
        'description',
        'status',
        'background_color',
        'border_color',
        'quickbooks_item_id',
        'quickbooks_synced_at',
        'is_travel_required',
        'sort_order',
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
        'uuid' => 'string',
        'type' => 'string',
        'status' => 'boolean',
        'gst_enabled' => 'boolean',
        'pst_enabled' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'is_travel_required' => 'boolean',
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
    ];

    /**
     * Calculate dynamic duration in minutes based on property sq ft.
     * Fallback chain: Product Option Override -> Organization Service Override -> Global Service Default.
     */
    public function calculateDuration($sqFt = 0, $option = null, $orgId = null): int
    {
        $sqFt = (float) $sqFt;

        // 1. Check Organization Service Override
        $orgOverride = null;
        if ($orgId) {
            $orgOverride = OrganizationService::where('organization_id', $orgId)
                ->where('service_id', $this->id)
                ->first();
        }

        $baseMins = $option->base_duration_mins ?? $orgOverride->base_duration_mins ?? $this->base_duration_mins ?? 60;
        $baseSqFt = $option->base_sq_ft ?? $orgOverride->base_sq_ft ?? $this->base_sq_ft ?? 2000;
        $incMins  = $option->increment_duration_mins ?? $orgOverride->increment_duration_mins ?? $this->increment_duration_mins ?? 30;
        $incSqFt  = $option->increment_sq_ft ?? $orgOverride->increment_sq_ft ?? $this->increment_sq_ft ?? 1000;

        if ($sqFt <= $baseSqFt || $incSqFt <= 0 || $incMins <= 0) {
            return (int) $baseMins;
        }

        $extraSqFt = $sqFt - $baseSqFt;
        $steps = (int) ceil($extraSqFt / $incSqFt);

        return (int) ($baseMins + ($steps * $incMins));
    }

    protected $appends = ['thumbnail_url'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class);
    }

    public function productOptions(): HasMany
    {
        return $this->hasMany(ProductOption::class)->orderBy('sort_order')->orderBy('id');
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

    public function getThumbnailUrlAttribute()
    {
        if (!$this->thumbnail) {
            return null;
        }

        if (str_starts_with($this->thumbnail, 'http://') || str_starts_with($this->thumbnail, 'https://')) {
            return $this->thumbnail;
        }

        if (str_contains($this->thumbnail, '/')) {
            return Storage::disk('s3')->url($this->thumbnail);
        }

        return Storage::disk('s3')->url("services/{$this->uuid}/{$this->thumbnail}");
    }

    public function vendorServices(): HasMany
    {
        return $this->hasMany(VendorService::class);
    }

    public function discountServices(): HasMany
    {
        return $this->hasMany(DiscountService::class);
    }

    public function serviceAddOns(): HasMany
    {
        return $this->hasMany(ServiceAddOn::class);
    }

    public function orderSlots(): HasMany
    {
        return $this->hasMany(OrderSlot::class);
    }
}