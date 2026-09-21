<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorWorkHour extends Model
{
    protected $fillable = [
        'uuid', 'vendor_id', 'start_time', 'end_time', 'work_days', 
        'repeat_weekly', 'break_start', 'break_end', 
        'commute_minutes', 'timezone'
    ];

    protected $hidden = ['id'];

    protected $casts = ['work_days' => 'array'];

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