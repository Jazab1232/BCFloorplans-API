<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\ServiceAddOn;
use App\Models\ProductOption;
use App\Models\OrganizationService;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Intervention\Image\Laravel\Facades\Image;

class ServiceController extends Controller
{
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

            if ($user instanceof \App\Models\Vendor) {
                $services = Service::whereHas('vendorServices', function ($query) use ($user) {
                    $query->where('vendor_id', $user->id);
                })->with(['category', 'productOptions', 'serviceAddOns'])->orderBy('sort_order')->orderBy('name')->get();
                if ($orgId) {
                    $overrides = \App\Models\OrganizationService::where('organization_id', $orgId)->get()->keyBy('service_id');
                    $services = $services->map(fn($s) => $this->applyOrganizationOverrides($s, $orgId, $overrides[$s->id] ?? null));
                }
            } else {
                $services = Service::with(['category', 'productOptions', 'serviceAddOns'])->orderBy('sort_order')->orderBy('name')->get();

                // Apply Organization Overrides if in an org context
                if ($orgId) {
                    $overrides = \App\Models\OrganizationService::where('organization_id', $orgId)->get()->keyBy('service_id');
                    $isAdminManagement = ($user instanceof \App\Models\User) || $request->boolean('all') || $request->boolean('manage');
                    
                    $mappedServices = $services->map(fn($service) => $this->applyOrganizationOverrides($service, $orgId, $overrides[$service->id] ?? null));

                    // For admin management view, return ALL services with their org status intact.
                    // For public order forms, filter out disabled services.
                    if (!$isAdminManagement) {
                        $services = $mappedServices->filter(function($service) {
                            return $service->status;
                        })->values();
                    } else {
                        $services = $mappedServices->values();
                    }
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Services retrieved successfully',
                'data' => $services
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving services: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve services',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'type' => 'nullable|string|max:50',
            'category_id' => 'required|exists:service_categories,id',
            'thumbnail' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,webp,avif,bmp|max:20480',
            'description' => 'nullable|string',
            'is_travel_required' => 'nullable|boolean',
            'gst_enabled' => 'nullable|boolean',
            'pst_enabled' => 'nullable|boolean',
            'vendor_pay_type' => 'nullable|string|in:flat,per_sq_ft,per_unit,hourly',
            'vendor_price' => 'nullable|numeric|min:0',
            'vendor_sq_ft_rate' => 'nullable|numeric|min:0',
            'vendor_min_price' => 'nullable|numeric|min:0',
            'vendor_unit_rate' => 'nullable|numeric|min:0',
            'vendor_hourly_rate' => 'nullable|numeric|min:0',
            'base_duration_mins' => 'nullable|integer|min:0',
            'base_sq_ft' => 'nullable|integer|min:0',
            'increment_duration_mins' => 'nullable|integer|min:0',
            'increment_sq_ft' => 'nullable|integer|min:0',
            'background_color' => 'nullable|string',
            'border_color' => 'nullable|string',
            'product_options' => 'sometimes|array',
            'product_options.*.title' => 'required_with:product_options|string|max:255',
            'product_options.*.quantity' => 'nullable|integer',
            'product_options.*.sq_ft_range' => 'nullable|string',
            'product_options.*.sq_ft_rate' => 'nullable|numeric',
            'product_options.*.service_duration' => 'nullable|string',
            'product_options.*.amount' => 'required_with:product_options|numeric|min:0',
            'product_options.*.min_price' => 'nullable|numeric',
            'product_options.*.sort_order' => 'nullable|integer',
            'product_options.*.vendor_pay_type' => 'nullable|string|in:flat,per_sq_ft,per_unit,hourly',
            'product_options.*.vendor_price' => 'nullable|numeric|min:0',
            'product_options.*.vendor_sq_ft_rate' => 'nullable|numeric|min:0',
            'product_options.*.vendor_min_price' => 'nullable|numeric|min:0',
            'product_options.*.vendor_unit_rate' => 'nullable|numeric|min:0',
            'product_options.*.vendor_hourly_rate' => 'nullable|numeric|min:0',
            'product_options.*.base_duration_mins' => 'nullable|integer|min:0',
            'product_options.*.base_sq_ft' => 'nullable|integer|min:0',
            'product_options.*.increment_duration_mins' => 'nullable|integer|min:0',
            'product_options.*.increment_sq_ft' => 'nullable|integer|min:0',
            'add_ons' => 'sometimes|array',
            'add_ons.*.title' => 'required_with:add_ons|string|max:255',
            'add_ons.*.amount' => 'required_with:add_ons|numeric|min:0',
        ], [
            'thumbnail.mimes' => 'Thumbnail must be a valid image format (JPEG, PNG, JPG, GIF, SVG, WebP, AVIF, BMP).',
            'thumbnail.max' => 'Thumbnail image may not be larger than 20MB.',
            'product_options.array' => 'Product options must be an array.',
            'product_options.*.title.required_with' => 'Each product option must have a title.',
            'product_options.*.title.string' => 'The title of each product option must be a string.',
            'product_options.*.title.max' => 'The title of each product option may not exceed 255 characters.',
            'product_options.*.quantity.integer' => 'The quantity must be a valid integer.',
            'product_options.*.sq_ft_range.string' => 'The sq. ft. range must be a string.',
            'product_options.*.sq_ft_rate.numeric' => 'The sq. ft. rate must be a number.',
            'product_options.*.service_duration.string' => 'The service duration must be a string.',
            'product_options.*.amount.required_with' => 'Each product option must have an amount.',
            'product_options.*.amount.numeric' => 'The amount must be a number.',
            'product_options.*.amount.min' => 'The amount must be at least 0.',
            'add_ons.array' => 'Add-ons must be an array.',
            'add_ons.*.title.required_with' => 'Each add-on must have a title.',
            'add_ons.*.title.string' => 'The title of each add-on must be a string.',
            'add_ons.*.title.max' => 'The title of each add-on may not exceed 255 characters.',
            'add_ons.*.amount.required_with' => 'Each add-on must have an amount.',
            'add_ons.*.amount.numeric' => 'The add-on amount must be a number.',
            'add_ons.*.amount.min' => 'The add-on amount must be at least 0.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $data = $validator->validated();

            // Auto-assign organization_id if in organization context
            $orgId = app()->bound('current_organization_id') ? app('current_organization_id') : null;
            if (!$orgId && auth()->user() && isset(auth()->user()->organization_id)) {
                $orgId = auth()->user()->organization_id;
            }
            if ($orgId && !isset($data['organization_id'])) {
                $data['organization_id'] = $orgId;
            }

            // Generate UUID once
            $uuid = (string) Str::uuid();
            $data['uuid'] = $uuid;
            Log::info("New Service {$uuid} for Org " . ($data['organization_id'] ?? 'Global'));
            if (isset($data['is_travel_required']) === false) {
                $data['is_travel_required'] = true; // default value
            }

            $data['background_color'] = !empty($data['background_color']) && strlen($data['background_color']) >= 4 ? $data['background_color'] : '#ffffff';
            $data['border_color'] = !empty($data['border_color']) && strlen($data['border_color']) >= 4 ? $data['border_color'] : '#000000';

            if ($request->hasFile('thumbnail')) {
                $file = $request->file('thumbnail');
                try {
                    $data['thumbnail'] = $this->uploadAndOptimizeThumbnail($file, $data['uuid']);
                } catch (\Throwable $e) {
                    Log::error("Failed to upload service thumbnail: " . $e->getMessage());
                    return response()->json([
                        'success' => false,
                        'message' => 'Failed to process thumbnail image: ' . $e->getMessage()
                    ], 422);
                }
            }

            if (!isset($data['status'])) {
                $data['status'] = true;
            }
            if (!isset($data['gst_enabled'])) {
                $data['gst_enabled'] = true;
            }
            if (!isset($data['pst_enabled'])) {
                $data['pst_enabled'] = false;
            }
            if (empty($data['type'])) {
                $data['type'] = 'standard';
            }

            $service = Service::create($data);

            // Auto-link service to current organization context if present
            $orgId = app()->bound('current_organization_id') ? app('current_organization_id') : null;
            if (!$orgId && auth()->user() && isset(auth()->user()->organization_id)) {
                $orgId = auth()->user()->organization_id;
            }
            if ($orgId) {
                \App\Models\OrganizationService::updateOrCreate([
                    'organization_id' => $orgId,
                    'service_id'      => $service->id,
                ], [
                    'is_enabled'              => true,
                    'gst_enabled'             => $service->gst_enabled,
                    'pst_enabled'             => $service->pst_enabled,
                    'base_duration_mins'      => $service->base_duration_mins,
                    'base_sq_ft'              => $service->base_sq_ft,
                    'increment_duration_mins' => $service->increment_duration_mins,
                    'increment_sq_ft'         => $service->increment_sq_ft,
                ]);
            }

            // Create product options
            if (isset($data['product_options']) && is_array($data['product_options'])) {
                $productOptions = [];
                foreach ($data['product_options'] as $option) {
                    $option['uuid'] = Str::uuid()->toString();
                    $option['service_id'] = $service->id;
                    $productOptions[] = new ProductOption($option);
                }
                $service->productOptions()->saveMany($productOptions);
            }

            if (isset($data['add_ons']) && is_array($data['add_ons'])) {
                $addOns = [];
                foreach ($data['add_ons'] as $addOn) {
                    $addOn['uuid'] = Str::uuid()->toString();
                    $addOn['service_id'] = $service->id;
                    $addOns[] = new ServiceAddOn($addOn);
                }
                $service->serviceAddOns()->saveMany($addOns);
            }

            return response()->json([
                'status' => true,
                'message' => 'Service created successfully',
                'data' => $service->load(['category', 'productOptions', 'serviceAddOns'])
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating service: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to create service',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function show($uuid): JsonResponse
    {
        try {
            $service = Service::where('uuid', $uuid)->with(['category', 'productOptions', 'serviceAddOns', 'vendorServices.vendor.homebaseAddress'])->firstOrFail();

            $orgId = app()->bound('current_organization_id') ? app('current_organization_id') : null;
            if (!$orgId && auth()->user() && isset(auth()->user()->organization_id)) {
                $orgId = auth()->user()->organization_id;
            }

            if ($orgId) {
                $service = $this->applyOrganizationOverrides($service, $orgId);
            }

            return response()->json([
                'status' => true,
                'message' => 'Service retrieved successfully',
                'data' => $service
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Service not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error retrieving service: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve service',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $uuid): JsonResponse
    {
        try {
            $service = Service::where('uuid', $uuid)->firstOrFail();
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Service not found',
                'error' => $e->getMessage()
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'type' => 'sometimes|nullable|string|max:50',
            'category_id' => 'sometimes|exists:service_categories,id',
            'thumbnail' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,webp,avif,bmp|max:20480',
            'description' => 'nullable|string',
            'background_color' => 'nullable|string',
            'border_color' => 'nullable|string',
            'is_travel_required' => 'nullable|boolean',
            'gst_enabled' => 'nullable|boolean',
            'pst_enabled' => 'nullable|boolean',
            'vendor_pay_type' => 'nullable|string|in:flat,per_sq_ft,per_unit,hourly',
            'vendor_price' => 'nullable|numeric|min:0',
            'vendor_sq_ft_rate' => 'nullable|numeric|min:0',
            'vendor_min_price' => 'nullable|numeric|min:0',
            'vendor_unit_rate' => 'nullable|numeric|min:0',
            'vendor_hourly_rate' => 'nullable|numeric|min:0',
            'base_duration_mins' => 'nullable|integer|min:0',
            'base_sq_ft' => 'nullable|integer|min:0',
            'increment_duration_mins' => 'nullable|integer|min:0',
            'increment_sq_ft' => 'nullable|integer|min:0',
            'product_options' => 'sometimes|array',
            'product_options.*.uuid' => 'sometimes|string|exists:product_options,uuid',
            'product_options.*.title' => 'required_with:product_options|string|max:255',
            'product_options.*.quantity' => 'nullable|integer',
            'product_options.*.sq_ft_range' => 'nullable|string',
            'product_options.*.sq_ft_rate' => 'nullable|numeric',
            'product_options.*.service_duration' => 'nullable|string',
            'product_options.*.amount' => 'required_with:product_options|numeric|min:0',
            'product_options.*.min_price' => 'nullable|numeric',
            'product_options.*.sort_order' => 'nullable|integer',
            'product_options.*.vendor_pay_type' => 'nullable|string|in:flat,per_sq_ft,per_unit,hourly',
            'product_options.*.vendor_price' => 'nullable|numeric|min:0',
            'product_options.*.vendor_sq_ft_rate' => 'nullable|numeric|min:0',
            'product_options.*.vendor_min_price' => 'nullable|numeric|min:0',
            'product_options.*.vendor_unit_rate' => 'nullable|numeric|min:0',
            'product_options.*.vendor_hourly_rate' => 'nullable|numeric|min:0',
            'product_options.*.base_duration_mins' => 'nullable|integer|min:0',
            'product_options.*.base_sq_ft' => 'nullable|integer|min:0',
            'product_options.*.increment_duration_mins' => 'nullable|integer|min:0',
            'product_options.*.increment_sq_ft' => 'nullable|integer|min:0',
            'add_ons' => 'sometimes|array',
            'add_ons.*.uuid' => 'sometimes|string|exists:service_add_ons,uuid',
            'add_ons.*.title' => 'required_with:add_ons|string|max:255',
            'add_ons.*.amount' => 'required_with:add_ons|numeric|min:0',
        ], [
            'thumbnail.mimes' => 'Thumbnail must be a valid image format (JPEG, PNG, JPG, GIF, SVG, WebP, AVIF, BMP).',
            'thumbnail.max' => 'Thumbnail image may not be larger than 20MB.',
            'product_options.array' => 'Product options must be an array.',
            'product_options.*.uuid.exists' => 'The selected product option does not exist.',
            'product_options.*.title.required_with' => 'Each product option must have a title.',
            'product_options.*.title.string' => 'The title of each product option must be a string.',
            'product_options.*.title.max' => 'The title of each product option may not exceed 255 characters.',
            'product_options.*.quantity.integer' => 'The quantity must be a valid integer.',
            'product_options.*.sq_ft_range.string' => 'The sq. ft. range must be a string.',
            'product_options.*.sq_ft_rate.numeric' => 'The sq. ft. rate must be a number.',
            'product_options.*.service_duration.string' => 'The service duration must be a string.',
            'product_options.*.amount.required_with' => 'Each product option must have an amount.',
            'product_options.*.amount.numeric' => 'The amount must be a number.',
            'product_options.*.amount.min' => 'The amount must be at least 0.',
            'add_ons.array' => 'Add-ons must be an array.',
            'add_ons.*.uuid.exists' => 'The selected add-on does not exist.',
            'add_ons.*.title.required_with' => 'Each add-on must have a title.',
            'add_ons.*.title.string' => 'The title of each add-on must be a string.',
            'add_ons.*.title.max' => 'The title of each add-on may not exceed 255 characters.',
            'add_ons.*.amount.required_with' => 'Each add-on must have an amount.',
            'add_ons.*.amount.numeric' => 'The add-on amount must be a number.',
            'add_ons.*.amount.min' => 'The add-on amount must be at least 0.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $data = $validator->validated();

            if ($request->hasFile('thumbnail')) {
                // Delete old file from S3 if exists
                if (isset($service) && $service->thumbnail && !str_starts_with($service->thumbnail, 'http')) {
                    $oldS3Key = str_contains($service->thumbnail, '/') ? $service->thumbnail : "services/{$service->uuid}/{$service->thumbnail}";
                    try {
                        Storage::disk('s3')->delete($oldS3Key);
                        Log::info("Deleted old thumbnail for service {$service->uuid}: {$service->thumbnail}");
                    } catch (\Throwable $e) {
                        Log::warning("Could not delete old thumbnail: " . $e->getMessage());
                    }
                }
                $file = $request->file('thumbnail');
                try {
                    $data['thumbnail'] = $this->uploadAndOptimizeThumbnail($file, $service->uuid);
                } catch (\Throwable $e) {
                    Log::error("Failed to upload updated service thumbnail: " . $e->getMessage());
                    return response()->json([
                        'success' => false,
                        'message' => 'Failed to process thumbnail image: ' . $e->getMessage()
                    ], 422);
                }
            }

            $orgId = app()->bound('current_organization_id') ? app('current_organization_id') : null;
            if (!$orgId && auth()->user() && isset(auth()->user()->organization_id)) {
                $orgId = auth()->user()->organization_id;
            }

            if ($orgId) {
                // Update master service properties (e.g. thumbnail, name, category, colors) if provided
                $masterFieldsToUpdate = array_intersect_key($data, array_flip([
                    'thumbnail', 'name', 'category_id', 'description', 'type', 'background_color', 'border_color', 'is_travel_required'
                ]));
                if (!empty($masterFieldsToUpdate)) {
                    $service->update($masterFieldsToUpdate);
                }

                // ORGANIZATION CONTEXT: Update organization-specific overrides ONLY (Multi-tenant isolated)
                $orgSyncData = [];

                if (array_key_exists('status', $data)) {
                    $orgSyncData['is_enabled'] = (bool) $data['status'];
                }
                if (array_key_exists('gst_enabled', $data)) {
                    $orgSyncData['gst_enabled'] = $data['gst_enabled'];
                }
                if (array_key_exists('pst_enabled', $data)) {
                    $orgSyncData['pst_enabled'] = $data['pst_enabled'];
                }
                if (array_key_exists('vendor_pay_type', $data)) {
                    $orgSyncData['vendor_pay_type'] = $data['vendor_pay_type'];
                }
                if (array_key_exists('vendor_price', $data)) {
                    $orgSyncData['vendor_price'] = $data['vendor_price'];
                }
                if (array_key_exists('vendor_sq_ft_rate', $data)) {
                    $orgSyncData['vendor_sq_ft_rate'] = $data['vendor_sq_ft_rate'];
                }
                if (array_key_exists('vendor_min_price', $data)) {
                    $orgSyncData['vendor_min_price'] = $data['vendor_min_price'];
                }
                if (array_key_exists('vendor_unit_rate', $data)) {
                    $orgSyncData['vendor_unit_rate'] = $data['vendor_unit_rate'];
                }
                if (array_key_exists('vendor_hourly_rate', $data)) {
                    $orgSyncData['vendor_hourly_rate'] = $data['vendor_hourly_rate'];
                }
                if (array_key_exists('base_duration_mins', $data)) {
                    $orgSyncData['base_duration_mins'] = $data['base_duration_mins'];
                }
                if (array_key_exists('base_sq_ft', $data)) {
                    $orgSyncData['base_sq_ft'] = $data['base_sq_ft'];
                }
                if (array_key_exists('increment_duration_mins', $data)) {
                    $orgSyncData['increment_duration_mins'] = $data['increment_duration_mins'];
                }
                if (array_key_exists('increment_sq_ft', $data)) {
                    $orgSyncData['increment_sq_ft'] = $data['increment_sq_ft'];
                }

                if ($request->has('product_options') && is_array($data['product_options'])) {
                    $optionsOverride = [];
                    foreach ($data['product_options'] as $idx => $optData) {
                        $optionsOverride[] = [
                            'uuid' => $optData['uuid'] ?? (string) Str::uuid(),
                            'title' => $optData['title'] ?? null,
                            'amount' => $optData['amount'] ?? null,
                            'sort_order' => $optData['sort_order'] ?? ($idx + 1),
                            'quantity' => $optData['quantity'] ?? null,
                            'sq_ft_range' => $optData['sq_ft_range'] ?? null,
                            'sq_ft_rate' => $optData['sq_ft_rate'] ?? null,
                            'service_duration' => $optData['service_duration'] ?? null,
                            'min_price' => $optData['min_price'] ?? null,
                            'vendor_pay_type' => $optData['vendor_pay_type'] ?? null,
                            'vendor_price' => $optData['vendor_price'] ?? null,
                            'vendor_sq_ft_rate' => $optData['vendor_sq_ft_rate'] ?? null,
                            'vendor_min_price' => $optData['vendor_min_price'] ?? null,
                            'vendor_unit_rate' => $optData['vendor_unit_rate'] ?? null,
                            'vendor_hourly_rate' => $optData['vendor_hourly_rate'] ?? null,
                            'base_duration_mins' => $optData['base_duration_mins'] ?? null,
                            'base_sq_ft' => $optData['base_sq_ft'] ?? null,
                            'increment_duration_mins' => $optData['increment_duration_mins'] ?? null,
                            'increment_sq_ft' => $optData['increment_sq_ft'] ?? null,
                        ];
                    }
                    $orgSyncData['options_override'] = $optionsOverride;
                }

                if ($request->has('add_ons') && is_array($data['add_ons'])) {
                    $addOnsOverride = [];
                    foreach ($data['add_ons'] as $aoData) {
                        $addOnsOverride[] = [
                            'uuid' => $aoData['uuid'] ?? (string) Str::uuid(),
                            'title' => $aoData['title'] ?? null,
                            'amount' => $aoData['amount'] ?? null,
                            'is_enabled' => $aoData['is_enabled'] ?? true,
                        ];
                    }
                    $orgSyncData['add_ons_override'] = $addOnsOverride;
                }

                OrganizationService::updateOrCreate(
                    ['organization_id' => $orgId, 'service_id' => $service->id],
                    $orgSyncData
                );

            } else {
                // SUPER ADMIN / GLOBAL PORTAL: Update master service & defaults
                $service->update($data);

                if ($request->has('product_options')) {
                    $currentOptionIds = $service->productOptions->pluck('id')->toArray();
                    $updatedOptionIds = [];

                    foreach ($data['product_options'] as $optionData) {
                        if (isset($optionData['uuid'])) {
                            $option = ProductOption::where('uuid', $optionData['uuid'])->first();
                            if ($option && $option->service_id === $service->id) {
                                $option->update($optionData);
                                $updatedOptionIds[] = $option->id;
                            }
                        } else {
                            $optionData['uuid'] = Str::uuid()->toString();
                            $optionData['service_id'] = $service->id;
                            $option = $service->productOptions()->create($optionData);
                            $updatedOptionIds[] = $option->id;
                        }
                    }

                    $optionsToDelete = array_diff($currentOptionIds, $updatedOptionIds);
                    if (!empty($optionsToDelete)) {
                        ProductOption::whereIn('id', $optionsToDelete)->delete();
                    }
                } elseif ($request->product_options === []) {
                    $service->productOptions()->delete();
                }

                if ($request->has('add_ons')) {
                    $currentAddOnIds = $service->serviceAddOns->pluck('id')->toArray();
                    $updatedAddOnIds = [];

                    foreach ($data['add_ons'] as $addOnData) {
                        if (isset($addOnData['uuid'])) {
                            $addOn = ServiceAddOn::where('uuid', $addOnData['uuid'])->first();
                            if ($addOn && $addOn->service_id === $service->id) {
                                $addOn->update($addOnData);
                                $updatedAddOnIds[] = $addOn->id;
                            }
                        } else {
                            $addOnData['uuid'] = Str::uuid()->toString();
                            $addOnData['service_id'] = $service->id;
                            $addOn = $service->serviceAddOns()->create($addOnData);
                            $updatedAddOnIds[] = $addOn->id;
                        }
                    }

                    $addOnsToDelete = array_diff($currentAddOnIds, $updatedAddOnIds);
                    if (!empty($addOnsToDelete)) {
                        ServiceAddOn::whereIn('id', $addOnsToDelete)->delete();
                    }
                } elseif ($request->add_ons === []) {
                    $service->serviceAddOns()->delete();
                }
            }

            $service = $service->fresh(['category', 'productOptions', 'serviceAddOns']);
            if ($orgId) {
                $service = $this->applyOrganizationOverrides($service, $orgId);
            }

            return response()->json([
                'status' => true,
                'message' => 'Service updated successfully',
                'data' => $service
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Service not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating service: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to update service',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function destroy($uuid): JsonResponse
    {
        try {
            $service = Service::where('uuid', $uuid)->firstOrFail();

            DB::transaction(function () use ($service) {
                $service->productOptions()->delete();
                $service->vendorServices()->delete();
                $service->discountServices()->delete();

                $service->delete();
            });

            if ($service->thumbnail) {
                $directory = "public/services/{$service->uuid}";
                if (Storage::exists($directory)) {
                    Storage::deleteDirectory($directory);
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Service deleted successfully'
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Service not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error deleting service: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete service',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function updateStatus(Request $request, $uuid): JsonResponse
    {
        try {
            $data = $request->validate([
                'status' => 'required|boolean',
            ]);

            $service = Service::where('uuid', $uuid)->firstOrFail();
            $service->status = $data['status'];
            $service->save();

            // Sync with organization override if in org context
            $orgId = app()->bound('current_organization_id') ? app('current_organization_id') : null;
            if (!$orgId && auth()->user() && isset(auth()->user()->organization_id)) {
                $orgId = auth()->user()->organization_id;
            }
            if ($orgId) {
                \App\Models\OrganizationService::updateOrCreate(
                    ['organization_id' => $orgId, 'service_id' => $service->id],
                    ['is_enabled' => $data['status']]
                );
            }

            return response()->json([
                'status' => true,
                'message' => 'Service status updated successfully',
                'data' => $service
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Service not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating service status: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Service status update failed',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function bulkUpdateSort(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'services' => 'required|array',
            'services.*.uuid' => 'required|string|exists:services,uuid',
            'services.*.sort_order' => 'required|integer',
        ], [
            'services.required' => 'The services array is required.',
            'services.array' => 'The services must be an array.',
            'services.*.uuid.required' => 'Each service must provide a uuid.',
            'services.*.uuid.exists' => 'One or more of the specified services do not exist.',
            'services.*.sort_order.required' => 'Each service must provide a sort_order.',
            'services.*.sort_order.integer' => 'The ort order must be a valid integer.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::transaction(function () use ($request) {
                foreach ($request->services as $serviceData) {
                    Service::where('uuid', $serviceData['uuid'])->update([
                        'sort_order' => $serviceData['sort_order']
                    ]);
                }
            });

            return response()->json([
                'status' => true,
                'message' => 'Service sort numbers updated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating service sort numbers: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to update service sort numbers',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function bulkUpdateProductOptionSort(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'options' => 'required|array',
            'options.*.uuid' => 'required|string|exists:product_options,uuid',
            'options.*.sort_order' => 'required|integer',
        ], [
            'options.required' => 'The options array is required.',
            'options.array' => 'The options must be an array.',
            'options.*.uuid.required' => 'Each option must provide a uuid.',
            'options.*.uuid.exists' => 'One or more of the specified product options do not exist.',
            'options.*.sort_order.required' => 'Each option must provide a sort_order.',
            'options.*.sort_order.integer' => 'The sort_order must be a valid integer.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $orgId = app()->bound('current_organization_id') ? app('current_organization_id') : null;
            if (!$orgId && auth()->user() && isset(auth()->user()->organization_id)) {
                $orgId = auth()->user()->organization_id;
            }

            if ($orgId) {
                // Multi-Tenant Isolation: Save sort_order to OrganizationService.options_override
                $optionUuids = array_column($request->options, 'uuid');
                $productOptions = ProductOption::whereIn('uuid', $optionUuids)->get()->keyBy('uuid');
                
                $optionsByService = [];
                foreach ($request->options as $optData) {
                    if (isset($productOptions[$optData['uuid']])) {
                        $serviceId = $productOptions[$optData['uuid']]->service_id;
                        $optionsByService[$serviceId][] = [
                            'uuid' => $optData['uuid'],
                            'sort_order' => (int) $optData['sort_order'],
                        ];
                    }
                }

                foreach ($optionsByService as $serviceId => $opts) {
                    $orgService = \App\Models\OrganizationService::firstOrCreate(
                        ['organization_id' => $orgId, 'service_id' => $serviceId],
                        ['is_enabled' => true]
                    );

                    $existingOverrides = $orgService->options_override ?? [];
                    $overrideMap = collect($existingOverrides)->keyBy('uuid')->toArray();

                    foreach ($opts as $opt) {
                        $uuid = $opt['uuid'];
                        if (!isset($overrideMap[$uuid])) {
                            $overrideMap[$uuid] = ['uuid' => $uuid];
                        }
                        $overrideMap[$uuid]['sort_order'] = $opt['sort_order'];
                    }

                    $orgService->options_override = array_values($overrideMap);
                    $orgService->save();
                }
            } else {
                // Super Admin / Global context: Update master product_options table defaults
                DB::transaction(function () use ($request) {
                    foreach ($request->options as $optionData) {
                        ProductOption::where('uuid', $optionData['uuid'])->update([
                            'sort_order' => $optionData['sort_order']
                        ]);
                    }
                });
            }

            return response()->json([
                'status' => true,
                'message' => 'Product option sort numbers updated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating product option sort numbers: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to update product option sort numbers',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload and optimize service thumbnail.
     * SVGs are preserved as vector files.
     * Raster images are scaled down proportionally to max 1024px width and web-optimized.
     */
    private function uploadAndOptimizeThumbnail($file, string $serviceUuid): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $mimeType = $file->getMimeType();

        // 1. Vector SVGs: save raw
        if ($extension === 'svg' || str_contains($mimeType, 'svg')) {
            $filename = Str::uuid() . '.svg';
            $s3Path = "services/{$serviceUuid}/{$filename}";
            Storage::disk('s3')->put($s3Path, file_get_contents($file), [
                'visibility' => 'public',
                'ContentType' => 'image/svg+xml'
            ]);
            Log::info("Service {$serviceUuid} : Uploaded SVG thumbnail to {$s3Path}");
            return $filename;
        }

        // 2. Raster images: downscale proportionally to max 1024px width
        try {
            $image = Image::read($file->getRealPath());

            if ($image->width() > 1024) {
                $image->scaleDown(width: 1024);
            }

            // If PNG, keep PNG format to preserve alpha channel
            if ($extension === 'png' || str_contains($mimeType, 'png')) {
                $filename = Str::uuid() . '.png';
                $s3Path = "services/{$serviceUuid}/{$filename}";
                $encoded = (string) $image->toPng();
                $contentType = 'image/png';
            } else {
                // Default to WebP / JPEG (85% quality)
                $filename = Str::uuid() . '.webp';
                $s3Path = "services/{$serviceUuid}/{$filename}";
                $encoded = (string) $image->toWebp(85);
                $contentType = 'image/webp';
            }

            Storage::disk('s3')->put($s3Path, $encoded, [
                'visibility' => 'public',
                'ContentType' => $contentType
            ]);
            Log::info("Service {$serviceUuid} : Optimized and uploaded thumbnail to {$s3Path}");
            return $filename;
        } catch (\Throwable $e) {
            Log::warning("Service {$serviceUuid} : Image optimization failed ({$e->getMessage()}). Falling back to direct upload.");
            $filename = Str::uuid() . '.' . $extension;
            $s3Path = "services/{$serviceUuid}/{$filename}";
            Storage::disk('s3')->put($s3Path, file_get_contents($file), [
                'visibility' => 'public',
                'ContentType' => $mimeType ?: 'image/jpeg'
            ]);
            return $filename;
        }
    }

    /**
     * Apply organization-level overrides to a service instance and its relations.
     */
    public static function applyOrganizationOverrides(Service $service, $orgId, ?OrganizationService $override = null): Service
    {
        if (!$orgId) {
            return $service;
        }

        if (!$override) {
            $override = OrganizationService::where('organization_id', $orgId)
                ->where('service_id', $service->id)
                ->first();
        }

        if (!$override) {
            return $service;
        }

        if (isset($override->is_enabled)) {
            $service->status = (bool) $override->is_enabled;
        }
        if (isset($override->gst_enabled) && $override->gst_enabled !== null) {
            $service->gst_enabled = (bool) $override->gst_enabled;
        }
        if (isset($override->pst_enabled) && $override->pst_enabled !== null) {
            $service->pst_enabled = (bool) $override->pst_enabled;
        }
        if (isset($override->base_duration_mins) && $override->base_duration_mins !== null) {
            $service->base_duration_mins = (int) $override->base_duration_mins;
        }
        if (isset($override->base_sq_ft) && $override->base_sq_ft !== null) {
            $service->base_sq_ft = (int) $override->base_sq_ft;
        }
        if (isset($override->increment_duration_mins) && $override->increment_duration_mins !== null) {
            $service->increment_duration_mins = (int) $override->increment_duration_mins;
        }
        if (isset($override->increment_sq_ft) && $override->increment_sq_ft !== null) {
            $service->increment_sq_ft = (int) $override->increment_sq_ft;
        }
        if (!empty($override->vendor_pay_type)) {
            $service->vendor_pay_type = $override->vendor_pay_type;
        }
        if ($override->vendor_price !== null) {
            $service->vendor_price = $override->vendor_price;
        }
        if ($override->vendor_sq_ft_rate !== null) {
            $service->vendor_sq_ft_rate = $override->vendor_sq_ft_rate;
        }
        if ($override->vendor_min_price !== null) {
            $service->vendor_min_price = $override->vendor_min_price;
        }
        if ($override->vendor_unit_rate !== null) {
            $service->vendor_unit_rate = $override->vendor_unit_rate;
        }
        if ($override->vendor_hourly_rate !== null) {
            $service->vendor_hourly_rate = $override->vendor_hourly_rate;
        }

        if (isset($override->price_override)) {
            foreach ($service->productOptions as $option) {
                $option->amount = $override->price_override;
            }
        }

        if (!empty($override->options_override) && is_array($override->options_override)) {
            $masterOptionsByUuid = $service->productOptions->keyBy('uuid');
            $customizedOptions = collect();

            foreach ($override->options_override as $idx => $optOverride) {
                $optUuid = $optOverride['uuid'] ?? null;
                $existing = $optUuid && isset($masterOptionsByUuid[$optUuid]) ? $masterOptionsByUuid[$optUuid] : null;

                if ($existing) {
                    if (isset($optOverride['title'])) {
                        $existing->title = $optOverride['title'];
                    }
                    if (isset($optOverride['amount'])) {
                        $existing->amount = $optOverride['amount'];
                    }
                    if (isset($optOverride['sort_order'])) {
                        $existing->sort_order = (int) $optOverride['sort_order'];
                    }
                    if (isset($optOverride['quantity'])) {
                        $existing->quantity = $optOverride['quantity'];
                    }
                    if (isset($optOverride['sq_ft_range'])) {
                        $existing->sq_ft_range = $optOverride['sq_ft_range'];
                    }
                    if (isset($optOverride['sq_ft_rate'])) {
                        $existing->sq_ft_rate = $optOverride['sq_ft_rate'];
                    }
                    if (isset($optOverride['service_duration'])) {
                        $existing->service_duration = $optOverride['service_duration'];
                    }
                    if (isset($optOverride['min_price'])) {
                        $existing->min_price = $optOverride['min_price'];
                    }
                    if (isset($optOverride['vendor_pay_type'])) {
                        $existing->vendor_pay_type = $optOverride['vendor_pay_type'];
                    }
                    if (array_key_exists('vendor_price', $optOverride) && $optOverride['vendor_price'] !== null) {
                        $existing->vendor_price = $optOverride['vendor_price'];
                    }
                    if (array_key_exists('vendor_sq_ft_rate', $optOverride) && $optOverride['vendor_sq_ft_rate'] !== null) {
                        $existing->vendor_sq_ft_rate = $optOverride['vendor_sq_ft_rate'];
                    }
                    if (array_key_exists('vendor_min_price', $optOverride) && $optOverride['vendor_min_price'] !== null) {
                        $existing->vendor_min_price = $optOverride['vendor_min_price'];
                    }
                    if (array_key_exists('vendor_unit_rate', $optOverride) && $optOverride['vendor_unit_rate'] !== null) {
                        $existing->vendor_unit_rate = $optOverride['vendor_unit_rate'];
                    }
                    if (array_key_exists('vendor_hourly_rate', $optOverride) && $optOverride['vendor_hourly_rate'] !== null) {
                        $existing->vendor_hourly_rate = $optOverride['vendor_hourly_rate'];
                    }
                    if (isset($optOverride['base_duration_mins'])) {
                        $existing->base_duration_mins = $optOverride['base_duration_mins'];
                    }
                    if (isset($optOverride['base_sq_ft'])) {
                        $existing->base_sq_ft = $optOverride['base_sq_ft'];
                    }
                    if (isset($optOverride['increment_duration_mins'])) {
                        $existing->increment_duration_mins = $optOverride['increment_duration_mins'];
                    }
                    if (isset($optOverride['increment_sq_ft'])) {
                        $existing->increment_sq_ft = $optOverride['increment_sq_ft'];
                    }
                    $customizedOptions->push($existing);
                } else {
                    $newOpt = new ProductOption([
                        'uuid' => $optUuid ?: Str::uuid()->toString(),
                        'service_id' => $service->id,
                        'title' => $optOverride['title'] ?? '',
                        'amount' => $optOverride['amount'] ?? 0.00,
                        'sort_order' => (int) ($optOverride['sort_order'] ?? ($idx + 1)),
                        'quantity' => $optOverride['quantity'] ?? null,
                        'sq_ft_range' => $optOverride['sq_ft_range'] ?? null,
                        'sq_ft_rate' => $optOverride['sq_ft_rate'] ?? null,
                        'service_duration' => $optOverride['service_duration'] ?? null,
                        'min_price' => $optOverride['min_price'] ?? null,
                        'vendor_pay_type' => $optOverride['vendor_pay_type'] ?? 'flat',
                        'vendor_price' => $optOverride['vendor_price'] ?? 0.00,
                        'vendor_sq_ft_rate' => $optOverride['vendor_sq_ft_rate'] ?? null,
                        'vendor_min_price' => $optOverride['vendor_min_price'] ?? null,
                        'vendor_unit_rate' => $optOverride['vendor_unit_rate'] ?? null,
                        'vendor_hourly_rate' => $optOverride['vendor_hourly_rate'] ?? null,
                        'base_duration_mins' => $optOverride['base_duration_mins'] ?? null,
                        'base_sq_ft' => $optOverride['base_sq_ft'] ?? null,
                        'increment_duration_mins' => $optOverride['increment_duration_mins'] ?? null,
                        'increment_sq_ft' => $optOverride['increment_sq_ft'] ?? null,
                    ]);
                    $newOpt->id = $service->id * 10000 + ($idx + 1);
                    $customizedOptions->push($newOpt);
                }
            }

            $sortedOptions = $customizedOptions->sortBy('sort_order')->values();
            $service->setRelation('productOptions', $sortedOptions);
        }

        if (!empty($override->add_ons_override) && is_array($override->add_ons_override)) {
            $masterAddOnsByUuid = $service->serviceAddOns->keyBy('uuid');
            $customizedAddOns = collect();

            foreach ($override->add_ons_override as $idx => $aoOverride) {
                $aoUuid = $aoOverride['uuid'] ?? null;
                $existing = $aoUuid && isset($masterAddOnsByUuid[$aoUuid]) ? $masterAddOnsByUuid[$aoUuid] : null;

                if ($existing) {
                    if (isset($aoOverride['amount'])) {
                        $existing->amount = $aoOverride['amount'];
                    }
                    if (isset($aoOverride['title'])) {
                        $existing->title = $aoOverride['title'];
                    }
                    if (isset($aoOverride['is_enabled'])) {
                        $existing->is_enabled = (bool) $aoOverride['is_enabled'];
                    }
                    $customizedAddOns->push($existing);
                } else {
                    $newAddon = new ServiceAddOn([
                        'uuid' => $aoUuid ?: Str::uuid()->toString(),
                        'service_id' => $service->id,
                        'title' => $aoOverride['title'] ?? '',
                        'amount' => $aoOverride['amount'] ?? 0.00,
                    ]);
                    $newAddon->id = $service->id * 10000 + ($idx + 1);
                    if (isset($aoOverride['is_enabled'])) {
                        $newAddon->is_enabled = (bool) $aoOverride['is_enabled'];
                    }
                    $customizedAddOns->push($newAddon);
                }
            }

            $service->setRelation('serviceAddOns', $customizedAddOns);
        }

        return $service;
    }
}