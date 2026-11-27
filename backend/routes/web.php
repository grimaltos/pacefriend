<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\DrivingSessionController;
use App\Http\Controllers\Web\ReferenceLapController;
use App\Http\Controllers\Web\SettingsController;
use App\Http\Controllers\Web\TrackCatalogController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/references', [ReferenceLapController::class, 'index'])->name('references.index');
    Route::post('/references', [ReferenceLapController::class, 'store'])->name('references.store');
    Route::post('/references/garage61/import-url', [ReferenceLapController::class, 'importGarage61Url'])->name('references.g61.url');
    Route::get('/references/garage61/search', [ReferenceLapController::class, 'searchGarage61'])->name('references.g61.search');
    Route::post('/references/garage61/import-lap', [ReferenceLapController::class, 'importGarage61Lap'])->name('references.g61.lap');
    Route::post('/laps/{lap}/promote', [ReferenceLapController::class, 'promoteFromLap'])->name('laps.promote');
    Route::post('/references/{referenceLap}/pin', [ReferenceLapController::class, 'togglePin'])->name('references.pin');
    Route::delete('/references/prune', [ReferenceLapController::class, 'pruneStale'])->name('references.prune');
    Route::delete('/references/{referenceLap}', [ReferenceLapController::class, 'destroy'])->name('references.destroy');

    Route::get('/sessions', [DrivingSessionController::class, 'index'])->name('sessions.index');
    Route::get('/sessions/{session}', [DrivingSessionController::class, 'show'])->name('sessions.show');
    Route::post('/sessions/{session}/finish', [DrivingSessionController::class, 'finish'])->name('sessions.finish');
    Route::post('/sessions/{session}/report', [DrivingSessionController::class, 'generateReport'])->name('sessions.report');

    Route::get('/tracks', [TrackCatalogController::class, 'index'])->name('tracks.index');
    Route::post('/tracks', [TrackCatalogController::class, 'save'])->name('tracks.save');

    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('/settings/profile', [SettingsController::class, 'updateProfile'])->name('settings.profile.update');
    Route::put('/settings/radio', [SettingsController::class, 'updateRadio'])->name('settings.radio.update');
    Route::post('/settings/radio/preview', [SettingsController::class, 'previewRadio'])->name('settings.radio.preview');
    Route::get('/settings/garage61/redirect', [SettingsController::class, 'redirectToGarage61'])->name('settings.garage61.redirect');
    Route::get('/settings/garage61/callback', [SettingsController::class, 'handleGarage61Callback'])->name('settings.garage61.callback');
    Route::put('/settings/garage61', [SettingsController::class, 'updateGarage61'])->name('settings.garage61.update');
    Route::post('/settings/tokens', [SettingsController::class, 'createToken'])->name('settings.tokens.create');
    Route::get('/settings/client-bundle', [SettingsController::class, 'downloadClientBundle'])->name('settings.client.bundle');
    Route::get('/settings/client-exe', [SettingsController::class, 'downloadClientExe'])->name('settings.client.exe');
    Route::delete('/settings/tokens/{tokenId}', [SettingsController::class, 'revokeToken'])->name('settings.tokens.revoke');
});
