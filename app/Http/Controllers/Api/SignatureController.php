<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Signature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SignatureController extends Controller
{
    /**
     * List all signatures for an organization.
     */
    public function index($orgUuid): JsonResponse
    {
        $organization = Organization::where('uuid', $orgUuid)->firstOrFail();
        $signatures = $organization->signatures()->orderBy('created_at', 'desc')->get();

        return response()->json([
            'status' => true,
            'data' => $signatures
        ]);
    }

    /**
     * Store a new signature.
     */
    public function store(Request $request, $orgUuid): JsonResponse
    {
        try {
            $organization = Organization::where('uuid', $orgUuid)->firstOrFail();

            $data = $request->validate([
                'name' => 'required|string|max:255',
                'html_content' => 'required|string',
                'media_url' => 'nullable|string|max:2048',
                'media_link' => 'nullable|string|max:2048',
                'is_active' => 'sometimes|boolean',
            ]);

            // Map media_link to media_url if provided
            if (isset($data['media_link']) && !isset($data['media_url'])) {
                $data['media_url'] = $data['media_link'];
            }

            $data['organization_id'] = $organization->id;

            $signature = Signature::create($data);

            return response()->json([
                'status' => true,
                'message' => 'Signature created successfully',
                'data' => $signature
            ], 201);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Organization not found with the provided UUID',
                'error' => $e->getMessage()
            ], 404);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to create signature',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show a single signature.
     */
    public function show(Signature $signature): JsonResponse
    {
        return response()->json([
            'status' => true,
            'data' => $signature
        ]);
    }

    /**
     * Update a signature.
     */
    public function update(Request $request, Signature $signature): JsonResponse
    {
        try {
            $data = $request->validate([
                'name' => 'sometimes|string|max:255',
                'html_content' => 'sometimes|string',
                'media_url' => 'nullable|string|max:2048',
                'media_link' => 'nullable|string|max:2048',
                'is_active' => 'sometimes|boolean',
            ]);

            // Map media_link to media_url if provided
            if (isset($data['media_link']) && !isset($data['media_url'])) {
                $data['media_url'] = $data['media_link'];
            }

            $signature->update($data);

            return response()->json([
                'status' => true,
                'message' => 'Signature updated successfully',
                'data' => $signature
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update signature',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a signature.
     */
    public function destroy(Signature $signature): JsonResponse
    {
        $signature->delete();

        return response()->json([
            'status' => true,
            'message' => 'Signature deleted successfully'
        ]);
    }
}
