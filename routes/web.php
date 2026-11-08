<?php

use App\Http\Controllers\CustomerController;
use App\Http\Controllers\OrderCancellationController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SupplierController;
use Illuminate\Support\Facades\Route;

Route::resource('products', ProductController::class);
Route::resource('customers', CustomerController::class);
Route::resource('suppliers', SupplierController::class);
Route::resource('orders', OrderController::class)->except('destroy');
Route::post('orders/{order}/cancellation', OrderCancellationController::class)->name('orders.cancel');

Route::prefix('reports')->name('reports.')->controller(ReportController::class)->group(function (): void {
    Route::get('/', 'index')->name('index');
    foreach (['sales', 'products', 'customers', 'suppliers'] as $report) {
        Route::get($report, $report)->name($report);
        Route::get($report.'/export', $report)->name($report.'.export');
    }
});

Route::get('/', function () {
    return view('welcome');
});
