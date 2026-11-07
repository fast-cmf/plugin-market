<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn (): array => [
    'module' => 'Announcement',
])->name('announcements.api-index');
