<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\OrganizationDomain;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class OrganizationController extends Controller
{
    // List all organizations
    public function index(): JsonResponse
    {
        $user = auth()->user();
        $orgId = app()->bound('current_organization_id') ? app('current_organization_id') : null;

        // TODO: Hardcoded email check for initial Super Admin. 
        // In the future, this can be expanded for more admins or removed once role-based access is fully stable across environments.
        $isSuperAdmin = ($user instanceof \App\Models\User) && (
            trim(strtolower($user->email)) === 'todd@tojuco.com' ||
            $user->roles()->where(function($q) {
                $q->where('name', 'Super Admin')
                  ->orWhere('name', 'super admin')
                  ->orWhere('name', 'super-admin');
            })->exists()
        );

        $query = Organization::with(['settings', 'domains']);

        // Only filter if NOT a Super Admin OR if a specific org context was resolved
        if (!$isSuperAdmin && $orgId !== null) {
            $query->where('id', $orgId);
        }

        $orgs = $query->get();

        return response()->json([
            'status' => true,
            'data' => $orgs
        ]);
    }

    // Create new organization


    public function store(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'name' => 'required|string|max:255',
                'slug' => 'nullable|string|max:255|unique:organizations,slug',
                'contact_name' => 'nullable|string|max:255',
                'contact_email' => 'nullable|email|max:255',
                'contact_phone' => 'nullable|string|max:20',
                'address_line_1' => 'nullable|string|max:255',
                'address_line_2' => 'nullable|string|max:255',
                'city' => 'nullable|string|max:255',
                'province' => 'nullable|string|max:255',
                'country' => 'nullable|string|max:255',
                'postal_code' => 'nullable|string|max:20',
                'is_active' => 'sometimes|boolean',
                'is_whitelabel' => 'sometimes|boolean',
                'domain' => 'nullable|string|max:255',
                'from_name' => 'nullable|string|max:255',
                'from_email' => 'nullable|email|max:255',
                'trial_ends_at' => 'nullable|date',
                'owner_user_id' => 'nullable|exists:users,id',
                'company_logos' => 'nullable|array',
                'company_logos.*.file' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
                'company_logos.*.type' => 'required_with:company_logos|string|max:50',
                'domains' => 'nullable|array',
                'domains.*.domain' => 'required|string|max:255',
                'domains.*.portal_type' => 'required|string|in:admin,agent,vendor,tours',
            ]);

            if (empty($data['slug'])) {
                $data['slug'] = Str::slug($data['name']) . '-' . Str::random(4);
            }

            $uuid = (string) Str::uuid();
            $data['uuid'] = $uuid;

            // Process multiple logos
            if ($request->has('company_logos')) {
                $logos = [];
                foreach ($request->input('company_logos') as $index => $logoData) {
                    $type = $logoData['type'];
                    $path = null;

                    if ($request->hasFile("company_logos.{$index}.file")) {
                        $file = $request->file("company_logos.{$index}.file");
                        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
                        $path = "organizations/{$uuid}/logos/{$filename}";
                        Storage::disk('s3')->put($path, file_get_contents($file), [
                            'visibility' => 'public',
                            'ContentType' => $file->getMimeType() ?: 'image/png'
                        ]);
                        Log::info("Uploaded multiple logo type {$type} to S3 for organization: {$path}");
                    }

                    $logos[] = [
                        'type' => $type,
                        'path' => $path
                    ];
                }
                $data['company_logos'] = $logos;
            }

            $organization = DB::transaction(function () use ($data) {
                $org = Organization::create($data);

                // Link owner user if provided
                if (!empty($data['owner_user_id'])) {
                    User::where('id', $data['owner_user_id'])
                        ->update(['organization_id' => $org->id]);
                }

                // Handle custom domains
                if (!empty($data['domains'])) {
                    foreach ($data['domains'] as $domainData) {
                        // Clean domain before saving
                        $cleanDomain = preg_replace('/^https?:\/\//i', '', $domainData['domain']);
                        $cleanDomain = explode(':', $cleanDomain)[0];
                        $cleanDomain = rtrim($cleanDomain, '/');

                        OrganizationDomain::create([
                            'organization_id' => $org->id,
                            'domain' => $cleanDomain,
                            'portal_type' => $domainData['portal_type'],
                        ]);
                    }
                }

                return $org;
            });

            $organization->load(['settings', 'domains']);

            return response()->json([
                'status' => true,
                'message' => 'Organization created successfully',
                'data' => $organization
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to create organization',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }


    // Show single organization
    public function show($uuid): JsonResponse
    {
        $orgId = app()->bound('current_organization_id') ? app('current_organization_id') : null;
        $query = Organization::where('uuid', $uuid)->with(['settings', 'domains']);

        if ($orgId !== null) {
            $query->where('id', $orgId);
        }

        $org = $query->first();

        if (!$org) {
            return response()->json([
                'status' => false,
                'message' => 'Organization not found'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $org
        ]);
    }

    // Update organization
    public function update(Request $request, $uuid): JsonResponse
    {
        try {
            $orgId = app()->bound('current_organization_id') ? app('current_organization_id') : null;
            $query = Organization::where('uuid', $uuid);

            if ($orgId !== null) {
                $query->where('id', $orgId);
            }

            $organization = $query->firstOrFail();

            $data = $request->validate([
                'name' => 'sometimes|string|max:255',
                'slug' => 'nullable|string|max:255|unique:organizations,slug,' . $organization->id,
                'contact_name' => 'nullable|string|max:255',
                'contact_email' => 'nullable|email|max:255',
                'contact_phone' => 'nullable|string|max:20',
                'address_line_1' => 'nullable|string|max:255',
                'address_line_2' => 'nullable|string|max:255',
                'city' => 'nullable|string|max:255',
                'province' => 'nullable|string|max:255',
                'country' => 'nullable|string|max:255',
                'postal_code' => 'nullable|string|max:20',
                'is_active' => 'sometimes|boolean',
                'is_whitelabel' => 'sometimes|boolean',
                'domain' => 'nullable|string|max:255',
                'from_name' => 'nullable|string|max:255',
                'from_email' => 'nullable|email|max:255',
                'trial_ends_at' => 'nullable|date',
                'owner_user_id' => 'nullable|exists:users,id',
                'company_logos' => 'nullable|array',
                'company_logos.*.file' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
                'company_logos.*.type' => 'required_with:company_logos|string|max:50',
                'company_logos.*.path' => 'nullable|string',
                'domains' => 'nullable|array',
                'domains.*.domain' => 'required|string|max:255',
                'domains.*.portal_type' => 'required|string|in:admin,agent,vendor,tours',
            ]);

            if (empty($data['slug']) && isset($data['name'])) {
                $data['slug'] = Str::slug($data['name']) . '-' . Str::random(4);
            }

            // Process multiple logos
            if ($request->has('company_logos')) {
                $existingLogos = $organization->company_logos ?: [];
                $newLogos = [];
                $requestedLogos = $request->input('company_logos');

                foreach ($requestedLogos as $index => $logoData) {
                    $type = $logoData['type'];
                    $path = $logoData['path'] ?? null;

                    if ($request->hasFile("company_logos.{$index}.file")) {
                        // Delete old file if path exists
                        if ($path) {
                            Storage::disk('s3')->delete($path);
                        }
                        $file = $request->file("company_logos.{$index}.file");
                        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
                        $path = "organizations/{$organization->uuid}/logos/{$filename}";
                        Storage::disk('s3')->put($path, file_get_contents($file), [
                            'visibility' => 'public',
                            'ContentType' => $file->getMimeType() ?: 'image/png'
                        ]);
                    }

                    $newLogos[] = [
                        'type' => $type,
                        'path' => $path
                    ];
                }

                // Cleanup deleted logos from S3
                $newPaths = collect($newLogos)->pluck('path')->filter()->toArray();
                foreach ($existingLogos as $oldLogo) {
                    if (isset($oldLogo['path']) && !in_array($oldLogo['path'], $newPaths)) {
                        Storage::disk('s3')->delete($oldLogo['path']);
                    }
                }

                $data['company_logos'] = $newLogos;
            }

            $organization = DB::transaction(function () use ($organization, $data) {
                $organization->update($data);

                if (!empty($data['owner_user_id'])) {
                    User::where('id', $data['owner_user_id'])
                        ->update(['organization_id' => $organization->id]);
                }

                // Sync custom domains
                if (isset($data['domains'])) {
                    // Simple sync: delete all and recreate
                    OrganizationDomain::where('organization_id', $organization->id)->delete();
                    
                    foreach ($data['domains'] as $domainData) {
                        // Clean domain before saving
                        $cleanDomain = preg_replace('/^https?:\/\//i', '', $domainData['domain']);
                        $cleanDomain = explode(':', $cleanDomain)[0];
                        $cleanDomain = rtrim($cleanDomain, '/');

                        OrganizationDomain::create([
                            'organization_id' => $organization->id,
                            'domain' => $cleanDomain,
                            'portal_type' => $domainData['portal_type'],
                        ]);
                    }
                }

                return $organization;
            });

            $organization->load(['settings', 'domains']);

            return response()->json([
                'status' => true,
                'message' => 'Organization updated successfully',
                'data' => $organization
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update organization',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }


    // Delete organization
    public function destroy($uuid): JsonResponse
    {
        $orgId = app()->bound('current_organization_id') ? app('current_organization_id') : null;
        $query = Organization::where('uuid', $uuid);

        if ($orgId !== null) {
            $query->where('id', $orgId);
        }

        $org = $query->first();

        if (!$org) {
            return response()->json([
                'status' => false,
                'message' => 'Organization not found'
            ], 404);
        }

        $org->delete();

        return response()->json([
            'status' => true,
            'message' => 'Organization deleted successfully'
        ]);
    }

    // List active organizations for public signup
    public function publicIndex(): JsonResponse
    {
        $organizations = Organization::where('is_active', true)
            ->select('id', 'uuid', 'name', 'slug')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $organizations
        ]);
    }
}
