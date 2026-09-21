<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class BrandingController extends Controller
{
    protected SettingsService $settings;

    public function __construct(SettingsService $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Get branding settings for an organization.
     * GET /api/organizations/{uuid}/branding
     */
    public function show($uuid): JsonResponse
    {
        $organization = Organization::where('uuid', $uuid)->firstOrFail();

        // Get colors from settings
        $primaryColor = null;
        $secondaryColor = null;

        try {
            $val = $this->settings->get($uuid, 'primary_color');
            $primaryColor = is_array($val) ? ($val['value'] ?? $val[0] ?? '#6BAE41') : $val;
        } catch (\Exception $e) {
            $primaryColor = '#6BAE41'; // Default
        }

        try {
            $val = $this->settings->get($uuid, 'secondary_color');
            $secondaryColor = is_array($val) ? ($val['value'] ?? $val[0] ?? '#DC9600') : $val;
        } catch (\Exception $e) {
            $secondaryColor = '#DC9600'; // Default
        }

        // Get logo from organization model
        $logoUrl = null;
        if (!empty($organization->company_logos_urls)) {
            $logos = collect($organization->company_logos_urls);
            $primary = $logos->firstWhere('type', 'primary_logo') ?? $logos->first();
            $logoUrl = $primary['url'] ?? null;
        }

        return response()->json([
            'status' => true,
            'data' => [
                'primary_color' => $primaryColor,
                'secondary_color' => $secondaryColor,
                'logo_url' => $logoUrl,
                'company_logos' => $organization->company_logos_urls // Full list for flexibility
            ]
        ]);
    }

    /**
     * Update branding settings (colors and logo).
     * POST /api/organizations/{uuid}/branding
     */
    public function update(Request $request, $uuid): JsonResponse
    {
        $organization = Organization::where('uuid', $uuid)->firstOrFail();

        $request->validate([
            'primary_color' => 'sometimes|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'secondary_color' => 'sometimes|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'logo' => 'sometimes|image|mimes:jpg,jpeg,png,webp,svg|max:2048',
        ]);

        $adminUuid = auth()->user()->uuid ?? null;

        DB::transaction(function () use ($request, $organization, $uuid, $adminUuid) {
            // 1. Update Colors in Settings
            if ($request->has('primary_color')) {
                $this->settings->set($uuid, 'primary_color', ['value' => $request->primary_color], $adminUuid);
            }

            if ($request->has('secondary_color')) {
                $this->settings->set($uuid, 'secondary_color', ['value' => $request->secondary_color], $adminUuid);
            }

            // 2. Update Logo in Organization Model
            if ($request->hasFile('logo')) {
                $file = $request->file('logo');
                $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
                $path = "organizations/{$organization->uuid}/logos/{$filename}";

                // Upload to S3
                Storage::disk('s3')->put($path, file_get_contents($file), 'public');

                // Update company_logos array
                $logos = $organization->company_logos ?: [];
                
                // Find and remove old primary logo file from S3 if it exists
                foreach ($logos as $index => $logo) {
                    if (($logo['type'] ?? '') === 'primary_logo' && isset($logo['path'])) {
                        Storage::disk('s3')->delete($logo['path']);
                        unset($logos[$index]);
                    }
                }

                // Add new primary logo
                $logos[] = [
                    'type' => 'primary_logo',
                    'path' => $path
                ];

                $organization->update([
                    'company_logos' => array_values($logos)
                ]);
            }
        });

        return $this->show($uuid);
    }
}
