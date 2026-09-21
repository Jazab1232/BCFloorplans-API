<?php

namespace App\Http\Controllers\Api;

use App\Models\Discount;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

class DiscountController extends Controller
{
    public function index()
    {
        try {
            $discounts = Discount::with('services')->get();
            return response()->json([
                'status' => true,
                'message' => 'Discounts retrieved successfully',
                'data' => $discounts
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve discounts',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'type' => 'required|in:quantity,code',
                'name' => 'required_if:type,quantity|nullable|string|max:255',
                'code_key' => 'required_if:type,code|nullable|string|unique:discounts,code_key',
                'quantity' => 'required_if:type,quantity|nullable|integer|min:1',
                'percentage' => 'required|numeric|between:0.01,99.99',
                'expiry_date' => 'nullable|date',
                'description' => 'nullable|string',
                'services' => 'required|array',
                'services.*' => 'exists:services,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $request->all();
            $data['uuid'] = (string) Str::uuid();

            $discount = Discount::create($data);
            $discount->services()->sync($request->services);

            return response()->json([
                'status' => true,
                'message' => 'Discount created successfully',
                'data' => $discount->load('services')
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to create discount',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($uuid)
    {
        try {
            $discount = Discount::where('uuid', $uuid)->with('services')->firstOrFail();
            
            return response()->json([
                'status' => true,
                'message' => 'Discount found',
                'data' => $discount
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Discount not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve discount',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $uuid)
    {
        try {
            $discount = Discount::where('uuid', $uuid)->firstOrFail();

            $validator = Validator::make($request->all(), [
                'type' => 'sometimes|in:quantity,code',
                'name' => 'required_if:type,quantity|nullable|string|max:255',
                'code_key' => 'required_if:type,code|nullable|string|unique:discounts,code_key,'.$discount->id,
                'quantity' => 'required_if:type,quantity|nullable|integer|min:1',
                'percentage' => 'sometimes|numeric|between:0.01,99.99',
                'expiry_date' => 'nullable|date',
                'description' => 'nullable|string',
                'services' => 'sometimes|array',
                'services.*' => 'exists:services,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $discount->update($request->all());

            if ($request->has('services')) {
                $discount->services()->sync($request->services);
            }

            return response()->json([
                'status' => true,
                'message' => 'Discount updated successfully',
                'data' => $discount->load('services')
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Discount not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update discount',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($uuid)
    {
        try {
            $discount = Discount::where('uuid', $uuid)->firstOrFail();
            $discount->services()->detach();
            $discount->delete();

            return response()->json([
                'status' => true,
                'message' => 'Discount deleted successfully'
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Discount not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete discount',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateStatus(Request $request, $uuid): JsonResponse
    {
        try {
            $data = $request->validate([
                'status' => 'required|boolean',
            ]);

            $discount = Discount::where('uuid', $uuid)->firstOrFail();
            $discount->status = $data['status'];
            $discount->save();

            return response()->json([
                'status' => true,
                'message' => 'Discount status updated successfully',
                'data' => $discount
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
                'message' => 'Discount not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating discount status: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Discount status update failed',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }
}