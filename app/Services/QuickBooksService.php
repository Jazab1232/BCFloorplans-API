<?php

namespace App\Services;

use QuickBooksOnline\API\DataService\DataService;
use App\Models\Agent;
use App\Models\Order;
use App\Models\AgentPayment;
use App\Models\Service;
use App\Models\OrderService;
use Illuminate\Support\Facades\Auth;
use QuickBooksOnline\API\Facades\Customer;
use QuickBooksOnline\API\Facades\Invoice;
use QuickBooksOnline\API\Facades\Payment;
use QuickBooksOnline\API\Facades\Item;
use QuickBooksOnline\API\Facades\CreditMemo;
use QuickBooksOnline\API\Facades\Bill;
use QuickBooksOnline\API\Facades\BillPayment;
use QuickBooksOnline\API\Facades\Vendor;
use QuickBooksOnline\API\Data\IPPLine;
use QuickBooksOnline\API\Data\IPPReferenceType;
use QuickBooksOnline\API\Data\IPPPayment;
use QuickBooksOnline\API\Data\IPPLinkedTxn;
use QuickBooksOnline\API\Data\IPPSalesItemLineDetail;
use QuickBooksOnline\API\Core\OAuth\OAuth2\OAuth2AccessToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Organization;
use App\Models\QbSyncLog;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class QuickBooksService
{
    protected $dataService;
    protected $organization;

    /**
     * Initialize DataService with optional tokens
     */
    public function initializeDataService($accessToken = null, $refreshToken = null, $realmId = null)
    {
        $config = [
            'auth_mode' => 'oauth2',
            'ClientID' => config('quickbooks.client_id'),
            'ClientSecret' => config('quickbooks.client_secret'),
            'RedirectURI' => config('quickbooks.redirect_uri'),
            'scope' => config('quickbooks.scope'),
            'baseUrl' => config("quickbooks.base_urls." . config('quickbooks.environment')),
        ];

        // Decrypt if necessary
        $token = $accessToken;
        if ($token && config('quickbooks.encrypt_tokens')) {
            try {
                $token = Crypt::decryptString($token);
            } catch (\Exception $e) {
                // If decryption fails, assume it's already plain (for migration period)
                Log::warning('QB Access Token decryption failed, using as-is.');
            }
        }

        $refresh = $refreshToken;
        if ($refresh && config('quickbooks.encrypt_tokens')) {
            try {
                $refresh = Crypt::decryptString($refresh);
            } catch (\Exception $e) {
                Log::warning('QB Refresh Token decryption failed, using as-is.');
            }
        }

        if ($token) {
            $config['accessTokenKey'] = $token;
        }
        if ($refresh) {
            $config['refreshTokenKey'] = $refresh;
        }
        if ($realmId) {
            $config['QBORealmID'] = $realmId;
        }

        $this->dataService = DataService::Configure($config);
        
        return $this->dataService;
    }

    /**
     * Get authorization URL for OAuth
     * 
     * NOTE: We build the URL manually instead of using the SDK's getAuthorizationCodeURL()
     * because the Intuit PHP SDK ignores the $state parameter we pass and generates
     * its own internal 5-char state token. By building the URL ourselves, we ensure
     * our UUID state is preserved through the entire OAuth round-trip.
     */
    public function getAuthorizationUrl($state = null)
    {
        $params = [
            'client_id' => config('quickbooks.client_id'),
            'redirect_uri' => config('quickbooks.redirect_uri'),
            'response_type' => 'code',
            'scope' => config('quickbooks.scope', 'com.intuit.quickbooks.accounting'),
            'state' => $state,
        ];

        $authUrl = 'https://appcenter.intuit.com/connect/oauth2?' . http_build_query($params);
        
        Log::info('QB OAuth: built auth URL manually', [
            'state' => $state,
            'redirect_uri' => config('quickbooks.redirect_uri'),
        ]);

        return $authUrl;
    }

    /**
     * Handle OAuth callback and store tokens
     */
    public function handleCallback($code, $realmId, ?array $cachedData = null)
    {
        try {
            Log::info('QB handleCallback: starting', [
                'has_code' => !empty($code),
                'realmId' => $realmId,
                'cachedData' => $cachedData,
            ]);

            $user = Auth::user();
            $organization = $user ? $user->organization : null;

            Log::info('QB handleCallback: auth check', [
                'auth_user_id' => $user?->id,
                'auth_org' => $organization?->id,
            ]);

            if (!$organization && !empty($cachedData['organization_id'])) {
                $organization = Organization::find($cachedData['organization_id']);
                Log::info('QB handleCallback: resolved org from cachedData.organization_id', [
                    'org_id' => $cachedData['organization_id'],
                    'found' => !is_null($organization),
                ]);
            }

            if (!$organization && !empty($cachedData['user_id'])) {
                $cachedUser = User::find($cachedData['user_id']);
                $organization = $cachedUser ? $cachedUser->organization : null;
                Log::info('QB handleCallback: resolved org from cachedData.user_id', [
                    'user_id' => $cachedData['user_id'],
                    'found_user' => !is_null($cachedUser),
                    'found_org' => !is_null($organization),
                ]);
            }

            if (!$organization) {
                Log::warning('QB handleCallback: no org found, falling back to System org');
                $organization = Organization::where('slug', 'system')->first();
                if (!$organization) {
                    $organization = Organization::create([
                        'name' => 'System',
                        'slug' => 'system',
                        'is_active' => true
                    ]);
                }
            }

            Log::info('QB handleCallback: final organization', [
                'org_id' => $organization->id,
                'org_name' => $organization->name,
            ]);
        
            $this->initializeDataService();
            $OAuth2LoginHelper = $this->dataService->getOAuth2LoginHelper();
            
            Log::info('QB handleCallback: exchanging authorization code for tokens...');
            $accessToken = $OAuth2LoginHelper->exchangeAuthorizationCodeForToken($code, $realmId);
            Log::info('QB handleCallback: tokens received successfully');
            
            $this->storeTokens($accessToken, $realmId, $organization);
            
            Log::info('QB handleCallback: tokens stored for org', [
                'org_id' => $organization->id,
                'realm_id' => $realmId,
            ]);

            return $accessToken;
        } catch (\Exception $e) {
            Log::error('QB handleCallback: FAILED', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \Exception('OAuth authentication failed: ' . $e->getMessage());
        }
    }

   /**
 * ✅ FIXED: Store tokens with proper expiration calculation
 * SDK returns date strings like "2026/01/02 13:21:17", not seconds
 */
    protected function storeTokens(OAuth2AccessToken $token, string $realmId, Organization $organization): void
    {
        $accessExpiresRaw = $token->getAccessTokenExpiresAt();
        $refreshExpiresRaw = $token->getRefreshTokenExpiresAt();
        
        $accessToken = $token->getAccessToken();
        $refreshToken = $token->getRefreshToken();

        // Encrypt if configured
        if (config('quickbooks.encrypt_tokens')) {
            $accessToken = Crypt::encryptString($accessToken);
            if ($refreshToken) {
                $refreshToken = Crypt::encryptString($refreshToken);
            }
        }

        $updateData = [
            'qb_realm_id' => $realmId,
            'qb_access_token' => $accessToken,
            'qb_access_expires_at' => $this->parseTokenExpiration($accessExpiresRaw),
        ];
        
        if ($refreshToken) {
            $updateData['qb_refresh_token'] = $refreshToken;
        }
        
        if ($refreshExpiresRaw) {
            $updateData['qb_refresh_expires_at'] = $this->parseTokenExpiration($refreshExpiresRaw);
        }
        
        $organization->update($updateData);
        
        Log::info('QB Tokens Stored for Organization', [
            'org_id' => $organization->id,
            'access_expires_at' => $updateData['qb_access_expires_at']->toDateTimeString(),
        ]);
    }
    /**
     * Parse token expiration from SDK date string
     */

    protected function parseTokenExpiration($expirationValue)
{
    if (empty($expirationValue)) {
        return now()->addHour(); // Default 1 hour if null
    }

    // Case 1: If it's a numeric value (seconds from now)
    if (is_numeric($expirationValue)) {
        return now()->addSeconds((int)$expirationValue);
    }

    // Case 2: If it's a date string (e.g., "2026/01/02 13:21:17")
    try {
        // Try parsing as date string
        $parsedDate = \Carbon\Carbon::parse($expirationValue);
        
        // Validate it's in the future
        if ($parsedDate->isFuture()) {
            return $parsedDate;
        }
        
        Log::warning('QB token expiration is in the past', [
            'value' => $expirationValue,
            'parsed' => $parsedDate->toDateTimeString(),
        ]);
        
        return now()->addHour(); // Fallback
        
    } catch (\Exception $e) {
        Log::error('Failed to parse QB token expiration', [
            'value' => $expirationValue,
            'error' => $e->getMessage(),
        ]);
        
        return now()->addHour(); // Fallback
    }
}
    /**
     * Refresh access token when expired
     */
    public function refreshAccessToken(Organization $organization = null)
    {
        try {
            if (!$organization) {
                $organization = Organization::whereNotNull('qb_refresh_token')
                    ->whereNotNull('qb_realm_id')
                    ->first();
            }
                
            if (!$organization) {
                throw new \Exception('No organization with QuickBooks connection found');
            }
            
            $this->initializeDataService(
                $organization->qb_access_token,
                $organization->qb_refresh_token,
                $organization->qb_realm_id
            );
            
            $OAuth2LoginHelper = $this->dataService->getOAuth2LoginHelper();
            
            // Decrypt refresh token for the SDK
            $refreshToken = $organization->qb_refresh_token;
            if (config('quickbooks.encrypt_tokens')) {
                try {
                    $refreshToken = Crypt::decryptString($refreshToken);
                } catch (\Exception $e) {}
            }

            $refreshedToken = $OAuth2LoginHelper->refreshAccessTokenWithRefreshToken($refreshToken);
            
            $this->storeTokens($refreshedToken, $organization->qb_realm_id, $organization);
            
            Log::info('QuickBooks access token refreshed successfully for Org: ' . $organization->uuid);
            
            return $refreshedToken;
        } catch (\Exception $e) {
            Log::error('QuickBooks Token Refresh Error: ' . $e->getMessage());
            throw new \Exception('Token refresh failed: ' . $e->getMessage());
        }
    }

    /**
     * Get DataService with automatic token refresh
     */
    public function getDataService(Organization $organization = null): DataService
    {
        if (!$organization) {
            $user = Auth::user();
            $organization = $user ? $user->organization : null;
            
            if (!$organization) {
                $organization = Organization::whereNotNull('qb_access_token')
                    ->whereNotNull('qb_refresh_token')
                    ->first();
            }
        }
            
        if (!$organization || !$organization->qb_access_token) {
            throw new \Exception('QuickBooks not connected.');
        }

        $expiresAt = $organization->qb_access_expires_at 
            ? \Carbon\Carbon::parse($organization->qb_access_expires_at)
            : now()->subMinute();

        $buffer = config('quickbooks.token_refresh_buffer', 5);

        if (now()->addMinutes($buffer)->gte($expiresAt)) {
            Log::info('QuickBooks token expiring soon, refreshing...', [
                'expires_at' => $expiresAt->toDateTimeString(),
                'org_uuid' => $organization->uuid,
            ]);
            
            $this->refreshAccessToken($organization);
            $organization->refresh();
        }

        $this->organization = $organization;

        return $this->initializeDataService(
            $organization->qb_access_token,
            $organization->qb_refresh_token,
            $organization->qb_realm_id
        );
    }

    /**
     * Get active TaxCodes from QuickBooks
     */
    public function getTaxCodes(Organization $organization = null): array
    {
        $dataService = $this->getDataService($organization);
        $taxCodes = $dataService->Query("SELECT * FROM TaxCode WHERE Active = true");

        $result = [];
        if (!empty($taxCodes)) {
            foreach ($taxCodes as $tc) {
                $result[] = [
                    'id' => $tc->Id,
                    'name' => $tc->Name,
                    'description' => $tc->Description ?? '',
                    'taxable' => $tc->Taxable ?? true,
                    'tax_group' => $tc->TaxGroup ?? false,
                ];
            }
        }

        return $result;
    }

    /**
     * Revoke QuickBooks access and clear tokens
     */
    public function revokeAccess(Organization $organization = null)
    {
        try {
            if (!$organization) {
                $user = Auth::user();
                $organization = $user ? $user->organization : null;
            }

            if ($organization && $organization->qb_access_token) {
                try {
                    $this->initializeDataService($organization->qb_access_token);
                    $OAuth2LoginHelper = $this->dataService->getOAuth2LoginHelper();
                    
                    // Decrypt for SDK
                    $token = $organization->qb_access_token;
                    if (config('quickbooks.encrypt_tokens')) {
                        try { $token = Crypt::decryptString($token); } catch (\Exception $e) {}
                    }

                    $result = $OAuth2LoginHelper->revokeToken($token);
                    
                    if ($result) {
                        Log::info('QuickBooks token revoked successfully');
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to revoke token with QuickBooks: ' . $e->getMessage());
                }
                
                $organization->update([
                    'qb_access_token' => null,
                    'qb_refresh_token' => null,
                    'qb_realm_id' => null,
                    'qb_access_expires_at' => null,
                    'qb_refresh_expires_at' => null,
                ]);
                
                Log::info('QuickBooks tokens cleared for Org: ' . $organization->uuid);
            }
            
            return true;
        } catch (\Exception $e) {
            Log::error('QuickBooks Revoke Error: ' . $e->getMessage());
            throw new \Exception('Disconnection failed: ' . $e->getMessage());
        }
    }

    /**
     * Get stored tokens
     */
    public function getStoredTokens(Organization $organization = null)
    {
        if (!$organization) {
            $user = Auth::user();
            $organization = $user ? $user->organization : null;
        }
        
        if (!$organization || !$organization->qb_realm_id) {
            return null;
        }
        
        return [
            'access_token' => $organization->qb_access_token,
            'refresh_token' => $organization->qb_refresh_token,
            'expires_in' => $organization->qb_access_expires_at,
            'refresh_expires_in' => $organization->qb_refresh_expires_at,
            'realm_id' => $organization->qb_realm_id,
        ];
    }

    /**
     * Validate configuration
     */
    public function validateConfig()
    {
        $missing = [];
        
        if (empty(config('quickbooks.client_id'))) {
            $missing[] = 'QUICKBOOKS_CLIENT_ID';
        }
        
        if (empty(config('quickbooks.client_secret'))) {
            $missing[] = 'QUICKBOOKS_CLIENT_SECRET';
        }
        
        if (empty(config('quickbooks.redirect_uri'))) {
            $missing[] = 'QUICKBOOKS_REDIRECT_URI';
        }
        
        if (!empty($missing)) {
            throw new \Exception('Missing QuickBooks configuration: ' . implode(', ', $missing));
        }
        
        return true;
    }

    public function getOrCreateCustomer(Agent $agent, Organization $organization = null): string
    {
        try {
            if ($agent->quickbooks_customer_id) {
                Log::info('Checking if cached QB customer ID is valid: ' . $agent->quickbooks_customer_id);
                try {
                    if (!$organization) {
                        $organization = $agent->organization ?? null;
                    }
                    $dataService = $this->getDataService($organization);
                    $qbCustomer = $dataService->FindById('Customer', $agent->quickbooks_customer_id);
                    if ($qbCustomer) {
                        return $agent->quickbooks_customer_id;
                    }
                } catch (\Exception $e) {
                    Log::warning('Cached QB customer ID is invalid or not found in current QB instance. Clearing cache. Error: ' . $e->getMessage());
                }
                $agent->update(['quickbooks_customer_id' => null]);
            }

            if (!$organization) {
                $organization = $agent->organization ?? null;
            }

            $dataService = $this->getDataService($organization);

            if ($agent->email) {
                Log::info('Searching QB for existing customer: ' . $agent->email);
                
                try {
                    $escapedEmail = str_replace("'", "''", $agent->email);
                    $query = "SELECT * FROM Customer WHERE PrimaryEmailAddr = '{$escapedEmail}' MAXRESULTS 1";
                    
                    $customers = $dataService->Query($query);

                    if (!empty($customers) && is_array($customers)) {
                        $qbCustomer = $customers[0];
                        
                        Log::info('Found existing QB customer: ' . $qbCustomer->Id);

                        $agent->update(['quickbooks_customer_id' => $qbCustomer->Id]);

                        return $qbCustomer->Id;
                    }
                } catch (\Exception $searchError) {
                    Log::warning('QB customer search failed: ' . $searchError->getMessage());
                }
            }

            Log::info('Creating new QB customer for agent: ' . $agent->id);
            
            $displayName = trim($agent->first_name . ' ' . ($agent->last_name ?? ''));
            $uniqueDisplayName = $this->ensureUniqueDisplayName($dataService, $displayName, $agent->id);
            
            $customer = Customer::create([
                "DisplayName" => $uniqueDisplayName,
                "GivenName" => $agent->first_name,
                "FamilyName" => $agent->last_name ?? '',
                "PrimaryEmailAddr" => [
                    "Address" => $agent->email
                ],
                "BillAddr" => [
                    "Line1" => $agent->address ?? '',
                    "City" => $agent->city ?? '',
                    "Country" => $agent->country ?? 'US'
                ]
            ]);

            $createdCustomer = $dataService->Add($customer);

            if (!$createdCustomer) {
                $error = $dataService->getLastError();
                throw new \Exception('QB customer creation failed: ' . $error->getResponseBody());
            }

            $agent->update(['quickbooks_customer_id' => $createdCustomer->Id]);

            Log::info('QB customer created: ' . $createdCustomer->Id);

            return $createdCustomer->Id;
            
        } catch (\Exception $e) {
            Log::error('Failed to get/create QB customer: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * ✅ NEW: Main sync entry point for an internal Invoice
     */
    public function syncInvoiceToQB(\App\Models\Invoice $invoice): ?array
    {
        $log = QbSyncLog::create([
            'organization_id' => $invoice->organization_id ?? $invoice->order?->organization_id,
            'entity_type' => 'invoice',
            'entity_id' => $invoice->id,
            'action' => 'create',
            'status' => 'pending',
            'request_id' => (string) Str::uuid(),
        ]);

        try {
            $dataService = $this->getDataService($invoice->order->organization ?? null);

            // 1. Ensure Customer
            $customerId = $this->getOrCreateCustomer($invoice->agent, $invoice->order->organization ?? null);

            // 2. Build Lines with Tax Support
            $lines = [];
            foreach ($invoice->items as $item) {
                // We'll use a generic "Service" item if no quickbooks_item_id exists
                $itemId = $item->orderService?->service?->quickbooks_item_id 
                    ?? $this->getOrCreateServiceItem($dataService, $item->orderService?->service);
                
                $lines[] = $this->createInvoiceLine(
                    $itemId,
                    $item->quantity,
                    $item->unit_price,
                    $item->description
                );
            }

            // 3. Resolve Tax Code
            $taxCodeId = $this->resolveTaxCodeId($dataService, $invoice);

            // 4. Create QB Invoice
            $invoiceObj = Invoice::create([
                'CustomerRef' => ['value' => $customerId],
                'Line' => $lines,
                'DocNumber' => $invoice->invoice_number,
                'TxnDate' => $invoice->issued_at?->format('Y-m-d') ?? now()->format('Y-m-d'),
                'PrivateNote' => "Order #{$invoice->order_id} - Property: " . ($invoice->order->property->address ?? 'N/A'),
                'TxnTaxDetail' => $taxCodeId ? [
                    'TxnTaxCodeRef' => ['value' => $taxCodeId]
                ] : null,
            ]);

            $qbInvoice = $dataService->Add($invoiceObj);

            if (!$qbInvoice) {
                $error = $dataService->getLastError();
                throw new \Exception('QB invoice creation failed: ' . ($error?->getResponseBody() ?? 'Unknown'));
            }

            // 5. If paid, create payment
            $qbPaymentId = null;
            if ($invoice->status === 'paid' || $invoice->paid_amount > 0) {
                $qbPayment = $this->createPaymentForInvoice($dataService, $qbInvoice, $invoice->paid_amount);
                $qbPaymentId = $qbPayment?->Id;
            }

            $invoice->update([
                'quickbooks_invoice_id' => $qbInvoice->Id,
                'quickbooks_synced_at' => now(),
            ]);

            $log->update([
                'status' => 'success',
                'qb_entity_id' => $qbInvoice->Id,
                'qb_doc_number' => $qbInvoice->DocNumber,
                'response' => (array)$qbInvoice,
            ]);

            return [
                'invoice_id' => $qbInvoice->Id,
                'payment_id' => $qbPaymentId,
                'doc_number' => $qbInvoice->DocNumber,
            ];

        } catch (\Exception $e) {
            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            Log::error('QuickBooks Sync Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * ✅ NEW: Resolve QuickBooks TaxCode ID from Invoice tax_details
     */
    protected function resolveTaxCodeId($dataService, \App\Models\Invoice $invoice): ?string
    {
        if (empty($invoice->tax_details)) return null;

        $taxName = array_key_first($invoice->tax_details);
        $taxRate = $invoice->tax_details[$taxName]['rate'] ?? null;

        if (!$taxName) return null;

        // Try to fetch from organization settings first (Mapping)
        $orgUuid = $invoice->order->organization?->uuid ?? null;
        $mappings = null;
        if ($orgUuid) {
            $mappings = \App\Models\Setting::where('org_id', $orgUuid)->where('key', 'quickbooks_tax_mappings')->first();
        }

        if ($mappings && isset($mappings->value[$taxName])) {
            return $mappings->value[$taxName];
        }

        // Fallback: Search QB by name
        try {
            $escapedName = str_replace("'", "''", $taxName);
            $taxCodes = $dataService->Query("SELECT * FROM TaxCode WHERE Name = '{$escapedName}' AND Active = true");
            if (!empty($taxCodes)) {
                return $taxCodes[0]->Id;
            }

            // Secondary fallback: search by rate in name (e.g. "13%")
            $query = "SELECT * FROM TaxCode WHERE Name LIKE '%{$taxRate}%' AND Active = true";
            $taxCodes = $dataService->Query($query);
            if (!empty($taxCodes)) {
                return $taxCodes[0]->Id;
            }
        } catch (\Exception $e) {
            Log::warning('Failed to search QB TaxCode: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * ✅ NEW: Sync Refund as CreditMemo
     */
    public function syncRefundToQB(\App\Models\Invoice $invoice, float $amount): ?string
    {
        $log = QbSyncLog::create([
            'organization_id' => $invoice->organization_id ?? $invoice->order?->organization_id,
            'entity_type' => 'invoice',
            'entity_id' => $invoice->id,
            'action' => 'refund',
            'status' => 'pending',
            'request_id' => (string) Str::uuid(),
        ]);

        try {
            if (!$invoice->quickbooks_invoice_id) {
                throw new \Exception('Cannot refund an unsynced invoice.');
            }

            $dataService = $this->getDataService($invoice->order->organization ?? null);
            $customerId = $this->getOrCreateCustomer($invoice->agent);

            // Create CreditMemo
            $creditMemoObj = CreditMemo::create([
                'CustomerRef' => ['value' => $customerId],
                'TotalAmt' => $amount,
                'PrivateNote' => "Refund for Invoice #{$invoice->invoice_number}. Property: " . ($invoice->order->property->address ?? 'N/A'),
                'Line' => [
                    [
                        'Amount' => $amount,
                        'DetailType' => 'SalesItemLineDetail',
                        'SalesItemLineDetail' => [
                            'ItemRef' => ['value' => config('quickbooks.accounts.income_id', '1')], // Or a specific refund item
                        ]
                    ]
                ]
            ]);

            $qbCreditMemo = $dataService->Add($creditMemoObj);

            if (!$qbCreditMemo) {
                $error = $dataService->getLastError();
                throw new \Exception('QB CreditMemo creation failed: ' . ($error?->getResponseBody() ?? 'Unknown'));
            }

            $log->update([
                'status' => 'success',
                'qb_entity_id' => $qbCreditMemo->Id,
                'response' => (array)$qbCreditMemo,
            ]);

            return $qbCreditMemo->Id;

        } catch (\Exception $e) {
            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            return null;
        }
    }
    /**
     * ✅ NEW: Sync Vendor Invoice as Bill
     */
    public function syncVendorInvoiceToQB(\App\Models\VendorInvoice $vInvoice): ?string
    {
        $log = QbSyncLog::create([
            'organization_id' => $vInvoice->vendor?->organization_id ?? $vInvoice->organization_id,
            'entity_type' => 'vendor_invoice',
            'entity_id' => $vInvoice->id,
            'action' => 'create_bill',
            'status' => 'pending',
            'request_id' => (string) Str::uuid(),
        ]);

        try {
            $org = $vInvoice->vendor->organization ?? null;
            $dataService = $this->getDataService($org);
            $vendorId = $this->getOrCreateVendor($vInvoice->vendor, $org);

            // Use "Subcontracted Services" account
            $expenseAccountId = config('quickbooks.accounts.expense_id', '1'); 

            $lines = [];
            foreach ($vInvoice->lines as $line) {
                $lines[] = [
                    'Amount' => (float)$line->amount,
                    'DetailType' => 'AccountBasedExpenseLineDetail',
                    'AccountBasedExpenseLineDetail' => [
                        'AccountRef' => ['value' => $expenseAccountId],
                        'Description' => $line->description,
                    ]
                ];
            }

            // Handle Tax separately if needed, but Bills usually have it in line
            if ((float)$vInvoice->tax_amount > 0) {
                $lines[] = [
                    'Amount' => (float)$vInvoice->tax_amount,
                    'DetailType' => 'AccountBasedExpenseLineDetail',
                    'AccountBasedExpenseLineDetail' => [
                        'AccountRef' => ['value' => $expenseAccountId],
                        'Description' => 'Tax',
                    ]
                ];
            }

            $billObj = Bill::create([
                'VendorRef' => ['value' => $vendorId],
                'Line' => $lines,
                'DocNumber' => $vInvoice->invoice_number,
                'PrivateNote' => "Vendor Invoice for Cycle: {$vInvoice->cycle_start} to {$vInvoice->cycle_end}",
            ]);

            $qbBill = $dataService->Add($billObj);

            if (!$qbBill) {
                $error = $dataService->getLastError();
                throw new \Exception('QB Bill creation failed: ' . ($error?->getResponseBody() ?? 'Unknown'));
            }

            $vInvoice->update([
                'quickbooks_bill_id' => $qbBill->Id,
            ]);

            $log->update([
                'status' => 'success',
                'qb_entity_id' => $qbBill->Id,
                'response' => (array)$qbBill,
            ]);

            return $qbBill->Id;

        } catch (\Exception $e) {
            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * ✅ NEW: Sync Vendor Payout as BillPayment
     */
    public function syncVendorPayoutToQB(\App\Models\VendorInvoice $vInvoice): ?string
    {
        $log = QbSyncLog::create([
            'organization_id' => $vInvoice->vendor?->organization_id ?? $vInvoice->organization_id,
            'entity_type' => 'vendor_invoice',
            'entity_id' => $vInvoice->id,
            'action' => 'pay_bill',
            'status' => 'pending',
            'request_id' => (string) Str::uuid(),
        ]);

        try {
            if (!$vInvoice->quickbooks_bill_id) {
                // Try to sync bill first
                $billId = $this->syncVendorInvoiceToQB($vInvoice);
                if (!$billId) throw new \Exception('Failed to sync bill before payment.');
            }

            $org = $vInvoice->vendor->organization ?? null;
            $dataService = $this->getDataService($org);
            $vendorId = $this->getOrCreateVendor($vInvoice->vendor, $org);

            $linkedTxn = new IPPLinkedTxn();
            $linkedTxn->TxnId = (string)$vInvoice->quickbooks_bill_id;
            $linkedTxn->TxnType = 'Bill';

            $billPaymentObj = BillPayment::create([
                'VendorRef' => ['value' => $vendorId],
                'TotalAmt' => (float)$vInvoice->total_amount,
                'PayType' => 'Check', // Or Bank if available
                'Line' => [
                    [
                        'Amount' => (float)$vInvoice->total_amount,
                        'LinkedTxn' => [$linkedTxn]
                    ]
                ],
                'PrivateNote' => "Stripe Payout: " . ($vInvoice->stripe_transfer_id ?? 'N/A'),
            ]);

            $qbBillPayment = $dataService->Add($billPaymentObj);

            if (!$qbBillPayment) {
                $error = $dataService->getLastError();
                throw new \Exception('QB BillPayment failed: ' . ($error?->getResponseBody() ?? 'Unknown'));
            }

            $log->update([
                'status' => 'success',
                'qb_entity_id' => $qbBillPayment->Id,
                'response' => (array)$qbBillPayment,
            ]);

            return $qbBillPayment->Id;

        } catch (\Exception $e) {
            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * ✅ NEW: Ensure Vendor exists in QuickBooks
     */
    public function getOrCreateVendor(\App\Models\Vendor $vendor, Organization $organization = null): string
    {
        if ($vendor->quickbooks_vendor_id) {
            Log::info('Checking if cached QB vendor ID is valid: ' . $vendor->quickbooks_vendor_id);
            try {
                if (!$organization) {
                    $organization = $vendor->organization ?? null;
                }
                $dataService = $this->getDataService($organization);
                $qbVendor = $dataService->FindById('Vendor', $vendor->quickbooks_vendor_id);
                if ($qbVendor) {
                    return $vendor->quickbooks_vendor_id;
                }
            } catch (\Exception $e) {
                Log::warning('Cached QB vendor ID is invalid or not found in current QB instance. Clearing cache. Error: ' . $e->getMessage());
            }
            $vendor->update(['quickbooks_vendor_id' => null]);
        }

        if (!$organization) {
            $organization = $vendor->organization ?? null;
        }

        $dataService = $this->getDataService($organization);
        $email = $vendor->email;
        $displayName = $vendor->business_name ?: ($vendor->first_name . ' ' . $vendor->last_name);

        // Search by email
        $vendors = $dataService->Query("SELECT * FROM Vendor WHERE PrimaryEmailAddr = '{$email}'");
        if (!empty($vendors)) {
            $vendor->update(['quickbooks_vendor_id' => $vendors[0]->Id]);
            return $vendors[0]->Id;
        }

        // Create new
        $vendorObj = Vendor::create([
            'DisplayName' => $displayName,
            'PrimaryEmailAddr' => ['Address' => $email],
            'GivenName' => $vendor->first_name,
            'FamilyName' => $vendor->last_name,
            'CompanyName' => $vendor->business_name,
        ]);

        $qbVendor = $dataService->Add($vendorObj);
        if (!$qbVendor) {
            // Check if display name exists
            $error = $dataService->getLastError();
            if (strpos($error->getResponseBody(), 'Duplicate Name Exists Error') !== false) {
                $vendors = $dataService->Query("SELECT * FROM Vendor WHERE DisplayName = '{$displayName}'");
                if (!empty($vendors)) {
                    $vendor->update(['quickbooks_vendor_id' => $vendors[0]->Id]);
                    return $vendors[0]->Id;
                }
            }
            throw new \Exception('Failed to create QB Vendor: ' . ($error?->getResponseBody() ?? 'Unknown'));
        }

        $vendor->update(['quickbooks_vendor_id' => $qbVendor->Id]);
        return $qbVendor->Id;
    }

    protected function ensureUniqueDisplayName($dataService, string $displayName, $agentId): string
    {
        try {
            $escapedName = str_replace("'", "''", $displayName);
            $query = "SELECT * FROM Customer WHERE DisplayName = '{$escapedName}' MAXRESULTS 1";
            $existing = $dataService->Query($query);
            
            if (!empty($existing) && is_array($existing)) {
                return $displayName . ' (' . $agentId . ')';
            }
            
            return $displayName;
        } catch (\Exception $e) {
            Log::warning('Could not check DisplayName uniqueness: ' . $e->getMessage());
            return $displayName . ' (' . $agentId . ')';
        }
    }

    /**
     * ✅ NEW: Main invoice creation method with proper service handling
     */
    public function createInvoiceForOrder(Order $order, AgentPayment $payment): ?array
    {
        try {
            DB::beginTransaction();
            Log::info('Starting QB invoice creation for order: ' . $order->id);
            
            $dataService = $this->getDataService();

            // 1️⃣ Ensure customer exists
            $customerId = $this->getOrCreateCustomer($order->agent);

            // 2️⃣ Build invoice lines based on payment type
            $lines = $this->buildInvoiceLinesForPayment($dataService, $order, $payment);

            if (empty($lines)) {
                Log::error('No invoice lines could be created for order: ' . $order->id);
                throw new \Exception('No valid invoice lines could be created');
            }

            // 3️⃣ Create invoice
            $invoice = $this->createInvoice($dataService, $order, $customerId, $lines);

            // 4️⃣ Record payment (mark as PAID)
            $qbPayment = $this->createPaymentForInvoice($dataService, $invoice, (float)$payment->amount);

            DB::commit();

            Log::info('QB invoice + payment created', [
                'invoice_id' => $invoice->Id,
                'payment_id' => $qbPayment?->Id,
                'doc_number' => $invoice->DocNumber,
            ]);

            return [
                'invoice_id' => $invoice->Id,
                'payment_id' => $qbPayment?->Id,
                'doc_number' => $invoice->DocNumber,
                'synced_at' => now(),
            ];

        } catch (\Throwable $e) {
            DB::rollBack();
            
            Log::error('QB invoice creation failed', [
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * ✅ NEW: Build invoice lines based on payment type (full vs partial)
     */
    protected function buildInvoiceLinesForPayment($dataService, Order $order, AgentPayment $payment): array
    {
        $lines = [];
        Log::info('Building invoice lines for payment type: ' . $payment->payment_type);

        if ($payment->payment_type === 'full') {
            // FULL PAYMENT: Include ALL services in the order
            Log::info('Building invoice lines for FULL payment', ['order_id' => $order->id]);
            
            $orderServices = $order->services()->with('service','option')->get();

            foreach ($orderServices as $orderService) {
                $service = $orderService->service;
                $option = $orderService->option;
                
                if (!$service) {
                    Log::warning('OrderService has no linked service', ['order_service_id' => $orderService->id]);
                    continue;
                }

                // Get or create QB service item
                $qbItemId = $this->getOrCreateServiceItem($dataService, $service);

                // Create invoice line
                $lines[] = $this->createInvoiceLine(
                    $qbItemId,
                    $option->quantity ?? 1,
                    $option->amount ?? 0,
                    "service option: " . ($option->title ?? '') . "\n" .
                    "square footage: " . ($option->sq_ft_range ?? '')
                );
            }

        } else {
            // PARTIAL PAYMENT: Include only the specific service being paid for
            Log::info('Building invoice lines for PARTIAL payment', [
                'order_id' => $order->id,
                'order_service_id' => $payment->order_service_id,
            ]);

            $orderService = OrderService::with('service')->find($payment->order_service_id);

            if (!$orderService || !$orderService->service) {
                throw new \Exception('Order service not found for partial payment');
            }

            $service = $orderService->service;
            $qbItemId = $this->getOrCreateServiceItem($dataService, $service);

            $lines[] = $this->createInvoiceLine(
                $qbItemId,
                $orderService->quantity ?? 1,
                $payment->amount, // Use actual payment amount
                $service->name . ' - Partial Payment'
            );
        }

        return $lines;
    }

    /**
     * ✅ NEW: Get or create QuickBooks service item with DB caching
     */
    protected function getOrCreateServiceItem($dataService, Service $service): string
    {
        // 1️⃣ Check if service already has QB item ID
        if ($service->quickbooks_item_id) {
            Log::info('Checking if cached QB item ID is valid', [
                'service_id' => $service->id,
                'qb_item_id' => $service->quickbooks_item_id,
            ]);
            try {
                $qbItem = $dataService->FindById('Item', $service->quickbooks_item_id);
                if ($qbItem) {
                    return $service->quickbooks_item_id;
                }
            } catch (\Exception $e) {
                Log::warning('Cached QB item ID is invalid or not found in current QB instance. Clearing cache. Error: ' . $e->getMessage());
            }
            $service->update(['quickbooks_item_id' => null]);
        }

        // 2️⃣ Search QB for existing item by name
        try {
            $escapedName = str_replace("'", "''", $service->name);
            $query = "SELECT * FROM Item WHERE Name = '{$escapedName}' AND Type = 'Service' MAXRESULTS 1";
            $items = $dataService->Query($query);

            if (!empty($items) && is_array($items)) {
                $qbItem = $items[0];
                
                Log::info('Found existing QB item', [
                    'service_id' => $service->id,
                    'qb_item_id' => $qbItem->Id,
                ]);

                // Cache in database
                $service->update(['quickbooks_item_id' => $qbItem->Id]);

                return $qbItem->Id;
            }
        } catch (\Exception $e) {
            Log::warning('QB item search failed: ' . $e->getMessage());
        }

        // 3️⃣ Create new QB item
        Log::info('Creating new QB item', ['service_name' => $service->name]);

        $item = Item::create([
            'Name' => $service->name,
            'Type' => 'Service',
            'IncomeAccountRef' => [
                'value' => $this->getIncomeAccountId($dataService),
            ],
            'UnitPrice' => $service->price ?? 0,
            'Taxable' => false,
            'Active' => true,
        ]);

        $createdItem = $dataService->Add($item);

        if (!$createdItem) {
            $error = $dataService->getLastError();
            throw new \Exception('QB item creation failed: ' . ($error?->getResponseBody() ?? 'Unknown error'));
        }

        // Cache in database
        $service->update(['quickbooks_item_id' => $createdItem->Id]);

        Log::info('QB item created', [
            'service_id' => $service->id,
            'qb_item_id' => $createdItem->Id,
        ]);

        return $createdItem->Id;
    }

    /**
     * Create invoice line item
     */
    protected function createInvoiceLine($itemId, $quantity, $totalAmount, $description = '')
{
    $qty =  1;
    
    $unitPrice = $totalAmount ? $totalAmount  : 0;

    $line = new IPPLine();
    $line->DetailType = "SalesItemLineDetail";
    $line->Description = $description;

    $salesItemLineDetail = new IPPSalesItemLineDetail();
    $salesItemLineDetail->ItemRef = new IPPReferenceType(['value' => $itemId]);
    $salesItemLineDetail->Qty = $qty;
    $salesItemLineDetail->UnitPrice = $unitPrice;

    $line->SalesItemLineDetail = $salesItemLineDetail;

    // Explicitly set Amount
    $line->Amount = round($qty * $unitPrice, 2);

    return $line;
}



    /**
     * Create invoice in QuickBooks
     */
    protected function createInvoice($dataService, Order $order, string $customerId, array $lines)
    {
        $invoiceObj = Invoice::create([
            'CustomerRef' => [
                'value' => $customerId,
            ],
            'Line' => $lines,
            'DocNumber' => 'INV-' . str_pad($order->id, 6, '0', STR_PAD_LEFT),
            'TxnDate' => now()->format('Y-m-d'),
            'DueDate' => now()->format('Y-m-d'),
            'PrivateNote' => "Order #{$order->id} - Payment via Stripe",
            'BillEmail' => [
                'Address' => $order->agent->email,
            ],
            'CustomerMemo' => [
                'value' => 'Thank you for your business!',
            ],
        ]);

        $invoice = $dataService->Add($invoiceObj);

        if (!$invoice) {
            $error = $dataService->getLastError();
            throw new \Exception('QB invoice creation failed: ' . ($error?->getResponseBody() ?? 'Unknown'));
        }

        return $invoice;
    }

    /**
     * Create payment for invoice (marks as PAID)
     */
    protected function createPaymentForInvoice($dataService, $qbInvoice, float $amount)
    {
        Log::info('Creating QB payment for invoice: ' . $qbInvoice->Id);

        $customerRefValue = is_object($qbInvoice->CustomerRef)
            ? $qbInvoice->CustomerRef->value
            : $qbInvoice->CustomerRef;

        $linkedTxn = new IPPLinkedTxn();
        $linkedTxn->TxnId = (string)$qbInvoice->Id;
        $linkedTxn->TxnType = 'Invoice';

        $paymentObj = Payment::create([
            'CustomerRef' => [
                'value' => (string)$customerRefValue,
            ],
            'TotalAmt' => $amount,
            'TxnDate' => now()->format('Y-m-d'),
            'Line' => [[
                'Amount' => $amount,
                'LinkedTxn' => [$linkedTxn],
            ]],
            'PrivateNote' => 'Synced from BCF System',
        ]);

        $qbPayment = $dataService->Add($paymentObj);

        if (!$qbPayment) {
            $error = $dataService->getLastError();
            Log::error('QB payment creation failed', [
                'invoice_id' => $qbInvoice->Id,
                'error' => $error ? $error->getResponseBody() : null,
            ]);
            return null;
        }

        return $qbPayment;
    }

    /**
     * Get income account ID from QuickBooks
     */
    protected function getIncomeAccountId($dataService)
    {
        $cacheKey = 'qb_income_account_id';
        
        return Cache::remember($cacheKey, 3600, function () use ($dataService) {
            try {
                $accounts = $dataService->Query("SELECT * FROM Account WHERE AccountType = 'Income' MAXRESULTS 1");
                
                if (!empty($accounts) && is_array($accounts)) {
                    return $accounts[0]->Id;
                }
                
                return config('quickbooks.default_income_account_id', '79');
            } catch (\Exception $e) {
                Log::warning('Could not fetch income account: ' . $e->getMessage());
                return config('quickbooks.default_income_account_id', '79');
            }
        });
    }

    /**
     * Retry failed syncs
     */
    public function retryFailedSyncs()
    {
        Log::info('Starting QB retry for failed syncs...');
        
        $failedPayments = AgentPayment::where('status', 'succeeded')
            ->whereNotNull('paid_at')
            ->whereNull('quickbooks_invoice_id')
            ->with('order.agent', 'order.services.service')
            ->limit(50)
            ->get();

        Log::info('Found ' . $failedPayments->count() . ' payments to sync');

        $successCount = 0;
        $failCount = 0;

        foreach ($failedPayments as $payment) {
            $orgId = $payment->order?->organization_id;

            $log = QbSyncLog::create([
                'organization_id' => $orgId,
                'entity_type' => 'payment',
                'entity_id' => $payment->id,
                'action' => 'retry_payment_invoice_sync',
                'status' => 'pending',
                'request_id' => (string) Str::uuid(),
            ]);

            try {
                if (!$payment->order) {
                    Log::warning('Payment has no order, skipping', ['payment_id' => $payment->id]);
                    $log->update([
                        'status' => 'failed',
                        'error_message' => 'Payment has no associated order.'
                    ]);
                    continue;
                }

                Log::info('Retrying QB sync', ['payment_id' => $payment->id]);
                
                $result = $this->createInvoiceForOrder($payment->order, $payment);
                
                if ($result) {
                    $payment->update([
                        'quickbooks_invoice_id' => $result['invoice_id'],
                        'quickbooks_payment_id' => $result['payment_id'],
                        'quickbooks_txn_id' => $result['doc_number'],
                        'quickbooks_synced_at' => $result['synced_at'],
                    ]);
                    
                    $successCount++;
                    Log::info('Successfully synced payment: ' . $payment->id);

                    $log->update([
                        'status' => 'success',
                        'qb_entity_id' => $result['invoice_id'],
                        'qb_doc_number' => $result['doc_number'],
                    ]);
                } else {
                    $failCount++;
                    Log::warning('Failed to sync payment: ' . $payment->id);

                    $log->update([
                        'status' => 'failed',
                        'error_message' => 'Sync completed but returned empty/failed result.'
                    ]);
                }
                
            } catch (\Exception $e) {
                $failCount++;
                Log::error('Retry failed', [
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ]);

                $log->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            }
        }

        Log::info("QB retry completed", [
            'success' => $successCount,
            'failed' => $failCount,
            'total' => $failedPayments->count(),
        ]);
        
        return [
            'success' => $successCount,
            'failed' => $failCount,
            'total' => $failedPayments->count()
        ];
    }

    /**
     * Get invoice details from QuickBooks
     */
    public function getInvoice($invoiceId)
    {
        try {
            $dataService = $this->getDataService();
            $invoice = $dataService->FindById('Invoice', $invoiceId);
            
            return $invoice;
        } catch (\Exception $e) {
            Log::error('Failed to fetch QB invoice: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Void an invoice in QuickBooks
     */
    public function voidInvoice($invoiceId)
    {
        try {
            $dataService = $this->getDataService();
            $invoice = $dataService->FindById('Invoice', $invoiceId);
            
            if (!$invoice) {
                throw new \Exception('Invoice not found');
            }

            $result = $dataService->Void($invoice);

            if ($result) {
                Log::info('QB invoice voided: ' . $invoiceId);
                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::error('Failed to void QB invoice: ' . $e->getMessage());
            return false;
        }
    }
}