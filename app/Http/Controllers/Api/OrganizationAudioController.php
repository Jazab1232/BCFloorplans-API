<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Organization;
use App\Models\AudioFile;
use App\Models\Agent;
use App\Models\SubAccount;

class OrganizationAudioController extends Controller
{
    /**
     * List audio files for an organization
     * GET /api/organization-audio?organization_id=uuid
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'organization_id' => 'required|exists:organizations,uuid'
        ]);

        $org = Organization::where('uuid', $request->organization_id)->firstOrFail();

        // Get organization-wide shared files (where organization_id matches and agent_id is null)
        $files = AudioFile::where('organization_id', $org->id)
            ->whereNull('agent_id')
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $files
        ]);
    }

    /**
     * Upload new audio file for an organization
     * POST /api/organization-audio
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'organization_id' => 'required|exists:organizations,uuid',
            'audio'           => 'required|file|mimetypes:audio/mpeg,audio/wav,audio/mp3|max:20480',
            'name'            => 'nullable|string|max:255'
        ]);

        $org = Organization::where('uuid', $request->organization_id)->firstOrFail();
        $file = $request->file('audio');
        
        $data = [
            'uuid'            => (string) Str::uuid(),
            'organization_id' => $org->id,
            'agent_id'        => null,
            'name'            => $request->name ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'mime_type'       => $file->getMimeType(),
            'size'            => $file->getSize(),
            'duration'        => null,
            'uploaded_by'     => auth()->user()->uuid ?? null,
        ];

        $uploadPath = "audio-files/organizations/{$org->uuid}";
        $filename = $data['uuid'] . '.' . $file->getClientOriginalExtension();
        $path = "{$uploadPath}/{$filename}";
        
        Storage::disk('s3')->put($path, file_get_contents($file), 'public');
        $data['file_path'] = $path;

        $audio = AudioFile::create($data);

        return response()->json([
            'status' => true,
            'message' => 'Organization audio uploaded successfully to S3',
            'data' => $audio
        ], 201);
    }

    /**
     * Disable / delete organization audio
     * DELETE /api/organization-audio/{uuid}
     */
    public function destroy(string $uuid): JsonResponse
    {
        $audio = AudioFile::where('uuid', $uuid)
            ->whereNull('agent_id') // Make sure we only delete organization audio
            ->firstOrFail();

        // Authorization check: Make sure the logged-in user belongs to the same organization as the audio file
        $user = auth()->user();
        $orgId = match(true) {
            $user instanceof \App\Models\User       => $user->organization_id,
            $user instanceof \App\Models\Agent      => $user->organization_id,
            $user instanceof \App\Models\SubAccount => $user->organization_id,
            $user instanceof \App\Models\Vendor     => $user->organization_id,
            default => null,
        };

        // Bypass for super admin
        $isSuperAdmin = false;
        if ($user instanceof \App\Models\User) {
            $isSuperAdmin = (trim(strtolower($user->email)) === 'todd@tojuco.com') || $user->roles()->where(function($q) {
                $q->where('name', 'Super Admin')
                  ->orWhere('name', 'super admin')
                  ->orWhere('name', 'super-admin');
            })->exists();
        }

        if (!$isSuperAdmin && $audio->organization_id !== $orgId) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized to delete this organization audio file.'
            ], 403);
        }

        $audio->update([
            'is_active' => false
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Organization audio removed successfully'
        ]);
    }
}
