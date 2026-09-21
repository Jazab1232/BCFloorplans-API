<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TourDailyStat extends Model
{
    use HasFactory;

    protected $fillable = [
        'tour_id',
        'date',
        'views'
    ];

    public function tour()
    {
        return $this->belongsTo(Tour::class);
    }
}
