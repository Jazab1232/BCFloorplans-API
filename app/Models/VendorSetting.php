<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorSetting extends Model
{
    protected $fillable = [
        'uuid', 'vendor_id', 'payment_per_km', 
        'enable_service_area', 'force_service_area', 'is_kilometers',
        'next_booking_slot_only',
        'tax_enabled', 'tax_rate', 'tax_number',
        'tax_country', 'tax_type',
        'tax_number_gst_hst', 'tax_number_pst', 'tax_number_qst', 'tax_number_us',
        'tax_exempt'
    ];
    protected $casts = [
        'is_kilometers' => 'boolean',
        'next_booking_slot_only' => 'boolean',
        'tax_enabled' => 'boolean',
        'tax_exempt' => 'boolean',
        'tax_rate' => 'float',
    ];  

    protected $hidden = ['id'];

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = \Illuminate\Support\Str::uuid()->toString();
            }
        });
    }

    public function vendor() {
        return $this->belongsTo(Vendor::class);
    }
}