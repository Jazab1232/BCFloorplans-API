<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Agent;
use App\Models\Organization;
use App\Models\AudioFile;
use App\Models\SubAccount;

class AgentAudioController extends Controller
{
    /**
     * List audio files for an agent (dropdown)
     * GET /api/agent-audio?agent_id=uuid
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'agent_id' => 'required|exists:agents,uuid'
        ]);

        $agent = Agent::where('uuid', $request->agent_id)->firstOrFail();

        // Get combined list:
        //   1. Agent's own private files     (agent_id = $agent->id)
        //   2. Org-wide shared files          (organization_id = org, agent_id IS NULL)
        //   3. Global/default system files   (organization_id IS NULL, agent_id IS NULL)
        $files = AudioFile::where(function($query) use ($agent) {
                // 1. Agent's own files (regardless of whether organization_id is set)
                $query->where('agent_id', $agent->id);

                // 2. Organization's shared files (only if agent belongs to an org)
                if ($agent->organization_id) {
                    $query->orWhere(function($q) use ($agent) {
                        $q->where('organization_id', $agent->organization_id)
                          ->whereNull('agent_id');
                    });
                }

                // 3. Global/default files (no agent and no org assigned)
                $query->orWhere(function($q) {
                    $q->whereNull('organization_id')
                      ->whereNull('agent_id');
                });
            })
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function($file) {
                // Tag each file with its origin so the frontend can group/label them
                if (!is_null($file->agent_id)) {
                    $file->source = 'agent';
                } elseif (!is_null($file->organization_id)) {
                    $file->source = 'organization';
                } else {
                    $file->source = 'default';
                }
                return $file;
            });

        return response()->json([
            'status' => true,
            'data' => $files
        ]);
    }

    /**
     * Upload new audio file
     * POST /api/agent-audio
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'agent_id'        => 'nullable|exists:agents,uuid',
            'organization_id' => 'nullable|exists:organizations,uuid',
            'audio'           => 'required|file|mimetypes:audio/mpeg,audio/wav,audio/mp3|max:20480',
            'name'            => 'nullable|string|max:255'
        ]);

        if (!$request->agent_id && !$request->organization_id) {
            return response()->json([
                'status' => false,
                'message' => 'Either agent_id or organization_id is required'
            ], 422);
        }

        $file = $request->file('audio');
        $data = [
            'uuid'        => (string) Str::uuid(),
            'name'        => $request->name ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'mime_type'   => $file->getMimeType(),
            'size'        => $file->getSize(),
            'duration'    => null,
            'uploaded_by' => auth()->user()->uuid ?? null,
        ];

        if ($request->agent_id) {
            $agent = Agent::where('uuid', $request->agent_id)->firstOrFail();
            $data['agent_id'] = $agent->id;
            if ($agent->organization_id) {
                $data['organization_id'] = $agent->organization_id;
            }
            $uploadPath = "audio-files/agents/{$agent->uuid}";
        } else {
            $org = Organization::where('uuid', $request->organization_id)->firstOrFail();
            $data['organization_id'] = $org->id;
            $uploadPath = "audio-files/organizations/{$org->uuid}";
        }

        $filename = $data['uuid'] . '.' . $file->getClientOriginalExtension();
        $path = "{$uploadPath}/{$filename}";
        
        Storage::disk('s3')->put($path, file_get_contents($file), 'public');
        $data['file_path'] = $path;

        $audio = AudioFile::create($data);

        return response()->json([
            'status' => true,
            'message' => 'Audio uploaded successfully to S3',
            'data' => $audio
        ], 201);
    }

    /**
     * Disable / delete audio
     * DELETE /api/agent-audio/{uuid}
     */
    public function destroy(string $uuid): JsonResponse
    {
        $audio = AudioFile::where('uuid', $uuid)->firstOrFail();

        // Authorization check: Make sure they own the file
        $user = auth()->user();
        if ($user instanceof Agent && $audio->agent_id !== $user->id) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized to delete this audio file.'
            ], 403);
        }
        if ($user instanceof SubAccount && $audio->agent_id !== $user->agent_id) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized to delete this audio file.'
            ], 403);
        }

        // Soft delete behavior
        $audio->update([
            'is_active' => false
        ]);

        // Optional: remove file physically from S3
        // Storage::disk('s3')->delete($audio->file_path);

        return response()->json([
            'status' => true,
            'message' => 'Audio removed successfully'
        ]);
    }
}
