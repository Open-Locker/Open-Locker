<?php

use App\Http\Controllers\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/reset-password', function (Request $request) {
    return view('auth.reset-password', [
        'email' => $request->string('email')->toString(),
        'token' => $request->string('token')->toString(),
    ]);
})->name('password.reset.form');

Route::post('/reset-password', [AuthController::class, 'storeNewPassword'])
    ->name('password.reset.web.store');
