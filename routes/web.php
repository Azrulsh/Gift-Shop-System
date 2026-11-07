<?php

use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

Route::resource('products', ProductController::class);
Route::resource('customers', CustomerController::class);

Route::get('/', function () {
    return view('welcome');
});
