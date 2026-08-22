<?php

use App\Http\Controllers\ClientAccessController;
use App\Http\Controllers\OtpVerificationController;
use App\Http\Controllers\ReservationAuthController;
use App\Http\Resources\PropertyResource;
use App\Models\Property;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome', [
        'properties' => PropertyResource::collection(Property::query()
            ->with(['images', 'reviews'])
            ->where('status', 'active')
            ->latest()
            ->take(4)
            ->get())->resolve(),
    ]);
})->name('home');

Route::inertia('/properties', 'public/properties')->name('properties.index');
Route::get('/properties/{slug}', function ($slug) {
    return Inertia::render('public/property/[slug]', [
        'slug' => $slug,
    ]);
})->name('properties.show');

Route::post('/reservation/register', [ReservationAuthController::class, 'start']);
Route::post('/reservation/verify', [ReservationAuthController::class, 'verify']);
Route::post('/client/auth/start', [ClientAccessController::class, 'start'])->name('client.auth.start');
Route::post('/client/auth/login', [ClientAccessController::class, 'login'])->name('client.auth.login');
Route::post('/client/auth/verify', [ClientAccessController::class, 'verify'])->name('client.auth.verify');
Route::get('/client/auth/password', [ClientAccessController::class, 'showPassword'])->name('client.auth.password');
Route::post('/client/auth/password', [ClientAccessController::class, 'storePassword'])->name('client.auth.password.store');

Route::middleware(['auth'])->group(function () {
    Route::get('otp/verify', [OtpVerificationController::class, 'show'])->name('otp.verify');
    Route::post('otp/verify', [OtpVerificationController::class, 'store'])->name('otp.verify.store');

    Route::redirect('client', '/dashboard')->name('client.home');
    Route::inertia('client/bookings', 'client/bookings')->name('client.bookings');
    Route::inertia('client/profile', 'client/profile')->name('client.profile');
});

Route::middleware(['auth', 'verified', 'otp.verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
