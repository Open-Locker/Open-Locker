<?php

use App\Http\Controllers\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
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

Route::get('/verify-email/{id}/{hash}', [AuthController::class, 'verifyEmailLink'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify.web');

Route::post('/reset-password', [AuthController::class, 'storeNewPassword'])
    ->name('password.reset.web.store');

// The admin panel's `EN | DE` switcher links here; the locale is persisted in
// the session (plus a long-lived cookie so it survives session expiry) and
// applied on later requests by the panel's SetPanelLocale middleware.
Route::get('/locale/{locale}', function (string $locale) {
    abort_unless(in_array($locale, config('app.supported_locales', ['en']), true), 404);
    session()->put('locale', $locale);
    Cookie::queue(Cookie::forever('locale', $locale));

    return redirect()->back(fallback: '/admin');
})->name('locale.switch');

// Legacy dual-panel locale URLs (ADR-0024) — permanent redirect to the single panel.
Route::get('/{locale}/admin/{path?}', fn (string $locale, ?string $path = null) => redirect('/admin'.($path !== null ? '/'.$path : ''), 301))
    ->where(['locale' => 'en|de', 'path' => '.*'])
    ->name('admin.legacy-locale.redirect');
