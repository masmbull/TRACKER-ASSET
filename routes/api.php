<?php

use App\Http\Controllers\Api\AgentCommandApiController;
use App\Http\Controllers\Api\AgentRegistrationController;
use App\Http\Controllers\Api\AuditApiController;
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
Route::post('/agent/register', [AgentRegistrationController::class, 'register']);

Route::middleware(AuthenticateAuditAgent::class)->group(function () {
    Route::post('/audits', [AuditApiController::class, 'submit']);

    Route::get('/agent/inbox', [AgentCommandApiController::class, 'inbox']);
    Route::post('/agent/commands/{command}/ack', [AgentCommandApiController::class, 'ack']);
});
