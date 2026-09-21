<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

/**
 * @deprecated This controller is redundant and will be discarded. 
 * TODO: Redirect logic to the OrganizationController.
 */
class CompanyController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $companies = Company::all();

            return response()->json([
                'status' => true,
                'message' => 'Companies retrieved successfully',
                'data' => $companies
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving companies: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve companies',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $company = Company::where('user_id', $user->id)->first();
            if ($company) {
                return response()->json([
                    'status' => false,
                    'message' => 'Company already exists for this user',
                    'data' => $company
                ], 409);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'website' => 'required|string|url',
                'email' => 'required|email',
                'primary_phone' => 'required|string|max:20',
                'secondary_phone' => 'nullable|string|max:20',
                'street' => 'sometimes|string|nullable|max:100',
                'city' => 'sometimes|string|nullable|max:50',
                'province' => 'sometimes|string|nullable|max:50',
                'country' => 'sometimes|string|max:50',
                'billing_street_1' => 'required|string',
                'billing_street_2' => 'nullable|string',
                'review_files' => 'sometimes|boolean',
                'start_time' => 'required|date_format:H:i',
                'end_time' => 'required|date_format:H:i',
                'work_days' => 'required|array',
                'work_days.*' => Rule::in(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']),
                'repeat_weekly' => 'sometimes|string',
                'timezone' => 'required|timezone',
                'commute_minutes' => 'sometimes|integer|min:0',
                'enable_breaks' => 'sometimes|boolean',
                'sync_google' => 'sometimes|boolean',
                'sync_email' => 'sometimes|in:primary,secondary',
                'payment_per_km' => 'sometimes|numeric|min:0',
                'order_form_url' => 'nullable|url',
                'iframe_code' => 'nullable|string',
                'logo_path' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
                'banner_path' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $validator->validated();
            $data['user_id'] = $user->id;

            $uuid = (string) Str::uuid();
            $data['uuid'] = $uuid;

            // Handle logo_path and banner_path uploads
            foreach (['logo_path', 'banner_path'] as $field) {
                if ($request->hasFile($field)) {
                    $file = $request->file($field);
                    $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
                    $file->storeAs("companies/{$uuid}", $filename, 'public');
                    Log::info("Uploaded new file for {$field}: companies/{$uuid}/{$filename}");
                    $data[$field] = $filename;
                }
            }

            if (isset($data['country']) && $data['country'] === '') {
                unset($data['country']);
            }

            $company = Company::create($data);

            return response()->json([
                'status' => true,
                'message' => 'Company created successfully',
                'data' => $company
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating company: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to create company',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function show($uuid): JsonResponse
    {
        try {
            $company = Company::with('paymentMethods')->where('uuid', $uuid)->firstOrFail();
            return response()->json([
                'status' => true,
                'message' => 'Company retrieved successfully',
                'data' => $company
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Company not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error retrieving company: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve company',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function byUser(): JsonResponse
    {
        try {
            $user = auth()->user();
            $company = Company::where('user_id', $user->id)->firstOrFail();

            return response()->json([
                'status' => true,
                'message' => 'Company retrieved successfully',
                'data' => $company
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Company not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error retrieving company: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve company',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $uuid): JsonResponse
    {
        try {
            $company = Company::where('uuid', $uuid)->firstOrFail();

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'website' => 'sometimes|string|url',
                'email' => 'sometimes|email',
                'primary_phone' => 'sometimes|string|max:20',
                'secondary_phone' => 'nullable|string|max:20',
                'street' => 'sometimes|string|nullable|max:100',
                'city' => 'sometimes|string|nullable|max:50',
                'province' => 'sometimes|string|nullable|max:50',
                'country' => 'sometimes|string|max:50',
                'billing_street_1' => 'sometimes|string',
                'billing_street_2' => 'nullable|string',
                'review_files' => 'sometimes|boolean',
                'start_time' => 'sometimes|date_format:H:i',
                'end_time' => 'sometimes|date_format:H:i',
                'work_days' => 'sometimes|array',
                'work_days.*' => Rule::in(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']),
                'repeat_weekly' => 'sometimes|string',
                'timezone' => 'sometimes|timezone',
                'commute_minutes' => 'sometimes|integer|min:0',
                'enable_breaks' => 'sometimes|boolean',
                'sync_google' => 'sometimes|boolean',
                'sync_email' => 'sometimes|in:primary,secondary',
                'payment_per_km' => 'sometimes|numeric|min:0',
                'order_form_url' => 'nullable|url',
                'iframe_code' => 'nullable|string',
                'logo_path' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
                'banner_path' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $validator->validated();

            // Handle logo_path and banner_path uploads
            foreach (['logo_path', 'banner_path'] as $field) {
                if ($request->hasFile($field)) {
                    // Delete old file if exists
                    if (isset($company) && $company->$field) {
                        Storage::delete("public/companies/{$company->uuid}/{$company->$field}");
                        Log::info("Deleted old file for {$field}: companies/{$company->uuid}/{$company->$field}");
                    }
                    $file = $request->file($field);
                    $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
                    $file->storeAs("companies/" . (isset($company) ? $company->uuid : $data['uuid']), $filename, 'public');
                    Log::info("Uploaded new file for {$field}: companies/" . (isset($company) ? $company->uuid : $data['uuid']) . "/{$filename}");
                    $data[$field] = $filename;
                }
            }

            if (isset($data['country']) && $data['country'] === '') {
                unset($data['country']);
            }

            $company->update($data);

            return response()->json([
                'status' => true,
                'message' => 'Company updated successfully',
                'data' => $company
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Company not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating company: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to update company',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function destroy($uuid): JsonResponse
    {
        try {
            $company = Company::where('uuid', $uuid)->firstOrFail();

            // Delete company images
            foreach (['logo_path', 'banner_path'] as $field) {
                if ($company->$field && Storage::disk('public')->exists("companies/{$company->uuid}/{$company->$field}")) {
                    Storage::delete("public/companies/{$company->uuid}/{$company->$field}");
                }
            }

            $company->delete();

            return response()->json([
                'status' => true,
                'message' => 'Company deleted successfully'
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Company not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error deleting company: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete company',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }
}