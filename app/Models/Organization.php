<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use App\Services\StripeResolverService;
use Stripe\StripeClient;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'name',
        'slug',
        'contact_name',
        'contact_email',
        'contact_phone',
        'address_line_1',
        'address_line_2',
        'city',
        'province',
        'country',
        'postal_code',
        'is_active',
        'is_whitelabel',
        'domain',
        'from_name',
        'from_email',
        'trial_ends_at',
        'owner_user_id',
        'company_logos',
        'qb_realm_id',
        'qb_access_token',
        'qb_refresh_token',
        'qb_access_expires_at',
        'qb_refresh_expires_at',
        // BYO Stripe — Option A
        'stripe_publishable_key',
        'stripe_secret_key',
        'stripe_webhook_secret',
    ];

    /**
     * Never expose Stripe secrets in API responses.
     */
    protected $hidden = [
        'stripe_secret_key',
        'stripe_webhook_secret',
    ];

    protected $casts = [
        'company_logos' => 'array',
        'is_active' => 'boolean',
        'is_whitelabel' => 'boolean',
        'trial_ends_at' => 'datetime',
        'qb_access_expires_at' => 'datetime',
        'qb_refresh_expires_at' => 'datetime',
    ];

    protected $appends = [
        'company_logos_urls',
        'has_stripe_secret_key',
        'has_stripe_webhook_secret',
        'byo_stripe_enabled',
    ];

    public function getHasStripeSecretKeyAttribute(): bool
    {
        return !empty($this->attributes['stripe_secret_key']);
    }

    public function getHasStripeWebhookSecretAttribute(): bool
    {
        return !empty($this->attributes['stripe_webhook_secret']);
    }

    public function getByoStripeEnabledAttribute(): bool
    {
        return !empty($this->attributes['stripe_secret_key']) && !empty($this->attributes['stripe_publishable_key']);
    }

    protected static function boot(): void
    {
        parent::boot();

        // Automatically generate UUID on create
        static::creating(function ($model) {
            $model->uuid = (string) Str::uuid();
        });
    }

    public function getCompanyLogosUrlsAttribute()
    {
        $logos = $this->company_logos ?: [];

        return array_map(function ($logo) {
            if (isset($logo['path'])) {
                $logo['url'] = Storage::disk('s3')->url($logo['path']);
            }
            return $logo;
        }, $logos);
    }

    // Owner relation (optional)
    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    // -------------------------------------------------------------------------
    // BYO Stripe helpers
    // -------------------------------------------------------------------------

    /**
     * Return a StripeClient pre-configured with this organization's Stripe key
     * (or the platform fallback if the org has no key set).
     */
    public function stripeClient(): StripeClient
    {
        return StripeResolverService::clientForOrganization($this);
    }

    /**
     * Whether this org is operating in BYO Stripe mode.
     */
    public function isUsingOwnStripe(): bool
    {
        return StripeResolverService::isUsingOwnStripe($this);
    }
    public function settings()
    {
        return $this->hasMany(Setting::class, 'org_id', 'uuid');
    }

    public function signatures()
    {
        return $this->hasMany(Signature::class, 'organization_id');
    }
    public function audioFiles()
    {
        return $this->hasMany(AudioFile::class, 'organization_id');
    }

    /**
     * Get the services for the organization.
     */
    public function services()
    {
        return $this->belongsToMany(Service::class, 'organization_services')
            ->withPivot('is_enabled', 'price_override', 'options_override')
            ->withTimestamps();
    }

    /**
     * Get the service overrides for the organization.
     */
    public function serviceOverrides()
    {
        return $this->hasMany(OrganizationService::class);
    }

    /**
     * Get the custom domains for the organization.
     */
    public function domains()
    {
        return $this->hasMany(OrganizationDomain::class);
    }
}
