<?php
namespace App\Models;

use Laravel\Passport\HasApiTokens;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Relations\{HasOne, HasMany};
use Illuminate\Support\Facades\Storage;
use App\Traits\BelongsToOrganization;

class Vendor extends Authenticatable
{
    use HasApiTokens, Notifiable, BelongsToOrganization;

    protected $fillable = [
        'uuid', 'first_name', 'last_name', 'email', 'secondary_email', 'password',
        'notification_email', 'email_type', 'primary_phone', 'secondary_phone',
        'name_on_booking', 'review_files', 'sync_google_calendar', 'sync_google',
        'sync_email', 'avatar', 'status', 'coordinates',
        'stripe_account_id', 'stripe_connect','pay_outside','google_access_token',
        'google_refresh_token', 'google_token_expires_at',
        'organization_id', 'quickbooks_vendor_id', 'quickbooks_synced_at',
    ];

    protected $casts = [
        'quickbooks_synced_at' => 'datetime',
        'sync_google_calendar' => 'boolean',
        'notification_email' => 'boolean',
        'status' => 'boolean',
    ];

    protected $attributes = [
        'status' => true,
        'sync_google_calendar' => true,
        'notification_email' => true,
    ];


    protected $hidden = [
        'id',
        'password',

    ];
    
    protected $appends = ['avatar_url'];

    public function company(): HasOne { 
        return $this->hasOne(VendorCompany::class);
    }

    public function addresses(): HasMany {
        return $this->hasMany(VendorAddress::class);
    }

    public function workHours(): HasOne {
        return $this->hasOne(VendorWorkHour::class);
    }

    public function settings(): HasOne {
        return $this->hasOne(VendorSetting::class);
    }

    public function vendorServices(): HasMany {
        return $this->hasMany(VendorService::class);
    }

    public function orderServices(): HasMany {
        return $this->hasMany(OrderService::class, 'vendor_id', 'uuid');
    }

    public function getAvatarUrlAttribute()
    {
        return $this->avatar ? Storage::disk('s3')->url('vendors/' . $this->uuid . '/' . $this->avatar) : null;
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

    public function homebaseAddress(): HasOne
    {
        return $this->hasOne(VendorAddress::class, 'vendor_id')
            ->where('type', 'start_location');
    }

    public function orderSlots(): HasMany {
        return $this->hasMany(OrderSlot::class);
    }

    public function additionalBreaks(): HasMany {
        return $this->hasMany(VendorBreak::class);
    }

    public function getAuthProvider()
    {
        return 'vendors';
    }
    // app/Models/Vendor.php
    // VendorPortfolioImage
    public function portfolioImages()
    {
        return $this->hasMany(VendorPortfolioImage::class);
    }
}