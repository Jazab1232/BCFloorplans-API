<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\User;
use App\Services\CoAgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class CoAgentController extends Controller
{
    protected CoAgentService $coAgentService;

    public function __construct(CoAgentService $coAgentService)
    {
        $this->coAgentService = $coAgentService;
    }

    /**
     * Get all linked co-agents for the authenticated agent (or specified agent for Admin).
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $targetAgent = $this->resolvePrimaryAgent($request);
            if ($targetAgent instanceof JsonResponse) {
                return $targetAgent;
            }

            if (!$targetAgent) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'No agent specified or logged in'
                ]);
            }

            $coAgents = $this->coAgentService->getLinkedCoAgents($targetAgent);

            return response()->json([
                'success' => true,
                'data' => $coAgents,
                'message' => 'Co-Agents retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving co-agents: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve co-agents',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Link or create a co-agent partner under the primary agent.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'name' => 'nullable|string',
            'first_name' => 'nullable|string',
            'last_name' => 'nullable|string',
            'password' => 'nullable|string|min:6',
            'primary_phone' => 'nullable|string',
            'number' => 'nullable|string',
            'company_name' => 'nullable|string',
            'agent_uuid' => 'nullable|string|exists:agents,uuid',
            'agent_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $primaryAgent = $this->resolvePrimaryAgent($request);
            if ($primaryAgent instanceof JsonResponse) {
                return $primaryAgent;
            }

            if (!$primaryAgent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Primary agent could not be determined'
                ], 400);
            }

            $email = strtolower(trim($request->email));
            $name = $request->name;
            if (!$name && ($request->first_name || $request->last_name)) {
                $name = trim(($request->first_name ?? '') . ' ' . ($request->last_name ?? ''));
            }
            if (!$name) {
                $name = 'Co-Agent';
            }

            $phone = $request->primary_phone ?? $request->number;

            $coAgent = $this->coAgentService->resolveOrCreateCoAgent(
                $email,
                $name,
                $phone,
                $primaryAgent,
                null,
                [
                    'company_name' => $request->company_name,
                    'password' => $request->password,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Co-Agent linked successfully',
                'data' => $coAgent
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error linking co-agent: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to link co-agent',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Unlink a co-agent partnership.
     */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        try {
            $primaryAgent = $this->resolvePrimaryAgent($request);
            if ($primaryAgent instanceof JsonResponse) {
                return $primaryAgent;
            }

            if (!$primaryAgent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Primary agent could not be determined'
                ], 400);
            }

            if (!$primaryAgent->getLinkedAgents()->contains('uuid', $uuid)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Co-Agent is not linked to this agent',
                ], 404);
            }

            $success = $this->coAgentService->unlinkCoAgent($primaryAgent, $uuid);

            if (!$success) {
                return response()->json([
                    'success' => false,
                    'message' => 'Co-Agent not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Co-Agent unlinked successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error unlinking co-agent: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to unlink co-agent',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function resolvePrimaryAgent(Request $request): Agent|JsonResponse|null
    {
        $user = auth()->user();
        if ($user instanceof Agent) {
            return $user;
        }

        if (!$user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Only agents and administrators can manage co-agents',
            ], 403);
        }

        $identifier = $request->input('agent_uuid') ?? $request->input('agent_id');
        if (!$identifier) {
            return null;
        }

        $targetAgent = Agent::where(function ($query) use ($identifier) {
            $query->where('uuid', $identifier);
            if (is_numeric($identifier)) {
                $query->orWhere('id', (int) $identifier);
            }
        })->first();

        if (!$targetAgent || (
            $user->organization_id !== null
            && (string) $targetAgent->organization_id !== (string) $user->organization_id
        )) {
            return response()->json([
                'success' => false,
                'message' => 'The selected agent is not available to this administrator',
            ], 403);
        }

        return $targetAgent;
    }
}
