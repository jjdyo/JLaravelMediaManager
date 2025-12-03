<?php

use app\Http\Controllers\MediaController;
use Illuminate\Support\Facades\Route;

// Example routes for Media Manager (place inside an auth middleware group)
Route::middleware(['auth'])->group(function () {
    Route::get('/media', [MediaController::class, 'index'])->name('media.index');
    Route::get('/media/directories', [MediaController::class, 'directories'])->name('media.directories');
    Route::post('/media', [MediaController::class, 'store'])->name('media.store');
    Route::post('/media/folders', [MediaController::class, 'createFolder'])->name('media.folders.create');
});
