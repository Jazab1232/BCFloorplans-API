<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SettingsRequest;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use App\Models\Organization;
use App\Exceptions\SettingNotFoundException;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    protected SettingsService $settings;

    public function __construct(SettingsService $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Resolve org_id from optional org_uuid
     */
    protected function resolveOrgId(Request $request): ?string
    {
        $user = auth()->user();

        if ($user) {
            // Determine if they are Super Admin
            $isSuperAdmin = false;
            if ($user instanceof \App\Models\User) {
                $isSuperAdmin = (trim(strtolower($user->email)) === 'todd@tojuco.com') || $user->roles()->where(function($q) {
                    $q->where('name', 'Super Admin')
                      ->orWhere('name', 'super admin')
                      ->orWhere('name', 'super-admin');
                })->exists();
            }

            if (!$isSuperAdmin) {
                // Non-Super-Admins can ONLY view settings of their own organization
                return match(true) {
                    $user instanceof \App\Models\User       => $user->organization?->uuid,
                    $user instanceof \App\Models\Agent      => $user->organization?->uuid,
                    $user instanceof \App\Models\SubAccount => $user->organization?->uuid,
                    $user instanceof \App\Models\Vendor     => $user->organization?->uuid,
                    default => null,
                };
            }
        }

        if (!$request->filled('org_uuid')) {
            return null; // GLOBAL SETTINGS
        }

        // $org = Organization::where('uuid', $request->org_uuid)->firstOrFail();
        return $request->org_uuid;
    }

    public function index(Request $request): JsonResponse
{
    $orgId = $this->resolveOrgId($request);

    $settings = \App\Models\Setting::when(
        $orgId,
        fn ($q) => $q->where('org_id', $orgId),
        fn ($q) => $q->whereNull('org_id')
    )->get();

    return response()->json([
        'org_uuid' => $orgId,
        'settings' => $settings
    ]);
}

    // GET /settings/{key}
    public function show(Request $request, string $key): JsonResponse
    {
        try {
            $orgId = $this->resolveOrgId($request);

            $value = $this->settings->get($orgId, $key);

            return response()->json([
                'key' => $key,
                'org_uuid' => $orgId,
                'value' => $value
            ]);
        } catch (SettingNotFoundException $e) {
            return response()->json([
                'message' => 'Setting not found',
                'key' => $key
            ], 404);
        }
    }

    // POST /settings/{key}
    public function update(SettingsRequest $request, string $key): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);

        $setting = $this->settings->set(
            $orgId,
            $key,
            $request->validated()['value'],
            auth()->user()->uuid
        );

        return response()->json([
            'message' => 'Setting updated successfully',
            'setting' => $setting
        ]);
    }

    // DELETE /settings/{key}
    public function destroy(Request $request, string $key): JsonResponse
    {
        $orgId = $this->resolveOrgId($request);

        if ($orgId === null) {
            return response()->json([
                'message' => 'Global settings cannot be deleted'
            ], 403);
        }

        $this->settings->delete($orgId, $key);

        return response()->json([
            'message' => 'Setting deleted successfully'
        ]);
    }
}
