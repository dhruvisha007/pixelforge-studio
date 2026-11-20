<?php

use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\CreditsController;
use App\Http\Controllers\GenerationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return auth()->check() ? to_route('studio') : Inertia::render('Welcome');
})->name('home');

Route::middleware('auth')->group(function () {
    Route::get('studio', [GenerationController::class, 'index'])->name('studio');
    Route::post('generations', [GenerationController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('generations.store');
    Route::post('generations/estimate', [GenerationController::class, 'estimate'])
        ->middleware('throttle:240,1')
        ->name('generations.estimate');
    Route::get('generations/{generation}/image', [GenerationController::class, 'image'])
        ->name('generations.image');

    Route::get('credits', [CreditsController::class, 'index'])->name('credits');
    Route::post('credits/purchase', [CreditsController::class, 'purchase'])->name('credits.purchase');

    Route::get('admin/report', ReportController::class)
        ->middleware('can:view-admin-report')
        ->name('admin.report');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
