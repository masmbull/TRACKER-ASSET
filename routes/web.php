<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AgentDevicePromotionController;
use App\Http\Controllers\AgentFleetController;
use App\Http\Controllers\AgentReleaseController;
use App\Http\Controllers\AgentTokenController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AssetImportController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BarcodeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\IkeBastInspectionController;
use App\Http\Controllers\MasterDataController;
use App\Http\Controllers\MicrosoftDirectoryController;
use App\Http\Controllers\QrController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ServiceMonitorController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'showLogin'])->name('login')->middleware('guest');
Route::post('/login', [AuthController::class, 'login'])->middleware('guest');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

// Microsoft Entra ID SSO (PRD §12, §38) — only functional when ENTRA_ENABLED=true.
Route::get('/auth/microsoft', [AuthController::class, 'microsoftRedirect'])->name('auth.microsoft')->middleware('guest');
Route::get('/auth/microsoft/callback', [AuthController::class, 'microsoftCallback'])->name('auth.microsoft.callback');

// Public agent download (for installer) - outside auth middleware
Route::get('/agent/download', [AgentTokenController::class, 'downloadLatest'])->name('agent.download.latest');
Route::get('/agent/{token}/download', [AgentTokenController::class, 'download'])->name('agent.download');

// A specific released build, so an update command can pin the exact version
// rather than always pulling "latest". Public for the same reason as the route
// above: an agent re-pulling during an update is not a logged-in browser.
Route::get('/agent/releases/{release}/download', [AgentReleaseController::class, 'download'])
    ->name('agent.releases.download');

