<?php
namespace App\Models;

use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Model;

class VendorCompany extends Model
{
    protected $fillable = [
        'uuid', 'vendor_id', 'company_name', 'company_website', 
        'company_logo', 'company_banner'
    ];

    protected $hidden = ['id'];
    protected $appends = ['company_logo_url', 'company_banner_url'];

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = Str::uuid()->toString();
            }
        });
    }

    public function vendor() {
        return $this->belongsTo(Vendor::class);
    }

    public function getCompanyLogoUrlAttribute()
    {
        if (!$this->company_logo) return null;
        
        $vendorUuid = optional($this->vendor)->uuid;
        return $vendorUuid 
            ? Storage::disk('s3')->url('vendors/' . $vendorUuid . '/' . $this->company_logo) 
            : null;
    }

    public function getCompanyBannerUrlAttribute()
    {
        if (!$this->company_banner) return null;
        
        $vendorUuid = optional($this->vendor)->uuid;
        return $vendorUuid 
            ? Storage::disk('s3')->url('vendors/' . $vendorUuid . '/' . $this->company_banner) 
            : null;
    }
}