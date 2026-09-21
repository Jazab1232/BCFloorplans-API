<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Setting extends Model
{
    protected $fillable = [
        'uuid',
        'key',
        'value',
        'org_id',
        'updated_by',
    ];

    protected $casts = [
        'value' => 'array',
    ];

    // Auto-generate UUID
    protected static function booted()
    {
        static::creating(function ($model) {
            if (!$model->uuid) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    // Relation to admin user
    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by', 'uuid');
    }
    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id', 'uuid');
    }

}
