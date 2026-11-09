<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Web installer
    |--------------------------------------------------------------------------
    |
    | Paths used by the /install wizard. Tests override these values to
    | isolate the real .env and lock file.
    |
    */

    'lock_path' => env('INSTALLER_LOCK', storage_path('app/installed.lock')),

    'env_path' => base_path('.env'),

    'env_example_path' => base_path('.env.example'),

];
