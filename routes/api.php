<?php

use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\WebhookDeliveryController;
use Illuminate\Support\Facades\Route;

// For API clients; the container healthcheck uses Laravel's /up.
Route::get('/health', fn () => response()->json(['data' => ['status' => 'ok']]));

Route::post('/applications', [ApplicationController::class, 'store']);
Route::post('/applications/{application}/webhooks', [WebhookController::class, 'store']);
Route::post('/applications/{application}/events', [EventController::class, 'store']);

Route::get('/applications/{application}/deliveries', [WebhookDeliveryController::class, 'index']);

// scopeBindings so a delivery from another application 404s.
Route::post('/applications/{application}/deliveries/{delivery}/retry', [WebhookDeliveryController::class, 'retry'])
    ->scopeBindings();
