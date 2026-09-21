<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\BelongsToOrganization;

class Package extends Model
{
    use HasFactory, BelongsToOrganization;

    public $orgScopeIncludesGlobal = false;

    protected $fillable = [
        'uuid',
        'organization_id',
        'name',
        'discount',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
        'discount' => 'integer',
        'organization_id' => 'integer',
    ];

    /**
     * A package has many services (many-to-many).
     */
    public function services()
    {
        return $this->belongsToMany(Service::class, 'package_service')
                    ->withTimestamps();
    }
}
