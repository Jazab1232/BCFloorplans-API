<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\QuickBooksService;
use App\Models\Order;
use App\Models\Agent;
use App\Models\AgentPayment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuickBooksController extends Controller
{
    protected $quickBooksService;

    public function __construct(QuickBooksService $quickBooksService)
    {
        $this->quickBooksService = $quickBooksService;
    }

    /**
     * Get authorization URL to connect QuickBooks
     */
    public function connect(Request $request): JsonResponse
    {
        try {
            $this->quickBooksService->validateConfig();
            
            $user = Auth::user();
            $redirectBackUrl = $request->query('redirect_back_url')
                ?? $request->header('referer')
                ?? config('app.admin_app', 'https://teams.tojuco.com') . '/dashboard/global-settings';

            // Use a short UUID as the state token (same pattern as Google Calendar which works)
            $state = (string) Str::uuid();

            $statePayload = [
                'user_id' => $user?->id,
                'user_uuid' => $user?->uuid,
                'organization_id' => $user?->organization_id ?? $user?->organization?->id,
                'redirect_back_url' => $redirectBackUrl,
            ];

            // Short cache key: "qb_st_<uuid>" = ~42 chars, well within PostgreSQL varchar(255)
            Cache::put("qb_st_{$state}", $statePayload, now()->addMinutes(15));

            Log::info('QB OAuth: connect() initiated', [
                'state' => $state,
                'user_id' => $user?->id,
                'org_id' => $statePayload['organization_id'],
                'redirect_back_url' => $redirectBackUrl,
            ]);

            $authUrl = $this->quickBooksService->getAuthorizationUrl($state);
            
            return response()->json([
                'success' => true,
                'auth_url' => $authUrl,
                'message' => 'Redirect to this URL for QuickBooks authorization'
            ]);
        } catch (\Exception $e) {
            Log::error('QB OAuth: connect() failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => 'Configuration error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle QuickBooks OAuth callback
     * This is a PUBLIC route (no auth middleware) — Intuit redirects the browser here.
     */
    public function callback(Request $request)
    {
        $code = $request->get('code');
        $realmId = $request->get('realmId');
        $state = $request->get('state');

        Log::info('QB OAuth: callback() hit', [
            'has_code' => !empty($code),
            'has_realmId' => !empty($realmId),
            'state' => $state,
            'has_error' => $request->has('error'),
            'all_params' => $request->all(),
        ]);

        // Retrieve state data from cache using the short UUID key
        $stateData = $state ? Cache::get("qb_st_{$state}") : null;

        Log::info('QB OAuth: cache lookup result', [
            'state' => $state,
            'cache_key' => "qb_st_{$state}",
            'found' => !is_null($stateData),
            'stateData' => $stateData,
        ]);

        $defaultUrl = config('app.admin_app', 'https://teams.tojuco.com') . '/dashboard/global-settings';
        $redirectBackUrl = $stateData['redirect_back_url'] ?? $defaultUrl;
        $separator = parse_url($redirectBackUrl, PHP_URL_QUERY) ? '&' : '?';

        Log::info('QB OAuth: redirect target resolved', [
            'redirect_back_url' => $redirectBackUrl,
            'default_url' => $defaultUrl,
        ]);

        if ($request->has('error') || $request->has('error_description')) {
            $errorMsg = $request->get('error_description') ?? $request->get('error');
            Log::warning('QB OAuth: Intuit returned error', ['error' => $errorMsg]);
            return redirect()->to($redirectBackUrl . $separator . 'qb_error=' . urlencode($errorMsg));
        }

        if (!$stateData) {
            Log::warning('QB OAuth: state not found in cache', [
                'state' => $state,
                'cache_key' => "qb_st_{$state}",
            ]);
            return redirect()->to($redirectBackUrl . $separator . 'qb_error=invalid_state');
        }

        if (!$code || !$realmId) {
            Log::warning('QB OAuth: missing code or realmId');
            return redirect()->to($redirectBackUrl . $separator . 'qb_error=missing_parameters');
        }

        try {
            Log::info('QB OAuth: exchanging code for tokens', [
                'org_id' => $stateData['organization_id'] ?? null,
                'user_id' => $stateData['user_id'] ?? null,
            ]);

            $this->quickBooksService->handleCallback($code, $realmId, $stateData);
            Cache::forget("qb_st_{$state}");

            Log::info('QB OAuth: SUCCESS — tokens stored, redirecting', [
                'redirect_to' => $redirectBackUrl . $separator . 'qb_success=1',
            ]);

            return redirect()->to($redirectBackUrl . $separator . 'qb_success=1');
        } catch (\Exception $e) {
            Log::error('QB OAuth: handleCallback failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return redirect()->to($redirectBackUrl . $separator . 'qb_error=' . urlencode($e->getMessage()));
        }
    }

    /**
     * Get QuickBooks connection status
     */
    public function status(): JsonResponse
    {
        try {
            $tokens = $this->quickBooksService->getStoredTokens();
            
            if (!$tokens) {
                return response()->json([
                    'connected' => false,
                    'message' => 'Not connected to QuickBooks'
                ]);
            }

            return response()->json([
                'connected' => true,
                'realm_id' => $tokens['realm_id'],
                'access_expires_at' => $tokens['expires_in'],
                'refresh_expires_at' => $tokens['refresh_expires_in'],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'connected' => false,
                'error' => 'Error checking status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Manually refresh access token
     */
    public function refresh(): JsonResponse
    {
        try {
            $user = Auth::user();
            $newToken = $this->quickBooksService->refreshAccessToken($user->organization);

            return response()->json([
                'success' => true,
                'message' => 'Token refreshed successfully',
                'expires_at' => $newToken->getAccessTokenExpiresAt(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Token refresh failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Disconnect from QuickBooks
     */
    public function disconnect(): JsonResponse
    {
        try {
            $this->quickBooksService->revokeAccess();

            return response()->json([
                'success' => true,
                'message' => 'Successfully disconnected from QuickBooks'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Disconnection failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get QuickBooks company info
     */
    public function getCompanyInfo(): JsonResponse
    {
        try {
            $dataService = $this->quickBooksService->getDataService();
            $companyInfo = $dataService->getCompanyInfo();

            return response()->json([
                'success' => true,
                'data' => $companyInfo
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get company info: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get active QuickBooks TaxCodes
     */
    public function getTaxCodes(): JsonResponse
    {
        try {
            $taxCodes = $this->quickBooksService->getTaxCodes();

            return response()->json([
                'success' => true,
                'data' => $taxCodes
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get tax codes: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create or get QuickBooks customer for authenticated agent
     */
    public function createCustomer(Request $request): JsonResponse
    {
        try {

            
            // Log::info('Creating or retrieving QuickBooks customer for agent ID: ' . Auth::id());
            $agent = Auth::user();
            
            if (!$agent instanceof Agent) {
                return response()->json([
                    'success' => false,
                    'error' => 'Only agents can create customers'
                ], 403);
            }

            $customerId = $this->quickBooksService->getOrCreateCustomer($agent);

            return response()->json([
                'success' => true,
                'message' => 'Customer created/retrieved successfully',
                'customer_id' => $customerId
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to create customer: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Manually create invoice for an order
     */
    public function createInvoice(Request $request): JsonResponse
    {
        $request->validate([
            'invoice_uuid' => 'required|exists:invoices,uuid'
        ]);

        try {
            $invoice = \App\Models\Invoice::with(['order.property', 'items.orderService.service', 'agent'])
                ->where('uuid', $request->invoice_uuid)
                ->firstOrFail();
            
            if ($invoice->quickbooks_invoice_id) {
                return response()->json([
                    'success' => false,
                    'error' => 'Invoice already exists in QuickBooks',
                    'invoice_id' => $invoice->quickbooks_invoice_id
                ], 400);
            }

            $result = $this->quickBooksService->syncInvoiceToQB($invoice);

            if (!$result) {
                return response()->json([
                    'success' => false,
                    'error' => 'Failed to create invoice. Check logs for details.'
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Invoice created successfully',
                'data' => $result
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to create invoice: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get invoice details from QuickBooks
     */
    public function getInvoice(Request $request): JsonResponse
    {
        $request->validate([
            'invoice_id' => 'required'
        ]);

        try {
            $invoice = $this->quickBooksService->getInvoice($request->invoice_id);

            if (!$invoice) {
                return response()->json([
                    'success' => false,
                    'error' => 'Invoice not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $invoice
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Void an invoice in QuickBooks
     */
    public function voidInvoice(Request $request): JsonResponse
    {
        $request->validate([
            'invoice_id' => 'required'
        ]);

        try {
            $result = $this->quickBooksService->voidInvoice($request->invoice_id);

            if (!$result) {
                return response()->json([
                    'success' => false,
                    'error' => 'Failed to void invoice'
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Invoice voided successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get unsynced payments count
     */
    public function getSyncQueue(): JsonResponse
    {
        try {
            $pending = AgentPayment::where('status', 'succeeded')
                ->whereNotNull('paid_at')
                ->whereNull('quickbooks_invoice_id')
                ->count();

            $synced = AgentPayment::whereNotNull('quickbooks_invoice_id')
                ->count();

            $recentUnsynced = AgentPayment::where('status', 'succeeded')
                ->whereNotNull('paid_at')
                ->whereNull('quickbooks_invoice_id')
                ->with('order')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function($payment) {
                    return [
                        'payment_id' => $payment->id,
                        'order_id' => $payment->order_id,
                        'amount' => $payment->amount,
                        'paid_at' => $payment->paid_at,
                        'created_at' => $payment->created_at,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'pending_syncs' => $pending,
                    'synced_count' => $synced,
                    'recent_unsynced' => $recentUnsynced
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Manually retry failed syncs
     */
    public function retryFailedSyncs(): JsonResponse
    {
        try {
            $result = $this->quickBooksService->retryFailedSyncs();

            return response()->json([
                'success' => true,
                'message' => 'Retry process completed',
                'data' => $result
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get sync status for an order
     */
    public function getOrderSyncStatus(Request $request): JsonResponse
    {
        $request->validate([
            'order_id' => 'required|exists:orders,id'
        ]);

        try {
            $payment = AgentPayment::where('order_id', $request->order_id)
                ->whereNotNull('paid_at')
                ->latest()
                ->first();

            if (!$payment) {
                return response()->json([
                    'success' => false,
                    'error' => 'No payment found for this order'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'order_id' => $request->order_id,
                    'payment_id' => $payment->id,
                    'synced' => !empty($payment->quickbooks_invoice_id),
                    'invoice_id' => $payment->quickbooks_invoice_id,
                    'payment_qb_id' => $payment->quickbooks_payment_id,
                    'synced_at' => $payment->quickbooks_synced_at,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}