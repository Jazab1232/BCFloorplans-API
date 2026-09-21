<?php


namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class PackageController extends Controller
{
    /**
     * List all packages with services
     */
    public function index(Request $request, $slug = null): JsonResponse
    {
        try {
            $user = auth()->user();
            $slug = $slug ?: $request->query('slug');
            $orgId = null;

            if ($slug) {
                $org = \App\Models\Organization::where('slug', $slug)->first();
                if (!$org) {
                    return response()->json([
                        'status' => true,
                        'data' => []
                    ]);
                }
                $orgId = $org->id;
                app()->instance('current_organization_id', $orgId);
            } else {
                $orgId = app()->bound('current_organization_id') ? app('current_organization_id') : null;
            }

            // Enforce organization resolution for unauthenticated guest requests
            if (!$user && !$orgId) {
                return response()->json([
                    'status' => true,
                    'data' => []
                ]);
            }

            $isAdminManagement = ($user instanceof \App\Models\User) || $request->query('all') == 'true' || $request->query('manage') == 'true';

            $query = Package::with('services');

            if ($orgId) {
                $query->where('organization_id', $orgId);
            }

            $packages = $query->get();

            if ($orgId) {
                $overrides = \App\Models\OrganizationService::where('organization_id', $orgId)->get()->keyBy('service_id');

                $packages = $packages->map(function ($package) use ($overrides) {
                    $filteredServices = $package->services->map(function ($service) use ($overrides) {
                        if (isset($overrides[$service->id])) {
                            $override = $overrides[$service->id];
                            if (isset($override->is_enabled)) {
                                $service->status = $override->is_enabled;
                            }
                            if (isset($override->price_override)) {
                                foreach ($service->productOptions as $option) {
                                    $option->amount = $override->price_override;
                                }
                            }
                        }
                        return $service;
                    })->filter(function ($service) {
                        return $service->status;
                    })->values();

                    $package->setRelation('services', $filteredServices);
                    return $package;
                });

                if (!$isAdminManagement) {
                    $packages = $packages->filter(function ($package) {
                        return $package->status && $package->services->count() > 0;
                    })->values();
                } else {
                    $packages = $packages->values();
                }
            } elseif (!$isAdminManagement) {
                $packages = $packages->filter(function ($package) {
                    return $package->status && $package->services->count() > 0;
                })->values();
            }

            return response()->json([
                'status' => true,
                'data' => $packages
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show single package
     */
    public function show($uuid)
    {
        $package = Package::with('services')->where('uuid', $uuid)->first();

        if (!$package) {
            return response()->json([
                'status' => false,
                'message' => 'Package not found'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $package
        ]);
    }

    /**
     * Store new package
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'discount' => 'required|integer|min:0|max:100',
            'status' => 'required|boolean',
            'service_ids' => 'required|array',
            'service_ids.*' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();
        $data['uuid'] = Str::uuid();

        // Assign organization_id if present
        $orgId = app()->bound('current_organization_id') ? app('current_organization_id') : null;
        if (!$orgId && auth()->user() && isset(auth()->user()->organization_id)) {
            $orgId = auth()->user()->organization_id;
        }
        if ($orgId && empty($data['organization_id'])) {
            $data['organization_id'] = $orgId;
        }

        $package = Package::create($data);

        // Accept both ID and UUID for service IDs
        $serviceIds = Service::whereIn('uuid', $data['service_ids'])
        ->pluck('id')
        ->toArray();

        $package->services()->sync($serviceIds);

        return response()->json([
            'status' => true,
            'message' => 'Package created successfully',
            'data' => $package->load('services')
        ], 201);
    }


    /**
     * Update package
     */
    public function update(Request $request, $uuid)
    {
        $package = Package::where('uuid', $uuid)->first();

        if (!$package) {
            return response()->json([
                'status' => false,
                'message' => 'Package not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'discount' => 'sometimes|integer|min:0|max:100',
            'status' => 'sometimes|boolean',
            'service_ids' => 'sometimes|array',
            'service_ids.*' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();
        $package->update($data);

        if (isset($data['service_ids'])) {
            $serviceIds = Service::whereIn('uuid', $data['service_ids'])
                ->pluck('id')
                ->toArray();

            $package->services()->sync($serviceIds);
        }

        return response()->json([
            'status' => true,
            'message' => 'Package updated successfully',
            'data' => $package->load('services')
        ]);
    }


    /**
     * Delete package
     */
    public function destroy($uuid)
    {
        $package = Package::where('uuid', $uuid)->first();

        if (!$package) {
            return response()->json([
                'status' => false,
                'message' => 'Package not found'
            ], 404);
        }

        $package->delete();

        return response()->json([
            'status' => true,
            'message' => 'Package deleted successfully'
        ]);
    }

    /*
        * update status
        */
    public function updateStatus(Request $request, $uuid)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $package = Package::where('uuid', $uuid)->first();

        if (!$package) {
            return response()->json([
                'status' => false,
                'message' => 'Package not found'
            ], 404);
        }

        $package->status = $request->status;
        $package->save();

        return response()->json([
            'status' => true,
            'message' => 'Status updated successfully',
            'data' => $package
        ]);
    }

}
