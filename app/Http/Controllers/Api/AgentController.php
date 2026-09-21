<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\PaymentMethod;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use App\Models\Organization;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;
use App\Models\Role;
class AgentController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $user = auth()->user();

            if ($user instanceof Agent) {
                // Agents only see their own profile
                $agents = Agent::where('id', $user->id)->with('role', 'audioFiles', 'organization', 'coagent')->get();
            } elseif ($user instanceof \App\Models\Vendor) {
                // TODO: For now, vendors only see agents they have worked with. 
                // We may need to allow them to see all agents in their organization later for the vendor portal.
                $agents = Agent::whereHas('orders.slots', function ($query) use ($user) {
                    $query->where('vendor_id', $user->id);
                })->with('role', 'audioFiles', 'organization', 'coagent')->get();
            } else {
                // Admins and Super Admins see all agents (filtered by org trait or bypassed for Super Admin)
                $agents = Agent::with('role', 'audioFiles', 'organization', 'coagent')->get();
            }

            return response()->json([
                'status' => true,
                'message' => 'Agents retrieved successfully',
                'data' => $agents
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving agents: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve agents',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        Log::info('Creating new agent');
        try {
            $validator = Validator::make($request->all(), [
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'role_id' => 'required|numeric|max:255',
                'organization_id' => 'nullable|exists:organizations,id',
                'email' => 'required|email|unique:agents,email',
                'email_cc' => 'nullable|email',
                'password' => 'required|string|min:8',
                'primary_phone' => 'required|string|max:20',
                'secondary_phone' => 'nullable|string|max:20',
                'company_name' => 'nullable|string|max:255',
                'website' => 'nullable|url',
                'license_number' => 'nullable|string|max:255',
                'certifications' => 'nullable|array',
                'certifications.*' => 'string|max:50',
                'headquarter_address' => 'nullable|string',
                'notes' => 'nullable|string',
                'requires_payment' => 'boolean',
                'avatar' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
                'company_logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
                'company_banner' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
                'notification_email' => 'sometimes|boolean',
                'co_agents' => 'nullable|array',
                'co_agents.*.name' => 'required_with:co_agents|string|max:255',
                'co_agents.*.email' => 'required_with:co_agents|email',
                'co_agents.*.primary_phone' => 'required_with:co_agents|string|max:20',
                'co_agents.*.split' => 'nullable|string|max:255',

                'company_logos' => 'nullable|array',
                'company_logos.*.file' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
                'company_logos.*.type' => 'required_with:company_logos|string|max:50',

                'agent_discount' => 'nullable|array',
                'agent_discount.uuid' => 'nullable|uuid',
                'agent_discount.name' => 'required_with:agent_discount|string|max:255',
                'agent_discount.expiry_date' => 'required_with:agent_discount|date',
                'agent_discount.is_active' => 'sometimes|boolean',
                'agent_discount.description' => 'nullable|string',
                'agent_discount.amount' => 'required_with:agent_discount|numeric|min:0.01',
                'agent_discount.is_percentage' => 'required_with:agent_discount|boolean',
                'agent_discount.minimum_orders' => 'nullable|integer|min:0',
                'agent_discount.minimum_spend' => 'nullable|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $validator->validated();
            $uuid = (string) Str::uuid();
            $data['uuid'] = $uuid;
            $data['password'] = bcrypt($data['password']);

            if (array_key_exists('co_agents', $data)) {
                if (empty($data['co_agents'])) {
                    $data['co_agents'] = null;
                } else {
                    $data['co_agents'] = array_map(function ($coAgent) {
                        return [
                            'name' => $coAgent['name'],
                            'email' => $coAgent['email'],
                            'primary_phone' => $coAgent['primary_phone'],
                            'split' => $coAgent['split'] ?? 'Even split for all parties'
                        ];
                    }, $data['co_agents']);
                }
            }
            if (array_key_exists('agent_discount', $data)) {
                if (empty($data['agent_discount'])) {
                    $data['agent_discount'] = null;
                } else {
                    $data['agent_discount'] = [
                        'uuid' => (string) Str::uuid(),
                        'name' => $data['agent_discount']['name'],
                        'expiry_date' => $data['agent_discount']['expiry_date'],
                        'description' => $data['agent_discount']['description'] ?? null,
                        'amount' => $data['agent_discount']['amount'],
                        'is_percentage' => $data['agent_discount']['is_percentage'],
                        'minimum_orders' => $data['agent_discount']['minimum_orders'] ?? null,
                        'minimum_spend' => $data['agent_discount']['minimum_spend'] ?? null,
                        'is_active' => true,
                    ];
                }
            }

            // Process single image fields
            $imageFields = ['avatar', 'company_logo', 'company_banner'];
            foreach ($imageFields as $field) {
                if ($request->hasFile($field)) {
                    $file = $request->file($field);
                    $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
                    $path = "agents/{$uuid}/{$filename}";
                    Storage::disk('s3')->put($path, file_get_contents($file), [
                        'visibility' => 'public',
                        'ContentType' => $file->getMimeType() ?: 'image/png'
                    ]);
                    Log::info("Uploaded new file for {$field} to S3: {$path}");
                    $data[$field] = $filename;
                }
            }

            // Process multiple logos
            if ($request->has('company_logos')) {
                $logos = [];
                foreach ($request->input('company_logos') as $index => $logoData) {
                    $type = $logoData['type'];
                    $path = null;

                    if ($request->hasFile("company_logos.{$index}.file")) {
                        $file = $request->file("company_logos.{$index}.file");
                        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
                        $path = "agents/{$uuid}/logos/{$filename}";
                        Storage::disk('s3')->put($path, file_get_contents($file), [
                            'visibility' => 'public',
                            'ContentType' => $file->getMimeType() ?: 'image/png'
                        ]);
                        Log::info("Uploaded multiple logo type {$type} to S3: {$path}");
                    }

                    $logos[] = [
                        'type' => $type,
                        'path' => $path
                    ];
                }
                $data['company_logos'] = $logos;
            }

            $orgId = $data['organization_id'] 
                ?? (app()->bound('current_organization_id') ? app('current_organization_id') : null)
                ?? Organization::where('uuid', 'fbd6e3a5-4b2c-4de1-ab73-e677b54c4b8a')->value('id');
            
            $data['organization_id'] = $orgId;
            
            if (empty($data['company_name'])) {
                $data['company_name'] = Organization::where('id', $orgId)->value('name');
            }

            $agent = Agent::create($data);

            return response()->json([
                'status' => true,
                'message' => 'Agent created successfully',
                'data' => $agent
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating agent: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to create agent',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function show($uuid): JsonResponse
    {
        try {
            $agent = Agent::with('role','properties','properties.orders','audioFiles','organization', 'coagent')->where('uuid', $uuid)->firstOrFail();
            return response()->json([
                'status' => true,
                'message' => 'Agent retrieved successfully',
                'data' => $agent
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Agent not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error retrieving agent: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve agent',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $uuid): JsonResponse
    {
        Log::info("Updating agent with UUID: {$uuid}");
        try {
            $agent = Agent::where('uuid', $uuid)->firstOrFail();

            $validator = Validator::make($request->all(), [
                'first_name' => 'sometimes|string|max:255',
                'last_name' => 'sometimes|string|max:255',
                'role_id' => 'sometimes|numeric|max:255',
                'organization_id' => 'nullable|exists:organizations,id',
                'email' => [
                    'sometimes',
                    'email',
                    Rule::unique('agents')->ignore($agent->id)
                ],
                'email_cc' => 'nullable|email',
                'password' => 'sometimes|string|min:8',
                'primary_phone' => 'sometimes|string|max:20',
                'secondary_phone' => 'nullable|string|max:20',
                'company_name' => 'sometimes|string|max:255',
                'website' => 'nullable|url',
                'license_number' => 'nullable|string|max:255',
                'certifications' => 'nullable|array',
                'certifications.*' => 'string|max:50',
                'headquarter_address' => 'nullable|string',
                'notes' => 'nullable|string',
                'requires_payment' => 'sometimes|boolean',
                'avatar' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
                'company_logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
                'company_banner' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
                'status' => 'sometimes|boolean',
                'sync_google_calendar' => 'sometimes|boolean',
                'notification_email' => 'sometimes|boolean',
                'co_agents' => 'nullable|array',
                'co_agents.*.name' => 'required_with:co_agents|string|max:255',
                'co_agents.*.email' => 'required_with:co_agents|email',
                'co_agents.*.primary_phone' => 'required_with:co_agents|string|max:20',
                'co_agents.*.split' => 'nullable|string|max:255',

                'company_logos' => 'nullable|array',
                'company_logos.*.file' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
                'company_logos.*.type' => 'required_with:company_logos|string|max:50',
                'company_logos.*.path' => 'nullable|string',

                'agent_discount' => 'nullable|array',
                'agent_discount.uuid' => 'nullable|uuid',
                'agent_discount.name' => 'required_with:agent_discount|string|max:255',
                'agent_discount.expiry_date' => 'required_with:agent_discount|date',
                'agent_discount.is_active' => 'sometimes|boolean',
                'agent_discount.description' => 'nullable|string',
                'agent_discount.amount' => 'required_with:agent_discount|numeric|min:0.01',
                'agent_discount.is_percentage' => 'required_with:agent_discount|boolean',
                'agent_discount.minimum_orders' => 'nullable|integer|min:0',
                'agent_discount.minimum_spend' => 'nullable|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $validator->validated();

            if (array_key_exists('co_agents', $data)) {
                if (empty($data['co_agents'])) {
                    $data['co_agents'] = null;
                } else {
                    $data['co_agents'] = array_map(function ($coAgent) {
                        return [
                            'name' => $coAgent['name'],
                            'email' => $coAgent['email'],
                            'primary_phone' => $coAgent['primary_phone'],
                            'split' => $coAgent['split'] ?? 'Even split for all parties'
                        ];
                    }, $data['co_agents']);
                }
            }

            if (array_key_exists('agent_discount', $data)) {
                if (empty($data['agent_discount'])) {
                    $data['agent_discount'] = null;
                } else {
                    $data['agent_discount'] = [
                        'uuid' => $data['agent_discount']['uuid'] ?? (string) Str::uuid(),
                        'name' => $data['agent_discount']['name'],
                        'expiry_date' => $data['agent_discount']['expiry_date'],
                        'is_active' => $data['agent_discount']['is_active'] ?? true,
                        'description' => $data['agent_discount']['description'] ?? null,
                        'amount' => $data['agent_discount']['amount'],
                        'is_percentage' => $data['agent_discount']['is_percentage'],
                        'minimum_orders' => $data['agent_discount']['minimum_orders'] ?? null,
                        'minimum_spend' => $data['agent_discount']['minimum_spend'] ?? null,
                    ];
                }
            }

            if (!empty($data['password'])) {
                $data['password'] = bcrypt($data['password']);
            }

            // Process single image fields
            $imageFields = ['avatar', 'company_logo', 'company_banner'];
            foreach ($imageFields as $field) {
                if ($request->hasFile($field)) {
                    // Delete old file from S3 if exists
                    if ($agent->$field) {
                        Storage::disk('s3')->delete("agents/{$agent->uuid}/{$agent->$field}");
                    }
                    $file = $request->file($field);
                    $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
                    $path = "agents/{$agent->uuid}/{$filename}";
                    Storage::disk('s3')->put($path, file_get_contents($file), [
                        'visibility' => 'public',
                        'ContentType' => $file->getMimeType() ?: 'image/png'
                    ]);
                    $data[$field] = $filename;
                }
            }

            // Process multiple logos
            if ($request->has('company_logos')) {
                $existingLogos = $agent->company_logos ?: [];
                $newLogos = [];
                $requestedLogos = $request->input('company_logos');

                foreach ($requestedLogos as $index => $logoData) {
                    $type = $logoData['type'] ?? 'General';
                    $path = $logoData['path'] ?? null;

                    if ($request->hasFile("company_logos.{$index}.file")) {
                        // Delete old file if path exists
                        if ($path) {
                            Storage::disk('s3')->delete($path);
                        }
                        $file = $request->file("company_logos.{$index}.file");
                        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
                        $path = "agents/{$agent->uuid}/logos/{$filename}";
                        Storage::disk('s3')->put($path, file_get_contents($file), [
                            'visibility' => 'public',
                            'ContentType' => $file->getMimeType() ?: 'image/png'
                        ]);
                    } elseif (empty($path)) {
                        // If path was empty, try to match by index in existingLogos or fallback to agent's company_logo
                        if (isset($existingLogos[$index]['path']) && !empty($existingLogos[$index]['path'])) {
                            $path = $existingLogos[$index]['path'];
                        } elseif ($agent->company_logo) {
                            $path = "agents/{$agent->uuid}/{$agent->company_logo}";
                        }
                    }

                    if (!empty($path)) {
                        $newLogos[] = [
                            'type' => $type,
                            'path' => $path
                        ];
                    }
                }

                // Cleanup deleted logos from S3
                $newPaths = collect($newLogos)->pluck('path')->filter()->toArray();
                foreach ($existingLogos as $oldLogo) {
                    if (isset($oldLogo['path']) && !in_array($oldLogo['path'], $newPaths)) {
                        Storage::disk('s3')->delete($oldLogo['path']);
                    }
                }

                $data['company_logos'] = $newLogos;

                // Sync top-level company_logo with the first logo in company_logos if available
                if (!empty($newLogos[0]['path'])) {
                    $data['company_logo'] = basename($newLogos[0]['path']);
                }
            }

            $agent->update($data);

            return response()->json([
                'status' => true,
                'message' => 'Agent updated successfully',
                'data' => $agent->fresh(['organization', 'coagent'])
            ]);
            } catch (ModelNotFoundException $e) {
                return response()->json([
                    'status' => false,
                    'message' => 'Agent not found'
                ], 404);
            } catch (\Exception $e) {
                Log::error('Error updating agent: ' . $e->getMessage());
                return response()->json([
                    'status' => false,
                    'message' => 'Failed to update agent',
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

            $agent = Agent::where('uuid', $uuid)->firstOrFail();
            $agent->status = $data['status'];
            $agent->save();

            return response()->json([
                'status' => true,
                'message' => 'Agent status updated successfully',
                'data' => $agent
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
                'message' => 'Agent not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating agent status: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Agent status update failed',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function destroy($uuid): JsonResponse
    {
        try {
            $agent = Agent::where('uuid', $uuid)->firstOrFail();
            $agent->delete();

            return response()->json([
                'status' => true,
                'message' => 'Agent deleted successfully'
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Agent not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error deleting agent: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete agent',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function updatePassword(Request $request, $uuid): JsonResponse
    {
        try {
            $agent = Agent::where('uuid', $uuid)->firstOrFail();
            $currentUser = auth()->user();
            
            // Check authorization: user can update their own password OR admin can update anyone's
            $isOwnPassword = $currentUser instanceof Agent && $currentUser->uuid === $uuid;
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
            if ($isOwnPassword && !Hash::check($validated['current_password'], $agent->password)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Current password is incorrect'
                ], 401);
            }

            $password = $validated['password'];

            // Update user's password
            $agent->password = Hash::make($password);
            $agent->save();

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
                'message' => 'Agent not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating agent password: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Agent password update failed',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function signup(Request $request): JsonResponse
{
    Log::info('Agent signup request');

    try {
        $validator = Validator::make($request->all(), [
            'first_name'   => 'required|string|max:255',
            'last_name'    => 'required|string|max:255',
            'email'        => 'required|email|unique:agents,email',
            'password'     => 'required|string|min:8|confirmed',
            'organization_id' => 'nullable|exists:organizations,id',
            'company_name' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $roleId = Role::whereRaw('LOWER(name) LIKE ?', ['agent%'])
              ->value('id');


        $orgId = $data['organization_id'] 
            ?? (app()->bound('current_organization_id') ? app('current_organization_id') : null)
            ?? Organization::where('uuid', 'fbd6e3a5-4b2c-4de1-ab73-e677b54c4b8a')->value('id');
        $orgName = Organization::where('id', $orgId)->value('name');

        $agent = Agent::create([
            'uuid'             => (string) Str::uuid(),
            'organization_id'  => $orgId,
            'first_name'       => $data['first_name'],
            'last_name'        => $data['last_name'],
            'email'            => $data['email'],
            'password'         => bcrypt($data['password']),
            'company_name'     => $orgName ?? $data['company_name'] ?? null,

            // sensible defaults
            'role_id'          => $roleId, // example: AGENT role
            'requires_payment' => true,
            'payment_status'   => 'GOOD',
            'status'           => true,
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Signup successful',
            'data'    => $agent,
            'token'   => $agent->createToken('agent_auth_token')->accessToken,
        ], 201);

    } catch (\Exception $e) {
        Log::error('Agent signup failed: '.$e->getMessage());

        return response()->json([
            'status'  => false,
            'message' => 'Signup failed',
            'error'   => config('app.env') === 'production' ? null : $e->getMessage(),
        ], 500);
    }
}
}