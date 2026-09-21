<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use Illuminate\Support\Facades\Storage;

class TourSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'tour_id',
        'name',
        'description',
        'file_name',
        'file_path',
        'x_axis',
        'y_axis',
        'sort_order',
        'is_admin_approved',
        'is_agent_approved',
        'is_hidden',
    ];

    protected $casts = [
        'x_axis' => 'float',
        'y_axis' => 'float',
        'is_admin_approved' => 'boolean',
        'is_agent_approved' => 'boolean',
        'is_hidden' => 'boolean',
    ];

    /**
     * Boot model events
     */
    protected static function boot()
    {
        parent::boot();

        // Clear storage when snapshot is deleted
        static::deleted(function ($snapshot) {
            if ($snapshot->file_path) {
                Storage::disk('s3')->delete($snapshot->file_path);
            }
        });
    }

    public function tour()
    {
        return $this->belongsTo(Tour::class);
    }
}