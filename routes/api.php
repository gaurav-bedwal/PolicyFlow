<?php

use App\Http\Controllers\Api\MockInsurerController;
use App\Http\Controllers\Api\PolicyRequestController;
use App\Http\Controllers\Api\PolicySubmissionController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json(['status' => 'healthy', 'app' => config('app.name')]);
});

// Policy Requests Lifecycle Endpoints
Route::prefix('policy-requests')->group(function () {
    Route::get('/', [PolicyRequestController::class, 'index']);
    Route::post('/', [PolicyRequestController::class, 'store']);
    Route::get('/{id}', [PolicyRequestController::class, 'show']);
    Route::post('/{id}/submit', [PolicySubmissionController::class, 'submit']);
    Route::post('/{id}/reconcile', [PolicySubmissionController::class, 'reconcile']);
});

// Mock Insurer Endpoints
Route::prefix('mock-insurer')->group(function () {
    Route::post('/callback', [MockInsurerController::class, 'handleCallback']);
    Route::get('/policies/{identifier}', [MockInsurerController::class, 'lookup']);
    Route::post('/issue', [MockInsurerController::class, 'issue']);
});


