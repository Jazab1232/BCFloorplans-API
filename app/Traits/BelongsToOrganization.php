<?php

namespace App\Traits;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToOrganization
{
    /**
     * Boot the trait to add global scope and auto-assign organization_id.
     */
    public static function bootBelongsToOrganization(): void
    {
        // 1. Global Scope: Automatically filter queries by organization_id
        static::addGlobalScope('organization', function (Builder $builder) {
            if (!app()->bound('current_organization_id')) {
                return;
            }

            $orgId = app()->bound('current_organization_id') ? app('current_organization_id') : null;
            $user = auth()->user();

            // Super Admin Bypass
            $isSuperAdmin = ($user instanceof \App\Models\User) && (
                trim(strtolower($user->email)) === 'todd@tojuco.com' ||
                $user->roles()->where(function($q) {
                    $q->where('name', 'Super Admin')
                      ->orWhere('name', 'super admin')
                      ->orWhere('name', 'super-admin');
                })->exists()
            );

            if ($isSuperAdmin) {
                return;
            }

            // Enforce scope for regular authenticated users who belong to an organization
            if ($orgId === null && $user) {
                $orgId = match(true) {
                    $user instanceof \App\Models\User       => $user->organization_id,
                    $user instanceof \App\Models\Agent      => $user->organization_id,
                    $user instanceof \App\Models\SubAccount => $user->organization_id,
                    $user instanceof \App\Models\Vendor     => $user->organization_id,
                    default => null,
                };
            }

            // Deny query if we have no organization ID for an authenticated non-super-admin context
            if ($orgId === null) {
                if ($user) {
                    $builder->whereRaw('1 = 0');
                }
                return;
            }

            $model = $builder->getModel();
            $table = $model->getTable();

            // Handle models that allow global fallback (org_id is null)
            if (property_exists($model, 'orgScopeIncludesGlobal') && $model->orgScopeIncludesGlobal) {
                $builder->where(function ($q) use ($table, $orgId) {
                    $q->where("{$table}.organization_id", $orgId)
                      ->orWhereNull("{$table}.organization_id");
                });
            } else {
                $builder->where("{$table}.organization_id", $orgId);
            }
        });

        // 2. Model Event: Auto-assign organization_id on creation
        static::creating(function ($model) {
            if (empty($model->organization_id) && app()->bound('current_organization_id')) {
                $orgId = app()->bound('current_organization_id') ? app('current_organization_id') : null;
                // Only auto-assign if it's not a super-admin context
                if ($orgId !== null) {
                    $model->organization_id = $orgId;
                }
            }
        });
    }

    /**
     * Relationship to the Organization.
     */
    public function organization()
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
