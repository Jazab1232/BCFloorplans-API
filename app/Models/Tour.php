<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tour extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'order_id',
        'slide_show',
        'is_publish'
    ];

    protected $casts = [
        'slide_show' => 'array',
        'is_publish' => 'boolean',
    ];

    public function files(): HasMany
    {
        return $this->hasMany(TourFile::class)->orderBy('sort_order', 'asc');
    }

    public function links(): HasMany
    {
        return $this->hasMany(TourLink::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(TourSnapshot::class);
    }

    public function dailyStats(): HasMany
    {
        return $this->hasMany(TourDailyStat::class);
    }

    public function visitors(): HasMany
    {
        return $this->hasMany(TourVisitor::class);
    }

    public function dailyReferrers(): HasMany
    {
        return $this->hasMany(TourDailyReferrer::class);
    }

    public function dailyMediaStats(): HasMany
    {
        return $this->hasMany(TourDailyMediaStat::class);
    }

    public function renewals(): HasMany
    {
        return $this->hasMany(MatterportRenewal::class)->orderBy('created_at', 'desc');
    }

    public function orders()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}