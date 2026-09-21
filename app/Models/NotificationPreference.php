<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use App\Traits\BelongsToOrganization;

class NotificationPreference extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'uuid',
        'organization_id',
        'user_id',
        'role',
        'event_type',
        'email_enabled',
    ];

    protected $casts = [
        'email_enabled' => 'boolean',
    ];

    /**
     * Auto-generate UUID
     */
    protected static function booted()
    {
        static::creating(function ($model) {
            if (!$model->uuid) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Relationship to User (if preference is user-specific)
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
