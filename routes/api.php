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
    
    // Subscribers & Squares
    Route::get('/squares', [App\Http\Controllers\Api\SubscriberController::class, 'squares']);
    Route::post('/squares', [App\Http\Controllers\Api\SubscriberController::class, 'storeSquare']);
    Route::get('/subscribers', [App\Http\Controllers\Api\SubscriberController::class, 'index']);
    Route::post('/subscribers', [App\Http\Controllers\Api\SubscriberController::class, 'store']);
    Route::get('/subscribers/{id}', [App\Http\Controllers\Api\SubscriberController::class, 'show']);
    Route::put('/subscribers/{id}', [App\Http\Controllers\Api\SubscriberController::class, 'update']);
    Route::get('/subscribers/{id}/statement', [App\Http\Controllers\Api\SubscriberController::class, 'statement']);
    Route::post('/meters', [App\Http\Controllers\Api\SubscriberController::class, 'storeMeter']);
    Route::put('/meters/{id}', [App\Http\Controllers\Api\SubscriberController::class, 'updateMeter']);
    Route::post('/subscription-fees', [App\Http\Controllers\Api\SubscriberController::class, 'storeSubscriptionFee']);
    Route::post('/service-requests', [App\Http\Controllers\Api\SubscriberController::class, 'storeServiceRequest']);
    Route::post('/subscription-fees/{id}/invoice', [App\Http\Controllers\Api\SubscriberController::class, 'invoiceSubscriptionFee']);
    Route::post('/service-requests/{id}/invoice', [App\Http\Controllers\Api\SubscriberController::class, 'invoiceServiceRequest']);
    
    // Admin Dashboard Stats
    Route::get('/admin/stats', [\App\Http\Controllers\Api\AdminController::class, 'stats']);

    // Admin Settings
    Route::get('/settings', [\App\Http\Controllers\Api\SettingController::class, 'index']);
    Route::post('/settings', [\App\Http\Controllers\Api\SettingController::class, 'update']);
});
