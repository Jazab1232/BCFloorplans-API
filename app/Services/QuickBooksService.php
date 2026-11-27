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
use QuickBooksOnline\API\Facades\Account;
use QuickBooksOnline\API\Data\IPPLine;
use QuickBooksOnline\API\Data\IPPReferenceType;
use QuickBooksOnline\API\Data\IPPPayment;
use QuickBooksOnline\API\Data\IPPLinkedTxn;
use QuickBooksOnline\API\Data\IPPSalesItemLineDetail;
use QuickBooksOnline\API\Data\IPPDiscountLineDetail;
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
        $token = null;
        $refresh = null;
        try {
            $token = $this->decryptTokenSafely($accessToken, 'access token');
        } catch (\Throwable $e) {
            Log::warning('QB initializeDataService: access token decryption failed: ' . $e->getMessage());
        }
        try {
            $refresh = $this->decryptTokenSafely($refreshToken, 'refresh token');
        } catch (\Throwable $e) {
            Log::warning('QB initializeDataService: refresh token decryption failed: ' . $e->getMessage());
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

        if (!defined('QUICKBOOKS_API_TIMEOUT')) {
            define('QUICKBOOKS_API_TIMEOUT', (int)config('quickbooks.timeout', 25));
        }

        $this->dataService = DataService::Configure($config);
        
        return $this->dataService;
    }

    /**
     * Decrypt a QuickBooks token safely, verifying whether it is an encrypted Laravel payload.
     *
     * @param string|null $token
     * @param string $type
     * @return string|null
     * @throws \Exception
     */
    public function decryptTokenSafely(?string $token, string $type = 'token'): ?string
    {
        if (empty($token)) {
            return null;
        }

        $isEncrypted = $this->isLaravelEncryptedPayload($token);

        // If the token is already plain text (not encrypted), return it directly
        if (!$isEncrypted) {
            return $token;
        }

        // The token is an encrypted payload: try to decrypt it
        try {
            return Crypt::decryptString($token);
        } catch (\Exception $e) {
            Log::error("QB {$type} decryption failed: Application APP_KEY mismatch or corrupted token.", [
                'error' => $e->getMessage(),
            ]);
            throw new \Exception("QuickBooks {$type} in the database is encrypted with a previous APP_KEY. Please reconnect QuickBooks in Settings to save fresh tokens.");
        }
    }

    /**
     * Determine if a string is a Laravel encrypted payload (base64 JSON containing iv, value, mac).
     */
    public function isLaravelEncryptedPayload(?string $value): bool
    {
        if (empty($value)) {
            return false;
        }
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return false;
        }
        $json = json_decode($decoded, true);
        return is_array($json) && isset($json['iv'], $json['value'], $json['mac']);
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
                $organization = $cachedUser ? ($cachedUser->organization ?? ($cachedUser->organization_id ? Organization::find($cachedUser->organization_id) : null)) : null;
                Log::info('QB handleCallback: resolved org from cachedData.user_id', [
                    'user_id' => $cachedData['user_id'],
                    'found_user' => !is_null($cachedUser),
                    'found_org' => !is_null($organization),
                ]);
            }

            if (!$organization && !empty($cachedData['user_uuid'])) {
                $cachedUser = User::where('uuid', $cachedData['user_uuid'])->first();
                $organization = $cachedUser ? ($cachedUser->organization ?? ($cachedUser->organization_id ? Organization::find($cachedUser->organization_id) : null)) : null;
                Log::info('QB handleCallback: resolved org from cachedData.user_uuid', [
                    'user_uuid' => $cachedData['user_uuid'],
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
     * Store tokens with proper expiration calculation and exception safety
     */
    protected function storeTokens(OAuth2AccessToken $token, string $realmId, Organization $organization): void
    {
        $accessExpiresRaw = null;
        try {
            $accessExpiresRaw = $token->getAccessTokenExpiresAt();
        } catch (\Throwable $e) {
            Log::warning('QB: getAccessTokenExpiresAt not available: ' . $e->getMessage());
        }

        $refreshExpiresRaw = null;
        try {
            $refreshExpiresRaw = $token->getRefreshTokenExpiresAt();
        } catch (\Throwable $e) {
            Log::warning('QB: getRefreshTokenExpiresAt not available: ' . $e->getMessage());
        }
        
        $accessToken = null;
        try {
            $accessToken = $token->getAccessToken();
        } catch (\Throwable $e) {
            Log::error('QB: getAccessToken failed: ' . $e->getMessage());
        }

        $refreshToken = null;
        try {
            $refreshToken = $token->getRefreshToken();
        } catch (\Throwable $e) {
            Log::warning('QB: getRefreshToken failed: ' . $e->getMessage());
        }

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
        } else {
            // Default 100 days for QuickBooks refresh token if SDK did not parse it
            $updateData['qb_refresh_expires_at'] = now()->addDays(100);
        }
        
        $previousRealmId = $organization->qb_realm_id;
        $organization->update($updateData);
        $this->clearCompanyMetadataCache($organization, $previousRealmId);
        $this->clearCompanyMetadataCache($organization, $realmId);
        
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
        if (!$organization) {
            $organization = Organization::whereNotNull('qb_refresh_token')
                ->whereNotNull('qb_realm_id')
                ->first();
        }
            
        if (!$organization) {
            throw new \Exception('No organization with QuickBooks connection found');
        }

        // Prevent race conditions: if multiple requests try to refresh simultaneously,
        // use an atomic lock so only one performs the refresh and the others reuse the newly stored token.
        $lockKey = 'qb_refresh_token_lock_' . $organization->id;
        $lock = Cache::lock($lockKey, 30);

        return $lock->block(15, function () use ($organization) {
            // Re-read fresh token from DB in case another process already refreshed it while waiting for the lock
            $organization->refresh();
            $expiresAt = $organization->qb_access_expires_at 
                ? \Carbon\Carbon::parse($organization->qb_access_expires_at) 
                : null;

            $buffer = config('quickbooks.token_refresh_buffer', 5);
            if ($expiresAt && now()->addMinutes($buffer)->lt($expiresAt) && !empty($organization->qb_access_token)) {
                Log::info('QB token was already refreshed by another concurrent process, reusing.');
                return null;
            }

            try {
                $this->initializeDataService(
                    $organization->qb_access_token,
                    $organization->qb_refresh_token,
                    $organization->qb_realm_id
                );
                
                $OAuth2LoginHelper = $this->dataService->getOAuth2LoginHelper();
                
                // Decrypt refresh token for the SDK
                $refreshToken = null;
                try {
                    $refreshToken = $this->decryptTokenSafely($organization->qb_refresh_token, 'refresh token');
                } catch (\Exception $e) {
                    // If encrypted with old APP_KEY or corrupted, clear stale tokens so org can reconnect
                    $organization->update([
                        'qb_access_token' => null,
                        'qb_refresh_token' => null,
                        'qb_access_expires_at' => null,
                        'qb_refresh_expires_at' => null,
                    ]);
                    throw new \Exception('QuickBooks authorization has expired or is invalid. Please reconnect QuickBooks in Global Settings.');
                }

                $refreshedToken = $OAuth2LoginHelper->refreshAccessTokenWithRefreshToken($refreshToken);
                
                $this->storeTokens($refreshedToken, $organization->qb_realm_id, $organization);
                
                Log::info('QuickBooks access token refreshed successfully for Org: ' . $organization->uuid);
                
                return $refreshedToken;
            } catch (\Exception $e) {
                Log::error('QuickBooks Token Refresh Error: ' . $e->getMessage());
                if (str_contains($e->getMessage(), 'invalid_grant') || str_contains($e->getMessage(), 'Incorrect or invalid refresh token') || str_contains($e->getMessage(), 'reconnect QuickBooks')) {
                    throw new \Exception('QuickBooks authorization has expired. Please reconnect QuickBooks in Global Settings.');
                }
                throw new \Exception('QuickBooks token refresh failed: ' . $e->getMessage());
            }
        });
    }

    /**
     * Get DataService with automatic token refresh
     */
    public function getDataService(Organization $organization = null): DataService
    {
        if (!$organization) {
            $orgId = app()->bound('current_organization_id') ? app('current_organization_id') : null;
            if ($orgId) {
                $organization = is_numeric($orgId) 
                    ? Organization::find($orgId) 
                    : Organization::where('uuid', $orgId)->first();
            }

            if (!$organization) {
                $user = Auth::user();
                $organization = $user ? $user->organization : null;
            }
            
            if (!$organization) {
                $organization = Organization::whereNotNull('qb_access_token')
                    ->whereNotNull('qb_refresh_token')
                    ->first();
            }
        }

        // If the requested organization has no QB tokens, fall back to the primary connected organization
        if ($organization && !$organization->qb_access_token) {
            $fallbackOrg = Organization::whereNotNull('qb_access_token')
                ->whereNotNull('qb_refresh_token')
                ->first();
            if ($fallbackOrg) {
                Log::info("QB getDataService: Organization {$organization->id} ({$organization->name}) has no QB credentials; falling back to Organization {$fallbackOrg->id} ({$fallbackOrg->name})");
                $organization = $fallbackOrg;
            }
        }
            
        if (!$organization || !$organization->qb_access_token) {
            throw new \Exception('QuickBooks is not connected. Please connect QuickBooks in Global Settings.');
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
                    $token = null;
                    try {
                        $token = $this->decryptTokenSafely($organization->qb_access_token, 'access token');
                    } catch (\Exception $e) {
                        Log::warning('QB: Failed to decrypt access token during revoke: ' . $e->getMessage());
                    }

                    if ($token) {
                        $this->initializeDataService($token);
                        $OAuth2LoginHelper = $this->dataService->getOAuth2LoginHelper();
                        $result = $OAuth2LoginHelper->revokeToken($token);
                        
                        if ($result) {
                            Log::info('QuickBooks token revoked successfully');
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to revoke token with QuickBooks: ' . $e->getMessage());
                }
                
                $previousRealmId = $organization->qb_realm_id;
                $organization->update([
                    'qb_access_token' => null,
                    'qb_refresh_token' => null,
                    'qb_realm_id' => null,
                    'qb_access_expires_at' => null,
                    'qb_refresh_expires_at' => null,
                ]);
                $this->clearCompanyMetadataCache($organization, $previousRealmId);
                
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
            $organization = $user ? ($user->organization ?? ($user->organization_id ? Organization::find($user->organization_id) : null)) : null;
        }

        // If the requested organization has no QB tokens, fall back to the primary connected organization
        if (!$organization || !$organization->qb_access_token) {
            $fallbackOrg = Organization::whereNotNull('qb_access_token')
                ->whereNotNull('qb_refresh_token')
                ->first();
            if ($fallbackOrg) {
                $organization = $fallbackOrg;
            }
        }
        
        if (!$organization || !$organization->qb_realm_id || !$organization->qb_access_token) {
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
     * Check if an organization is connected to QuickBooks
     */
    public function isConnected(?Organization $organization = null): bool
    {
        return $this->getStoredTokens($organization) !== null;
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
                if (!$organization) {
                    $organization = $agent->organization ?? null;
                }
                $realmKey = $organization?->qb_realm_id ?? 'default';
                $custVerifyKey = "qb_cust_verified_{$realmKey}_{$agent->id}_{$agent->quickbooks_customer_id}";

                if (Cache::get($custVerifyKey)) {
                    return $agent->quickbooks_customer_id;
                }

                Log::info('Checking if cached QB customer ID is valid: ' . $agent->quickbooks_customer_id);
                try {
                    $dataService = $this->getDataService($organization);
                    $qbCustomer = $dataService->FindById('Customer', $agent->quickbooks_customer_id);
                    if ($qbCustomer) {
                        $this->syncCustomerDetailsIfMissing($dataService, $qbCustomer, $agent);
                        Cache::put($custVerifyKey, true, 86400);
                        return $agent->quickbooks_customer_id;
                    }
                    $agent->update(['quickbooks_customer_id' => null]);
                } catch (\Exception $e) {
                    $errMsg = $e->getMessage();
                    $isTimeout = stripos($errMsg, 'cURL error') !== false || stripos($errMsg, 'timed out') !== false;
                    if ($isTimeout) {
                        Log::warning('QB customer verification timed out; keeping cached ID ' . $agent->quickbooks_customer_id);
                        return $agent->quickbooks_customer_id;
                    }
                    Log::warning('Cached QB customer ID is invalid or not found in current QB instance. Clearing cache. Error: ' . $errMsg);
                    $agent->update(['quickbooks_customer_id' => null]);
                }
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
                        $this->syncCustomerDetailsIfMissing($dataService, $qbCustomer, $agent);

                        return $qbCustomer->Id;
                    }
                } catch (\Exception $searchError) {
                    Log::warning('QB customer search failed: ' . $searchError->getMessage());
                }
            }

            Log::info('Creating new QB customer for agent: ' . $agent->id);
            
            $displayName = trim($agent->first_name . ' ' . ($agent->last_name ?? ''));
            $uniqueDisplayName = $this->ensureUniqueDisplayName($dataService, $displayName, $agent->id);
            
            $customerPayload = [
                "DisplayName" => $uniqueDisplayName,
                "GivenName" => $agent->first_name,
                "FamilyName" => $agent->last_name ?? '',
                "PrimaryEmailAddr" => [
                    "Address" => $agent->email
                ],
            ];

            if (!empty($agent->company_name)) {
                $customerPayload["CompanyName"] = $agent->company_name;
            }

            $phone = $agent->primary_phone ?? $agent->phone ?? null;
            if (!empty($phone)) {
                $customerPayload["PrimaryPhone"] = [
                    "FreeFormNumber" => $phone
                ];
            }

            if (!empty($agent->secondary_phone)) {
                $customerPayload["Mobile"] = [
                    "FreeFormNumber" => $agent->secondary_phone
                ];
            }

            if (!empty($agent->website)) {
                $customerPayload["WebAddr"] = [
                    "URI" => $agent->website
                ];
            }

            $address = $agent->headquarter_address ?? $agent->address ?? null;
            if (!empty($address)) {
                $customerPayload["BillAddr"] = [
                    "Line1" => $address,
                    "City" => $agent->city ?? '',
                    "Country" => $agent->country ?? 'Canada',
                ];
            }

            $customer = Customer::create($customerPayload);

            $createdCustomer = $dataService->Add($customer);

            if (!$createdCustomer || $createdCustomer instanceof \Exception) {
                $error = $dataService->getLastError();
                $sdkMsg = ($createdCustomer instanceof \Exception) ? $createdCustomer->getMessage() : null;
                $errorBody = $sdkMsg ?? ($error ? $error->getResponseBody() : '');

                // Handle Duplicate Name Exists gracefully (e.g. existing client's QB already has this name)
                if (strpos($errorBody, 'Duplicate Name Exists Error') !== false || strpos($errorBody, '6240') !== false) {
                    Log::info('QB customer creation returned Duplicate Name, attempting to find existing or use disambiguated name', [
                        'agent_id' => $agent->id,
                        'name' => $uniqueDisplayName,
                    ]);

                    $escapedName = str_replace("'", "''", $uniqueDisplayName);
                    $existing = $dataService->Query("SELECT * FROM Customer WHERE DisplayName = '{$escapedName}' MAXRESULTS 1");
                    if (!empty($existing) && is_array($existing)) {
                        $agent->update(['quickbooks_customer_id' => $existing[0]->Id]);
                        return $existing[0]->Id;
                    }

                    // If existing record was a Vendor or Employee, create with agent ID suffix
                    $disambiguatedName = $uniqueDisplayName . ' (Agent #' . $agent->id . ')';
                    $customer->DisplayName = $disambiguatedName;
                    $createdCustomer = $dataService->Add($customer);
                }

                if (!$createdCustomer || $createdCustomer instanceof \Exception) {
                    $retryMsg = ($createdCustomer instanceof \Exception) ? $createdCustomer->getMessage() : null;
                    throw new \Exception('QB customer creation failed: ' . ($retryMsg ?? ($errorBody ?: 'Unknown error')));
                }
            }

            $agent->update(['quickbooks_customer_id' => $createdCustomer->Id]);

            Log::info('QB customer created/resolved: ' . $createdCustomer->Id);

            return $createdCustomer->Id;
            
        } catch (\Exception $e) {
            Log::error('Failed to get/create QB customer: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * ✅ NEW: Main sync entry point for an internal Invoice (One Main Invoice Per Order Model)
     */
    public function syncInvoiceToQB(\App\Models\Invoice $invoice): ?array
    {
        // 1. Resolve to Consolidated Invoice for the SAME agent if this is an individual service invoice
        if ($invoice->order_id && $invoice->notes && stripos($invoice->notes, 'service invoice') !== false) {
            $isCoAgentInvoice = in_array($invoice->agent_type, ['co-agent', 'co_agent'], true);

            $mainInvoice = \App\Models\Invoice::with(['order.property', 'items.orderService.service', 'agent', 'order.organization'])
                ->where('order_id', $invoice->order_id)
                ->when($invoice->agent_id, fn ($q) => $q->where('agent_id', $invoice->agent_id))
                ->where(function ($q) {
                    $q->where('notes', 'ilike', '%consolidated%')
                      ->orWhere('notes', 'ilike', '%add-on service invoice%');
                })
                ->first();

            if (!$mainInvoice && !$isCoAgentInvoice) {
                $mainInvoice = \App\Models\Invoice::with(['order.property', 'items.orderService.service', 'agent', 'order.organization'])
                    ->where('order_id', $invoice->order_id)
                    ->where('agent_type', 'primary')
                    ->where(function ($q) {
                        $q->whereNull('notes')
                          ->orWhere('notes', 'not ilike', '%service invoice%');
                    })
                    ->first();
            }

            if ($mainInvoice) {
                Log::info("QB sync: resolving service invoice #{$invoice->id} to Order Main Invoice #{$mainInvoice->id}");
                $invoice = $mainInvoice;
            }
        }

        // Atomic lock to prevent duplicate concurrent syncs (e.g. Stripe webhook + manual retry)
        $lockKey = "qb_sync_invoice_lock_{$invoice->id}";
        $lock = Cache::lock($lockKey, 120);

        if (!$lock->get()) {
            Log::info('QB sync skipped: invoice is currently being synced by another process', [
                'invoice_id' => $invoice->id,
            ]);
            return null;
        }

        try {
            $invoice->refresh();

            // If Main Invoice is already created in QB, rebuild/update lines (so tax/discount fixes apply) & sync any unsynced payments
            if ($invoice->quickbooks_invoice_id) {
                Log::info('QB sync: Invoice already in QB — rebuilding lines and updating to sync taxes/discounts/payments', [
                    'invoice_id' => $invoice->id,
                    'qb_invoice_id' => $invoice->quickbooks_invoice_id,
                    'doc_number' => $invoice->invoice_number,
                ]);

                $log = QbSyncLog::create([
                    'organization_id' => $invoice->organization_id ?? $invoice->order?->organization_id,
                    'entity_type' => 'invoice',
                    'entity_id' => $invoice->id,
                    'action' => 'update',
                    'status' => 'pending',
                    'request_id' => (string) Str::uuid(),
                ]);

                try {
                    $org = $invoice->organization
                        ?? $invoice->order?->organization
                        ?? ($invoice->organization_id ? \App\Models\Organization::find($invoice->organization_id) : null);
                    $dataService = $this->getDataService($org);

                    $updatedInvoice = $this->rebuildAndUpdateQBInvoice($dataService, $invoice, $org);
                    $syncedPaymentIds = $this->syncPendingPaymentsForOrder($dataService, $invoice, $updatedInvoice);

                    $log->update([
                        'status'       => 'success',
                        'qb_entity_id' => $invoice->quickbooks_invoice_id,
                        'qb_doc_number' => $updatedInvoice->DocNumber ?? $this->formatDocNumber($invoice->invoice_number),
                        'synced_at'    => now(),
                        'response'     => $updatedInvoice ? (array)$updatedInvoice : [],
                    ]);

                    return [
                        'invoice_id' => $invoice->quickbooks_invoice_id,
                        'payment_id' => !empty($syncedPaymentIds) ? end($syncedPaymentIds) : null,
                        'doc_number' => $updatedInvoice->DocNumber ?? $invoice->invoice_number,
                    ];
                } catch (\Throwable $e) {
                    $log->update([
                        'status'        => 'failed',
                        'error_message' => $e->getMessage(),
                    ]);
                    throw $e;
                }
            }

            $log = QbSyncLog::create([
                'organization_id' => $invoice->organization_id ?? $invoice->order?->organization_id,
                'entity_type' => 'invoice',
                'entity_id' => $invoice->id,
                'action' => 'create',
                'status' => 'pending',
                'request_id' => (string) Str::uuid(),
            ]);

            try {
                $org = $invoice->organization
                    ?? $invoice->order?->organization
                    ?? ($invoice->organization_id ? \App\Models\Organization::find($invoice->organization_id) : null);
                $dataService = $this->getDataService($org);

                // 1. Ensure Customer
                $customerId = $this->getOrCreateCustomer($invoice->agent, $org);

                // 2. Build payload (respecting CA GTM vs US UsingSalesTax vs fallback)
                $invoicePayload = $this->prepareInvoicePayload($dataService, $invoice, $org, $customerId);

                $invoiceObj = Invoice::create($invoicePayload);
                $qbInvoice = $dataService->Add($invoiceObj);

                if (!$qbInvoice || $qbInvoice instanceof \Exception) {
                    $resolvedError = $this->extractQBErrorMessage($dataService, $qbInvoice);
                    $this->handleInvalidCustomerError($resolvedError, $invoice, $org);
                    throw new \Exception('QB invoice creation failed: ' . $resolvedError);
                }

                $invoice->update([
                    'quickbooks_invoice_id' => $qbInvoice->Id,
                    'quickbooks_synced_at' => now(),
                ]);

                // 3. Sync any successful payments for this order against the new Main Invoice
                $syncedPaymentIds = $this->syncPendingPaymentsForOrder($dataService, $invoice, $qbInvoice);
                $qbPaymentId = !empty($syncedPaymentIds) ? end($syncedPaymentIds) : null;

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
                    'attempts' => $log->attempts + 1,
                ]);
                Log::error('QuickBooks Sync Error: ' . $e->getMessage());
                return null;
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Rebuild lines/tax/discount payload for an already-synced QB Invoice and update it in place.
     */
    protected function rebuildAndUpdateQBInvoice($dataService, \App\Models\Invoice $invoice, ?Organization $org = null)
    {
        $qbInvoiceId = (string)$invoice->quickbooks_invoice_id;
        $existingQbInvoice = $this->callReadWithRetry(
            fn() => $dataService->FindById('Invoice', $qbInvoiceId),
            "FindById(Invoice, {$qbInvoiceId})"
        );

        if (!$existingQbInvoice || $existingQbInvoice instanceof \Exception) {
            $err = $this->extractQBErrorMessage($dataService, $existingQbInvoice);
            throw new \Exception("Failed to fetch existing QB invoice #{$qbInvoiceId} for update: {$err}");
        }

        // Skip redundant Update() if QB already matches BCF total and expected tax mode
        $caps = $this->getCompanyCapabilities($dataService, $org);
        $useNativeTaxBox = !$caps['is_us'] || $caps['using_sales_tax'];
        $existingTotal = (float)($existingQbInvoice->TotalAmt ?? 0);
        $targetTotal = (float)($invoice->total ?? 0);
        $hasNativeTxnTax = !empty($existingQbInvoice->TxnTaxDetail) && (float)($existingQbInvoice->TxnTaxDetail->TotalTax ?? 0) > 0;
        $taxModeMatches = ($useNativeTaxBox && (float)$invoice->tax_amount > 0) ? $hasNativeTxnTax : !$hasNativeTxnTax;

        if (abs($existingTotal - $targetTotal) < 0.01 && $taxModeMatches) {
            Log::info("QB sync: QB invoice #{$qbInvoiceId} already matches BCF total (\${$existingTotal}) and tax mode; skipping Update()");
            return $existingQbInvoice;
        }

        $customerId = $this->getOrCreateCustomer($invoice->agent, $org);
        $invoicePayload = $this->prepareInvoicePayload($dataService, $invoice, $org, $customerId);

        // Preserve original TxnDate if QB already has one, unless local issued_at is set
        if (empty($invoice->issued_at) && !empty($existingQbInvoice->TxnDate)) {
            $invoicePayload['TxnDate'] = $existingQbInvoice->TxnDate;
        }

        $updatedObj = Invoice::update($existingQbInvoice, $invoicePayload);

        // FacadeHelper::mergeObj does not unset omitted properties, so explicitly clear
        // tax fields when switching to a payload mode that omits them (e.g. UsingSalesTax=false).
        if (!array_key_exists('TxnTaxDetail', $invoicePayload)) {
            $updatedObj->TxnTaxDetail = null;
        }
        if (!array_key_exists('GlobalTaxCalculation', $invoicePayload)) {
            $updatedObj->GlobalTaxCalculation = null;
        }
        if (!array_key_exists('ApplyTaxAfterDiscount', $invoicePayload)) {
            $updatedObj->ApplyTaxAfterDiscount = null;
        }

        $qbUpdated = $dataService->Update($updatedObj);
        if (!$qbUpdated || $qbUpdated instanceof \Exception) {
            $resolvedError = $this->extractQBErrorMessage($dataService, $qbUpdated);
            $this->handleInvalidCustomerError($resolvedError, $invoice, $org);
            throw new \Exception('QB invoice update failed: ' . $resolvedError);
        }

        $invoice->update(['quickbooks_synced_at' => now()]);
        return $qbUpdated;
    }

    /**
     * Build the QuickBooks Invoice payload shared by both create and update flows.
     */
    protected function prepareInvoicePayload($dataService, \App\Models\Invoice $invoice, ?Organization $org, string $customerId): array
    {
        $caps = $this->getCompanyCapabilities($dataService, $org);
        $isUSCompany = $caps['is_us'];
        $usingSalesTax = $caps['using_sales_tax'];

        $taxAmount = round((float)($invoice->tax_amount ?? 0), 2);
        $taxCodeId = $this->resolveTaxCodeId($dataService, $invoice, $isUSCompany);

        // Determine per-line TaxCodeRef before constructing lines:
        // - Canadian GTM: actual mapped/matched QB TaxCode ID (e.g. GST/PST)
        // - US + SalesTax enabled: 'TAX' if taxable, 'NON' otherwise
        // - US + SalesTax disabled: 'NON' (QBO strips TxnTaxDetail when UsingSalesTax=false)
        $lineTaxCode = null;
        if ($isUSCompany) {
            $lineTaxCode = ($usingSalesTax && $taxAmount > 0) ? 'TAX' : 'NON';
        } else {
            $lineTaxCode = $taxCodeId;
        }

        // Build service lines + aggregate negative discounts
        $lines = [];
        $totalDiscount = 0.0;
        $discountBreakdown = [];

        foreach ($invoice->items as $item) {
            $itemAmount = (float)($item->amount ?: ($item->quantity * $item->unit_price));

            if ($itemAmount < 0 || (float)$item->unit_price < 0) {
                $absAmount = abs($itemAmount);
                $totalDiscount += $absAmount;
                $desc = $item->description ?: 'Discount';
                $discountBreakdown[] = "{$desc}: -$" . number_format($absAmount, 2);
                continue;
            }

            $service = $item->orderService?->service;
            $fallbackName = !empty($item->description) ? trim(explode("\n", $item->description)[0]) : 'Service';
            $itemId = $this->getOrCreateServiceItem($dataService, $service, $fallbackName);

            $lines[] = $this->createInvoiceLine(
                $itemId,
                $item->quantity,
                $itemAmount,
                $item->description,
                $lineTaxCode,
                false
            );
        }

        // Fallback 1: If invoice items are empty, populate from order services
        if (empty($lines) && $invoice->order && ($invoice->order->relationLoaded('services') ? $invoice->order->services->isNotEmpty() : ($invoice->order?->services()?->exists()))) {
            $orderServices = $invoice->order->relationLoaded('services') ? $invoice->order->services : $invoice->order->services()->with('service')->get();
            $serviceCount = max(1, $orderServices->count());
            foreach ($orderServices as $orderService) {
                $amount = (float)($orderService->price ?? $orderService->amount ?? ($invoice->total ? round($invoice->total / $serviceCount, 2) : 0));
                $service = $orderService->service;
                $serviceName = $service?->name ?? 'Service';

                if ($amount < 0) {
                    $absAmount = abs($amount);
                    $totalDiscount += $absAmount;
                    $discountBreakdown[] = "{$serviceName}: -$" . number_format($absAmount, 2);
                    continue;
                }

                $itemId = $this->getOrCreateServiceItem($dataService, $service, $serviceName);
                $lines[] = $this->createInvoiceLine(
                    $itemId,
                    1,
                    $amount,
                    $serviceName . ($invoice->order_id ? " (Order #{$invoice->order_id})" : ""),
                    $lineTaxCode,
                    false
                );
            }
        }

        // Fallback 2: General service line if order/invoice still has no positive lines
        if (empty($lines)) {
            $fallbackItemId = $this->getOrCreateServiceItem($dataService, null, 'General Services');
            $fallbackDesc = !empty($invoice->order_id)
                ? "Services for Order #{$invoice->order_id}"
                : "Services for Invoice #{$invoice->invoice_number}";
            $fallbackAmt = (float)($invoice->subtotal ?: ($invoice->total ?: ($invoice->paid_amount ?: 0)));

            $lines[] = $this->createInvoiceLine(
                $fallbackItemId,
                1,
                $fallbackAmt,
                $fallbackDesc,
                $lineTaxCode,
                false
            );
        }

        // Compile PrivateNote with Order, Property, Discount & Tax breakdown
        $privateNoteParts = [];
        $privateNoteParts[] = "Order #{$invoice->order_id} - Property: " . ($invoice->order->property->address ?? 'N/A');

        if (!empty($discountBreakdown)) {
            $privateNoteParts[] = "Discounts: " . implode(" | ", $discountBreakdown);
        }

        $taxBreakdownParts = [];
        if (!empty($invoice->tax_details) && is_array($invoice->tax_details)) {
            foreach ($invoice->tax_details as $tName => $tInfo) {
                $rateStr = isset($tInfo['rate']) ? " ({$tInfo['rate']}%)" : "";
                $amtStr = isset($tInfo['amount']) ? "$" . number_format((float)$tInfo['amount'], 2) : "";
                $taxBreakdownParts[] = trim("{$tName}{$rateStr}: {$amtStr}");
            }
            if (!empty($taxBreakdownParts)) {
                $privateNoteParts[] = "Taxes: " . implode(" | ", $taxBreakdownParts);
            }
        }

        $taxableNet = max(0, (float)$invoice->subtotal - $totalDiscount);
        $invoicePayload = [
            'CustomerRef' => ['value' => $customerId],
            'DocNumber'   => $this->formatDocNumber($invoice->invoice_number),
            'TxnDate'     => $invoice->issued_at?->format('Y-m-d') ?? now()->format('Y-m-d'),
            'PrivateNote' => implode("\n", $privateNoteParts),
        ];

        $useNativeTaxBox = !$isUSCompany || $usingSalesTax;

        if ($useNativeTaxBox) {
            if ($totalDiscount > 0) {
                $lines[] = $this->createDiscountLine($totalDiscount, 'Total Discounts');
            }

            if ($isUSCompany) {
                // US QBO with Sales Tax enabled: override AST using a user-defined TaxCode + TotalTax (no TaxLine)
                $txnTaxDetail = null;
                if ($taxAmount > 0) {
                    $customTax = $this->resolveOrCreateUSCustomTaxCode($dataService, $invoice, $org);
                    if ($customTax) {
                        $txnTaxDetail = [
                            'TxnTaxCodeRef' => [
                                'value' => $customTax['id'],
                                'name'  => $customTax['name'],
                            ],
                            'TotalTax' => $taxAmount,
                        ];
                    } else {
                        Log::warning("QB US company ({$org?->qb_realm_id}) has UsingSalesTax=true but no USER_DEFINED TaxCode could be resolved; falling back to Sales Tax line.");
                        $useNativeTaxBox = false;
                    }
                }
            } else {
                // Canadian GTM: full TxnTaxDetail with TaxCodeRef + TaxLine breakdown
                $txnTaxDetail = $this->buildTxnTaxDetail($dataService, $invoice, $taxCodeId, $taxableNet);
            }

            if ($useNativeTaxBox) {
                $invoicePayload['Line'] = $lines;
                $invoicePayload['GlobalTaxCalculation'] = 'TaxExcluded';
                $invoicePayload['ApplyTaxAfterDiscount'] = 'true';
                if (!empty($txnTaxDetail)) {
                    $invoicePayload['TxnTaxDetail'] = $txnTaxDetail;
                }
                return $invoicePayload;
            }
        }

        // Fallback for US QBO when UsingSalesTax=false (Intuit silently strips all TxnTaxDetail
        // when Sales Tax is turned off in QBO settings). Force service lines NON and add one
        // reusable 'Sales Tax' line item so QB invoice TotalAmt and Balance match BCF exactly.
        if ($taxAmount > 0) {
            $realmId = $org?->qb_realm_id ?? 'unknown';
            Log::warning("QB realm {$realmId} has UsingSalesTax=false in QBO Preferences; Intuit strips TxnTaxDetail unless Sales Tax is enabled in QB UI or a Canadian QB account is connected. Using 'Sales Tax' line so total matches BCF.");

            foreach ($lines as $line) {
                if ($line instanceof IPPLine && $line->DetailType === 'SalesItemLineDetail' && $line->SalesItemLineDetail) {
                    $line->SalesItemLineDetail->TaxCodeRef = new IPPReferenceType(['value' => 'NON']);
                }
            }

            $taxItemId = $this->getOrCreateServiceItem($dataService, null, 'Sales Tax');
            $taxLineDesc = !empty($taxBreakdownParts) ? implode(' | ', $taxBreakdownParts) : 'Sales Tax';
            $lines[] = $this->createInvoiceLine(
                $taxItemId,
                1,
                $taxAmount,
                $taxLineDesc,
                'NON',
                false
            );
        }

        if ($totalDiscount > 0) {
            // Re-ensure DiscountLineDetail sits cleanly after all SalesItemLineDetail lines (prevent duplicates)
            $lines = array_values(array_filter($lines, fn($l) => !($l instanceof IPPLine && $l->DetailType === 'DiscountLineDetail')));
            $lines[] = $this->createDiscountLine($totalDiscount, 'Total Discounts');
        }

        $invoicePayload['Line'] = $lines;
        return $invoicePayload;
    }

    /**
     * Extract human-readable error message from a failed QuickBooks SDK operation.
     */
    protected function extractQBErrorMessage($dataService, $qbResult): string
    {
        $error = $dataService->getLastError();
        $sdkMsg = ($qbResult instanceof \Exception) ? $qbResult->getMessage() : null;
        $errorBody = $error?->getResponseBody();

        $detailMsg = null;
        if ($errorBody) {
            if (preg_match('/<Detail>(.*?)<\/Detail>/i', $errorBody, $m)) {
                $detailMsg = $m[1];
            } elseif (preg_match('/<Message>(.*?)<\/Message>/i', $errorBody, $m)) {
                $detailMsg = $m[1];
            }
        }

        return $detailMsg ?: ($errorBody ?: ($sdkMsg ?: 'Unknown QuickBooks API error'));
    }

    /**
     * Clear stale customer reference if QuickBooks reports "Names element id X not found".
     */
    protected function handleInvalidCustomerError(string $resolvedError, \App\Models\Invoice $invoice, ?Organization $org): void
    {
        if (stripos($resolvedError, 'Names element id') !== false && stripos($resolvedError, 'not found') !== false && $invoice->agent) {
            $realmKey = $org?->qb_realm_id ?? 'default';
            Cache::forget("qb_cust_verified_{$realmKey}_{$invoice->agent->id}_{$invoice->agent->quickbooks_customer_id}");
            $invoice->agent->update(['quickbooks_customer_id' => null]);
        }
    }

    /**
     * Clear cached QuickBooks company, tax, and account metadata for an organization/realm.
     */
    public function clearCompanyMetadataCache(?Organization $org = null, ?string $realmId = null): void
    {
        $orgId = $org?->id ?? 'default';
        $realmKey = $realmId ?? ($org?->qb_realm_id ?? 'default');

        $keys = [
            "qb_is_us_company_{$orgId}",
            "qb_is_us_company_{$orgId}_{$realmKey}",
            "qb_using_sales_tax_{$orgId}_{$realmKey}",
            "qb_company_name_{$orgId}_{$realmKey}",
            "qb_tax_codes_{$orgId}",
            "qb_tax_codes_{$orgId}_{$realmKey}",
            "qb_tax_rates_{$orgId}",
            "qb_tax_rates_{$orgId}_{$realmKey}",
            "qb_income_account_id_{$orgId}",
            "qb_income_account_id_{$orgId}_{$realmKey}",
            "qb_expense_account_id_{$orgId}",
            "qb_expense_account_id_{$orgId}_{$realmKey}",
            "qb_bank_account_id_{$orgId}",
            "qb_bank_account_id_{$orgId}_{$realmKey}",
        ];

        foreach ($keys as $key) {
            Cache::forget($key);
        }
    }

    /**
     * Execute an idempotent QB read/query call with a short retry on transient cURL timeouts.
     */
    protected function callReadWithRetry(callable $fn, string $operationName, int $maxAttempts = 2)
    {
        $lastException = null;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $fn();
            } catch (\Throwable $e) {
                $lastException = $e;
                $msg = $e->getMessage();
                $isTransient = stripos($msg, 'cURL error') !== false || stripos($msg, 'timed out') !== false;
                if (!$isTransient || $attempt >= $maxAttempts) {
                    throw $e;
                }
                Log::warning("QB transient timeout during {$operationName} (attempt {$attempt}/{$maxAttempts}), retrying: {$msg}");
                usleep(250000);
            }
        }
        throw $lastException ?? new \Exception("QB operation {$operationName} failed");
    }

    /**
     * Detect connected QB company country and Sales Tax preference safely without poisoning cache on errors.
     */
    protected function getCompanyCapabilities($dataService, ?Organization $org = null): array
    {
        $orgCacheId = $org?->id ?? ($this->organization?->id ?? 'default');
        $realmKey = $org?->qb_realm_id ?? ($this->organization?->qb_realm_id ?? 'default');

        $usKey = "qb_is_us_company_{$orgCacheId}_{$realmKey}";
        $taxKey = "qb_using_sales_tax_{$orgCacheId}_{$realmKey}";

        $isUS = Cache::get($usKey);
        if ($isUS === null) {
            try {
                $companyInfo = $this->callReadWithRetry(fn() => $dataService->getCompanyInfo(), 'getCompanyInfo');
                if ($companyInfo && !empty($companyInfo->Country)) {
                    $isUS = strcasecmp($companyInfo->Country, 'US') === 0;
                    Cache::put($usKey, $isUS, 3600);
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to detect QB company country: ' . $e->getMessage());
            }
        }

        $usingSalesTax = Cache::get($taxKey);
        if ($usingSalesTax === null) {
            try {
                $prefs = $this->callReadWithRetry(fn() => $dataService->getCompanyPreferences(), 'getCompanyPreferences');
                if ($prefs && isset($prefs->TaxPrefs)) {
                    $raw = $prefs->TaxPrefs->UsingSalesTax ?? false;
                    $usingSalesTax = ($raw === true || $raw === 'true' || $raw === 1 || $raw === '1');
                    Cache::put($taxKey, $usingSalesTax, 300);
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to detect QB UsingSalesTax preference: ' . $e->getMessage());
            }
        }

        return [
            // Default safely: if US detection timed out twice, assume false (or true? wait: never cache error)
            'is_us' => (bool)($isUS ?? false),
            'using_sales_tax' => (bool)($usingSalesTax ?? false),
        ];
    }

    /**
     * Find an existing user-defined QB TaxCode suitable for overriding US AST TxnTaxDetail.
     */
    protected function resolveOrCreateUSCustomTaxCode($dataService, \App\Models\Invoice $invoice, ?Organization $org = null): ?array
    {
        $orgCacheId = $org?->id ?? ($this->organization?->id ?? ($invoice->organization_id ?? 'default'));
        $realmKey = $org?->qb_realm_id ?? ($this->organization?->qb_realm_id ?? 'default');
        $cacheKey = "qb_tax_codes_{$orgCacheId}_{$realmKey}";

        try {
            $taxCodes = Cache::get($cacheKey);
            if (empty($taxCodes)) {
                $queried = $this->callReadWithRetry(
                    fn() => $dataService->Query("SELECT * FROM TaxCode WHERE Active = true"),
                    'Query(TaxCode)'
                );
                if (!empty($queried) && is_array($queried)) {
                    $taxCodes = $queried;
                    Cache::put($cacheKey, $taxCodes, 3600);
                }
            }

            if (!empty($taxCodes) && is_array($taxCodes)) {
                foreach ($taxCodes as $tc) {
                    $idStr = (string)($tc->Id ?? '');
                    $nameStr = (string)($tc->Name ?? '');
                    // Never use US system-reserved codes for TxnTaxCodeRef
                    if (in_array(strtoupper($idStr), ['TAX', 'NON', 'CUSTOMSALESTAX'], true)) {
                        continue;
                    }
                    if (!($tc->Taxable === true || $tc->Taxable === 'true')) {
                        continue;
                    }
                    return ['id' => $idStr, 'name' => $nameStr ?: $idStr];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to resolve US custom TaxCode: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Build native QuickBooks TxnTaxDetail for Canadian Global Tax Model
     */
    protected function buildTxnTaxDetail($dataService, \App\Models\Invoice $invoice, ?string $taxCodeId, float $taxableNet = 0): ?array
    {
        $taxAmount = (float)$invoice->tax_amount;
        if ($taxAmount <= 0 && empty($taxCodeId)) {
            return null;
        }

        $taxDetail = [
            'TotalTax' => round($taxAmount, 2),
        ];

        if ($taxCodeId) {
            $taxDetail['TxnTaxCodeRef'] = ['value' => $taxCodeId];
        }

        // If invoice has detailed tax breakdown (e.g. GST and PST), build native TaxLine entries
        if (!empty($invoice->tax_details) && is_array($invoice->tax_details)) {
            $taxLines = [];
            $orgCacheId = $this->organization?->id ?? ($invoice->organization_id ?? 'default');
            $realmKey = $this->organization?->qb_realm_id ?? 'default';
            $rateCacheKey = "qb_tax_rates_{$orgCacheId}_{$realmKey}";

            $qbTaxRates = Cache::get($rateCacheKey);
            if (empty($qbTaxRates)) {
                try {
                    $rates = $this->callReadWithRetry(
                        fn() => $dataService->Query("SELECT * FROM TaxRate WHERE Active = true"),
                        'Query(TaxRate)'
                    );
                    if (!empty($rates) && is_array($rates)) {
                        $qbTaxRates = $rates;
                        Cache::put($rateCacheKey, $qbTaxRates, 3600);
                    } else {
                        $qbTaxRates = [];
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to query QB TaxRates for TaxLine building: ' . $e->getMessage());
                    $qbTaxRates = [];
                }
            }

            foreach ($invoice->tax_details as $taxName => $taxInfo) {
                $lineAmt = round((float)($taxInfo['amount'] ?? 0), 2);
                $lineRate = (float)($taxInfo['rate'] ?? 0);
                if ($lineAmt <= 0) continue;

                $taxRateRef = null;
                foreach ($qbTaxRates as $tr) {
                    if (stripos($tr->Name, $taxName) !== false || (abs((float)$tr->RateValue - $lineRate) < 0.01)) {
                        $taxRateRef = (string)$tr->Id;
                        break;
                    }
                }

                // Intuit requires TaxRateRef on every TaxLineDetail; skip TaxLine if TaxRateRef could not be resolved
                if ($taxRateRef) {
                    $effectiveBase = ($lineRate > 0)
                        ? min(round($taxableNet, 2), round($lineAmt / ($lineRate / 100), 2))
                        : round($taxableNet, 2);

                    $taxLines[] = [
                        'DetailType' => 'TaxLineDetail',
                        'Amount' => $lineAmt,
                        'TaxLineDetail' => [
                            'TaxRateRef' => ['value' => $taxRateRef],
                            'PercentBased' => 'true',
                            'TaxPercent' => $lineRate,
                            'NetAmountTaxable' => $effectiveBase,
                        ],
                    ];
                }
            }

            if (!empty($taxLines)) {
                $taxDetail['TaxLine'] = $taxLines;
            }
        }

        return $taxDetail;
    }

    /**
     * ✅ NEW: Resolve QuickBooks TaxCode ID from Invoice tax_details
     */
    protected function resolveTaxCodeId($dataService, \App\Models\Invoice $invoice, bool $isUSCompany = false): ?string
    {
        if (empty($invoice->tax_details) || !is_array($invoice->tax_details)) return null;

        if ($isUSCompany) {
            return 'TAX';
        }

        $orgUuid = $invoice->order?->organization?->uuid ?? null;
        $mappings = null;
        if ($orgUuid) {
            $mappings = \App\Models\Setting::where('org_id', $orgUuid)->where('key', 'quickbooks_tax_mappings')->first();
        }

        $taxNames = array_keys($invoice->tax_details);
        $primaryTaxName = $taxNames[0] ?? null;

        $combinedName = implode('/', $taxNames);
        if ($mappings && is_array($mappings->value)) {
            if (isset($mappings->value[$combinedName])) {
                return $mappings->value[$combinedName];
            }
            if ($primaryTaxName && isset($mappings->value[$primaryTaxName])) {
                return $mappings->value[$primaryTaxName];
            }
        }

        try {
            $orgCacheId = $this->organization?->id ?? ($invoice->organization_id ?? 'default');
            $realmKey = $this->organization?->qb_realm_id ?? 'default';
            $cacheKey = "qb_tax_codes_{$orgCacheId}_{$realmKey}";

            $taxCodes = Cache::get($cacheKey);
            if (empty($taxCodes)) {
                $queried = $this->callReadWithRetry(
                    fn() => $dataService->Query("SELECT * FROM TaxCode WHERE Active = true"),
                    'Query(TaxCode)'
                );
                if (!empty($queried) && is_array($queried)) {
                    $taxCodes = $queried;
                    Cache::put($cacheKey, $taxCodes, 3600);
                }
            }
            if (!empty($taxCodes) && is_array($taxCodes)) {
                if (count($taxNames) > 1) {
                    foreach ($taxCodes as $tc) {
                        $allMatch = true;
                        foreach ($taxNames as $tName) {
                            if (stripos($tc->Name, $tName) === false) {
                                $allMatch = false;
                                break;
                            }
                        }
                        if ($allMatch) {
                            return (string)$tc->Id;
                        }
                    }
                }

                if ($primaryTaxName) {
                    foreach ($taxCodes as $tc) {
                        if (stripos($tc->Name, $primaryTaxName) !== false) {
                            return (string)$tc->Id;
                        }
                    }
                }

                foreach ($taxCodes as $tc) {
                    if (strcasecmp($tc->Name, 'TAX') === 0) {
                        return (string)$tc->Id;
                    }
                }
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
        // ONE MAIN INVOICE PER ORDER MODEL:
        // If this is an individual service invoice, resolve to the Order's Main Invoice
        if ($invoice->order_id && $invoice->notes && stripos($invoice->notes, 'service invoice') !== false) {
            $mainInvoice = \App\Models\Invoice::with(['order.organization', 'agent', 'order.property'])
                ->where('order_id', $invoice->order_id)
                ->where(function ($q) {
                    $q->where('notes', 'ilike', '%consolidated%')
                      ->orWhere('notes', 'ilike', '%add-on service invoice%');
                })
                ->first();

            if (!$mainInvoice) {
                $mainInvoice = \App\Models\Invoice::with(['order.organization', 'agent', 'order.property'])
                    ->where('order_id', $invoice->order_id)
                    ->where('agent_type', 'primary')
                    ->where(function ($q) {
                        $q->whereNull('notes')
                          ->orWhere('notes', 'not ilike', '%service invoice%');
                    })
                    ->first();
            }

            if ($mainInvoice) {
                Log::info("QB refund: resolving service invoice #{$invoice->id} to Order Main Invoice #{$mainInvoice->id}");
                $invoice = $mainInvoice;
            }
        }

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

            $org = $invoice->organization 
                ?? $invoice->order?->organization 
                ?? ($invoice->organization_id ? \App\Models\Organization::find($invoice->organization_id) : null);
            $dataService = $this->getDataService($org);
            $customerId = $this->getOrCreateCustomer($invoice->agent, $org);

            // Dynamically get or create a valid service item for refunds so ItemRef is never null
            $refundItemId = $this->getOrCreateServiceItem($dataService, null, 'Refund / Adjustment');

            $caps = $this->getCompanyCapabilities($dataService, $org);
            $isUSCompany = $caps['is_us'];
            $usingSalesTax = $caps['using_sales_tax'];

            // Resolve tax code if the refunded invoice had tax
            $taxCodeId = $this->resolveTaxCodeId($dataService, $invoice, $isUSCompany);

            // Build CreditMemo directly with IPP objects to avoid FacadeHelper ReferenceType issues
            $creditMemoObj = new \QuickBooksOnline\API\Data\IPPCreditMemo();
            $creditMemoObj->CustomerRef = new IPPReferenceType(['value' => (string)$customerId]);
            $creditMemoObj->TotalAmt = round($amount, 2);
            $creditMemoObj->PrivateNote = "Refund for Invoice #{$invoice->invoice_number}. Property: " . ($invoice->order->property->address ?? 'N/A');

            $cmLine = new IPPLine();
            $cmLine->DetailType = 'SalesItemLineDetail';
            $cmSalesDetail = new IPPSalesItemLineDetail();
            $cmSalesDetail->ItemRef = new IPPReferenceType(['value' => (string)$refundItemId]);

            if ($isUSCompany) {
                // US QB: use NON so AST doesn't alter the exact refund amount; no TxnTaxDetail
                $cmSalesDetail->TaxCodeRef = new IPPReferenceType(['value' => 'NON']);
                $cmLine->Amount = round($amount, 2);
            } else {
                // Canadian QB: attach TxnTaxDetail with actual tax code
                if ($taxCodeId) {
                    $txnTax = new \QuickBooksOnline\API\Data\IPPTxnTaxDetail();
                    $txnTax->TxnTaxCodeRef = new IPPReferenceType(['value' => (string)$taxCodeId]);
                    $creditMemoObj->TxnTaxDetail = $txnTax;
                    $cmSalesDetail->TaxCodeRef = new IPPReferenceType(['value' => (string)$taxCodeId]);
                }
                $cmLine->Amount = round($amount, 2);
            }

            $cmLine->SalesItemLineDetail = $cmSalesDetail;
            $creditMemoObj->Line = [$cmLine];

            $qbCreditMemo = $dataService->Add($creditMemoObj);

            if (!$qbCreditMemo || $qbCreditMemo instanceof \Exception) {
                $error = $dataService->getLastError();
                $sdkMsg = ($qbCreditMemo instanceof \Exception) ? $qbCreditMemo->getMessage() : null;
                throw new \Exception('QB CreditMemo creation failed: ' . ($sdkMsg ?? $error?->getResponseBody() ?? 'Unknown'));
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
                'attempts' => $log->attempts + 1,
            ]);
            Log::error('QuickBooks Refund Sync Error: ' . $e->getMessage());
            return null;
        }
    }
    /**
     * ✅ Sync Vendor Invoice as Bill
     */
    public function syncVendorInvoiceToQB(\App\Models\VendorInvoice $vInvoice): ?string
    {
        // Idempotency guard: Return early if bill already created in QB
        if ($vInvoice->quickbooks_bill_id) {
            Log::info("Vendor invoice {$vInvoice->id} already synced to QB as Bill #{$vInvoice->quickbooks_bill_id}");
            return $vInvoice->quickbooks_bill_id;
        }

        $log = QbSyncLog::create([
            'organization_id' => $vInvoice->vendor?->organization_id ?? $vInvoice->organization_id,
            'entity_type' => 'vendor_invoice',
            'entity_id' => $vInvoice->id,
            'action' => 'create_bill',
            'status' => 'pending',
            'request_id' => (string) Str::uuid(),
        ]);

        try {
            $vendor = $vInvoice->vendor ?: ($vInvoice->vendor_id ? \App\Models\Vendor::where('uuid', $vInvoice->vendor_id)->first() : null);
            if (!$vendor) {
                throw new \Exception("Cannot sync bill to QB: Vendor not found for vendor invoice #{$vInvoice->id}.");
            }

            $org = $vendor->organization ?? $vInvoice->organization ?? null;
            $dataService = $this->getDataService($org);
            $vendorId = $this->getOrCreateVendor($vendor, $org);

            // Dynamic expense account discovery
            $expenseAccountId = $this->getExpenseAccountId($dataService);

            $lines = [];
            foreach ($vInvoice->lines as $line) {
                $lines[] = [
                    'Description' => $line->description ?: 'Vendor service item',
                    'Amount' => round((float)$line->amount, 2),
                    'DetailType' => 'AccountBasedExpenseLineDetail',
                    'AccountBasedExpenseLineDetail' => [
                        'AccountRef' => ['value' => $expenseAccountId],
                    ],
                ];
            }

            // Handle Tax separately as an expense line if applicable
            if ((float)$vInvoice->tax_amount > 0) {
                $taxDesc = 'Tax';
                if (!empty($vInvoice->tax_type)) {
                    $taxDesc .= ' (' . $vInvoice->tax_type . ')';
                }
                $lines[] = [
                    'Description' => $taxDesc,
                    'Amount' => round((float)$vInvoice->tax_amount, 2),
                    'DetailType' => 'AccountBasedExpenseLineDetail',
                    'AccountBasedExpenseLineDetail' => [
                        'AccountRef' => ['value' => $expenseAccountId],
                    ],
                ];
            }

            if (empty($lines)) {
                $lines[] = [
                    'Description' => "Vendor Invoice for Cycle: " . ($vInvoice->cycle_start?->format('Y-m-d') ?? 'N/A') . " to " . ($vInvoice->cycle_end?->format('Y-m-d') ?? 'N/A'),
                    'Amount' => round((float)($vInvoice->total_amount ?: 0), 2),
                    'DetailType' => 'AccountBasedExpenseLineDetail',
                    'AccountBasedExpenseLineDetail' => [
                        'AccountRef' => ['value' => $expenseAccountId],
                    ],
                ];
            }

            $docNumber = $this->formatDocNumber($vInvoice->invoice_number);
            $txnDate = $vInvoice->paid_at?->format('Y-m-d')
                ?? $vInvoice->created_at?->format('Y-m-d')
                ?? $vInvoice->cycle_end?->format('Y-m-d')
                ?? now()->format('Y-m-d');

            $billData = [
                'VendorRef' => ['value' => $vendorId],
                'Line' => $lines,
                'DocNumber' => $docNumber,
                'TxnDate' => $txnDate,
                'PrivateNote' => "Vendor Invoice for Cycle: " . ($vInvoice->cycle_start?->format('Y-m-d') ?? 'N/A') . " to " . ($vInvoice->cycle_end?->format('Y-m-d') ?? 'N/A'),
            ];

            $log->update([
                'payload' => $billData,
                'qb_doc_number' => $docNumber,
            ]);

            $billObj = Bill::create($billData);
            $qbBill = $dataService->Add($billObj);

            if (!$qbBill || $qbBill instanceof \Exception) {
                $error = $dataService->getLastError();
                $sdkMsg = ($qbBill instanceof \Exception) ? $qbBill->getMessage() : null;
                throw new \Exception('QB Bill creation failed: ' . ($sdkMsg ?? $error?->getResponseBody() ?? 'Unknown'));
            }

            $vInvoice->update([
                'quickbooks_bill_id' => $qbBill->Id,
                'quickbooks_synced_at' => now(),
            ]);

            $log->update([
                'status' => 'success',
                'qb_entity_id' => $qbBill->Id,
                'qb_doc_number' => $docNumber,
                'response' => (array)$qbBill,
            ]);

            return $qbBill->Id;

        } catch (\Exception $e) {
            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'attempts' => $log->attempts + 1,
            ]);
            Log::error('QuickBooks Bill Sync Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * ✅ Sync Vendor Payout as BillPayment
     */
    public function syncVendorPayoutToQB(\App\Models\VendorInvoice $vInvoice): ?string
    {
        // Idempotency guard: Return early if BillPayment already exists in QB
        if ($vInvoice->quickbooks_bill_payment_id) {
            Log::info("Vendor invoice {$vInvoice->id} already synced to QB as BillPayment #{$vInvoice->quickbooks_bill_payment_id}");
            return $vInvoice->quickbooks_bill_payment_id;
        }

        $log = QbSyncLog::create([
            'organization_id' => $vInvoice->vendor?->organization_id ?? $vInvoice->organization_id,
            'entity_type' => 'vendor_invoice',
            'entity_id' => $vInvoice->id,
            'action' => 'pay_bill',
            'status' => 'pending',
            'request_id' => (string) Str::uuid(),
        ]);

        try {
            // Ensure Bill exists first
            if (!$vInvoice->quickbooks_bill_id) {
                $billId = $this->syncVendorInvoiceToQB($vInvoice);
                if (!$billId) {
                    throw new \Exception('Failed to sync bill before payment.');
                }
                $vInvoice->refresh();
            }

            $vendor = $vInvoice->vendor ?: ($vInvoice->vendor_id ? \App\Models\Vendor::where('uuid', $vInvoice->vendor_id)->first() : null);
            if (!$vendor) {
                throw new \Exception("Cannot sync payout to QB: Vendor not found for vendor invoice #{$vInvoice->id}.");
            }

            $org = $vendor->organization ?? $vInvoice->organization ?? null;
            $dataService = $this->getDataService($org);
            $vendorId = $this->getOrCreateVendor($vendor, $org);
            $bankAccountId = $this->getBankAccountId($dataService);

            $linkedTxn = new IPPLinkedTxn();
            $linkedTxn->TxnId = (string)$vInvoice->quickbooks_bill_id;
            $linkedTxn->TxnType = 'Bill';

            $totalAmt = round((float)$vInvoice->total_amount, 2);
            $txnDate = $vInvoice->paid_at?->format('Y-m-d') ?? now()->format('Y-m-d');
            $docNumber = $this->formatDocNumber($vInvoice->invoice_number);

            $privateNote = $vInvoice->stripe_transfer_id === 'manual'
                ? "Manual Vendor Payout - {$vInvoice->invoice_number}"
                : "Stripe Transfer: " . ($vInvoice->stripe_transfer_id ?? 'N/A') . " - {$vInvoice->invoice_number}";

            $paymentData = [
                'VendorRef' => ['value' => $vendorId],
                'TotalAmt' => $totalAmt,
                'PayType' => 'Check',
                'TxnDate' => $txnDate,
                'CheckPayment' => [
                    'BankAccountRef' => ['value' => $bankAccountId],
                ],
                'Line' => [
                    [
                        'Amount' => $totalAmt,
                        'LinkedTxn' => [$linkedTxn],
                    ]
                ],
                'PrivateNote' => $privateNote,
            ];

            $log->update([
                'payload' => $paymentData,
                'qb_doc_number' => $docNumber,
            ]);

            $billPaymentObj = BillPayment::create($paymentData);
            $qbBillPayment = $dataService->Add($billPaymentObj);

            if (!$qbBillPayment || $qbBillPayment instanceof \Exception) {
                $error = $dataService->getLastError();
                $sdkMsg = ($qbBillPayment instanceof \Exception) ? $qbBillPayment->getMessage() : null;
                throw new \Exception('QB BillPayment failed: ' . ($sdkMsg ?? $error?->getResponseBody() ?? 'Unknown'));
            }

            $vInvoice->update([
                'quickbooks_bill_payment_id' => $qbBillPayment->Id,
                'quickbooks_synced_at' => now(),
            ]);

            $log->update([
                'status' => 'success',
                'qb_entity_id' => $qbBillPayment->Id,
                'qb_doc_number' => $docNumber,
                'response' => (array)$qbBillPayment,
            ]);

            return $qbBillPayment->Id;

        } catch (\Exception $e) {
            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'attempts' => $log->attempts + 1,
            ]);
            Log::error('QuickBooks BillPayment Sync Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * ✅ Ensure Vendor exists in QuickBooks
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
        $email = trim($vendor->email ?? '');
        $displayName = trim($vendor->business_name ?: ($vendor->first_name . ' ' . $vendor->last_name));
        if (empty($displayName)) {
            $displayName = "Vendor #{$vendor->id}";
        }

        // Search by email if email is provided
        if (!empty($email)) {
            $escapedEmail = str_replace("'", "''", $email);
            $vendors = $dataService->Query("SELECT * FROM Vendor WHERE PrimaryEmailAddr = '{$escapedEmail}'");
            if (!empty($vendors)) {
                $vendor->update([
                    'quickbooks_vendor_id' => $vendors[0]->Id,
                    'quickbooks_synced_at' => now(),
                ]);
                return $vendors[0]->Id;
            }
        }

        // Search by DisplayName
        $escapedName = str_replace("'", "''", $displayName);
        $vendors = $dataService->Query("SELECT * FROM Vendor WHERE DisplayName = '{$escapedName}'");
        if (!empty($vendors)) {
            $vendor->update([
                'quickbooks_vendor_id' => $vendors[0]->Id,
                'quickbooks_synced_at' => now(),
            ]);
            return $vendors[0]->Id;
        }

        // Create new Vendor
        $vendorData = [
            'DisplayName' => $displayName,
            'GivenName' => $vendor->first_name,
            'FamilyName' => $vendor->last_name,
            'CompanyName' => $vendor->business_name,
        ];
        if (!empty($email)) {
            $vendorData['PrimaryEmailAddr'] = ['Address' => $email];
        }

        $vendorObj = Vendor::create($vendorData);
        $qbVendor = $dataService->Add($vendorObj);

        if (!$qbVendor || $qbVendor instanceof \Exception) {
            $error = $dataService->getLastError();
            $sdkMsg = ($qbVendor instanceof \Exception) ? $qbVendor->getMessage() : null;
            $errorBody = $sdkMsg ?? ($error ? $error->getResponseBody() : '');

            // Check if duplicate name exists error (code 6240 or message)
            if ($errorBody && (strpos($errorBody, 'Duplicate Name Exists Error') !== false || strpos($errorBody, '6240') !== false)) {
                $existingVendors = $dataService->Query("SELECT * FROM Vendor WHERE DisplayName = '{$escapedName}'");
                if (!empty($existingVendors)) {
                    $vendor->update([
                        'quickbooks_vendor_id' => $existingVendors[0]->Id,
                        'quickbooks_synced_at' => now(),
                    ]);
                    return $existingVendors[0]->Id;
                }

                // If not found in Vendor, name belongs to a Customer/Employee; disambiguate
                $disambiguatedName = "{$displayName} (Vendor #{$vendor->id})";
                $vendorData['DisplayName'] = $disambiguatedName;
                $vendorObj = Vendor::create($vendorData);
                $qbVendor = $dataService->Add($vendorObj);

                if ($qbVendor && !($qbVendor instanceof \Exception)) {
                    $vendor->update([
                        'quickbooks_vendor_id' => $qbVendor->Id,
                        'quickbooks_synced_at' => now(),
                    ]);
                    return $qbVendor->Id;
                }

                $error = $dataService->getLastError();
                $sdkMsg = ($qbVendor instanceof \Exception) ? $qbVendor->getMessage() : null;
                $errorBody = $sdkMsg ?? ($error ? $error->getResponseBody() : '');
            }

            throw new \Exception('Failed to create QB Vendor: ' . ($errorBody ?: 'Unknown'));
        }

        $vendor->update([
            'quickbooks_vendor_id' => $qbVendor->Id,
            'quickbooks_synced_at' => now(),
        ]);
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
     * Synchronize company name, contact phone, website, and billing address to an existing QB customer if missing.
     */
    protected function syncCustomerDetailsIfMissing($dataService, $qbCustomer, Agent $agent): void
    {
        $needsUpdate = false;
        $updatePayload = [];

        if (empty($qbCustomer->CompanyName) && !empty($agent->company_name)) {
            $updatePayload['CompanyName'] = $agent->company_name;
            $needsUpdate = true;
        }

        $phone = $agent->primary_phone ?? $agent->phone ?? null;
        if (empty($qbCustomer->PrimaryPhone) && !empty($phone)) {
            $updatePayload['PrimaryPhone'] = ['FreeFormNumber' => $phone];
            $needsUpdate = true;
        }

        if (empty($qbCustomer->Mobile) && !empty($agent->secondary_phone)) {
            $updatePayload['Mobile'] = ['FreeFormNumber' => $agent->secondary_phone];
            $needsUpdate = true;
        }

        if (empty($qbCustomer->WebAddr) && !empty($agent->website)) {
            $updatePayload['WebAddr'] = ['URI' => $agent->website];
            $needsUpdate = true;
        }

        $address = $agent->headquarter_address ?? $agent->address ?? null;
        if (empty($qbCustomer->BillAddr) && !empty($address)) {
            $updatePayload['BillAddr'] = [
                'Line1' => $address,
                'City' => $agent->city ?? '',
                'Country' => $agent->country ?? 'Canada',
            ];
            $needsUpdate = true;
        }

        if ($needsUpdate) {
            try {
                $updateObj = Customer::update($qbCustomer, $updatePayload);
                $dataService->Update($updateObj);
                Log::info("QB customer #{$qbCustomer->Id} updated with company/contact details");
            } catch (\Throwable $e) {
                Log::warning("Failed to update QB customer #{$qbCustomer->Id} with extra details: " . $e->getMessage());
            }
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
     * ✅ Get or create QuickBooks service item with DB caching & intelligent fallback
     */
    protected function getOrCreateServiceItem($dataService, ?Service $service = null, string $fallbackName = 'Service'): string
    {
        $itemName = $service ? $service->name : $fallbackName;
        $realmKey = $this->organization?->qb_realm_id ?? 'default';
        $namedCacheKey = !$service ? "qb_named_item_{$realmKey}_" . md5(strtolower($itemName)) : null;

        if ($namedCacheKey && ($cachedId = Cache::get($namedCacheKey))) {
            return (string)$cachedId;
        }

        // 1️⃣ Check if service already has QB item ID
        if ($service && $service->quickbooks_item_id) {
            $itemVerifyKey = "qb_item_verified_{$realmKey}_{$service->id}_{$service->quickbooks_item_id}";

            if (Cache::get($itemVerifyKey)) {
                return $service->quickbooks_item_id;
            }

            Log::info('Checking if cached QB item ID is valid', [
                'service_id' => $service->id,
                'qb_item_id' => $service->quickbooks_item_id,
            ]);
            try {
                $qbItem = $this->callReadWithRetry(fn() => $dataService->FindById('Item', $service->quickbooks_item_id), "FindById(Item, {$service->quickbooks_item_id})");
                if ($qbItem) {
                    Cache::put($itemVerifyKey, true, 86400);
                    return $service->quickbooks_item_id;
                }
                $service->update(['quickbooks_item_id' => null]);
            } catch (\Exception $e) {
                $errMsg = $e->getMessage();
                $isTimeout = stripos($errMsg, 'cURL error') !== false || stripos($errMsg, 'timed out') !== false;
                if ($isTimeout) {
                    Log::warning('QB item verification timed out; keeping cached item ID ' . $service->quickbooks_item_id);
                    return $service->quickbooks_item_id;
                }
                Log::warning('Cached QB item ID is invalid or not found in current QB instance. Clearing cache. Error: ' . $errMsg);
                $service->update(['quickbooks_item_id' => null]);
            }
        }

        // 2️⃣ Search QB for existing item by name (handles existing client QB setup)
        try {
            $escapedName = str_replace("'", "''", $itemName);
            $query = "SELECT * FROM Item WHERE Name = '{$escapedName}' AND Type = 'Service' MAXRESULTS 1";
            $items = $this->callReadWithRetry(fn() => $dataService->Query($query), "Query(Item {$itemName})");

            if (!empty($items) && is_array($items)) {
                $qbItem = $items[0];
                
                Log::info('Found existing QB item', [
                    'service_id' => $service?->id,
                    'name' => $itemName,
                    'qb_item_id' => $qbItem->Id,
                ]);

                if ($service) {
                    $service->update(['quickbooks_item_id' => $qbItem->Id]);
                } elseif ($namedCacheKey) {
                    Cache::put($namedCacheKey, (string)$qbItem->Id, 86400);
                }

                return $qbItem->Id;
            }
        } catch (\Exception $e) {
            Log::warning('QB item search failed: ' . $e->getMessage());
        }

        // 3️⃣ Create new QB item
        Log::info('Creating new QB item', ['item_name' => $itemName]);

        $item = Item::create([
            'Name' => $itemName,
            'Type' => 'Service',
            'IncomeAccountRef' => [
                'value' => $this->getIncomeAccountId($dataService),
            ],
            'UnitPrice' => $service?->price ?? 0,
            'Taxable' => false,
            'Active' => true,
        ]);

        $createdItem = $dataService->Add($item);

        if (!$createdItem || $createdItem instanceof \Exception) {
            $error = $dataService->getLastError();
            $sdkMsg = ($createdItem instanceof \Exception) ? $createdItem->getMessage() : null;
            $errorBody = $sdkMsg ?? ($error ? $error->getResponseBody() : '');

            // Handle duplicate name collision if it exists in QB
            if ($errorBody && (strpos($errorBody, 'Duplicate Name Exists Error') !== false || strpos($errorBody, '6240') !== false)) {
                $escapedName = str_replace("'", "''", $itemName);
                $existing = $this->callReadWithRetry(fn() => $dataService->Query("SELECT * FROM Item WHERE Name = '{$escapedName}' MAXRESULTS 1"), "Query(Item duplicate {$itemName})");
                if (!empty($existing) && is_array($existing)) {
                    if ($service) {
                        $service->update(['quickbooks_item_id' => $existing[0]->Id]);
                    } elseif ($namedCacheKey) {
                        Cache::put($namedCacheKey, (string)$existing[0]->Id, 86400);
                    }
                    return $existing[0]->Id;
                }
            }

            throw new \Exception('QB item creation failed: ' . ($errorBody ?: 'Unknown error'));
        }

        if ($service) {
            $service->update(['quickbooks_item_id' => $createdItem->Id]);
        } elseif ($namedCacheKey) {
            Cache::put($namedCacheKey, (string)$createdItem->Id, 86400);
        }

        Log::info('QB item created/resolved', [
            'service_id' => $service?->id,
            'name' => $itemName,
            'qb_item_id' => $createdItem->Id,
        ]);

        return $createdItem->Id;
    }

    /**
     * Create invoice line item with accurate quantity, price, and tax code
     */
    protected function createInvoiceLine($itemId, $quantity, $totalAmount, $description = '', ?string $taxCodeId = null, bool $isUSCompany = false)
    {
        $qty = max(1, (int)$quantity);
        $total = (float)$totalAmount;
        $unitPrice = $qty > 1 ? round($total / $qty, 2) : ($total ?: 0);

        $line = new IPPLine();
        $line->DetailType = "SalesItemLineDetail";
        $line->Description = $description;

        $salesItemLineDetail = new IPPSalesItemLineDetail();
        $salesItemLineDetail->ItemRef = new IPPReferenceType(['value' => $itemId]);
        $salesItemLineDetail->Qty = $qty;
        $salesItemLineDetail->UnitPrice = $unitPrice;

        if ($taxCodeId) {
            // For US QBO companies, line TaxCode must be 'TAX' (or 'NON'). For Canadian GTM, line uses the actual TaxCode ID.
            $lineTaxCode = ($isUSCompany && (is_numeric($taxCodeId) || strlen($taxCodeId) > 3)) ? 'TAX' : $taxCodeId;
            $salesItemLineDetail->TaxCodeRef = new IPPReferenceType(['value' => $lineTaxCode]);
        }

        $line->SalesItemLineDetail = $salesItemLineDetail;

        // Explicitly set Amount to match original line total
        $line->Amount = round($total, 2);

        return $line;
    }

    /**
     * Create a QuickBooks Discount line detail for discounts or negative adjustments
     */
    protected function createDiscountLine(float $amount, string $description = 'Discount'): IPPLine
    {
        $line = new IPPLine();
        $line->DetailType = "DiscountLineDetail";
        $line->Description = $description;
        // DiscountLine amount must be positive in QuickBooks Online
        $line->Amount = round(abs($amount), 2);

        $discountDetail = new \QuickBooksOnline\API\Data\IPPDiscountLineDetail();
        // Must be string 'false' so XSD2PHP Php2Xml serializer renders <PercentBased>false</PercentBased>
        $discountDetail->PercentBased = 'false';

        $line->DiscountLineDetail = $discountDetail;

        return $line;
    }

    /**
     * Format DocNumber to ensure it never exceeds QuickBooks limit of 21 characters.
     */
    protected function formatDocNumber(?string $docNumber): ?string
    {
        if (empty($docNumber)) {
            return null;
        }

        if (strlen($docNumber) <= 21) {
            return $docNumber;
        }

        // If prefixed with 'INV-', remove it first
        if (str_starts_with($docNumber, 'INV-')) {
            $shortened = substr($docNumber, 4);
            if (strlen($shortened) <= 21) {
                return $shortened;
            }
        }

        // If still over 21 characters, take the last 21 characters
        return substr($docNumber, -21);
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

        if (!$invoice || $invoice instanceof \Exception) {
            $error = $dataService->getLastError();
            $sdkMsg = ($invoice instanceof \Exception) ? $invoice->getMessage() : null;
            throw new \Exception('QB invoice creation failed: ' . ($sdkMsg ?? $error?->getResponseBody() ?? 'Unknown'));
        }

        return $invoice;
    }

    /**
     * Create payment for invoice (marks as PAID or partial payment)
     */
    protected function createPaymentForInvoice($dataService, $qbInvoice, float $amount, ?string $note = null)
    {
        $qbInvoiceId = is_object($qbInvoice) ? $qbInvoice->Id : (string)$qbInvoice;
        Log::info('Creating QB payment for invoice: ' . $qbInvoiceId, ['amount' => $amount]);

        $customerRefValue = null;
        if (is_object($qbInvoice)) {
            $customerRefValue = is_object($qbInvoice->CustomerRef)
                ? $qbInvoice->CustomerRef->value
                : $qbInvoice->CustomerRef;
        }

        // If customerRefValue not resolved from qbInvoice object, fetch from QB
        if (!$customerRefValue) {
            try {
                $fetched = $dataService->FindById('Invoice', $qbInvoiceId);
                if ($fetched) {
                    $customerRefValue = is_object($fetched->CustomerRef) ? $fetched->CustomerRef->value : $fetched->CustomerRef;
                }
            } catch (\Exception $e) {
                Log::warning('Failed to fetch QB Invoice for customer ref: ' . $e->getMessage());
            }
        }

        $linkedTxn = new IPPLinkedTxn();
        $linkedTxn->TxnId = (string)$qbInvoiceId;
        $linkedTxn->TxnType = 'Invoice';

        $paymentObj = Payment::create([
            'CustomerRef' => [
                'value' => (string)$customerRefValue,
            ],
            'TotalAmt' => round($amount, 2),
            'TxnDate' => now()->format('Y-m-d'),
            'Line' => [[
                'Amount' => round($amount, 2),
                'LinkedTxn' => [$linkedTxn],
            ]],
            'PrivateNote' => $note ?: 'Synced from BCF System',
        ]);

        $qbPayment = $dataService->Add($paymentObj);

        if (!$qbPayment || $qbPayment instanceof \Exception) {
            $errMsg = $this->extractQBErrorMessage($dataService, $qbPayment);
            Log::error('QB payment creation failed', [
                'invoice_id' => $qbInvoiceId,
                'error' => $errMsg,
            ]);
            throw new \Exception("QB payment creation failed for invoice #{$qbInvoiceId}: {$errMsg}");
        }

        return $qbPayment;
    }

    /**
     * Sync any unsynced successful payments for an Order against its Main Invoice
     */
    public function syncPendingPaymentsForOrder($dataService, \App\Models\Invoice $mainInvoice, $qbInvoice = null): array
    {
        if (!$mainInvoice->order_id || !$mainInvoice->quickbooks_invoice_id) {
            return [];
        }

        $qbInvoiceId = $mainInvoice->quickbooks_invoice_id;

        // Find all successful payments for this order (scoped to invoice owner on split orders) that have not yet been synced to QB
        $isSplitOrder = ($mainInvoice->order && (bool) $mainInvoice->order->split_invoice)
            || !empty($mainInvoice->split_details['splits'])
            || in_array($mainInvoice->agent_type, ['co-agent', 'co_agent'], true);

        $unsyncedPaymentsQuery = AgentPayment::where('order_id', $mainInvoice->order_id)
            ->where('status', 'succeeded')
            ->where('amount', '>', 0)
            ->whereNull('quickbooks_payment_id');

        if ($isSplitOrder && $mainInvoice->agent_id) {
            $unsyncedPaymentsQuery->where('agent_id', $mainInvoice->agent_id);
        }

        $unsyncedPayments = $unsyncedPaymentsQuery
            ->orderBy('id', 'asc')
            ->get()
            ->values();

        $syncedPaymentIds = [];
        $paymentCount = $unsyncedPayments->count();

        foreach ($unsyncedPayments as $idx => $payment) {
            $payAmount = (float)$payment->amount;
            if ($payAmount <= 0) continue;

            // Absorb <= $0.02 split-invoice tax rounding delta on the final payment of a paid invoice
            if ($mainInvoice->status === 'paid' && $idx === ($paymentCount - 1) && is_object($qbInvoice)) {
                $qbBalance = (float)($qbInvoice->Balance ?? 0);
                $delta = round($qbBalance - $payAmount, 2);
                if ($qbBalance > 0 && abs($delta) > 0 && abs($delta) <= 0.02) {
                    Log::info("QB payment: adjusting final payment #{$payment->id} by {$delta} to match QB balance \${$qbBalance}");
                    $payAmount = round($qbBalance, 2);
                }
            }

            $note = "Payment #{$payment->id} for Order #{$mainInvoice->order_id}";
            $qbPayment = $this->createPaymentForInvoice($dataService, $qbInvoice ?: $qbInvoiceId, $payAmount, $note);

            if ($qbPayment && !($qbPayment instanceof \Exception) && !empty($qbPayment->Id)) {
                $payment->update([
                    'quickbooks_invoice_id' => $qbInvoiceId,
                    'quickbooks_payment_id' => $qbPayment->Id,
                    'quickbooks_txn_id'     => $mainInvoice->invoice_number,
                    'quickbooks_synced_at'  => now(),
                ]);
                $syncedPaymentIds[] = $qbPayment->Id;
                Log::info("QB Payment #{$qbPayment->Id} synced for AgentPayment #{$payment->id} against QB Invoice #{$qbInvoiceId}");
            }
        }

        // Also update any already-synced payments that missed quickbooks_invoice_id reference
        AgentPayment::where('order_id', $mainInvoice->order_id)
            ->where('status', 'succeeded')
            ->whereNotNull('quickbooks_payment_id')
            ->whereNull('quickbooks_invoice_id')
            ->update([
                'quickbooks_invoice_id' => $qbInvoiceId,
                'quickbooks_synced_at'  => now(),
            ]);

        return $syncedPaymentIds;
    }

    /**
     * Discover and cache an active QuickBooks account ID per organization + realm (without caching fallback errors).
     */
    protected function discoverAccountId(
        $dataService,
        string $cachePrefix,
        string $accountTypeFilter,
        array $preferredNames,
        ?string $configuredId = null,
        bool $verifyConfigured = false,
        string $fallbackId = '1',
        ?callable $onEmpty = null
    ): string {
        $orgId = $this->organization?->id ?? 'default';
        $realmKey = $this->organization?->qb_realm_id ?? 'default';
        $cacheKey = "{$cachePrefix}_{$orgId}_{$realmKey}";

        $cached = Cache::get($cacheKey);
        if (!empty($cached)) {
            return (string)$cached;
        }

        try {
            if ($verifyConfigured && $configuredId) {
                try {
                    $acc = $this->callReadWithRetry(fn() => $dataService->FindById('Account', $configuredId), "FindById(Account, {$configuredId})");
                    if ($acc && ($acc->Active === true || $acc->Active === 'true')) {
                        Cache::put($cacheKey, (string)$configuredId, 3600);
                        return (string)$configuredId;
                    }
                } catch (\Exception $e) {
                    Log::warning("Configured QB account {$configuredId} invalid: " . $e->getMessage());
                }
            }

            $accounts = $this->callReadWithRetry(
                fn() => $dataService->Query("SELECT * FROM Account WHERE {$accountTypeFilter} AND Active = true"),
                "Query(Account {$cachePrefix})"
            );

            if (!empty($accounts) && is_array($accounts)) {
                foreach ($preferredNames as $preferred) {
                    foreach ($accounts as $account) {
                        $name = strtolower(trim($account->Name ?? ''));
                        if ($name === $preferred || str_contains($name, $preferred)) {
                            Cache::put($cacheKey, (string)$account->Id, 3600);
                            return (string)$account->Id;
                        }
                    }
                }

                Cache::put($cacheKey, (string)$accounts[0]->Id, 3600);
                return (string)$accounts[0]->Id;
            }

            if ($onEmpty) {
                $createdId = $onEmpty($dataService);
                if ($createdId) {
                    Cache::put($cacheKey, (string)$createdId, 3600);
                    return (string)$createdId;
                }
            }
        } catch (\Exception $e) {
            Log::warning("Could not discover QB account ({$cachePrefix}): " . $e->getMessage());
        }

        return (string)($configuredId ?: $fallbackId);
    }

    /**
     * Get income account ID from QuickBooks with intelligent discovery
     */
    protected function getIncomeAccountId($dataService)
    {
        return $this->discoverAccountId(
            $dataService,
            'qb_income_account_id',
            "AccountType = 'Income'",
            ['services', 'sales', 'service/fee income', 'sales of product income', 'fee income', 'operating revenue'],
            config('quickbooks.accounts.income_id', config('quickbooks.default_income_account_id', '1')),
            false,
            '1'
        );
    }

    /**
     * Get expense / COGS account ID from QuickBooks with intelligent discovery
     */
    protected function getExpenseAccountId($dataService): string
    {
        return $this->discoverAccountId(
            $dataService,
            'qb_expense_account_id',
            "AccountType IN ('Cost of Goods Sold', 'Expense')",
            [
                'subcontracted services',
                'contractors',
                'subcontractors',
                'cost of labor - cos',
                'cost of labor',
                'job expenses',
                'contract labor',
                'cost of goods sold',
                'subcontractor expense',
            ],
            config('quickbooks.accounts.expense_id'),
            true,
            '1'
        );
    }

    /**
     * Get bank account ID from QuickBooks with intelligent discovery for bill payments
     */
    protected function getBankAccountId($dataService): string
    {
        return $this->discoverAccountId(
            $dataService,
            'qb_bank_account_id',
            "AccountType = 'Bank'",
            ['stripe clearing', 'stripe', 'checking', 'chequing', 'operating', 'operating account', 'business checking', 'main checking'],
            config('quickbooks.accounts.bank_id'),
            true,
            '1',
            function ($ds) {
                try {
                    $newBank = Account::create([
                        'Name' => 'Checking (Operating)',
                        'AccountType' => 'Bank',
                        'AccountSubType' => 'Checking',
                    ]);
                    $createdBank = $ds->Add($newBank);
                    if ($createdBank && !($createdBank instanceof \Exception) && !empty($createdBank->Id)) {
                        Log::info("Auto-created QB Bank Account 'Checking (Operating)' with ID: {$createdBank->Id}");
                        return (string)$createdBank->Id;
                    }
                } catch (\Exception $createEx) {
                    Log::warning('Could not auto-create QB bank account: ' . $createEx->getMessage());
                }
                return null;
            }
        );
    }

    /**
     * Retry failed syncs — dispatches queued jobs to avoid HTTP timeout.
     * Finds paid invoices missing quickbooks_invoice_id and queues them for sync.
     */
    public function retryFailedSyncs()
    {
        Log::info('Starting QB retry for failed syncs (queued)...');

        $user = Auth::user();
        $orgId = $user ? ($user->organization_id ?? $user->organization?->id) : null;

        $unsyncedPaymentOrderIds = AgentPayment::where('status', 'succeeded')
            ->where('amount', '>', 0)
            ->whereNull('quickbooks_payment_id')
            ->whereNotNull('order_id')
            ->distinct()
            ->pluck('order_id');

        $query = \App\Models\Invoice::where(function ($q) use ($unsyncedPaymentOrderIds) {
                $q->whereNull('quickbooks_invoice_id');
                if ($unsyncedPaymentOrderIds->isNotEmpty()) {
                    $q->orWhereIn('order_id', $unsyncedPaymentOrderIds);
                }
            })
            ->whereIn('status', ['paid', 'partially_paid'])
            ->where('total', '>', 0)
            ->where(function ($q) {
                // In One Main Invoice Per Order model, only select Main/Consolidated invoices for retry
                $q->where('notes', 'ilike', '%consolidated%')
                  ->orWhere('notes', 'ilike', '%add-on service invoice%')
                  ->orWhere(function ($sub) {
                      $sub->where('agent_type', 'primary')
                          ->where(function ($s2) {
                              $s2->whereNull('notes')
                                 ->orWhere('notes', 'not ilike', '%service invoice%');
                          });
                  });
            })
            ->select('id'); // Only fetch IDs — jobs load the full model themselves

        if ($orgId) {
            $query->where('organization_id', $orgId);
        }

        $invoiceIds = $query->limit(50)->pluck('id');

        Log::info('QB retry: dispatching ' . $invoiceIds->count() . ' invoice jobs to queue');

        foreach ($invoiceIds as $invoiceId) {
            \App\Jobs\SyncInvoiceToQuickBooks::dispatch($invoiceId);
        }

        // Also retry unsynced paid vendor invoices if vendor sync is enabled
        $vendorInvoiceIds = collect();
        if (config('quickbooks.vendor_sync_enabled', true)) {
            $vQuery = \App\Models\VendorInvoice::where('status', 'paid')
                ->where('total_amount', '>', 0)
                ->where(function ($q) {
                    $q->whereNull('quickbooks_bill_id')
                      ->orWhereNull('quickbooks_bill_payment_id');
                })
                ->select('id');

            if ($orgId) {
                $vQuery->where('organization_id', $orgId);
            }

            $vendorInvoiceIds = $vQuery->limit(50)->pluck('id');
            Log::info('QB retry: dispatching ' . $vendorInvoiceIds->count() . ' vendor invoice jobs to queue');

            foreach ($vendorInvoiceIds as $vInvoiceId) {
                \App\Jobs\SyncVendorPayoutToQuickBooks::dispatch($vInvoiceId);
            }
        }

        $totalQueued = $invoiceIds->count() + $vendorInvoiceIds->count();
        Log::info('QB retry: all jobs dispatched', ['total' => $totalQueued]);

        return [
            'queued' => $totalQueued,
            'invoices_queued' => $invoiceIds->count(),
            'vendor_invoices_queued' => $vendorInvoiceIds->count(),
            'total'  => $totalQueued,
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