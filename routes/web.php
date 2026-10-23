<?php

use App\Http\Controllers\OperationsController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Redirect;

// Redirect root to /operations
Route::get('/', function () {
    return Redirect::to('/operations');
});

// Operations UI Dashboard
Route::get('/operations', [OperationsController::class, 'index'])->name('operations.index');
Route::get('/operations/policies/{id}', [OperationsController::class, 'show'])->name('operations.show');
Route::post('/operations/policies/{id}/submit', [OperationsController::class, 'submit'])->name('operations.submit');
Route::post('/operations/policies/{id}/reconcile', [OperationsController::class, 'reconcile'])->name('operations.reconcile');
Route::post('/operations/demo', [OperationsController::class, 'createDemo'])->name('operations.demo');
