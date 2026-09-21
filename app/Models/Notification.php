<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use App\Traits\BelongsToOrganization;

class Notification extends Model
{
    use HasFactory, BelongsToOrganization;

    protected $fillable = [
        'uuid',
        'organization_id',
        'source',
        'source_id',
        'type',
        'description',
        'user_uuid',
        'agent_uuid',
        'vendor_uuids',
        'role',
        'diff_data',
        'meta_data',
        'is_read',
        'created_by_name',
    ];

    protected $casts = [
        'vendor_uuids' => 'array',
        'is_read' => 'boolean',
        'diff_data' => 'array',
        'meta_data' => 'array',
    ];

    protected static function booted()
    {
        static::creating(function ($model) {
            $model->uuid = $model->uuid ?? Str::uuid();
        });
    }

    // Relationship: linked agent
    public function agent()
    {
        return $this->belongsTo(Agent::class, 'agent_uuid', 'uuid');
    }

    // Relationship: not direct, but helper to get all vendors in one go
    public function vendors()
    {
        return Vendor::whereIn('uuid', $this->vendor_uuids ?? [])->get();
    }

    // Optional: relationship for admin/user
    public function user()
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }
    public function order()
    {
        return $this->belongsTo(Order::class, 'source_id', 'uuid');
    }

}


