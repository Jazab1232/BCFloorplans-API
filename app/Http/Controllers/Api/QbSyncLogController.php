<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Exception;

class QbSyncLogController extends Controller
{
    public function index(Request $request)
    {
        try {
            $limit = $request->query('limit', 100);
            
            $orgId = app()->bound('current_organization_id') ? app('current_organization_id') : null;
            $user = auth()->user();
            if ($orgId === null && $user) {
                $orgId = $user->organization_id;
            }

            $query = \App\Models\QbSyncLog::orderBy('created_at', 'desc');

            if ($orgId) {
                $query->where('organization_id', $orgId);
            }

            if ($request->has('status')) {
                $query->where('status', $request->query('status'));
            }

            if ($request->has('entity_type')) {
                $query->where('entity_type', $request->query('entity_type'));
            }

            $logs = $query->limit($limit)->get();

            $formattedData = $logs->map(function ($log) {
                return [
                    'uuid' => $log->uuid,
                    'entity_type' => $log->entity_type,
                    'entity_id' => $log->entity_id,
                    'qb_entity_id' => $log->qb_entity_id,
                    'qb_doc_number' => $log->qb_doc_number,
                    'action' => $log->action,
                    'status' => $log->status,
                    'error_message' => $log->error_message,
                    'error_code' => $log->error_code,
                    'attempts' => $log->attempts,
                    'created_at' => $log->created_at->toIso8601String(),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedData,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch sync logs.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($uuid)
    {
        try {
            $orgId = app()->bound('current_organization_id') ? app('current_organization_id') : null;
            $user = auth()->user();
            if ($orgId === null && $user) {
                $orgId = $user->organization_id;
            }

            $query = \App\Models\QbSyncLog::where('uuid', $uuid);

            if ($orgId) {
                $query->where('organization_id', $orgId);
            }

            $log = $query->firstOrFail();

            return response()->json([
                'success' => true,
                'data' => [
                    'uuid' => $log->uuid,
                    'entity_type' => $log->entity_type,
                    'entity_id' => $log->entity_id,
                    'qb_entity_id' => $log->qb_entity_id,
                    'qb_doc_number' => $log->qb_doc_number,
                    'action' => $log->action,
                    'status' => $log->status,
                    'error_message' => $log->error_message,
                    'error_code' => $log->error_code,
                    'attempts' => $log->attempts,
                    'payload' => $log->payload,
                    'response' => $log->response,
                    'created_at' => $log->created_at->toIso8601String(),
                    'updated_at' => $log->updated_at->toIso8601String(),
                ]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch sync log.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function retrySingle(Request $request, $uuid)
    {
        try {
            $orgId = app()->bound('current_organization_id') ? app('current_organization_id') : null;
            $user = auth()->user();
            if ($orgId === null && $user) {
                $orgId = $user->organization_id;
            }

            $query = \App\Models\QbSyncLog::where('uuid', $uuid);

            if ($orgId) {
                $query->where('organization_id', $orgId);
            }

            $log = $query->firstOrFail();

            $log->update([
                'status' => 'pending',
                'attempts' => 0
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Retry initiated successfully.'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retry sync log.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
