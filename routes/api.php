<?php

use App\Http\Controllers\Api\PolicyRequestController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json(['status' => 'healthy', 'app' => config('app.name')]);
});

Route::prefix('policy-requests')->group(function () {
    Route::get('/', [PolicyRequestController::class, 'index']);
    Route::post('/', [PolicyRequestController::class, 'store']);
    Route::get('/{id}', [PolicyRequestController::class, 'show']);
});

