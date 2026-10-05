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

    public function retrySingle(Request $request, $uuid, \App\Services\QuickBooksService $qbService)
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

            // Reset to pending before attempting
            $log->update([
                'status'        => 'pending',
                'error_message' => null,
                'error_code'    => null,
            ]);

            // ─────────────────────────────────────────────────────────────────
            // KEY FIX: The sync service methods (syncInvoiceToQB, etc.) always
            // create their OWN brand-new QbSyncLog row internally — they never
            // update *this* original log. So we must update the original log
            // ourselves based on whether $result is non-null (success) or null (fail).
            // ─────────────────────────────────────────────────────────────────
            $finalizeLog = function ($result) use ($log) {
                if ($result !== null) {
                    // Extract the QB entity ID from the result (array or string)
                    $qbEntityId = is_array($result)
                        ? ($result['invoice_id'] ?? $result['bill_id'] ?? $result['payment_id'] ?? null)
                        : (string) $result;

                    $log->update([
                        'status'        => 'success',
                        'qb_entity_id'  => $qbEntityId,
                        'error_message' => null,
                        'attempts'      => $log->attempts + 1,
                    ]);
                } else {
                    // Pull the real error from the newest sibling log the sync service created
                    $sibling = \App\Models\QbSyncLog::where('entity_type', $log->entity_type)
                        ->where('entity_id', $log->entity_id)
                        ->where('status', 'failed')
                        ->where('id', '!=', $log->id)
                        ->latest()
                        ->first();

                    $errorMessage = $sibling?->error_message ?? 'Sync failed — check server logs.';

                    $log->update([
                        'status'        => 'failed',
                        'error_message' => $errorMessage,
                        'attempts'      => $log->attempts + 1,
                    ]);
                }
            };

            // ── invoice ───────────────────────────────────────────────────────
            if ($log->entity_type === 'invoice') {
                $invoice = \App\Models\Invoice::with([
                    'order.property',
                    'order.organization',
                    'items.orderService.service',
                    'agent',
                ])->find($log->entity_id);

                if (!$invoice) {
                    $log->update(['status' => 'failed', 'error_message' => "Invoice #{$log->entity_id} not found.", 'attempts' => $log->attempts + 1]);
                    return response()->json(['success' => false, 'message' => "Invoice #{$log->entity_id} not found."], 404);
                }

                if ($log->action === 'refund') {
                    $amount = $invoice->paid_amount ?? $invoice->total ?? 0;
                    $result = $qbService->syncRefundToQB($invoice, (float) $amount);
                    $finalizeLog($result);
                } else {
                    // Invoice already in QB — mark success directly without re-syncing
                    if ($invoice->quickbooks_invoice_id) {
                        $log->update([
                            'status'        => 'success',
                            'qb_entity_id'  => $invoice->quickbooks_invoice_id,
                            'error_message' => null,
                            'attempts'      => $log->attempts + 1,
                        ]);
                    } else {
                        $result = $qbService->syncInvoiceToQB($invoice);
                        $finalizeLog($result);
                    }
                }

            // ── vendor_invoice ────────────────────────────────────────────────
            } elseif ($log->entity_type === 'vendor_invoice') {
                $vInvoice = \App\Models\VendorInvoice::with([
                    'vendor.organization',
                    'lines',
                ])->find($log->entity_id);

                if (!$vInvoice) {
                    $log->update(['status' => 'failed', 'error_message' => "Vendor invoice #{$log->entity_id} not found.", 'attempts' => $log->attempts + 1]);
                    return response()->json(['success' => false, 'message' => "Vendor invoice #{$log->entity_id} not found."], 404);
                }

                if ($log->action === 'pay_bill') {
                    if (!$vInvoice->quickbooks_bill_id) {
                        // Sync the bill first before paying
                        $qbService->syncVendorInvoiceToQB($vInvoice);
                        $vInvoice->refresh();
                    }
                    $result = $vInvoice->quickbooks_bill_id
                        ? $qbService->syncVendorPayoutToQB($vInvoice)
                        : null;
                } else {
                    $result = $qbService->syncVendorInvoiceToQB($vInvoice);
                }

                $finalizeLog($result);

            } else {
                $log->update([
                    'status'        => 'failed',
                    'error_message' => "Unsupported entity type: {$log->entity_type}",
                    'attempts'      => $log->attempts + 1,
                ]);
            }

            $log->refresh();

            return response()->json([
                'success' => $log->status === 'success',
                'message' => $log->status === 'success'
                    ? 'Retry completed successfully.'
                    : ('Retry failed: ' . ($log->error_message ?? 'Unknown error')),
                'status'  => $log->status,
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retry sync log.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
