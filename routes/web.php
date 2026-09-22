<?php

use App\Http\Controllers\AssetController;
use App\Http\Controllers\AssetImportController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\TemplateController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'showLogin'])->name('login')->middleware('guest');
Route::post('/login', [AuthController::class, 'login'])->middleware('guest');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

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

    Route::middleware('can:manage-master-data')->group(function () {
        Route::resource('templates', TemplateController::class)->except(['show', 'edit', 'update']);
        Route::put('/templates/{template}/toggle', [TemplateController::class, 'toggle'])->name('templates.toggle');
    });
});
