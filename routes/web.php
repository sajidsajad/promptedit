<?php

use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\GenerationEstimateController;
use App\Http\Controllers\PixelforgeWebhookController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->name('dashboard');

    Route::get('/generations/estimate', GenerationEstimateController::class)
        ->name('generations.estimate');

    Route::post('/wallet/purchase', [WalletController::class, 'purchase'])
        ->name('wallet.purchase');

    Route::post('/generations', [GenerationController::class, 'store'])
        ->name('generations.store');

    Route::get('/generations/{generation}', [GenerationController::class, 'show'])
        ->name('generations.show');
});

Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('/admin/report', AdminReportController::class)
        ->name('admin.report');
});

Route::post('/webhooks/pixelforge', PixelforgeWebhookController::class)
    ->name('webhooks.pixelforge');

require __DIR__.'/settings.php';
