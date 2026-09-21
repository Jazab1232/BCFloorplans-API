<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TourDailyMediaStat extends Model
{
    use HasFactory;

    protected $fillable = [
        'tour_id',
        'date',
        'media_uuid',
        'views'
    ];

    public function tour()
    {
        return $this->belongsTo(Tour::class);
    }
}
