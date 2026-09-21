<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TourDailyReferrer extends Model
{
    use HasFactory;

    protected $fillable = [
        'tour_id',
        'date',
        'referrer_domain',
        'count'
    ];

    public function tour()
    {
        return $this->belongsTo(Tour::class);
    }
}
