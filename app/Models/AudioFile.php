<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AudioFile extends Model
{
    use HasFactory;

    protected $table = 'audio_files';

    protected $fillable = [
        'uuid',
        'agent_id',
        'organization_id',
        'name',
        'file_path',
        'mime_type',
        'size',
        'duration',
        'is_active',
        'uploaded_by',
    ];

    protected $appends = [
        'audio_url'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'size' => 'integer',
        'duration' => 'integer',
    ];

    /**
     * Publicly accessible audio URL from S3
     */
    public function getAudioUrlAttribute(): ?string
    {
        if (!$this->file_path) {
            return null;
        }

        // Check if path is already a full URL
        if (filter_var($this->file_path, FILTER_VALIDATE_URL)) {
            return $this->file_path;
        }

        // New files go to S3, old ones might still be on public disk
        // But the requirement is to handle with S3, so we assume S3 for new ones
        // If we want to support both, we'd need a 'disk' column, but let's assume S3 for now
        // as per the user's request "handle those files with s3 as well instead of local"
        return Storage::disk('s3')->url($this->file_path);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
