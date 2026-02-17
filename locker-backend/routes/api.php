<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AppInfoController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CompartmentController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\LockerBankStatusController;
use App\Http\Controllers\Mqtt\MosquittoAuthController;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\VerifyMosqHttpAuth;
use Illuminate\Support\Facades\Route;

// Publicly accessible route for API identification
Route::get('identify', [AppInfoController::class, 'identify'])->name('api.identify');

Route::controller(AuthController::class)->group(function () {
    Route::post('login', 'login')->middleware(['throttle:6,1'])->name('auth.login');
    Route::get('password/email', 'sendPasswordEmail')->middleware(['throttle:6,1'])->name('password.reset');

    Route::post('reset-password', 'storeNewPassword')
        ->name('password.store');

});

Route::middleware('auth:sanctum')->group(function () {

    Route::get('locker-banks/{lockerBank}/status', LockerBankStatusController::class)
        ->name('locker-banks.status');

    Route::get('compartments', [CompartmentController::class, 'index'])
        ->name('compartments.index');

    Route::controller(AuthController::class)->group(function () {
        Route::post('logout', 'logout')->name('auth.logout');
        Route::get('user', 'user')->name('auth.user');
        Route::get('verify-email/{id}/{hash}', 'verifyEmail')
            ->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
        Route::post('email/verification-notification', 'sendVerificationEmail')
            ->middleware('throttle:6,1')
            ->name('verification.send');

    });
    Route::controller(ItemController::class)->prefix('/items')->group(function () {
        Route::get('', 'index')->name('items.index');
    });

    Route::controller(CompartmentController::class)->prefix('/compartments')->group(function () {
        Route::post('{compartment}/open', 'open')->name('compartments.open');
        Route::get('open-requests/{commandId}', 'openStatus')->name('compartments.open-status');
    });

    // Admin routes
    Route::middleware(AdminMiddleware::class)->prefix('admin')->name('admin.')->group(function () {
        Route::controller(AdminController::class)->group(function () {
            Route::get('users', 'getAllUsers')->name('users.index');
            Route::post('users/{user}/make-admin', 'makeAdmin')->name('users.make-admin');
            Route::post('users/{user}/remove-admin', 'removeAdmin')->name('users.remove-admin');
            Route::get('statistics', 'getStatistics')->name('statistics');
        });

        Route::post('users/register', [AuthController::class, 'register'])->name('users.register');

        // Locker routes for administrators
        // Route::controller(LockerController::class)->prefix('lockers')->name('lockers.')->group(function () {
        //     Route::get('', 'index')->name('index');
        //     Route::post('{locker}/open', 'openLocker')->name('open');
        // });
    });
});

// Mosquitto HTTP auth endpoints (secured via Basic Auth middleware)
Route::prefix('mosq')->group(function () {
    Route::post('auth', [MosquittoAuthController::class, 'auth'])
        ->middleware(VerifyMosqHttpAuth::class);
    Route::post('superuser', [MosquittoAuthController::class, 'superuser'])
        ->middleware(VerifyMosqHttpAuth::class);
    Route::post('acl', [MosquittoAuthController::class, 'acl'])
        ->middleware(VerifyMosqHttpAuth::class);
});
