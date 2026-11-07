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

];
