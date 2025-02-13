<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::controller( AuthController::class )->group( function () {
    Route::post( 'login', 'login' );
} );

Route::middleware( 'auth:sanctum' )->controller( AuthController::class )->group( function () {
    Route::post( 'register', 'register' );
    Route::post( 'logout', 'logout' );
    Route::get( 'user', 'user' );
} );
