<?php

use App\Http\Controllers\Plugins\PluginMarketController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

Route::middleware(['auth', 'verified', 'can:manage-plugins'])
    ->prefix('plugins')
    ->name('plugins.')
    ->group(function (): void {
        Route::get('/', [PluginMarketController::class, 'index'])->name('index');
        Route::post('upload', [PluginMarketController::class, 'upload'])->name('upload');
        Route::post('{plugin}/install', [PluginMarketController::class, 'install'])->name('install');
        Route::post('{plugin}/update', [PluginMarketController::class, 'update'])->name('update');
        Route::post('{plugin}/enable', [PluginMarketController::class, 'enable'])->name('enable');
        Route::post('{plugin}/disable', [PluginMarketController::class, 'disable'])->name('disable');
        Route::delete('{plugin}', [PluginMarketController::class, 'destroy'])->name('destroy');
    });

require __DIR__.'/settings.php';
require __DIR__.'/install.php';
