<?php

use App\Http\Controllers\CustomerController;
use App\Http\Controllers\OrderCancellationController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

Route::resource('products', ProductController::class);
Route::resource('customers', CustomerController::class);
Route::resource('orders', OrderController::class)->except('destroy');
Route::post('orders/{order}/cancellation', OrderCancellationController::class)->name('orders.cancel');

Route::get('/', function () {
    return view('welcome');
});
