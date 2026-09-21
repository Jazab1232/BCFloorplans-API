<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class RolePermissionController extends Controller
{
    public function roles(): JsonResponse
    {
        try {
            $roles = Role::all();
            return response()->json([
                'status' => true,
                'message' => 'Roles retrieved successfully',
                'data' => $roles
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving roles: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve roles',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function permissions(): JsonResponse
    {
        try {
            $permissions = Permission::all();
            return response()->json([
                'status' => true,
                'message' => 'Permissions retrieved successfully',
                'data' => $permissions
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving permissions: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve permissions',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function createRole(Request $request): JsonResponse
    {
        try {
            $data = $request->validate(['name' => 'required|string|unique:roles']);
            $role = Role::create($data);
            
            return response()->json([
                'status' => true,
                'message' => 'Role created successfully',
                'data' => $role
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error creating role: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to create role',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function createPermission(Request $request): JsonResponse
    {
        try {
            $data = $request->validate(['name' => 'required|string|unique:permissions']);
            $permission = Permission::create($data);
            
            return response()->json([
                'status' => true,
                'message' => 'Permission created successfully',
                'data' => $permission
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error creating permission: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to create permission',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function assignRole(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'user_id' => 'required|exists:users,id',
                'role_id' => 'required|exists:roles,id',
            ]);

            $user = User::findOrFail($data['user_id']);
            $user->roles()->syncWithoutDetaching([$data['role_id']]);

            return response()->json([
                'status' => true,
                'message' => 'Role assigned successfully'
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
                'message' => 'User or Role not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error assigning role: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to assign role',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function assignPermission(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'user_id' => 'required|exists:users,id',
                'permission_id' => 'required|exists:permissions,id',
            ]);

            $user = User::findOrFail($data['user_id']);
            $user->permissions()->syncWithoutDetaching([$data['permission_id']]);

            return response()->json([
                'status' => true,
                'message' => 'Permission assigned successfully'
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
                'message' => 'User or Permission not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error assigning permission: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to assign permission',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function userPermissions($uuid): JsonResponse
    {
        try {
            $user = User::with(['roles', 'permissions'])
                ->where('uuid', $uuid)
                ->firstOrFail();

            $permissions = $user->permissions->pluck('name')
                ->merge(
                    $user->roles->flatMap->permissions->pluck('name')
                )->unique()->values();

            return response()->json([
                'status' => true,
                'message' => 'User permissions retrieved successfully',
                'data' => [
                    'roles' => $user->roles->pluck('name'),
                    'permissions' => $permissions
                ]
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'User not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error retrieving user permissions: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve user permissions',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }
    public function deletePermission($id): JsonResponse
    {
        try {
            $permission = Permission::findOrFail($id);
            $permission->delete();

            return response()->json([
                'status' => true,
                'message' => 'Permission deleted successfully'
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Permission not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error deleting permission: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete permission',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }
}