<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class ServiceCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $categories = ServiceCategory::orderBy('name')->get();

            return response()->json([
                'status' => true,
                'message' => 'Categories retrieved successfully',
                'data' => $categories
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving categories: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve categories',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:service_categories,name',
                'type' => 'required|array|min:1',
                'type.*' => 'in:' . implode(',', ServiceCategory::ALLOWED_TYPES),
                'duration' => 'required|boolean',
                'add_ons' => 'nullable|boolean',
                'description' => 'nullable|string',
            ]);

            $category = ServiceCategory::create($validated);
            return response()->json([
                'status' => true,
                'message' => 'Category created successfully',
                'data' => $category
            ], 201);
        } catch (AuthenticationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Authentication failed',
                'error' => 'Invalid or expired token'
            ], 401);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error creating category: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to create category',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function show($uuid): JsonResponse
    {
        try {
            $serviceCategory = ServiceCategory::where('uuid', $uuid)->firstOrFail();
            return response()->json([
                'status' => true,
                'message' => 'Category retrieved successfully',
                'data' => $serviceCategory
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Category not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error retrieving category: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve category',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $uuid): JsonResponse
    {
        try {
            $serviceCategory = ServiceCategory::where('uuid', $uuid)->firstOrFail();

            $validated = $request->validate([
                'name' => 'sometimes|string|max:255|unique:service_categories,name,' . $serviceCategory->id,
                'type' => 'sometimes|array|min:1',
                'type.*' => 'in:' . implode(',', ServiceCategory::ALLOWED_TYPES),
                'duration' => 'sometimes|boolean',
                'add_ons' => 'sometimes|boolean',
                'description' => 'nullable|string',
            ]);

            $serviceCategory->update($validated);
            return response()->json([
                'status' => true,
                'message' => 'Category updated successfully',
                'data' => $serviceCategory
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
                'message' => 'Category not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating category: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to update category',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function destroy($uuid): JsonResponse
    {
        try {
            $serviceCategory = ServiceCategory::where('uuid', $uuid)->firstOrFail();
            $serviceCategory->delete();
            return response()->json([
                'status' => true,
                'message' => 'Category deleted successfully'
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Category not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error deleting category: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete category',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }
}