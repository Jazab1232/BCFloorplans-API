<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaDownloadJob extends Model
{
    protected $fillable = [
        'uuid',
        'user_id',
        'user_type',
        'status',
        'processed_count',
        'file_count',
        'zip_path',
        'expires_at',
        'error_message',
        'options',
    ];

    protected $casts = [
        'processed_count' => 'integer',
        'file_count' => 'integer',
        'expires_at' => 'datetime',
        'options' => 'array',
    ];

    /**
     * Get the user that owns the job (polymorphic).
     */
    public function user()
    {
        return $this->morphTo('user', 'user_type', 'user_id');
    }
}
