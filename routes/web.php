<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AssetImportController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BarcodeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\IkeBastInspectionController;
use App\Http\Controllers\MasterDataController;
use App\Http\Controllers\QrController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'showLogin'])->name('login')->middleware('guest');
Route::post('/login', [AuthController::class, 'login'])->middleware('guest');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

// Microsoft Entra ID SSO (PRD §12, §38) — only functional when ENTRA_ENABLED=true.
Route::get('/auth/microsoft', [AuthController::class, 'microsoftRedirect'])->name('auth.microsoft')->middleware('guest');
Route::get('/auth/microsoft/callback', [AuthController::class, 'microsoftCallback'])->name('auth.microsoft.callback');

Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Collection level routes must be declared before the resource route.
    Route::get('/assets/export', [AssetController::class, 'export'])
        ->name('assets.export')
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

    Route::resource('assets', AssetController::class);

    Route::get('/assets/{asset}/audit', [AssetController::class, 'audit'])->name('assets.audit');
    Route::post('/assets/{asset}/audit', [AssetController::class, 'submitAudit'])->name('assets.audit.submit');
    Route::get('/assets/{asset}/transfer', [AssetController::class, 'transfer'])->name('assets.transfer');
    Route::post('/assets/{asset}/transfer', [AssetController::class, 'submitTransfer'])->name('assets.transfer.submit');
    Route::get('/assets/{asset}/repair/create', [AssetController::class, 'createRepair'])->name('assets.repair.create');
    Route::post('/assets/{asset}/repair', [AssetController::class, 'storeRepair'])->name('assets.repair.store');
    Route::delete('/assets/{asset}/repair/{repair}', [AssetController::class, 'destroyRepair'])->name('assets.repair.destroy');

    Route::resource('assets/{asset}/documents', DocumentController::class)->only(['create', 'store', 'destroy']);

    Route::get('/assets/{asset}/documents/generate', [DocumentController::class, 'generate'])->name('documents.generate');
    Route::post('/assets/{asset}/documents/generate', [DocumentController::class, 'storeGenerated'])->name('documents.generate.store');
    Route::get('/documents/{document}', [DocumentController::class, 'preview'])->name('documents.preview');
    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');

    Route::get('/assets/{asset}/qr', [QrController::class, 'show'])->name('assets.qr');

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

    Route::middleware('can:manage-master-data')->group(function () {
        Route::get('/master/{type}', [MasterDataController::class, 'index'])->name('master.index');
        Route::get('/master/{type}/create', [MasterDataController::class, 'create'])->name('master.create');
        Route::post('/master/{type}', [MasterDataController::class, 'store'])->name('master.store');
        Route::get('/master/{type}/{id}/edit', [MasterDataController::class, 'edit'])->name('master.edit');
        Route::put('/master/{type}/{id}', [MasterDataController::class, 'update'])->name('master.update');
        Route::delete('/master/{type}/{id}', [MasterDataController::class, 'destroy'])->name('master.destroy');

        Route::middleware('can:manage-users')->group(function () {
            Route::get('/users', [UserController::class, 'index'])->name('users.index');
            Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
            Route::post('/users', [UserController::class, 'store'])->name('users.store');
            Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
            Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        });

        Route::resource('templates', TemplateController::class)->except(['show', 'edit', 'update']);
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
