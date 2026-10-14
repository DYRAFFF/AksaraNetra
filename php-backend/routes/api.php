<?php

declare(strict_types=1);

use App\Http\Controllers\AuditArtifactController;
use App\Http\Controllers\AuditCacheController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuditResultController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'show']);

Route::get('/audits/cache', [AuditCacheController::class, 'show']);
Route::post('/audits', [AuditController::class, 'store']);

Route::get('/audits/{id}/artifacts/{artifact}', [AuditArtifactController::class, 'show'])
    ->where('artifact', '[^/]+');

Route::get('/audits/{id}/result', [AuditResultController::class, 'show']);

Route::get('/audits/{id}', [AuditController::class, 'show']);
Route::delete('/audits/{id}', [AuditController::class, 'destroy']);

Route::fallback(function () {
    return response()->json([
        'error' => 'not-found',
        'message' => 'Endpoint tidak ditemukan.',
    ], 404, [
        'Cache-Control' => 'no-store',
        'X-Content-Type-Options' => 'nosniff',
    ]);
});
