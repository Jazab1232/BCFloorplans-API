<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrganizationDomain extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'domain',
        'portal_type',
    ];

    /**
     * Get the organization that owns the domain.
     */
    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
