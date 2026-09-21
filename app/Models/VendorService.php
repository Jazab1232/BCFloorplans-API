<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class VendorService extends Model
{
    protected $fillable = [
        'uuid',
        'vendor_id',
        'service_id',
        'status',
        'pay_type',
        'vendor_price',
        'sq_ft_rate',
        'min_price',
        'unit_rate',
        'hourly_rate'
    ];

    protected $casts = [
        'status' => 'boolean',
        'vendor_price' => 'decimal:2',
        'sq_ft_rate' => 'decimal:4',
        'min_price' => 'decimal:2',
        'unit_rate' => 'decimal:2',
        'hourly_rate' => 'decimal:2',
    ];

    protected $hidden = ['id'];

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

    public function service() {
        return $this->belongsTo(Service::class);
    }

    public function options() {
        return $this->hasMany(VendorServiceOption::class, 'vendor_service_id');
    }
}
