<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorAddress extends Model
{
    protected $fillable = [
        'uuid', 'vendor_id', 'type', 'address_line_1', 'address_line_2',
        'city', 'province', 'country'
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
}