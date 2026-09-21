<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use App\Services\ImageResizeService;

class FeatureSheetImage extends Model
{
    protected $fillable = [
        'uuid',
        'feature_sheet_id',
        'slot',
        'storage_path',
        'url',
        'mime',
        'size',
        'meta',
        'variants',
        'order',
        'is_processing',
        'uploaded_by',
        'uploaded_at',
        'is_hidden',
    ];

    protected $casts = [
        'meta' => 'array',
        'variants' => 'array',
        'is_processing' => 'boolean',
        'uploaded_at' => 'datetime',
        'is_hidden' => 'boolean',
    ];

    protected $appends = ['url', 'thumbnail_url', 'variant_urls', 'is_original_deleted', 'retention_policy_notice'];

    protected $hidden = [
        'storage_path',
        'url',
    ];

    /**
     * Boot model events
     */
    protected static function boot()
    {
        parent::boot();

        // Auto-generate UUID on creation
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = Str::uuid();
            }
        });

        // Clear storage when image is deleted
        static::deleted(function ($image) {
            // Delete original file from S3
            if ($image->storage_path) {
                Storage::disk('s3')->delete($image->storage_path);
            }

            // Delete variants from S3
            if (!empty($image->variants)) {
                $imageResizeService = app(ImageResizeService::class);
                $imageResizeService->deleteVariants($image->variants);
            }
        });
    }

    /**
     * Get presigned S3 URL for the original image
     */
    public function getUrlAttribute()
    {
        return $this->storage_path ? Storage::disk('s3')->url($this->storage_path) : null;
    }

    /**
     * Get presigned S3 URL for thumbnail variant
     */
    public function getThumbnailUrlAttribute()
    {
        if ($this->is_processing) {
            return null;
        }

        if (!empty($this->variants) && isset($this->variants['thumb'])) {
            return Storage::disk('s3')->url($this->variants['thumb']);
        }

        return $this->url;
    }

    /**
     * Get full URLs for all size variants
     */
    public function getVariantUrlsAttribute()
    {
        if (empty($this->variants)) {
            return [];
        }

        return collect($this->variants)
            ->map(function ($path) {
                return Storage::disk('s3')->url($path);
            })->toArray();
    }

    /**
     * Check if the original high-res file has been deleted/downsized
     */
    public function getIsOriginalDeletedAttribute(): bool
    {
        if (empty($this->variants) || !isset($this->variants['print'])) {
            return false;
        }

        return $this->storage_path === $this->variants['print'];
    }

    /**
     * Get a notice about the media retention policy
     */
    public function getRetentionPolicyNoticeAttribute(): ?string
    {
        if ($this->getIsOriginalDeletedAttribute()) {
            return "The original raw file has been archived. A high-quality print-optimized version is available.";
        }

        $daysOld = $this->created_at->diffInDays(now());
        $remainingDays = max(0, 30 - $daysOld);

        if ($remainingDays <= 7) {
            return "Notice: Original raw files are stored for 30 days. This file will be archived in {$remainingDays} days.";
        }

        return null;
    }

    /**
     * Override toArray
     */
    public function toArray()
    {
        $array = parent::toArray();
        return $array;
    }

    /**
     * Relation to feature sheet
     */
    public function featureSheet()
    {
        return $this->belongsTo(FeatureSheet::class, 'feature_sheet_id', 'id');
    }
}
