<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Traits\BelongsToOrganization;
use Illuminate\Support\Str;

class Discount extends Model
{
    use BelongsToOrganization;

    public $orgScopeIncludesGlobal = true;
    protected $fillable = [
        'uuid', 'organization_id', 'type', 'name', 'code_key', 'quantity',
        'percentage', 'expiry_date', 'description', 'status'
    ];

    protected $casts = [
        'uuid' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'discount_services');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    // Optional: use UUID for route model binding
    public function getRouteKeyName()
    {
        return 'uuid';
    }

    public function requiredServices()
    {
        return $this->belongsToMany(Service::class, 'discount_services');
    }
}
