<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\ImageResizeService;
use Illuminate\Support\Facades\Storage;

class TourFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'tour_id',
        'type',
        'subtype',
        'name',
        'file_path',
        'group',
        'is_featured',
        'service_id',
        'sort_order',
        'is_admin_approved',
        'is_agent_approved',
        'is_show',
        'is_processing',
        'variants',
        'is_complimentary',
        'is_hidden',
    ];

    protected $appends = ['url', 'thumbnail_url', 'variant_urls', 'is_paid', 'is_original_deleted', 'retention_policy_notice'];

    protected $hidden = [
        'file_path',
        'url',
    ];

    protected $casts = [
        'is_featured' => 'boolean',
        'is_admin_approved' => 'boolean',
        'is_agent_approved' => 'boolean',
        'is_show' => 'boolean',
        'is_processing' => 'boolean',
        'variants' => 'array',
        'is_complimentary' => 'boolean',
        'is_hidden' => 'boolean',
    ];

    public function getIsPaidAttribute()
    {
        $tour = $this->tour;
        $order = $tour ? ($tour->order ?? $tour->orders) : null;

        // If it's linked to a service, check the payment / media_access status
        if ($this->service_id) {
            if ($tour) {
                // Find the specific OrderService for this tour and service
                $orderService = \App\Models\OrderService::where('order_id', $tour->order_id ?? 0)
                                                        ->where('service_id', $this->service_id)
                                                        ->first();
                if ($orderService) {
                    if ($orderService->media_access !== null) {
                        return (bool) $orderService->media_access;
                    }
                    return $orderService->payment_status === 'PAID';
                }
            }
        }

        if ($order) {
            if ($order->lock_materials && $order->payment_status !== 'PAID') {
                return false;
            }
            if ($order->payment_status === 'PAID') {
                return true;
            }
        }

        // Fallback: If no service linked or no orderservice found, return true to avoid incorrectly locking old media.
        return true;
    }

    public function tour()
    {
        return $this->belongsTo(Tour::class);
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Boot model events
     */
    protected static function boot()
    {
        parent::boot();

        // Clear storage when file is deleted
        static::deleted(function ($tourFile) {
            // Delete original file from S3
            if ($tourFile->file_path) {
                Storage::disk('s3')->delete($tourFile->file_path);
            }

            // Clear cached resized images
            if ($tourFile->type === 'photo' && !empty($tourFile->variants)) {
                $imageResizeService = app(ImageResizeService::class);
                $imageResizeService->deleteVariants($tourFile->variants);
            }
        });
    }

    public function getUrlAttribute()
    {
        $url = $this->file_path ? Storage::disk('s3')->url($this->file_path) : null;
        return $this->appendCacheBuster($url);
    }

    public function getThumbnailUrlAttribute()
    {
        if ($this->is_processing) {
            return null;
        }

        // Security check: if PDF/Video and not paid, don't return thumbnail (which would be original URL for PDFs)
        if (in_array($this->type, ['pdf', 'video']) && !$this->is_paid) {
            return null;
        }

        if (!empty($this->variants) && isset($this->variants['thumb'])) {
            $url = Storage::disk('s3')->url($this->variants['thumb']);
            return $this->appendCacheBuster($url);
        }

        return $this->url;
    }

    public function getVariantUrlsAttribute()
    {
        if (empty($this->variants)) {
            return [];
        }

        // Security check: if PDF and not paid, don't return any variant URLs (since they point to original file)
        if ($this->type === 'pdf' && !$this->is_paid) {
            return [];
        }

        return collect($this->variants)
            ->map(function ($s3Key) {
                $url = Storage::disk('s3')->url($s3Key);
                return $this->appendCacheBuster($url);
            })->toArray();
    }

    /**
     * Append updated_at cache-busting timestamp to S3 URL
     */
    protected function appendCacheBuster(?string $url): ?string
    {
        if (empty($url)) {
            return $url;
        }

        $timestamp = $this->updated_at ? $this->updated_at->timestamp : time();
        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . 't=' . $timestamp;
    }

    /**
     * Check if the original high-res file has been deleted/downsized
     */
    public function getIsOriginalDeletedAttribute(): bool
    {
        if (empty($this->variants) || !isset($this->variants['print'])) {
            return false;
        }

        // If file_path matches the print variant path, it means the original is gone
        return $this->file_path === $this->variants['print'];
    }

    /**
     * Get a notice about the media retention policy
     */
    public function getRetentionPolicyNoticeAttribute(): ?string
    {
        if ($this->getIsOriginalDeletedAttribute()) {
            return "The original raw file has been archived. A high-quality print-optimized version is available for download.";
        }

        if (!$this->created_at) {
            return null;
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

        $user = request()->user();
        $isAdminOrVendor = $user && ($user instanceof \App\Models\User || $user instanceof \App\Models\Vendor);

        // Expose url and file_path for videos if paid, complimentary, or requested by authenticated user
        if ($this->type === 'video') {
            if ($this->is_paid || $this->is_complimentary || $user !== null) {
                $array['url'] = $this->url;
                $array['file_path'] = $this->file_path;
            }
        }

        // Expose url and file_path for PDFs and documents if paid, complimentary, or requested by admin/vendor
        if (in_array($this->type, ['pdf', 'document'])) {
            if ($this->is_paid || $this->is_complimentary || $isAdminOrVendor) {
                $array['url'] = $this->url;
                $array['file_path'] = $this->file_path;
            }
        }

        // If paid or complimentary or admin/vendor, ensure url is exposed for non-photo or fallback media
        if ($this->is_paid || $this->is_complimentary || $isAdminOrVendor) {
            if ($this->type !== 'photo' || empty($this->variants)) {
                $array['url'] = $this->url;
            }
        }

        return $array;
    }
}