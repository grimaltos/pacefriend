<?php

use App\Http\Controllers\Api\V1\AudioClipController;
use App\Http\Controllers\Api\V1\TelemetryLapController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public immutable hash-addressed audio clip endpoint for instant client RAM preloading
    Route::get('/audio/{hash}', [AudioClipController::class, 'show'])->name('api.v1.audio.show');

    // Sanctum-authenticated client telemetry endpoints
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/telemetry/heartbeat', [TelemetryLapController::class, 'heartbeat'])->name('api.v1.telemetry.heartbeat');
        Route::post('/telemetry/lap', [TelemetryLapController::class, 'store'])->name('api.v1.telemetry.lap');
        Route::post('/telemetry/session/end', [TelemetryLapController::class, 'endSession'])->name('api.v1.telemetry.session.end');
    });
});
