<?php

namespace App\Models;

use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * @deprecated This model is redundant and will be discarded. 
 * TODO: Move any remaining unique fields to the Organization model.
 */
class Company extends Model
{
    protected $fillable = [
        'uuid',
        'name',
        'website',
        'email',
        'primary_phone',
        'secondary_phone',
        'street',
        'city',
        'province',
        'country',
        'billing_street_1',
        'billing_street_2',
        'user_id',
        'review_files',
        'logo_path',
        'banner_path',
        'start_time',
        'end_time',
        'work_days',
        'repeat_weekly',
        'timezone',
        'commute_minutes',
        'enable_breaks',
        'sync_google',
        'sync_email',
        'payment_per_km',
        'order_form_url',
        'iframe_code'
    ];

    protected $casts = [
        'review_files' => 'boolean',
        'enable_breaks' => 'boolean',
        'sync_google' => 'boolean',
        'payment_per_km' => 'decimal:2',
        'start_time' => 'datetime:H:i:s',
        'end_time' => 'datetime:H:i:s',
    ];

    protected $appends = ['logo_url', 'banner_url'];

    protected function workDays(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => explode(',', $value),
            set: fn ($value) => implode(',', $value),
        );
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

    public function getLogoUrlAttribute()
    {
        return $this->logo_path ? asset('storage/companies/' . $this->uuid . '/' . $this->logo_path) : null;
    }

    public function getBannerUrlAttribute()
    {
        return $this->banner_path ? asset('storage/companies/' . $this->uuid . '/' . $this->banner_path) : null;
    }

    // public function paymentMethods()
    // {
    //     return $this->hasMany(PaymentMethod::class);
    // }
}