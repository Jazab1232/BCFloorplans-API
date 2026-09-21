<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use App\Traits\BelongsToOrganization;

class MatterportRenewal extends Model
{
    use HasFactory, BelongsToOrganization;

    protected $fillable = [
        'uuid',
        'tour_id',
        'tour_link_id',
        'agent_id',
        'organization_id',
        'invoice_id',
        'previous_expiry_date',
        'new_expiry_date',
        'duration_months',
        'amount',
        'payment_status',
        'payment_method',
        'renewed_by_type',
        'renewed_by_id',
        'notes',
    ];

    protected $casts = [
        'previous_expiry_date' => 'date',
        'new_expiry_date' => 'date',
        'duration_months' => 'integer',
        'amount' => 'decimal:2',
    ];

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function tour(): BelongsTo
    {
        return $this->belongsTo(Tour::class);
    }

    public function tourLink(): BelongsTo
    {
        return $this->belongsTo(TourLink::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
