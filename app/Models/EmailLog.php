<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use App\Traits\BelongsToOrganization;

class EmailLog extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'uuid',
        'organization_id',
        'event_type',
        'recipient_role',
        'to_email',
        'from_email',
        'subject',
        'status',
        'resend_email_id',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
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
}
