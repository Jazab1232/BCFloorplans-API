<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use App\Notifications\PasswordUpdatedNotification;
use Illuminate\Support\Facades\Hash;
use App\Models\Organization;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            // Eager load roles, permissions and organization for all users
            $users = User::with(['roles', 'permissions', 'organization'])->get();
            return response()->json([
                'status' => true,
                'message' => 'Users retrieved successfully',
                'data' => $users
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving users: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve users',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function show($uuid): JsonResponse
    {
        try {
            $user = User::with(['roles', 'permissions'])->where('uuid', $uuid)->firstOrFail();
            return response()->json([
                'status' => true,
                'message' => 'User retrieved successfully',
                'data' => $user
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'User not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error retrieving user: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve user',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'first_name' => 'required|string|max:255',
                'last_name' => 'nullable|string|max:255',
                'email' => 'required|email|unique:users,email',
                'secondary_email' => 'nullable|email',
                'password' => 'required|string|min:8|confirmed',
                'primary_phone' => 'nullable|string|max:20',
                'secondary_phone' => 'nullable|string|max:20',
                'company_name' => 'nullable|string|max:255',
                'website' => 'nullable|url',
                'address' => 'nullable|string',
                'city' => 'nullable|string',
                'province' => 'nullable|string',
                'country' => 'nullable|string',
                'avatar' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
                'company_logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
                'company_banner' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
                'roles' => 'required|array',
                'roles.*' => 'exists:roles,id', // or 'exists:roles,uuid' if using UUIDs
                'permissions' => 'required|array',
                'permissions.*' => 'exists:permissions,id',
                'organization_id' => 'nullable|exists:organizations,id',
                'notification_email' => 'sometimes|boolean',
            ]);

            $data['password'] = bcrypt($data['password']);

            // Generate UUID once
            $uuid = (string) Str::uuid();
            $data['uuid'] = $uuid;

            $imageFields = ['avatar', 'company_logo', 'company_banner'];
            foreach ($imageFields as $field) {
                if ($request->hasFile($field)) {
                    $file = $request->file($field);
                    $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
                    $path = "users/{$uuid}/{$filename}";
                    Storage::disk('s3')->put($path, file_get_contents($file), 'public');
                    Log::info("Uploaded new file for {$field} to S3: {$path}");
                    $data[$field] = $filename;
                }
            }

            $orgId = $data['organization_id'] 
                ?? (app()->bound('current_organization_id') ? app('current_organization_id') : null)
                ?? Organization::where('uuid', 'fbd6e3a5-4b2c-4de1-ab73-e677b54c4b8a')->value('id');
            
            $data['organization_id'] = $orgId;
            
            if (empty($data['company_name'])) {
                $data['company_name'] = Organization::where('id', $orgId)->value('name');
            }

            $user = User::create($data);

            // Assign roles if provided
            if (isset($data['roles']) && is_array($data['roles'])) {
                // Assuming $data['roles'] is an array of role IDs or UUIDs
                $user->roles()->sync($data['roles']);
            }

            // Assign permissions if provided
            if (isset($data['permissions']) && is_array($data['permissions'])) {
                // Assuming $data['permissions'] is an array of permission IDs or UUIDs
                $user->permissions()->sync($data['permissions']);
            }

            $user->load('roles', 'permissions');

            return response()->json([
                'status' => true,
                'message' => 'User created successfully',
                'data' => $user
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error creating user: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'User creation failed',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $uuid): JsonResponse
    {
        try {
            $user = User::where('uuid', $uuid)->firstOrFail();

            $data = $request->validate([
                'first_name' => 'sometimes|string|max:255',
                'last_name' => 'nullable|string|max:255',
                'email' => 'sometimes|email|unique:users,email,' . $user->id,
                'secondary_email' => 'nullable|email',
                'password' => 'sometimes|string|min:8|confirmed',
                'primary_phone' => 'nullable|string|max:20',
                'secondary_phone' => 'nullable|string|max:20',
                'company_name' => 'nullable|string|max:255',
                'website' => 'nullable|url',
                'address' => 'nullable|string',
                'city' => 'nullable|string',
                'province' => 'nullable|string',
                'country' => 'nullable|string',
                'avatar' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
                'company_logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
                'company_banner' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
                'roles' => 'required|array',
                'roles.*' => 'exists:roles,id', // or 'exists:roles,uuid' if using UUIDs
                'permissions' => 'required|array',
                'permissions.*' => 'exists:permissions,id',
                'organization_id' => 'nullable|exists:organizations,id',
                'notification_email' => 'sometimes|boolean',
            ]);

            if (!empty($data['password'])) {
                $data['password'] = bcrypt($data['password']);
            }

            $imageFields = ['avatar', 'company_logo', 'company_banner'];
            foreach ($imageFields as $field) {
                if ($request->hasFile($field)) {
                    // Delete old file from S3
                    if (isset($user) && $user->$field) {
                        Storage::disk('s3')->delete("users/{$user->uuid}/{$user->$field}");
                        Log::info("Deleted old file for {$field} from S3: users/{$user->uuid}/{$user->$field}");
                    }
                    // Upload new file to S3
                    $file = $request->file($field);
                    $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
                    $path = "users/{$user->uuid}/{$filename}";
                    Storage::disk('s3')->put($path, file_get_contents($file), 'public');
                    Log::info("Uploaded new file for {$field} to S3: {$path}");
                    $data[$field] = $filename;
                }
            }

            $user->update($data);

            // Remove previous roles and assign new ones if provided
            if ($request->has('roles')) {
                $user->roles()->sync($data['roles'] ?? []);
            }

            // Remove previous permissions and assign new ones if provided
            if ($request->has('permissions')) {
                $user->permissions()->sync($data['permissions'] ?? []);
            }

            $user->load('roles', 'permissions');

            return response()->json([
                'status' => true,
                'message' => 'User updated successfully',
                'data' => $user
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
                'message' => 'User not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating user: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'User update failed',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function destroy($uuid): JsonResponse
    {
        try {
            $user = User::where('uuid', $uuid)->firstOrFail();

            $imageFields = ['avatar', 'company_logo', 'company_banner'];
            foreach ($imageFields as $field) {
                if ($user->$field) {
                    Storage::disk('s3')->delete("users/{$user->uuid}/{$user->$field}");
                }
            }

            // Delete associated companies
            foreach ($user->companies as $company) {
                // Delete company images
                foreach (['logo_path', 'banner_path'] as $companyField) {
                    if ($company->$companyField && Storage::disk('public')->exists("companies/{$company->uuid}/{$company->$companyField}")) {
                        Storage::delete("public/companies/{$company->uuid}/{$company->$companyField}");
                    }
                }
                $company->delete();
            }

            foreach ($user->paymentmethods as $method) {
                $method->delete();
            }

            $user->delete();

            return response()->json([
                'status' => true,
                'message' => 'User deleted successfully'
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'User not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error deleting user: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'User deletion failed',
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

            $user = User::where('uuid', $uuid)->firstOrFail();
            $user->status = $data['status'];
            $user->save();

            return response()->json([
                'status' => true,
                'message' => 'User status updated successfully',
                'data' => $user
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
                'message' => 'User not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating user status: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'User status update failed',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function updatePassword(Request $request, $uuid): JsonResponse
    {
        try {
            $user = User::where('uuid', $uuid)->firstOrFail();

            $validated = $request->validate([
                'new_password' => 'required|min:8',
            ]);

            // Generate a temporary password (or use the one from request)
            $newPassword = $validated['new_password'];

            // Update user's password
            $user->password = Hash::make($newPassword);
            $user->save();

            // Send notification with plain text password
            $user->notify(new PasswordUpdatedNotification($newPassword));

            return response()->json([
                'status' => true,
                'message' => 'Password updated and user notified'
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
                'message' => 'User not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating user status: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'User status update failed',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }
}