<?php

namespace App\Models;

use Illuminate\Support\Str;
use App\Traits\LogsActivity;
use App\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;
    use LogsActivity, BelongsToOrganization;

    protected static $logRelations = ['slots', 'services', 'areas', 'agent', 'property','services.vendor','totals','services.option'];

    protected $fillable = [
        'uuid',
        'organization_id',
        'agent_id',
        'package_id',
        'property_id',
        'amount',
        'order_status',
        'payment_status',
        'property_address',
        'property_location',
        'notes',
        'co_agents',
        'split_invoice',
        'paid_amount',
        'lock_materials',
        'release_media_before_payment',
        'meta',
        'agent_google_event_id',
        'cancellation_fee',
        'cancelled_at',
        'cancelled_by_type',
        'cancelled_by_uuid',
        'cancellation_reason',
    ];

     protected $attributes = [
        'lock_materials' => true,
        'release_media_before_payment' => false,
    ];

     protected $casts = [
        'lock_materials' => 'boolean',
        'release_media_before_payment' => 'boolean',
        'meta' => 'array',
        'notes' => 'array',
        'co_agents' => 'array',
        'cancellation_fee' => 'decimal:2',
        'cancelled_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(OrderService::class);
    }

    public function slots(): HasMany
    {
        return $this->hasMany(OrderSlot::class);
    }

    public function totals(): HasMany
    {
        return $this->hasMany(OrderTotal::class);
    }

    public function areas(): HasMany
    {
        return $this->hasMany(OrderArea::class);
    }

    public function tours(): HasMany
    {
        return $this->hasMany(Tour::class, 'order_id', 'id');
    }

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = Str::uuid()->toString();
            }
        });
    }

    public function featureSheets(): HasMany
    {
        return $this->hasMany(FeatureSheet::class, 'order_id', 'uuid');
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    public function logs()
    {
        return $this->hasMany(ActivityLog::class, 'model_id', 'uuid')
                    ->where('model_type', self::class)
                    ->latest();
    }

    /**
     * Get notes filtered by visibility rules.
     * Admins see all notes.
     * Agents and Vendors see only non-internal notes.
     */
    public function getFilteredNotesAttribute()
    {
        if (!$this->notes || !is_array($this->notes)) {
            return $this->notes;
        }

        $user = auth()->user();
        $canSeeInternal = false;

        if ($user) {
            // Check if user is an admin or a vendor
            if ($user instanceof \App\Models\User || $user instanceof \App\Models\Vendor) {
                $canSeeInternal = true;
            }
        }

        if ($canSeeInternal) {
            return $this->notes;
        }

        return array_values(array_filter($this->notes, function ($note) {
            $isInternal = (isset($note['internal']) && ($note['internal'] === 'true' || $note['internal'] === true || $note['internal'] === '1' || $note['internal'] === 1)) 
                        || (isset($note['is_internal']) && ($note['is_internal'] === true || $note['is_internal'] === 'true'));
            return !$isInternal;
        }));
    }
}
