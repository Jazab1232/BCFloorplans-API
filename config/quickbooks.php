<?php

return [
    'client_id' => env('QUICKBOOKS_CLIENT_ID'),
    'client_secret' => env('QUICKBOOKS_CLIENT_SECRET'),
    'redirect_uri' => env('QUICKBOOKS_REDIRECT_URI', env('APP_URL') . '/api/quickbooks/callback'),
    'environment' => env('QUICKBOOKS_ENVIRONMENT', 'sandbox'), // 'sandbox' or 'production'
    'scope' => env('QUICKBOOKS_SCOPE', 'com.intuit.quickbooks.accounting'),
    'base_urls' => [
        'sandbox' => 'development',
        'production' => 'production'
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Expiration Buffer (in minutes)
    |--------------------------------------------------------------------------
    | Refresh tokens this many minutes before actual expiration
    |--------------------------------------------------------------------------
    */
    'token_refresh_buffer' => env('QUICKBOOKS_TOKEN_REFRESH_BUFFER', 5),

    /*
    |--------------------------------------------------------------------------
    | Sync Settings
    |--------------------------------------------------------------------------
    */
    'auto_sync_enabled' => env('QUICKBOOKS_AUTO_SYNC', true),
    'vendor_sync_enabled' => env('QUICKBOOKS_VENDOR_SYNC', false), // Keep disabled until client confirms mapping
    'two_way_sync_enabled' => env('QUICKBOOKS_TWO_WAY_SYNC', false), // Webhooks sync back to BCF
    'retry_failed_syncs' => env('QUICKBOOKS_RETRY_FAILED_SYNCS', true),
    'max_retry_attempts' => env('QUICKBOOKS_MAX_RETRY_ATTEMPTS', 3),

    /*
    |--------------------------------------------------------------------------
    | Default QB Accounts (Mapping)
    |--------------------------------------------------------------------------
    | Map items to specific QuickBooks accounts.
    | These should be the QB ID (numeric string).
    |--------------------------------------------------------------------------
    */
    'accounts' => [
        'income_id' => env('QUICKBOOKS_INCOME_ACCOUNT_ID'),
        'expense_id' => env('QUICKBOOKS_EXPENSE_ACCOUNT_ID'),
        'ar_id' => env('QUICKBOOKS_AR_ACCOUNT_ID'),
        'ap_id' => env('QUICKBOOKS_AP_ACCOUNT_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Encryption
    |--------------------------------------------------------------------------
    */
    'encrypt_tokens' => env('QUICKBOOKS_ENCRYPT_TOKENS', true),
];