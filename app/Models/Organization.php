<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

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
    ];

    protected $casts = [
        'company_logos' => 'array',
        'is_active' => 'boolean',
        'is_whitelabel' => 'boolean',
        'trial_ends_at' => 'datetime',
        'qb_access_expires_at' => 'datetime',
        'qb_refresh_expires_at' => 'datetime',
    ];

    protected $appends = ['company_logos_urls'];

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
