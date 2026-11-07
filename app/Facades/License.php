<?php

namespace App\Facades;

use App\Services\Market\License\LicenseManager;
use Illuminate\Support\Facades\Facade;

/**
 * License facade — entry point for product gating:
 *
 *     License::for('todo-pro')->isValid()
 *     License::for('todo-pro')->feature('export')
 *     License::for('todo-pro')->state()
 */
class License extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LicenseManager::class;
    }
}