// The page an asset QR opens when scanned (PRD §41). Outside auth on purpose:
// the code is stuck on a machine and is scanned by whoever is holding it - an
// auditor, a new hire, a courier - who has no account on this system. The
// unguessable token, not the numeric id, is what keeps it from being a public
// index of the register.
Route::get('/a/{token}', [QrController::class, 'publicAsset'])->name('assets.public');
Route::get('/a/{token}/pdf', [QrController::class, 'publicPdf'])->name('assets.public.pdf');

Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Collection level routes must be declared before the resource route.
    Route::get('/assets/export', [AssetController::class, 'export'])
        ->name('assets.export')
        ->middleware('can:export-assets');

    Route::get('/assets/retired', [AssetController::class, 'retired'])
        ->name('assets.retired');

    Route::get('/transfers', [TransferController::class, 'index'])
        ->name('transfers.index')
        ->middleware('can:export-assets');

    Route::get('/assets/import', [AssetImportController::class, 'show'])
        ->name('assets.import')
        ->middleware('can:import-assets');
    Route::post('/assets/import/analyze', [AssetImportController::class, 'analyze'])
        ->name('assets.import.analyze')
        ->middleware('can:import-assets');
    Route::post('/assets/import/confirm', [AssetImportController::class, 'confirm'])
        ->name('assets.import.confirm')
        ->middleware('can:import-assets');

    // Before the resource route below: /assets/qr would otherwise be matched by
    // assets/{asset} with "qr" as the id, and 404 on an asset lookup.
    Route::get('/assets/qr', [QrController::class, 'sheet'])->name('assets.qr.sheet');
    Route::post('/assets/qr/custom', [QrController::class, 'custom'])->name('assets.qr.custom');
    Route::get('/assets/qr/history/{customQrHistory}/download', [QrController::class, 'downloadHistory'])->name('assets.qr.history.download');
    Route::get('/assets/{asset}/qr', [QrController::class, 'show'])->name('assets.qr');
    // Issue or rotate the public scan target behind a label. POST-only: it
    // writes a token, and a GET that mints one would fire on a prefetch or a
    // crawler and silently rotate the code out from under a printed sticker.
    Route::post('/assets/{asset}/qr/generate', [QrController::class, 'generate'])->name('assets.qr.generate');

    Route::resource('assets', AssetController::class);

    Route::get('/assets/{asset}/audit', [AssetController::class, 'audit'])->name('assets.audit');
    Route::post('/assets/{asset}/audit', [AssetController::class, 'submitAudit'])->name('assets.audit.submit');
    Route::get('/assets/{asset}/audit-device', [AssetController::class, 'auditDevice'])->name('assets.audit.device');
    Route::post('/assets/{asset}/audit-device', [AssetController::class, 'submitAuditDevice'])->name('assets.audit.device.submit');
    Route::get('/assets/{asset}/transfer', [AssetController::class, 'transfer'])->name('assets.transfer');
    Route::post('/assets/{asset}/transfer', [AssetController::class, 'submitTransfer'])->name('assets.transfer.submit');
    Route::get('/assets/{asset}/repair/create', [AssetController::class, 'createRepair'])->name('assets.repair.create');
    Route::post('/assets/{asset}/repair', [AssetController::class, 'storeRepair'])->name('assets.repair.store');
    Route::delete('/assets/{asset}/repair/{repair}', [AssetController::class, 'destroyRepair'])->name('assets.repair.destroy');
    Route::post('/assets/{asset}/retire', [AssetController::class, 'retire'])->name('assets.retire');
    Route::post('/assets/{asset}/dispose', [AssetController::class, 'dispose'])->name('assets.dispose');
    Route::post('/assets/{asset}/agent-scan', [AssetController::class, 'agentScan'])->name('assets.audit.agent.scan');

    Route::resource('assets/{asset}/documents', DocumentController::class)->only(['create', 'store', 'destroy']);

    Route::get('/assets/{asset}/documents/generate', [DocumentController::class, 'generate'])->name('documents.generate');
    Route::post('/assets/{asset}/documents/generate', [DocumentController::class, 'storeGenerated'])->name('documents.generate.store');
    Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::get('/documents/{document}', [DocumentController::class, 'preview'])->name('documents.preview');
    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');

    Route::post('/assets/{asset}/attachments', [AttachmentController::class, 'store'])->name('assets.attachments.store');
    Route::get('/assets/{asset}/attachments/{attachment}', [AttachmentController::class, 'download'])->name('assets.attachments.download');
    Route::delete('/assets/{asset}/attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('assets.attachments.destroy');

    Route::get('/activity', [ActivityLogController::class, 'index'])
        ->name('activity.index')
        ->middleware('can:view-activity-log');

    Route::get('/reports', [ReportController::class, 'index'])
        ->name('reports.index')
        ->middleware('can:export-assets');
    Route::get('/reports/export', [ReportController::class, 'export'])
        ->name('reports.export')
        ->middleware('can:export-assets');

    Route::get('/barcode', [BarcodeController::class, 'form'])->name('barcode.form');
    Route::post('/barcode', [BarcodeController::class, 'resolve'])->name('barcode.resolve');

    Route::middleware('can:manage-agents')->group(function () {
        Route::get('/agent', [AgentTokenController::class, 'index'])->name('agent.index');
        Route::post('/agent/{token}/revoke', [AgentTokenController::class, 'revoke'])->name('agent.revoke');

        // Release registry. Declared before the `/agent/{token}/...` routes below
        // so "releases" can never be read as a token id, and the per-release
        // download is public the same way the fleet-wide one is (see the route
        // outside this group) - an updating agent has no session.
        Route::get('/agent/releases', [AgentReleaseController::class, 'index'])->name('agent.releases');
        Route::post('/agent/releases', [AgentReleaseController::class, 'store'])->name('agent.releases.store');
        Route::post('/agent/releases/{release}/activate', [AgentReleaseController::class, 'activate'])->name('agent.releases.activate');
        Route::post('/agent/releases/{release}/push', [AgentReleaseController::class, 'push'])->name('agent.releases.push');
        Route::delete('/agent/releases/{release}', [AgentReleaseController::class, 'destroy'])->name('agent.releases.destroy');

        Route::get('/agent/fleet', [AgentFleetController::class, 'index'])->name('agent.fleet');
        Route::get('/agent/fleet/refresh', [AgentFleetController::class, 'refresh'])->name('agent.fleet.refresh');
        Route::get('/agent/commands', [AgentFleetController::class, 'commands'])->name('agent.commands');
        Route::post('/agent/commands/script', [AgentFleetController::class, 'pushScript'])->name('agent.commands.script');
        // Deep inventory detail for one device. Declared before the token-scoped
        // POST routes so "fingerprint" can never be read as a token id.
        Route::get('/agent/{token}/fingerprint', [AgentFleetController::class, 'fingerprint'])->name('agent.fingerprint');
        Route::post('/agent/scan/{token}', [AgentFleetController::class, 'scanNow'])->name('agent.scan');
        Route::post('/agent/scan-all', [AgentFleetController::class, 'scanAll'])->name('agent.scanAll');
        Route::post('/agent/update-all', [AgentFleetController::class, 'updateAll'])->name('agent.updateAll');
        Route::post('/agent/{token}/update', [AgentFleetController::class, 'updateOne'])->name('agent.updateOne');
        Route::post('/agent/{token}/elevate', [AgentFleetController::class, 'elevate'])->name('agent.elevate');
        Route::post('/agent/{token}/uninstall', [AgentFleetController::class, 'uninstall'])->name('agent.uninstall');
Route::post('/agent/{token}/delete', [AgentFleetController::class, 'destroy'])->name('agent.destroy');
        Route::post('/agent/sweep', [AgentFleetController::class, 'sweep'])->name('agent.sweep');

        // Fleet actions on a single device.
        //
        // Wake is the odd one out: it is answered by the server itself, not by
        // the agent, because a sleeping machine has no agent to ask. It is
        // still a POST - it changes the state of a device, and a link that can
        // be prefetched or crawled should not be able to power a laptop on.
        Route::post('/agent/{token}/wake', [AgentFleetController::class, 'wake'])->name('agent.wake');
        Route::post('/agent/{token}/power', [AgentFleetController::class, 'power'])->name('agent.power');

        // A .rdp file the browser hands to the operator. It only reads, so it
        // is a GET - the browser downloads it and Windows opens it.
        Route::get('/agent/{token}/rdp', [AgentFleetController::class, 'remoteDesktop'])->name('agent.rdp');

        // Polled by the progress modal while an action runs. Declared before the
        // token-scoped routes for the same reason as the others: "commands" must
        // never be read as a token id.
        Route::get('/agent/commands/{command}/status', [AgentFleetController::class, 'commandStatus'])->name('agent.commands.status');

        // Move a device that is not on the register yet into the register.
        // Inside the manage-agents group rather than under the assets policy on
        // purpose: promoting a device writes an asset row, so it must not be
        // reachable by anyone who cannot manage the fleet, and it must not be
        // reachable by someone who cannot manage agents either.
        Route::get('/agent/{token}/promote', [AgentDevicePromotionController::class, 'create'])->name('agent.promote');
        Route::post('/agent/{token}/promote', [AgentDevicePromotionController::class, 'store'])->name('agent.promote.store');
    });

    // Accept a scan's readings onto the asset it was compared against.
    //
    // Outside the manage-agents group deliberately. What this writes is the asset
    // row, and AssetPolicy::update already allows support as well as admin - a
    // route inside the agents group would quietly take that job away from support.
    // The authorize() in the controller is the gate that matters, and it is the
    // asset policy rather than the fleet gate.
    //
    // Keyed by the audit, not the token: the decision is about one scan on one
    // asset, and a device can have many scans over its life.
    Route::post('/audit/{audit}/apply-scan', [AgentDevicePromotionController::class, 'applyScan'])->name('audit.apply-scan');

    // Infrastructure monitor: which backing services are up. Admin-only
    // because the checks expose the database driver, mail host and cache
    // backend, and a support or technician account has no reason to see any
    // of it. The JSON variant answers 503 when anything is down so a load
    // balancer reads the status code rather than trusting a cheerful body.
    Route::middleware('can:manage-users')->group(function () {
        Route::get('/services', [ServiceMonitorController::class, 'index'])->name('services.index');
        Route::get('/services/health.json', [ServiceMonitorController::class, 'json'])->name('services.health');
    });

    Route::middleware('can:manage-master-data')->group(function () {
        Route::get('/master/{type}', [MasterDataController::class, 'index'])->name('master.index');
        Route::get('/master/{type}/create', [MasterDataController::class, 'create'])->name('master.create');
        Route::post('/master/{type}', [MasterDataController::class, 'store'])->name('master.store');
        Route::get('/master/{type}/{id}/edit', [MasterDataController::class, 'edit'])->name('master.edit');
        Route::put('/master/{type}/{id}', [MasterDataController::class, 'update'])->name('master.update');
        Route::delete('/master/{type}/{id}', [MasterDataController::class, 'destroy'])->name('master.destroy');

        Route::middleware('can:manage-users')->group(function () {
            Route::get('/users/access', [UserController::class, 'access'])->name('users.access');
            Route::put('/users/{user}/access', [UserController::class, 'updateAccess'])->name('users.access.update');
            Route::get('/users', [UserController::class, 'index'])->name('users.index');
            Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
            Route::post('/users', [UserController::class, 'store'])->name('users.store');
            Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
            Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');

            // Employee directory typeahead (PRD §39). Inside the same gate as
            // user administration: it returns names, addresses, departments and
            // job titles for the whole tenant.
            Route::get('/users/directory', MicrosoftDirectoryController::class)->name('users.directory');
        });

        Route::resource('templates', TemplateController::class)->except(['edit', 'update']);
        Route::put('/templates/{template}/toggle', [TemplateController::class, 'toggle'])->name('templates.toggle');

        Route::get('/ike-bast/inspection/create/{asset}', [IkeBastInspectionController::class, 'create'])->name('ike-bast.inspection.create');
        Route::get('/ike-bast/inspection', [IkeBastInspectionController::class, 'index'])->name('ike-bast.inspection.index');
        Route::post('/ike-bast/inspection', [IkeBastInspectionController::class, 'store'])->name('ike-bast.inspection.store');
        Route::get('/ike-bast/inspection/{session}', [IkeBastInspectionController::class, 'show'])->name('ike-bast.inspection.show');
        Route::put('/ike-bast/inspection/{session}', [IkeBastInspectionController::class, 'update'])->name('ike-bast.inspection.update');
        Route::post('/ike-bast/inspection/{session}/approve', [IkeBastInspectionController::class, 'approve'])->name('ike-bast.inspection.approve');
        Route::get('/ike-bast/inspection/{session}/print', [IkeBastInspectionController::class, 'print'])->name('ike-bast.inspection.print');
    });
});
