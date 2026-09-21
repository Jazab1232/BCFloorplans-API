<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TaxCalculationService;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class TaxSettingsController extends Controller
{
    /**
     * Resolve org_uuid from request or authenticated user.
     */
    protected function resolveOrgUuid(Request $request): ?string
    {
        $user = Auth::user();

        if ($user) {
            $isSuperAdmin = false;
            if ($user instanceof User) {
                $isSuperAdmin = (trim(strtolower($user->email)) === 'todd@tojuco.com') || $user->roles()->where(function ($q) {
                    $q->where('name', 'Super Admin')
                      ->orWhere('name', 'super admin')
                      ->orWhere('name', 'super-admin');
                })->exists();
            }

            if (!$isSuperAdmin) {
                return match (true) {
                    $user instanceof \App\Models\User       => $user->organization?->uuid,
                    $user instanceof \App\Models\Agent      => $user->organization?->uuid,
                    $user instanceof \App\Models\SubAccount => $user->organization?->uuid,
                    $user instanceof \App\Models\Vendor     => $user->organization?->uuid,
                    default => null,
                };
            }
        }

        if ($request->filled('org_uuid')) {
            return $request->org_uuid;
        }

        return $user->organization?->uuid ?? null;
    }

    /**
     * GET /api/tax-settings
     * Retrieve tax settings for the current organization.
     */
    public function show(Request $request): JsonResponse
    {
        try {
            $orgUuid = $this->resolveOrgUuid($request);

            if (!$orgUuid) {
                return response()->json([
                    'success' => false,
                    'message' => 'Organization not resolved',
                ], 404);
            }

            $settings = TaxCalculationService::getOrgTaxSettings($orgUuid);

            return response()->json([
                'success' => true,
                'org_uuid' => $orgUuid,
                'data' => $settings,
            ]);
        } catch (\Throwable $e) {
            Log::error('TaxSettingsController@show error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve tax settings: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/tax-settings
     * Update tax settings for the current organization.
     */
    public function update(Request $request): JsonResponse
    {
        try {
            $orgUuid = $this->resolveOrgUuid($request);

            if (!$orgUuid) {
                return response()->json([
                    'success' => false,
                    'message' => 'Organization not resolved',
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'is_enabled' => 'nullable|boolean',
                'calculation_basis' => 'required|in:origin,destination',
                'origin_taxes' => 'nullable|array',
                'origin_taxes.*.name' => 'required|string|max:100',
                'origin_taxes.*.rate' => 'required|numeric|min:0|max:100',
                'origin_taxes.*.registration_number' => 'nullable|string|max:100',
                'origin_taxes.*.is_enabled' => 'nullable|boolean',
                'destination_rules' => 'nullable|array',
                'destination_rules.*.state_province' => 'required|string|max:100',
                'destination_rules.*.country' => 'nullable|string|max:10',
                'destination_rules.*.taxes' => 'nullable|array',
                'destination_rules.*.taxes.*.name' => 'required|string|max:100',
                'destination_rules.*.taxes.*.rate' => 'required|numeric|min:0|max:100',
                'destination_rules.*.taxes.*.registration_number' => 'nullable|string|max:100',
                'destination_rules.*.taxes.*.is_enabled' => 'nullable|boolean',
                'unmatched_destination_policy' => 'nullable|in:zero_tax,fallback_rate',
                'fallback_tax' => 'nullable|array',
                'fallback_tax.name' => 'nullable|string|max:100',
                'fallback_tax.rate' => 'nullable|numeric|min:0|max:100',
                'fallback_tax.registration_number' => 'nullable|string|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $user = Auth::user();
            $updated = TaxCalculationService::saveOrgTaxSettings($orgUuid, $request->all(), $user?->uuid);

            return response()->json([
                'success' => true,
                'message' => 'Tax settings saved successfully',
                'org_uuid' => $orgUuid,
                'data' => $updated,
            ]);
        } catch (\Throwable $e) {
            Log::error('TaxSettingsController@update error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to save tax settings: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/tax-settings/calculate-preview
     * Real-time tax calculation for order creation and checkout.
     */
    public function calculatePreview(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'items' => 'required|array',
                'items.*.amount' => 'required|numeric',
                'items.*.is_taxable' => 'nullable|boolean',
                'items.*.service_id' => 'nullable',
                'property_province' => 'nullable|string|max:100',
                'property_country' => 'nullable|string|max:10',
                'org_uuid' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $orgUuid = $request->input('org_uuid');
            if (!$orgUuid && $request->filled('org_slug')) {
                $org = Organization::where('slug', $request->input('org_slug'))->first();
                $orgUuid = $org?->uuid;
            }
            if (!$orgUuid) {
                $orgUuid = $this->resolveOrgUuid($request);
            }

            $items = $request->input('items', []);
            $province = $request->input('property_province');
            $country = $request->input('property_country', 'CA');

            $calculation = TaxCalculationService::calculateTaxes($orgUuid, $items, $province, $country);

            return response()->json([
                'success' => true,
                'data' => $calculation,
            ]);
        } catch (\Throwable $e) {
            Log::error('TaxSettingsController@calculatePreview error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Calculation failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
