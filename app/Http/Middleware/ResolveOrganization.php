<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveOrganization
{
    /**
     * Helper to resolve client host domain from Referer, Origin, or Host header.
     */
    private function resolveHost(Request $request): string
    {
        $referer = $request->header('Referer');
        if ($referer) {
            $host = parse_url($referer, PHP_URL_HOST);
            if ($host) {
                return $host;
            }
        }

        $origin = $request->header('Origin');
        if ($origin) {
            $host = parse_url($origin, PHP_URL_HOST);
            if ($host) {
                return $host;
            }
        }

        return $request->getHost();
    }

    /**
     * Resolve the current organization context from the authenticated user.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        // If no user is authenticated on the default guard, check other guards dynamically
        if (!$user && $request->bearerToken()) {
            $guards = ['api', 'agent-api', 'subaccount-api', 'vendor-api'];
            foreach ($guards as $guard) {
                if (\Illuminate\Support\Facades\Auth::guard($guard)->check()) {
                    $user = \Illuminate\Support\Facades\Auth::guard($guard)->user();
                    \Illuminate\Support\Facades\Auth::shouldUse($guard);
                    $request->setUserResolver(fn () => $user);
                    break;
                }
            }
        }

        if ($user) {
            // 1. Check for Super Admin (Case-insensitive variations)
            $isSuperAdmin = false;
            if ($user instanceof \App\Models\User) {
                // TODO: Hardcoded email check for initial Super Admin. 
                // In the future, this can be expanded for more admins or removed once role-based access is fully stable across environments.
                $isSuperAdmin = (trim(strtolower($user->email)) === 'todd@tojuco.com') || $user->roles()->where(function($q) {
                    $q->where('name', 'Super Admin')
                      ->orWhere('name', 'super admin')
                      ->orWhere('name', 'super-admin');
                })->exists();
            }

            // 2. Resolve Context
            if ($isSuperAdmin) {
                // Super Admin on WL Domain: Adopt that domain's context
                $host = $this->resolveHost($request);
                $domainRecord = \App\Models\OrganizationDomain::where('domain', $host)
                    ->orWhere('domain', 'like', '%' . $host . '%')
                    ->first();
                
                $orgId = $domainRecord ? $domainRecord->organization_id : null;
                app()->instance('current_organization_id', $orgId);
            } else {
                // Regular User Check
                $orgId = match(true) {
                    $user instanceof \App\Models\User       => $user->organization_id,
                    $user instanceof \App\Models\Agent      => $user->organization_id,
                    $user instanceof \App\Models\SubAccount => $user->organization_id,
                    $user instanceof \App\Models\Vendor     => $user->organization_id,
                    default => null,
                };

                // SECURITY BLOCK: Orphaned Admin
                if ($orgId === null && ($user instanceof \App\Models\User)) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Access Denied: This account is not assigned to an organization and lacks Super Admin privileges. Please resolve this data inconsistency.',
                        'code' => 'ORPHANED_ADMIN_ERROR'
                    ], 403);
                }

                app()->instance('current_organization_id', $orgId);
            }
        } else {
            // Unauthenticated requests: try to resolve organization context from the domain
            $host = $this->resolveHost($request);
            $domainRecord = \App\Models\OrganizationDomain::where('domain', $host)
                ->orWhere('domain', 'like', '%' . $host . '%')
                ->first();

            $orgId = $domainRecord ? $domainRecord->organization_id : null;
            app()->instance('current_organization_id', $orgId);
        }

        return $next($request);
    }
}
