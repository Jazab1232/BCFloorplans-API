<?php

namespace App\Models;

use Illuminate\Support\Str;
use Laravel\Passport\HasApiTokens;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;
use App\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Agent extends Authenticatable
{
    use HasApiTokens, Notifiable, BelongsToOrganization;

    protected $fillable = [
        'uuid',
        'first_name',
        'last_name',
        'role_id',
        'organization_id',
        'email',
        'email_cc',
        'password',
        'primary_phone',
        'secondary_phone',
        'company_name',
        'website',
        'license_number',
        'certifications',
        'headquarter_address',
        'notes',
        'requires_payment',
        'status',
        'payment_status',
        'avatar',
        'company_logo',
        'company_banner',
        'company_logos',
        'co_agents',
        'quickbooks_customer_id',
        'agent_discount',
        'google_access_token',
        'google_refresh_token',
        'google_token_expires_at',
        'google_calendar_id',
        'sync_google_calendar',
        'notification_email',
    ];

    protected $hidden = [
        'id',
        'password',
    ];
    protected $attributes = [
        'status' => true,
        'sync_google_calendar' => true,
        'notification_email' => true,
    ];
    protected $casts = [
        'requires_payment' => 'boolean',
        'certifications' => 'array',
        'password' => 'hashed',
        'co_agents' => 'array',
        'company_logos' => 'array',
        'status' => 'boolean',
        'agent_discount' => 'array',
        'google_token_expires_at' => 'datetime',
        'sync_google_calendar' => 'boolean',
        'notification_email' => 'boolean',
    ];

    protected $appends = ['avatar_url', 'company_logo_url', 'company_banner_url', 'company_logos_urls', 'logo_url', 'banner_url'];


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

    public function getAvatarUrlAttribute()
    {
        return $this->avatar ? Storage::disk('s3')->url('agents/' . $this->uuid . '/' . $this->avatar) : null;
    }

    public function getCompanyLogoUrlAttribute()
    {
        return $this->company_logo ? Storage::disk('s3')->url('agents/' . $this->uuid . '/' . $this->company_logo) : null;
    }

    public function getCompanyBannerUrlAttribute()
    {
        return $this->company_banner ? Storage::disk('s3')->url('agents/' . $this->uuid . '/' . $this->company_banner) : null;
    }

    public function getLogoUrlAttribute()
    {
        return $this->company_logo_url;
    }

    public function getBannerUrlAttribute()
    {
        return $this->company_banner_url;
    }

    public function getCompanyLogosUrlsAttribute()
    {
        $logos = $this->company_logos ?? [];

        // Build list of valid logo URLs
        $result = [];
        foreach ($logos as $logo) {
            $item = $logo;
            if (!empty($logo['path'])) {
                $item['url'] = Storage::disk('s3')->url($logo['path']);
            } elseif ($this->company_logo) {
                $item['path'] = 'agents/' . $this->uuid . '/' . $this->company_logo;
                $item['url'] = $this->company_logo_url;
            }
            if (!empty($item['url'])) {
                $result[] = $item;
            }
        }

        // If no valid logo found, fallback to primary company logo or organization logos
        if (empty($result)) {
            if ($this->company_logo && $this->company_logo_url) {
                $result[] = [
                    'type' => 'General',
                    'path' => 'agents/' . $this->uuid . '/' . $this->company_logo,
                    'url' => $this->company_logo_url,
                ];
            } elseif ($this->organization_id) {
                return $this->organization?->company_logos_urls ?? [];
            }
        }

        return $result;
    }

    public function role()
    {
        return $this->hasOne(Role::class, 'id', 'role_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'agent_id');
    }

    public function getAuthProvider()
    {
        return 'agents';
    }
    public function properties(): HasMany
    {
        return $this->hasMany(Property::class, 'agent_id');
    }
    public function audioFiles(): HasMany
    {
        return $this->hasMany(AudioFile::class, 'agent_id');
    }

    public function coagent(): HasOne
    {
        // Many agents have a subaccount that acts as their co-agent.
        // We link to the first subaccount record for this agent.
        return $this->hasOne(SubAccount::class, 'agent_id');
    }
}
