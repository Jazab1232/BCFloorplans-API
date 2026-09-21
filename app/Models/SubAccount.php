<?php

namespace App\Models;

use Illuminate\Support\Str;
use Laravel\Passport\HasApiTokens;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use App\Traits\BelongsToOrganization;

class SubAccount extends Authenticatable
{
    use HasApiTokens, Notifiable, BelongsToOrganization;
    protected $fillable = [
        'uuid',
        'organization_id',
        'first_name',
        'last_name',
        'agent_id',
        'role_id',
        'primary_email',
        'secondary_email',
        'password',
        'notification_email',
        'email_type',
        'primary_phone',
        'secondary_phone',
        'company_name',
        'website',
        'address',
        'city',
        'province',
        'country',
        'permissions',
        'avatar',
        'company_logo',
        'company_banner',
        'status'
    ];

    protected $hidden = [
        'id',
        'password',
    ];

    protected $appends = ['avatar_url', 'company_logo_url', 'company_banner_url', 'company_logos_urls', 'logo_url', 'banner_url'];

    protected $casts = [
        'permissions' => 'array',
        'status' => 'boolean',
        'notification_email' => 'boolean',
        'password' => 'hashed',
    ];

    protected $attributes = [
        'status' => true,
        'notification_email' => true,
    ];

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

    // Relationships
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function getAvatarUrlAttribute()
    {
        return $this->avatar ? Storage::disk('s3')->url('subaccounts/' . $this->uuid . '/' . $this->avatar) : null;
    }

    public function getCompanyLogoUrlAttribute()
    {
        return $this->company_logo ? Storage::disk('s3')->url('subaccounts/' . $this->uuid . '/' . $this->company_logo) : null;
    }

    public function getCompanyBannerUrlAttribute()
    {
        return $this->company_banner ? Storage::disk('s3')->url('subaccounts/' . $this->uuid . '/' . $this->company_banner) : null;
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
        if ($this->organization_id) {
            return $this->organization?->company_logos_urls ?? [];
        }

        return [];
    }

    public function getAuthProvider()
    {
        return 'subaccounts';
    }

    /**
     * Get the email address where password reset links are sent.
     *
     * @return string
     */
    public function getEmailForPasswordReset()
    {
        return $this->primary_email;
    }
}