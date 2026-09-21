<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Resend\Laravel\Facades\Resend;
use Exception;

class EmailLogController extends Controller
{
    /**
     * Display a listing of the sent emails from local database logs.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $limit = $request->query('limit', 100);
            
            // Resolve active organization context
            $orgId = app()->bound('current_organization_id') ? app('current_organization_id') : null;
            $user = auth()->user();
            if ($orgId === null && $user) {
                $orgId = $user->organization_id;
            }

            $query = \App\Models\EmailLog::orderBy('created_at', 'desc');

            if ($orgId) {
                $query->where('organization_id', $orgId);
            }

            $logs = $query->limit($limit)->get();

            $formattedData = $logs->map(function ($log) {
                return [
                    'id' => $log->id,
                    'from' => $log->from_email,
                    'to' => [$log->to_email],
                    'subject' => $log->subject,
                    'status' => $log->status,
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
                'message' => 'Failed to fetch email logs.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified email log details, retrieving fresh Resend status if available.
     *
     * @param  string  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $localLog = \App\Models\EmailLog::findOrFail($id);

            // Resolve active organization context to validate access
            $orgId = app()->bound('current_organization_id') ? app('current_organization_id') : null;
            $user = auth()->user();
            if ($orgId === null && $user) {
                $orgId = $user->organization_id;
            }

            if ($orgId && $localLog->organization_id !== $orgId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access Denied: You do not have permission to view this email log.'
                ], 403);
            }

            $html = null;
            $text = null;
            $status = $localLog->status;
            $lastEvent = $localLog->status;

            if ($localLog->resend_email_id) {
                try {
                    $resendEmail = Resend::emails()->get($localLog->resend_email_id);
                    if ($resendEmail) {
                        $resendData = is_object($resendEmail) 
                            ? (method_exists($resendEmail, 'toArray') ? $resendEmail->toArray() : (array)$resendEmail) 
                            : $resendEmail;
                        
                        $html = $resendData['html'] ?? null;
                        $text = $resendData['text'] ?? null;
                        $status = $resendData['last_event'] ?? $resendData['status'] ?? $localLog->status;
                        $lastEvent = $resendData['last_event'] ?? $status;

                        // Update local log status if it has been updated in Resend
                        if ($status !== $localLog->status) {
                            $localLog->update(['status' => $status]);
                        }
                    }
                } catch (Exception $resendException) {
                    // Fall back to local data on Resend API failure
                    \Illuminate\Support\Facades\Log::warning("Failed to fetch fresh details from Resend for ID {$localLog->resend_email_id}: " . $resendException->getMessage());
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $localLog->id,
                    'from' => $localLog->from_email,
                    'to' => [$localLog->to_email],
                    'subject' => $localLog->subject,
                    'status' => $status,
                    'created_at' => $localLog->created_at->toIso8601String(),
                    'last_event' => $lastEvent,
                    'html' => $html,
                    'text' => $text,
                ]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch email log details.',
                'error' => $e->getMessage()
            ], 404);
        }
    }
}

