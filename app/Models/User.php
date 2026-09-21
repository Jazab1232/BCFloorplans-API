<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use App\Traits\BelongsToOrganization;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, BelongsToOrganization;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'secondary_email',
        'password',
        'primary_phone',
        'secondary_phone',
        'company_name',
        'website',
        'address',
        'city',
        'province',
        'country',
        'avatar',
        'company_logo',
        'company_banner',
        'account_closed', // Added
        'closed_at',      // Added
        'uuid',
        'organization_id',
        'notification_email',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'id',
        'password',
        'remember_token',
    ];

    protected $attributes = [
        'notification_email' => true,
    ];
   

     /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */

    protected $appends = ['full_name', 'avatar_url', 'company_logo_url', 'company_banner_url', 'company_logos_urls'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'account_closed' => 'boolean', // Added
            'closed_at' => 'datetime',     // Added
            'notification_email' => 'boolean',
        ];
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

    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class);
    }

    // Check if user has a specific role
    public function hasRole($role)
    {
        return $this->roles()->where('name', $role)->exists();
    }

    // Check if user has a specific permission
    public function hasPermission($permission)
    {
        return $this->permissions()->where('name', $permission)->exists()
            || $this->roles()->whereHas('permissions', fn($q) => $q->where('name', $permission))->exists();
    }

    public function getFullNameAttribute()
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function sendPasswordResetNotification($token)
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function getAvatarUrlAttribute()
    {
        return $this->avatar ? Storage::disk('s3')->url('users/' . $this->uuid . '/' . $this->avatar) : null;
    }

    public function getCompanyLogoUrlAttribute()
    {
        return $this->company_logo ? Storage::disk('s3')->url('users/' . $this->uuid . '/' . $this->company_logo) : null;
    }

    public function getCompanyBannerUrlAttribute()
    {
        return $this->company_banner ? Storage::disk('s3')->url('users/' . $this->uuid . '/' . $this->company_banner) : null;
    }

    public function getCompanyLogosUrlsAttribute()
    {
        if ($this->organization_id) {
            return $this->organization?->company_logos_urls ?? [];
        }

        return [];
    }

    public function companies()
    {
        return $this->hasMany(Company::class);
    }

    public function paymentmethods()
    {
        return $this->hasMany(PaymentMethod::class);
    }

    // Relations


    public function ownedOrganization()
    {
        return $this->hasOne(Organization::class, 'owner_user_id');
    }
    

    

}
