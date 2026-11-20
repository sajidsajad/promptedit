<?php

use App\Http\Controllers\GenerationController;
use App\Http\Controllers\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::post('/generations', [GenerationController::class, 'store'])
        ->name('generations.store');
});

Route::post('/webhooks/pixelforge', PixelforgeWebhookController::class)
    ->name('webhooks.pixelforge');

require __DIR__.'/settings.php';
