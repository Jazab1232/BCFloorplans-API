<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\OrganizationDomain;

class DomainController extends Controller
{
    /**
     * Resolve a domain to its organization and portal type.
     */
    public function resolve(Request $request): JsonResponse
    {
        $domainStr = $request->query('domain');

        if (!$domainStr) {
            return response()->json(['error' => 'Domain parameter is required'], 400);
        }

        // Clean protocol, port, and trailing slashes for robust matching
        $domainStr = preg_replace('/^https?:\/\//i', '', $domainStr);
        $domainStr = explode(':', $domainStr)[0];
        $domainStr = rtrim($domainStr, '/');

        // Find the domain in the database (supports fuzzy matching for uncleaned records)
        $domainRecord = OrganizationDomain::with(['organization.settings'])
            ->where(function($query) use ($domainStr) {
                $query->where('domain', $domainStr)
                      ->orWhere('domain', 'like', '%' . $domainStr . '%');
            })->first();

        if (!$domainRecord || !$domainRecord->organization) {
            return response()->json(['error' => 'Domain not found'], 404);
        }

        $organization = $domainRecord->organization;

        // Parse settings into a key-value array with global fallback
        $settingsDict = [];
        
        // 1. Load Global Settings
        $globalSettings = \App\Models\Setting::whereNull('org_id')->get();
        foreach ($globalSettings as $setting) {
            $val = $setting->value;
            if (is_array($val) && isset($val['value'])) {
                $val = $val['value'];
            }
            $settingsDict[$setting->key] = $val;
        }

        // 2. Overwrite with Organization Settings
        if ($organization->settings) {
            foreach ($organization->settings as $setting) {
                // If the value is an array and has a 'value' key, extract it
                // This matches the pattern used in SettingsService/BrandingController
                $val = $setting->value;
                if (is_array($val) && isset($val['value'])) {
                    $val = $val['value'];
                }
                $settingsDict[$setting->key] = $val;
            }
        }

        // Get logo URL (use the first available logo in company_logos_urls)
        $logoUrl = null;
        if (!empty($organization->company_logos_urls)) {
            // First look for 'primary_logo', fallback to the first one available
            $logos = collect($organization->company_logos_urls);
            $primary = $logos->firstWhere('type', 'primary_logo') ?? $logos->first();
            $logoUrl = $primary['url'] ?? null;
        }

        return response()->json([
            'org_id' => $organization->id,
            'organization_id' => $organization->id,
            'uuid' => $organization->uuid,
            'organization_uuid' => $organization->uuid,
            'slug' => $organization->slug,
            'portal_type' => $domainRecord->portal_type,
            'is_whitelabel' => (bool)$organization->is_whitelabel,
            'from_name' => $organization->from_name,
            'from_email' => $organization->from_email,
            'branding' => [
                'primary_color' => $settingsDict['primary_color'] ?? '#6BAE41',
                'secondary_color' => $settingsDict['secondary_color'] ?? '#DC9600',
                'logo' => $logoUrl,
                'white_label_styles' => isset($settingsDict['white_label_styles']) ? 
                    (is_string($settingsDict['white_label_styles']) ? json_decode($settingsDict['white_label_styles'], true) : $settingsDict['white_label_styles']) 
                    : null
            ]
        ], 200);
    }
}
