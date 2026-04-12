<?php

use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\SimulationController;
use App\Http\Controllers\Api\TransactionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| AI Fraud Detection Platform API
| All routes are prefixed with /api automatically
|
*/

// Transaction Management
Route::post('/transactions', [TransactionController::class, 'store']);
Route::get('/transactions', [TransactionController::class, 'index']);
Route::get('/transactions/{id}', [TransactionController::class, 'show']);
Route::post('/transactions/{id}/explain', [TransactionController::class, 'explain']);

// Fraud Alerts
Route::get('/alerts', [AlertController::class, 'index']);
Route::patch('/alerts/{id}', [AlertController::class, 'update']);

// Dashboard & Analytics
Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
Route::get('/dashboard/trends', [DashboardController::class, 'trends']);
Route::get('/dashboard/insights', [DashboardController::class, 'insights']);

// Demo Simulation
Route::post('/simulation', [SimulationController::class, 'run']);

// Phase 12: Azure Open AI Telemetry Testing
Route::get('/test-azure', function () {
    $azureService = app(\App\Services\AzureOpenAIService::class);
    $dummyTransaction = new \App\Models\Transaction([
        'transaction_id' => 'TXN-TEST-123',
        'amount' => 12500000,
        'location' => 'Sydney, Australia',
        'device' => 'Test-MacBook'
    ]);
    
    $start = microtime(true);
    try {
        // This will force a fallback if AZURE_OPENAI_ENABLED is false
        $response = $azureService->generateDeepExplanation($dummyTransaction);
        
        return response()->json([
            'status' => env('AZURE_OPENAI_ENABLED', false) ? 'API Call Processed' : 'Forced Fallback (AZURE_OPENAI_ENABLED=false)',
            'latency_seconds' => round(microtime(true) - $start, 3),
            'azure_response' => $response
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'FAILED',
            'error' => $e->getMessage(),
            'latency_seconds' => round(microtime(true) - $start, 3)
        ], 500);
    }
});
