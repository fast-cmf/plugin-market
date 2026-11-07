<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Paths
    |--------------------------------------------------------------------------
    |
    | Filesystem locations used by the plugin market. Tests override these
    | values to isolate the real Modules directory and status file.
    |
    */

    'paths' => [
        'modules' => base_path('Modules'),
        'statuses' => base_path('modules_statuses.json'),
        'uploads' => storage_path('app/plugins/uploads'),
        'backups' => storage_path('app/plugins/backups'),
        'samples' => storage_path('app/plugins/samples'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Upload constraints
    |--------------------------------------------------------------------------
    */

    'max_zip_size' => 50 * 1024 * 1024,

    /*
    |--------------------------------------------------------------------------
    | Staging / trash prefixes used for atomic directory swaps.
    |--------------------------------------------------------------------------
    */

    'staging_prefix' => '.staging-',
    'trash_prefix' => '.trash-',

    /*
    |--------------------------------------------------------------------------
    | Package signing
    |--------------------------------------------------------------------------
    |
    | mode:
    |   strict     - unsigned or unverifiable packages are rejected.
    |   permissive - signature problems are collected as warnings, but the
    |                package is still accepted (internal/enterprise markets).
    |   off        - signature checks are skipped entirely (local debugging).
    |
    | Any unknown value falls back to strict behaviour (fail closed).
    |
    */

    'signing' => [
        'mode' => env('PLUGIN_MARKET_SIGNING_MODE', 'permissive'),

        // key id => base64 encoded Ed25519 public key.
        'keys' => [],

        // Directory scanned for `{keyId}.pub` base64 public key files.
        'keys_path' => storage_path('app/plugin-keys'),

        // Local revocation list consumed by Signature\RevocationList.
        'revocations_path' => storage_path('app/plugin-revocations.json'),
    ],

    /*
    |--------------------------------------------------------------------------
    | License verification (design §4)
    |--------------------------------------------------------------------------
    |
    | Client-side license SDK talking to the market platform. The platform
    | public key must be the base64 Ed25519 public key of the marketplace
    | issuer; documents are verified offline against it.
    |
    */

    'license' => [
        // Market platform API root (no trailing slash).
        'base_url' => env('MARKET_BASE_URL', 'http://127.0.0.1:8000/api'),

        // base64 encoded platform Ed25519 public key for license documents.
        'platform_public_key' => env('MARKET_PLATFORM_PUBLIC_KEY', ''),

        // Fallback grace period (days) when a document carries none.
        'grace_days' => (int) env('MARKET_GRACE_DAYS', 14),

        // Encrypted-at-rest license archive.
        'store_path' => storage_path('app/market/license.json'),
    ],

];
