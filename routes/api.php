<?php

use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/assets', function () {
        return response()->json(['message' => 'Assets API']);
    });
    Route::get('/dashboard/summary', function () {
        return response()->json(['message' => 'Dashboard summary']);
    });
    Route::get('/dashboard/charts', function () {
        return response()->json(['message' => 'Dashboard charts']);
    });
});
