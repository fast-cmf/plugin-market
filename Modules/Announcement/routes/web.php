<?php

use Illuminate\Support\Facades\Route;
use Modules\Announcement\Http\Controllers\AnnouncementController;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
});
