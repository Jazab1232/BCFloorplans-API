<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VendorPortfolioImage extends Model
{
    protected $fillable = [
        'uuid',
        'vendor_id',
        'image_path',
        'image_type',
        'is_processing',
        'variants',
    ];

    protected $casts = [
        'is_processing' => 'boolean',
        'variants' => 'array',
    ];

    protected $appends = ['image_url', 'full_storage_path', 'file_exists', 'variant_urls', 'is_original_deleted', 'retention_policy_notice'];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->uuid = \Illuminate\Support\Str::uuid();
        });

        // Clear storage when portfolio image is deleted
        static::deleted(function ($image) {
            if ($image->image_type === 'uploaded') {
                if ($image->variants) {
                    foreach ($image->variants as $variantPath) {
                        Storage::disk('s3')->delete($variantPath);
                    }
                }
                Storage::disk('s3')->delete($image->image_path);
            } elseif ($image->full_storage_path) {
                Storage::disk('s3')->delete($image->full_storage_path);
            }
        });
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    // NEW: Optional relationship to tour
    // public function tour(): BelongsTo
    // {
    //     return $this->belongsTo(Tour::class);
    // }

    /**
     * Generate the full URL for the image based on type
     */
    public function getImageUrlAttribute()
    {
        if (!$this->image_path) {
            return null;
        }

        // Handle tour reference images or external URLs
        if ($this->image_type === 'tour_reference' || $this->image_type === 'external_url') {
            if (filter_var($this->image_path, FILTER_VALIDATE_URL)) {
                return $this->image_path;
            }
            return asset('storage/' . $this->image_path);
        }

        // Handle uploaded portfolio images on S3
        if ($this->image_type === 'uploaded') {
            // Check if it's an S3 path (starts with 'vendors/')
            if (str_starts_with($this->image_path, 'vendors/')) {
                // If we have variants, return the 'slider' or 'landing' variant by default
                if ($this->variants && isset($this->variants['slider'])) {
                    return Storage::disk('s3')->url($this->variants['slider']);
                }
                return Storage::disk('s3')->url($this->image_path);
            }

            // Legacy local storage
            $vendor = $this->vendor;
            if (!$vendor) {
                return null;
            }
            return asset('storage/vendors/portfolio/' . $vendor->uuid . '/' . $this->image_path);
        }

        return null;
    }

    /**
     * Get the full storage path (for file operations)
     */
    public function getFullStoragePathAttribute()
    {
        if (!$this->image_path) {
            return null;
        }

        // For tour references, path is already complete
        if ($this->image_type === 'tour_reference') {
            return $this->image_path;
        }

        // For uploaded images, build the path
        $vendor = $this->vendor;
        if (!$vendor) {
            return null;
        }

        return "vendors/portfolio/{$vendor->uuid}/{$this->image_path}";
    }

    /**
     * Check if the file actually exists in storage
     */
    public function getFileExistsAttribute()
    {
        $fullPath = $this->full_storage_path;
        if (!$fullPath) {
            return false;
        }

        return Storage::disk('public')->exists($fullPath);
    }

    public function getVariantUrlsAttribute()
    {
        if ($this->image_type !== 'uploaded' || empty($this->variants)) {
            return [];
        }

        return collect($this->variants)
            ->forget('print') // Hide print variant from frontend
            ->map(function ($path) {
                return Storage::disk('s3')->url($path);
            })->toArray();
    }

    /**
     * Check if the original high-res file has been deleted/downsized
     */
    public function getIsOriginalDeletedAttribute(): bool
    {
        if ($this->image_type !== 'uploaded' || empty($this->variants) || !isset($this->variants['print'])) {
            return false;
        }

        return $this->image_path === $this->variants['print'];
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
     * Override toArray to hide 'print' key from variants JSON
     */
    public function toArray()
    {
        $array = parent::toArray();
        if (isset($array['variants']) && is_array($array['variants'])) {
            unset($array['variants']['print']);
        }
        return $array;
    }
}