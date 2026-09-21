<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Property;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Enums\OccupancyType;
use App\Enums\MediaAccessType;

class PropertyController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $properties = Property::select([
                'id', 'uuid', 'listing_price', 'bedrooms', 'bathrooms', 'square_footage', 
                'year_constructed', 'parking_spots', 'property_type', 'property_status', 
                'suite', 'address', 'city', 'province', 'postal_code', 'country', 
                'status', 'tour_activated', 'created_at', 'agent_id', 'organization_id'
            ])->with([
                'agent' => function($query) {
                    $query->select('id', 'uuid', 'first_name', 'last_name', 'company_name', 'email', 'status', 'primary_phone');
                },
                'orders' => function($query) {
                    $query->select('id', 'uuid', 'property_id', 'agent_id', 'amount', 'payment_status', 'order_status', 'created_at');
                },
                'orders.tours' => function($query) {
                    $query->select('id', 'uuid', 'order_id', 'is_publish');
                },
                'orders.tours.files' => function($query) {
                    $query->select('id', 'uuid', 'tour_id', 'file_path', 'is_featured', 'type', 'variants', 'is_processing', 'service_id', 'created_at');
                }
            ]);

            $user = auth()->user();
            if ($user instanceof \App\Models\Agent) {
                $agent = Agent::where('uuid', $user->uuid)->firstOrFail();
                $properties->where('agent_id', $agent->id);
            } else if ($user instanceof \App\Models\Vendor) {
                $vendorId = $user->id;
                $properties->whereHas('orders.slots', function ($query) use ($vendorId) {
                    $query->where('vendor_id', $vendorId);
                });
            }

            $properties = $properties->get();

            return response()->json([
                'status' => true,
                'message' => 'Properties retrieved successfully',
                'data' => $properties
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving properties: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve properties',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $nullableFields = [
                'listing_price', 'mls_number', 'bedrooms', 'bathrooms', 'square_footage', 
                'lot_size', 'year_constructed', 'parking_spots', 'property_type', 
                'property_status', 'heading', 'description', 'suite', 'postal_code'
            ];
            foreach ($nullableFields as $field) {
                if ($request->has($field) && $request->input($field) === '') {
                    $request->merge([$field => null]);
                }
            }

            $validator = Validator::make($request->all(), [
                'listing_price' => 'nullable|numeric|min:0',
                'mls_number' => 'nullable|string|max:50|unique:properties',
                'agent_id' => 'required|exists:agents,uuid',
                'bedrooms' => 'nullable|integer|min:0|max:20',
                'bathrooms' => 'nullable|numeric|min:0|max:20',
                'square_footage' => 'nullable|integer|min:0',
                'lot_size' => 'nullable|string|max:100',
                'year_constructed' => 'nullable|integer|min:1800|max:' . date('Y'),
                'parking_spots' => 'nullable|integer|min:0|max:50',
                'property_type' => ['nullable', new Enum(PropertyType::class)],
                'property_status' => ['nullable', new Enum(PropertyStatus::class)],
                'heading' => 'nullable|string|max:255',
                'description' => 'nullable|string',
                'suite' => 'nullable|string|max:100',
                'address' => 'required|string|max:255',
                'city' => 'required|string|max:100',
                'province' => 'required|string|max:50',
                'postal_code' => 'nullable|string|max:20',
                'country' => 'required|string|max:50',
                'tour_activated' => 'sometimes|boolean',
                'publish_date' => 'nullable|date|after_or_equal:today',
                'property_website' => 'nullable|url|max:255',
                'mls_property' => 'nullable|url|max:255',
                'occupancy' => ['sometimes', new Enum(OccupancyType::class)],
                'media_creator_access' => ['nullable', new Enum(MediaAccessType::class)],
                'instructions' => 'nullable|string|max:500',
                'animals_on_property' => 'sometimes|boolean',
                'co_agents' => 'nullable|array',
                'co_agents.*' => 'email|max:255',
                'send_statistics_email' => 'sometimes|boolean',
                'statistics_email_frequency' => 'nullable|string|max:50',
                'statistics_email_recipients' => 'nullable|array',
                'statistics_email_recipients.*' => 'email|max:255',
            ], [
                'mls_number.required' => 'MLS number is mandatory.',
                'mls_number.unique' => 'This MLS number is already in use. Please provide a unique MLS number.',
                'agent_id.required' => 'Agent is mandatory.',
                'agent_id.exists' => 'The selected agent does not exist.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $validator->validated();
            $data['tour_activated'] = $request->get('tour_activated', false);
            $data['animals_on_property'] = $request->get('animals_on_property', false);
            $agent = Agent::where('uuid', $data['agent_id'])->firstOrFail();
            $data['agent_id'] = $agent->id;
            $data['organization_id'] = $agent->organization_id;

            $property = Property::create($data);

            return response()->json([
                'status' => true,
                'message' => 'Property created successfully',
                'data' => $property
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating property: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to create property',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function show($uuid): JsonResponse
    {
        try {
            $property = Property::with(['agent', 'orders.services.service', 'orders.services.option','orders.tours.files'])->where('uuid', $uuid)->firstOrFail();
            return response()->json([
                'status' => true,
                'message' => 'Property retrieved successfully',
                'data' => $property
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Property not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error retrieving property: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve property',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $uuid): JsonResponse
    {
        try {
            $property = Property::where('uuid', $uuid)->firstOrFail();

            $user = auth()->user();
            if ($user instanceof \App\Models\Vendor) {
                $vendorId = $user->id;
                $hasSlot = $property->orders()->whereHas('slots', function ($query) use ($vendorId) {
                    $query->where('vendor_id', $vendorId);
                })->exists();

                if (!$hasSlot) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Unauthorized: You are not assigned to this property.'
                    ], 403);
                }
            }

            $nullableFields = [
                'listing_price', 'mls_number', 'bedrooms', 'bathrooms', 'square_footage', 
                'lot_size', 'year_constructed', 'parking_spots', 'property_type', 
                'property_status', 'heading', 'description', 'suite', 'postal_code'
            ];
            foreach ($nullableFields as $field) {
                if ($request->has($field) && $request->input($field) === '') {
                    $request->merge([$field => null]);
                }
            }

            $validator = Validator::make($request->all(), [
                'listing_price' => 'nullable|numeric|min:0',
                'mls_number' => [
                    'nullable',
                    'string',
                    'max:50',
                    Rule::unique('properties')->ignore($property->id)
                ],
                'agent_id' => 'sometimes|required|exists:agents,uuid',
                'bedrooms' => 'nullable|integer|min:0|max:20',
                'bathrooms' => 'nullable|numeric|min:0|max:20',
                'square_footage' => 'nullable|integer|min:0',
                'lot_size' => 'nullable|string|max:100',
                'year_constructed' => 'nullable|integer|min:1800|max:' . date('Y'),
                'parking_spots' => 'nullable|integer|min:0|max:50',
                'property_type' => ['nullable', new Enum(PropertyType::class)],
                'property_status' => ['nullable', new Enum(PropertyStatus::class)],
                'heading' => 'nullable|string|max:255',
                'description' => 'nullable|string',
                'suite' => 'nullable|string|max:100',
                'address' => 'sometimes|required|string|max:255',
                'city' => 'sometimes|required|string|max:100',
                'province' => 'sometimes|required|string|max:50',
                'postal_code' => 'nullable|string|max:20',
                'country' => 'sometimes|required|string|max:50',
                'tour_activated' => 'sometimes|boolean',
                'publish_date' => 'nullable|date|after_or_equal:today',
                'property_website' => 'nullable|url|max:255',
                'mls_property' => 'nullable|url|max:255',
                'occupancy' => ['sometimes', new Enum(OccupancyType::class)],
                'media_creator_access' => ['nullable', new Enum(MediaAccessType::class)],
                'instructions' => 'nullable|string|max:500',
                'animals_on_property' => 'sometimes|boolean',
                'co_agents' => 'nullable|array',
                'co_agents.*' => 'email|max:255',
                'send_statistics_email' => 'sometimes|boolean',
                'statistics_email_frequency' => 'nullable|string|max:50',
                'statistics_email_recipients' => 'nullable|array',
                'statistics_email_recipients.*' => 'email|max:255',
            ], [
                'mls_number.required' => 'MLS number is mandatory.',
                'mls_number.unique' => 'This MLS number is already in use. Please provide a unique MLS number.',
                'agent_id.required' => 'Agent is mandatory.',
                'agent_id.exists' => 'The selected agent does not exist.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $validator->validated();
            if (isset($data['agent_id'])) {
                $agent = Agent::where('uuid', $data['agent_id'])->firstOrFail();
                $data['agent_id'] = $agent->id;
            }

            $property->update($data);

            return response()->json([
                'status' => true,
                'message' => 'Property updated successfully',
                'data' => $property
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'property not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating property: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to update property',
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

            $property = Property::where('uuid', $uuid)->firstOrFail();
            $property->status = $data['status'];
            $property->save();

            return response()->json([
                'status' => true,
                'message' => 'Property status updated successfully',
                'data' => $property
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
                'message' => 'Property not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating user status: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Property status update failed',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function destroy($uuid): JsonResponse
    {
        try {
            $property = Property::where('uuid', $uuid)->firstOrFail();
            $property->delete();

            return response()->json([
                'status' => true,
                'message' => 'Property deleted successfully'
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Property not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error deleting property: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete property',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function orderStoreProperty(Request $request): JsonResponse
    {
        try {
            $nullableFields = [
                'listing_price', 'mls_number', 'bedrooms', 'bathrooms', 'square_footage', 
                'lot_size', 'year_constructed', 'parking_spots', 'property_type', 
                'property_status', 'heading', 'description', 'suite', 'postal_code'
            ];
            foreach ($nullableFields as $field) {
                if ($request->has($field) && $request->input($field) === '') {
                    $request->merge([$field => null]);
                }
            }

            $validator = Validator::make($request->all(), [
                'listing_price' => 'nullable|numeric|min:0',
                'mls_number' => 'nullable|string|max:50|unique:properties',
                'agent_id' => 'nullable|exists:agents,uuid',
                'bedrooms' => 'nullable|integer|min:0|max:20',
                'bathrooms' => 'nullable|numeric|min:0|max:20',
                'square_footage' => 'nullable|integer|min:0',
                'lot_size' => 'nullable|string|max:100',
                'year_constructed' => 'nullable|integer|min:1800|max:' . date('Y'),
                'parking_spots' => 'nullable|integer|min:0|max:50',
                'property_type' => ['nullable', new Enum(PropertyType::class)],
                'property_status' => ['nullable', new Enum(PropertyStatus::class)],
                'heading' => 'nullable|string|max:255',
                'description' => 'nullable|string',
                'suite' => 'nullable|string|max:100',
                'address' => 'required|string|max:255',
                'city' => 'required|string|max:100',
                'province' => 'required|string|max:50',
                'postal_code' => 'nullable|string|max:20',
                'country' => 'sometimes|string|max:50',
                'tour_activated' => 'sometimes|boolean',
                'publish_date' => 'nullable|date|after_or_equal:today',
                'property_website' => 'nullable|url|max:255',
                'mls_property' => 'nullable|url|max:255',
                'occupancy' => ['sometimes', new Enum(OccupancyType::class)],
                'media_creator_access' => ['nullable', new Enum(MediaAccessType::class)],
                'instructions' => 'nullable|string|max:500',
                'animals_on_property' => 'sometimes|boolean',
                'co_agents' => 'nullable|array',
                'co_agents.*' => 'email|max:255',
                'send_statistics_email' => 'sometimes|boolean',
                'statistics_email_frequency' => 'nullable|string|max:50',
                'statistics_email_recipients' => 'nullable|array',
                'statistics_email_recipients.*' => 'email|max:255',
            ], [
                'mls_number.unique' => 'This MLS number is already in use. Please provide a unique MLS number.',
                'agent_id.exists' => 'The selected agent does not exist.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $validator->validated();
            $data['tour_activated'] = $request->get('tour_activated', false);
            $data['animals_on_property'] = $request->get('animals_on_property', false);
            if (!empty($data['agent_id']) && $data['agent_id'] != "") {
                $agent = Agent::where('uuid', $data['agent_id'])->firstOrFail();
                $data['agent_id'] = $agent->id;
            }

            $property = Property::create($data);

            return response()->json([
                'status' => true,
                'message' => 'property created successfully',
                'data' => $property
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating property: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to create property',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function orderUpdateProperty(Request $request, $uuid): JsonResponse
    {
        try {
            $property = Property::where('uuid', $uuid)->firstOrFail();

            $user = auth()->user();
            if ($user instanceof \App\Models\Vendor) {
                $vendorId = $user->id;
                $hasSlot = $property->orders()->whereHas('slots', function ($query) use ($vendorId) {
                    $query->where('vendor_id', $vendorId);
                })->exists();

                if (!$hasSlot) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Unauthorized: You are not assigned to this property.'
                    ], 403);
                }
            }

            $nullableFields = [
                'listing_price', 'mls_number', 'bedrooms', 'bathrooms', 'square_footage', 
                'lot_size', 'year_constructed', 'parking_spots', 'property_type', 
                'property_status', 'heading', 'description', 'suite', 'postal_code'
            ];
            foreach ($nullableFields as $field) {
                if ($request->has($field) && $request->input($field) === '') {
                    $request->merge([$field => null]);
                }
            }

            $validator = Validator::make($request->all(), [
                'listing_price' => 'nullable|numeric|min:0',
                'mls_number' => [
                    'nullable',
                    'string',
                    'max:50',
                    Rule::unique('properties')->ignore($property->id)
                ],
                'agent_id' => 'nullable|exists:agents,uuid',
                'bedrooms' => 'nullable|integer|min:0|max:20',
                'bathrooms' => 'nullable|numeric|min:0|max:20',
                'square_footage' => 'nullable|integer|min:0',
                'lot_size' => 'nullable|string|max:100',
                'year_constructed' => 'nullable|integer|min:1800|max:' . date('Y'),
                'parking_spots' => 'nullable|integer|min:0|max:50',
                'property_type' => ['nullable', new Enum(PropertyType::class)],
                'property_status' => ['nullable', new Enum(PropertyStatus::class)],
                'heading' => 'nullable|string|max:255',
                'description' => 'nullable|string',
                'suite' => 'nullable|string|max:100',
                'address' => 'sometimes|required|string|max:255',
                'city' => 'sometimes|required|string|max:100',
                'province' => 'sometimes|required|string|max:50',
                'postal_code' => 'nullable|string|max:20',
                'country' => 'sometimes|string|max:50',
                'tour_activated' => 'sometimes|boolean',
                'publish_date' => 'nullable|date|after_or_equal:today',
                'property_website' => 'nullable|url|max:255',
                'mls_property' => 'nullable|url|max:255',
                'occupancy' => ['sometimes', new Enum(OccupancyType::class)],
                'media_creator_access' => ['nullable', new Enum(MediaAccessType::class)],
                'instructions' => 'nullable|string|max:500',
                'animals_on_property' => 'sometimes|boolean',
                'co_agents' => 'nullable|array',
                'co_agents.*' => 'email|max:255',
                'send_statistics_email' => 'sometimes|boolean',
                'statistics_email_frequency' => 'nullable|string|max:50',
                'statistics_email_recipients' => 'nullable|array',
                'statistics_email_recipients.*' => 'email|max:255',
            ], [
                'mls_number.unique' => 'This MLS number is already in use. Please provide a unique MLS number.',
                'agent_id.exists' => 'The selected agent does not exist.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $validator->validated();
            if (!empty($data['agent_id']) && $data['agent_id'] != "") {
                $agent = Agent::where('uuid', $data['agent_id'])->firstOrFail();
                $data['agent_id'] = $agent->id;
            }

            $property->update($data);

            return response()->json([
                'status' => true,
                'message' => 'Property updated successfully',
                'data' => $property
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'property not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating property: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to update property',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }
}