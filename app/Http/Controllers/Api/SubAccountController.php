<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\SubAccount;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class SubAccountController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $subAccounts = SubAccount::with(['role', 'agent', 'organization']);

            $user = auth()->user();
            if ($user instanceof \App\Models\Agent) {
                $agent = Agent::where('uuid', $user->uuid)->firstOrFail();
                $subAccounts->where('agent_id', $agent->id);
            }

            $subAccounts = $subAccounts->get();

            return response()->json([
                'success' => true,
                'message' => 'SubAccounts retrieved successfully',
                'data' => $subAccounts
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving sub accounts: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve subAccounts',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string',
            'last_name' => 'required|string',
            'agent_id' => 'required|exists:agents,uuid',
            'role_id' => 'required|exists:roles,id',
            'primary_email' => 'required|email|unique:sub_accounts,primary_email',
            'secondary_email' => 'nullable|email',
            'password' => 'required|min:8',
            'notification_email' => 'sometimes|boolean',
            'email_type' => 'sometimes|string',
            'primary_phone' => 'required|string|max:20',
            'secondary_phone' => 'nullable|string|max:20',
            'company_name' => 'sometimes|string',
            'website' => 'sometimes|string',
            'address' => 'sometimes|string|max:100',
            'city' => 'sometimes|string|max:50',
            'province' => 'sometimes|string|max:50',
            'country' => 'sometimes|string|max:50',
            'permissions' => 'nullable|array',
            'avatar' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'company_logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'company_banner' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
        ], [
            'agent_id.required' => 'Agent is required',
            'role_id.required' => 'Role is required'
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

            $agent = Agent::where('uuid', $data['agent_id'])->firstOrFail();
            $data['agent_id'] = $agent->id;

            $data['password'] = bcrypt($data['password']);

            // Generate UUID once
            $uuid = (string) Str::uuid();
            $data['uuid'] = $uuid;
            $data['status'] = true; // Default status to active

            $imageFields = ['avatar', 'company_logo', 'company_banner'];
            foreach ($imageFields as $field) {
                if ($request->hasFile($field)) {
                    $file = $request->file($field);
                    $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
                    $path = "subaccounts/{$uuid}/{$filename}";
                    Storage::disk('s3')->put($path, file_get_contents($file), 'public');
                    Log::info("Uploaded new file for {$field} to S3: {$path}");
                    $data[$field] = $filename;
                }
            }

            $data['organization_id'] = $agent->organization_id;
            $subAccount = SubAccount::create($data);

            return response()->json([
                'success' => true,
                'message' => 'SubAccount created successfully',
                'data' => $subAccount
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create subAccount',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($uuid): JsonResponse
    {
        try {
            $subAccount = SubAccount::with(['role', 'agent'])->where('uuid', $uuid)->firstOrFail();

            return response()->json([
                'success' => true,
                'message' => 'SubAccount found',
                'data' => $subAccount
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'SubAccount not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function update(Request $request, $uuid): JsonResponse
    {
        try {
            $subAccount = SubAccount::where('uuid', $uuid)->firstOrFail();
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'SubAccount not found',
                'error' => $e->getMessage()
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'first_name' => 'sometimes|string',
            'last_name' => 'sometimes|string',
            'agent_id' => 'sometimes|exists:agents,uuid',
            'role_id' => 'sometimes|exists:roles,id',
            'primary_email' => 'email|unique:sub_accounts,primary_email,' . $subAccount->id,
            'secondary_email' => 'nullable|email',
            'password' => 'sometimes|min:8',
            'notification_email' => 'sometimes|boolean',
            'email_type' => 'sometimes|string',
            'primary_phone' => 'sometimes|string|max:20',
            'secondary_phone' => 'nullable|string|max:20',
            'company_name' => 'sometimes|string',
            'website' => 'sometimes|string',
            'address' => 'sometimes|string|max:100',
            'city' => 'sometimes|string|max:50',
            'province' => 'sometimes|string|max:50',
            'country' => 'sometimes|string|max:50',
            'permissions' => 'nullable|array',
            'avatar' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'company_logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'company_banner' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
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
            if (!empty($data['password'])) {
                $data['password'] = bcrypt($data['password']);
            }

            if (isset($data['agent_id'])) {
                $agent = Agent::where('uuid', $data['agent_id'])->firstOrFail();
                $data['agent_id'] = $agent->id;
            }

            $imageFields = ['avatar', 'company_logo', 'company_banner'];
            foreach ($imageFields as $field) {
                if ($request->hasFile($field)) {
                    // Delete old file from S3
                    if ($subAccount->$field) {
                        Storage::disk('s3')->delete("subaccounts/{$subAccount->uuid}/{$subAccount->$field}");
                        Log::info("Deleted old file for {$field} from S3: subaccounts/{$subAccount->uuid}/{$subAccount->$field}");
                    }
                    // Upload new file to S3
                    $file = $request->file($field);
                    $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
                    $path = "subaccounts/{$subAccount->uuid}/{$filename}";
                    Storage::disk('s3')->put($path, file_get_contents($file), 'public');
                    Log::info("Uploaded new file for {$field} to S3: {$path}");
                    $data[$field] = $filename;
                }
            }

            $subAccount->update($data);
            return response()->json([
                'success' => true,
                'message' => 'SubAccount updated successfully',
                'data' => $subAccount
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update subAccount',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($uuid): JsonResponse
    {
        try {
            $subAccount = SubAccount::where('uuid', $uuid)->firstOrFail();
            
            // Delete files from S3
            $imageFields = ['avatar', 'company_logo', 'company_banner'];
            foreach ($imageFields as $field) {
                if ($subAccount->$field) {
                    Storage::disk('s3')->delete("subaccounts/{$subAccount->uuid}/{$subAccount->$field}");
                }
            }

            $subAccount->delete();

            return response()->json([
                'success' => true,
                'message' => 'SubAccount deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete subAccount',
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

            $subAccount = SubAccount::where('uuid', $uuid)->firstOrFail();
            $subAccount->status = $data['status'];
            $subAccount->save();

            return response()->json([
                'status' => true,
                'message' => 'Sub account status updated successfully',
                'data' => $subAccount
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
                'message' => 'Sub account not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating sub account status: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Sub account status update failed',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function updatePassword(Request $request, $uuid): JsonResponse
    {
        try {
            $subAccount = SubAccount::where('uuid', $uuid)->firstOrFail();
            $currentUser = auth()->user();
            
            // Check authorization: user can update their own password OR admin can update anyone's
            $isOwnPassword = $currentUser instanceof SubAccount && $currentUser->uuid === $uuid;
            $isAdmin = $currentUser instanceof \App\Models\User; // Admin user
            
            if (!$isOwnPassword && !$isAdmin) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized to change this password'
                ], 403);
            }

            // Validation rules differ based on who is updating
            $rules = ['password' => 'required|min:8|confirmed'];
            if ($isOwnPassword) {
                $rules['current_password'] = 'required|string';
            }
            
            $validated = $request->validate($rules);

            // Check current password only if user is updating their own password
            if ($isOwnPassword && !Hash::check($validated['current_password'], $subAccount->password)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Current password is incorrect'
                ], 401);
            }

            $password = $validated['password'];

            // Update user's password
            $subAccount->password = Hash::make($password);
            $subAccount->save();

            // // Send notification with plain text password
            // $agent->notify(new PasswordUpdatedNotification($newPassword));

            return response()->json([
                'status' => true,
                'message' => 'Password updated'
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
                'message' => 'Sub Account not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating sub account password: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Sub account password update failed',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }
}