<?php

use App\Http\Controllers\Api\AuditApiController;
use App\Http\Middleware\AuthenticateAuditAgent;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Audit Agent API (PRD §11 — preferred audit architecture)
|--------------------------------------------------------------------------
|
| Lightweight client posts device JSON; all logic stays in Laravel.
| Authenticates with a Bearer token issued via `php artisan audit:token`.
|
*/

Route::post('/audits', [AuditApiController::class, 'submit'])
    ->middleware(AuthenticateAuditAgent::class);

