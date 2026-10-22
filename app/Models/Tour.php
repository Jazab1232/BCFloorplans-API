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

    public function getSlideShowAttribute($value)
    {
        $data = is_array($value) ? $value : (is_string($value) ? json_decode($value, true) : null);
        if (!is_array($data)) {
            return $data;
        }

        $booleanFields = ['auto_play', 'video_overlay', 'pan_adjust'];
        foreach ($booleanFields as $field) {
            if (array_key_exists($field, $data)) {
                $val = $data[$field];
                if ($val === null) {
                    $data[$field] = false;
                } elseif (is_bool($val)) {
                    $data[$field] = $val;
                } elseif (is_string($val)) {
                    $trimmed = strtolower(trim($val));
                    $data[$field] = in_array($trimmed, ['1', 'true', 'yes', 'on'], true);
                } elseif (is_numeric($val)) {
                    $data[$field] = (int) $val === 1;
                } else {
                    $data[$field] = (bool) $val;
                }
            }
        }

        if (isset($data['slide_delay']) && is_numeric($data['slide_delay'])) {
            $data['slide_delay'] = (int) $data['slide_delay'];
        }

        return $data;
    }

    public function setSlideShowAttribute($value)
    {
        $data = is_array($value) ? $value : (is_string($value) ? json_decode($value, true) : null);
        if (is_array($data)) {
            $booleanFields = ['auto_play', 'video_overlay', 'pan_adjust'];
            foreach ($booleanFields as $field) {
                if (array_key_exists($field, $data)) {
                    $val = $data[$field];
                    if ($val === null) {
                        $data[$field] = false;
                    } elseif (is_bool($val)) {
                        $data[$field] = $val;
                    } elseif (is_string($val)) {
                        $trimmed = strtolower(trim($val));
                        $data[$field] = in_array($trimmed, ['1', 'true', 'yes', 'on'], true);
                    } elseif (is_numeric($val)) {
                        $data[$field] = (int) $val === 1;
                    } else {
                        $data[$field] = (bool) $val;
                    }
                }
            }

            if (isset($data['slide_delay']) && is_numeric($data['slide_delay'])) {
                $data['slide_delay'] = (int) $data['slide_delay'];
            }

            $this->attributes['slide_show'] = json_encode($data);
        } else {
            $this->attributes['slide_show'] = $value;
        }
    }

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