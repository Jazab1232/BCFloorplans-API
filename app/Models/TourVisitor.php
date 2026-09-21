<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TourVisitor extends Model
{
    use HasFactory;

    protected $fillable = [
        'tour_id',
        'visitor_identifier'
    ];

    public function tour()
    {
        return $this->belongsTo(Tour::class);
    }
}
