<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use App\Traits\BelongsToOrganization;

class PrintRequest extends Model
{
    use BelongsToOrganization;
    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'organization_id',
        'feature_sheet_id',
        'agent_id',
        'property_id',
        'tour_id',
        'copies',
        'option_id',
        'amount',
        'with_bleed',
        'additional_info',
        'status',
    ];

    protected $casts = [
        'with_bleed' => 'boolean',
        'amount' => 'decimal:2',
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
    }

    /**
     * Relation to feature sheet
     */
    public function featureSheet()
    {
        return $this->belongsTo(FeatureSheet::class, 'feature_sheet_id', 'uuid');
    }

    /**
     * Relation to agent
     */
    public function agent()
    {
        return $this->belongsTo(Agent::class, 'agent_id', 'uuid');
    }

    /**
     * Relation to property
     */
    public function property()
    {
        return $this->belongsTo(Property::class, 'property_id', 'uuid');
    }

    /**
     * Relation to tour
     */
    public function tour()
    {
        return $this->belongsTo(Tour::class, 'tour_id', 'uuid');
    }
}
