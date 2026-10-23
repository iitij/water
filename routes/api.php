<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ReadingController;
use App\Http\Controllers\Api\InvoiceController;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/profile', [AuthController::class, 'updateProfile']);
    
    // Readings (Meter Reader)
    Route::get('/readings', [ReadingController::class, 'index']);
    Route::get('/meters/search', [ReadingController::class, 'searchMeter']);
    Route::get('/meters/{id}/period', [ReadingController::class, 'getMeterPeriod']);
    Route::post('/readings', [ReadingController::class, 'store']);

    // Invoices & Payments (Collector)
    Route::get('/invoices/unpaid', [InvoiceController::class, 'unpaidInvoices']);
    Route::get('/invoices/search', [InvoiceController::class, 'search']);
    Route::get('/invoices/{id}', [InvoiceController::class, 'show']);
    Route::post('/invoices/{id}/pay', [App\Http\Controllers\Api\InvoiceController::class, 'pay']);
    
    // Squares (SCR-04) — Dev 4 (Fouad)
    Route::get('/squares', [App\Http\Controllers\Api\SquareController::class, 'index']);
    Route::post('/squares', [App\Http\Controllers\Api\SquareController::class, 'store']);
    Route::post('/squares/bulk-delete', [App\Http\Controllers\Api\SquareController::class, 'bulkDelete']);
    Route::get('/squares/{id}', [App\Http\Controllers\Api\SquareController::class, 'show']);
    Route::put('/squares/{id}', [App\Http\Controllers\Api\SquareController::class, 'update']);
    Route::delete('/squares/{id}', [App\Http\Controllers\Api\SquareController::class, 'destroy']);

    // Subscribers & 360° Profile (SCR-05, SCR-06, SCR-07) — Dev 4 (Fouad)
    Route::get('/subscribers', [App\Http\Controllers\Api\SubscriberController::class, 'index']);
    Route::post('/subscribers', [App\Http\Controllers\Api\SubscriberController::class, 'store']);
    Route::post('/subscribers/bulk-delete', [App\Http\Controllers\Api\SubscriberController::class, 'bulkDelete']);
    Route::get('/subscribers/{id}', [App\Http\Controllers\Api\SubscriberController::class, 'show']);
    Route::put('/subscribers/{id}', [App\Http\Controllers\Api\SubscriberController::class, 'update']);
    Route::delete('/subscribers/{id}', [App\Http\Controllers\Api\SubscriberController::class, 'destroy']);
    Route::get('/subscribers/{id}/statement', [App\Http\Controllers\Api\SubscriberController::class, 'statement']);
    Route::get('/subscribers/{id}/transactions', [App\Http\Controllers\Api\SubscriberController::class, 'transactions']);
    Route::post('/subscribers/{id}/collect-payment', [App\Http\Controllers\Api\SubscriberController::class, 'collectPayment']);

    // Subscription Fees (SCR-19) — Dev 4 (Fouad)
    Route::get('/subscription-fees', [App\Http\Controllers\Api\SubscriptionFeeController::class, 'index']);
    Route::put('/subscription-fees/{id}', [App\Http\Controllers\Api\SubscriptionFeeController::class, 'update']);
    Route::delete('/subscription-fees/{id}', [App\Http\Controllers\Api\SubscriptionFeeController::class, 'destroy']);
    Route::post('/subscription-fees/bulk-delete', [App\Http\Controllers\Api\SubscriptionFeeController::class, 'bulkDelete']);
    Route::post('/subscription-fees/{id}/invoice', [App\Http\Controllers\Api\SubscriptionFeeController::class, 'invoice']);

    // Service Requests / Water Tanker (SCR-20) — Dev 4 (Fouad)
    Route::get('/service-requests', [App\Http\Controllers\Api\ServiceRequestController::class, 'index']);
    Route::put('/service-requests/{id}', [App\Http\Controllers\Api\ServiceRequestController::class, 'update']);
    Route::delete('/service-requests/{id}', [App\Http\Controllers\Api\ServiceRequestController::class, 'destroy']);
    Route::post('/service-requests/bulk-delete', [App\Http\Controllers\Api\ServiceRequestController::class, 'bulkDelete']);
    Route::patch('/service-requests/{id}/complete', [App\Http\Controllers\Api\ServiceRequestController::class, 'complete']);
    Route::post('/service-requests/{id}/invoice', [App\Http\Controllers\Api\ServiceRequestController::class, 'invoice']);

    // General Ledger (SCR-22, read-only) — Dev 4 (Fouad)
    Route::get('/transactions', [App\Http\Controllers\Api\TransactionController::class, 'index']);
    Route::post('/meters', [App\Http\Controllers\Api\SubscriberController::class, 'storeMeter']);
    Route::put('/meters/{id}', [App\Http\Controllers\Api\SubscriberController::class, 'updateMeter']);
    Route::post('/subscription-fees', [App\Http\Controllers\Api\SubscriberController::class, 'storeSubscriptionFee']);
    Route::post('/service-requests', [App\Http\Controllers\Api\SubscriberController::class, 'storeServiceRequest']);
    
    // Admin Dashboard Stats
    Route::get('/admin/stats', [\App\Http\Controllers\Api\AdminController::class, 'stats']);

    // Admin Settings
    Route::get('/settings', [\App\Http\Controllers\Api\SettingController::class, 'index']);
    Route::post('/settings', [\App\Http\Controllers\Api\SettingController::class, 'update']);
});
