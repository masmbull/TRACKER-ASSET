<?php

use App\Http\Controllers\Api\AgentCommandApiController;
use App\Http\Controllers\Api\AgentFingerprintController;
use App\Http\Controllers\Api\AgentRegistrationController;
use App\Http\Controllers\Api\AuditApiController;
use App\Http\Controllers\Api\InternalScriptCommandController;
use App\Http\Middleware\AuthenticateAuditAgent;
use Illuminate\Support\Facades\Route;

/*
|---------------------------------------------------------------------------
| Audit Agent API (PRD section 11)
|---------------------------------------------------------------------------
|
| Resident daemon polls GET /api/agent/inbox every N seconds, acks commands.
| Browser audit POST /api/audits (one-shot collector).
| All endpoints use Bearer tokens issued via /agent page.
|
*/

// Public endpoints (no auth required)
Route::get('/health', fn () => response()->json(['status' => 'ok']));
Route::get('/agent/register/options', [AgentRegistrationController::class, 'options']);
Route::post('/agent/register', [AgentRegistrationController::class, 'register']);

Route::middleware(AuthenticateAuditAgent::class)->group(function () {
    Route::post('/audits', [AuditApiController::class, 'submit']);
    Route::post('/agent/bootstrap-asset', [AgentRegistrationController::class, 'bootstrapAsset']);

    // Telemetry heartbeat: the daemon pings this on its own schedule so Live
    // Fleet reflects reality without anyone pressing "Scan Now".
    Route::post('/agent/heartbeat', [AuditApiController::class, 'heartbeat']);

    // Deep inventory (software, security posture, licensing). Separate from the
    // heartbeat on purpose: this is a registry + WMI walk costing seconds, so
    // the agent posts it at install and then daily, never every minute.
    Route::post('/agent/fingerprint', [AgentFingerprintController::class, 'store']);

    Route::get('/agent/inbox', [AgentCommandApiController::class, 'inbox']);
    Route::post('/agent/commands/{command}/ack', [AgentCommandApiController::class, 'ack']);

    // Progress for long-running work. The agent owns the stage vocabulary, so
    // these take a free-text sentence rather than a server-side enum.
    Route::post('/agent/commands/{command}/progress', [AgentCommandApiController::class, 'progress']);
    Route::post('/agent/install-progress', [AgentCommandApiController::class, 'installProgress']);
});

Route::middleware('throttle:20,1')->post('/internal/agent/commands/script', [InternalScriptCommandController::class, 'dispatch']);
