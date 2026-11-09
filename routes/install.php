<?php

use App\Http\Controllers\Install\InstallWizardController;
use Illuminate\Support\Facades\Route;

Route::get('install', [InstallWizardController::class, 'index'])
    ->name('install.index');

Route::post('install/database', [InstallWizardController::class, 'database'])
    ->name('install.database');

Route::post('install/account', [InstallWizardController::class, 'account'])
    ->name('install.account');

Route::post('install/finish', [InstallWizardController::class, 'finish'])
    ->name('install.finish');
