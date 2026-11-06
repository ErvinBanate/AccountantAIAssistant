<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\GmailController;
use App\Http\Controllers\WorkflowController;
use Illuminate\Support\Facades\Route;

Route::get('/', [WorkflowController::class, 'index']);
Route::post('/demo', [WorkflowController::class, 'demo'])->middleware('throttle:10,1');
Route::middleware(['guest', 'throttle:10,1'])->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
});
Route::middleware('auth')->group(function () {
    Route::post('/logout', [WorkflowController::class, 'logout']);
    Route::post('/clients', [ClientController::class, 'store']);
    Route::post('/clients/sync-history', [ClientController::class, 'history']);
    Route::post('/clients/{id}', [ClientController::class, 'update'])->whereNumber('id');
    Route::delete('/clients/{id}', [ClientController::class, 'destroy'])->whereNumber('id');
    Route::post('/requests', [WorkflowController::class, 'ingest']);
    Route::post('/requests/{id}/draft', [WorkflowController::class, 'saveDraft'])->whereNumber('id');
    Route::post('/requests/{id}/ai-draft', [WorkflowController::class, 'aiDraft'])->whereNumber('id')->middleware('throttle:10,1');
    Route::post('/requests/{id}/clarify', [WorkflowController::class, 'clarify'])->whereNumber('id');
    Route::post('/sources', [WorkflowController::class, 'importSource']);
    Route::post('/sources/{id}', [WorkflowController::class, 'updateSource'])->whereNumber('id');
    Route::delete('/sources/{id}', [WorkflowController::class, 'deleteSource'])->whereNumber('id');
    Route::get('/sources/template', [WorkflowController::class, 'sourceTemplate']);
    Route::get('/gmail/connect', [GmailController::class, 'connect']);
    Route::get('/gmail/callback', [GmailController::class, 'callback']);
    Route::post('/gmail/sync', [GmailController::class, 'sync'])->middleware('throttle:5,1');
    Route::post('/gmail/disconnect', [GmailController::class, 'disconnect']);
    Route::get('/requests/{id}/attachment', [WorkflowController::class, 'attachment'])->whereNumber('id');
    Route::post('/requests/{id}/{action}', [WorkflowController::class, 'transition'])
        ->whereNumber('id')->whereIn('action', ['review', 'approve', 'send']);
});
