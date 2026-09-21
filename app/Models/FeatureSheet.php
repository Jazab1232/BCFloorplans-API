<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use App\Traits\BelongsToOrganization;

class FeatureSheet extends Model
{
    use BelongsToOrganization;
    protected $fillable = [
        'uuid',
        'organization_id',
        'order_id',
        'template_key',
        'content',
        'pdf_path',
        'pdf_url',
        'type',
        'uploaded_by',
        'is_processing',
        'is_published',
    ];

    protected $casts = [
        'content' => 'array',
        'is_processing' => 'boolean',
        'is_published' => 'boolean',
    ];

    protected $appends = ['url'];

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

        // Delete PDF from S3 when feature sheet is deleted
        static::deleted(function ($featureSheet) {
            if ($featureSheet->type === 'pdf' && $featureSheet->pdf_path) {
                Storage::disk('s3')->delete($featureSheet->pdf_path);
            }
        });
    }

    /**
     * Get presigned S3 URL for PDF
     */
    public function getUrlAttribute()
    {
        if ($this->type === 'pdf' && $this->pdf_path) {
            return Storage::disk('s3')->url($this->pdf_path);
        }
        return null;
    }

    /**
     * Relation to feature sheet images
     */
    public function images()
    {
        return $this->hasMany(FeatureSheetImage::class);
    }

    /**
     * Relation to order
     */
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'uuid');
    }

    /**
     * Relation to order services
     */
    public function orderServices()
    {
        return $this->hasMany(OrderService::class, 'feature_sheet_uuid', 'uuid');
    }

    /**
     * Relation to print requests
     */
    public function printRequests()
    {
        return $this->hasMany(PrintRequest::class, 'feature_sheet_id', 'uuid');
    }
}
