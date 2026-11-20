<?php

use App\Http\Controllers\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;

// Outside the "web" middleware group: no session, cookies or CSRF token. Requests
// are authenticated by their Pixelforge signature instead.
Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)
    ->name('webhooks.pixelforge');
