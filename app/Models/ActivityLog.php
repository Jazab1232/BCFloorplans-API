<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $fillable = [
        'action',
        'model_type',
        'model_id',
        'data',
        'ip_address',
        'user_agent',
        'causer_type',
        'causer_uuid'
    ];

    protected $casts = [
        'data' => 'array',
    ];

    public function causer()
    {
        return $this->morphTo(__FUNCTION__, 'causer_type', 'causer_uuid', 'uuid');
    }
}