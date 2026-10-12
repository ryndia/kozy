<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\ClientBookingController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PropertyController;
use App\Http\Controllers\Api\ReviewController;
use Illuminate\Support\Facades\Route;

Route::get('/properties', [PropertyController::class, 'index']);
Route::get('/properties/{slug}', [PropertyController::class, 'show']);
Route::get('/properties/{slug}/availability', [PropertyController::class, 'availability']);

Route::middleware(['web', 'auth'])->group(function () {
    // Reservations
    Route::post('/bookings', [BookingController::class, 'store']);
    Route::get('/client/bookings', [ClientBookingController::class, 'index']);
    Route::get('/client/bookings/{id}', [ClientBookingController::class, 'show'])->whereNumber('id');
    Route::get('/client/bookings/{id}/cancellation-quote', [ClientBookingController::class, 'cancellationQuote'])->whereNumber('id');
    Route::post('/client/bookings/{id}/cancel', [ClientBookingController::class, 'cancel'])->whereNumber('id');
    Route::get('/client/bookings/{id}/receipt', [ClientBookingController::class, 'receipt'])->whereNumber('id');

    // Payments
    Route::post('/payments/create-intent', [PaymentController::class, 'createIntent']);
    Route::post('/payments/confirm', [PaymentController::class, 'confirm']);
    Route::post('/payments/checkout-session', [PaymentController::class, 'createCheckoutSession']);

    // Reviews
    Route::post('/reviews', [ReviewController::class, 'store']);

    // Profile
    Route::get('/me', [ProfileController::class, 'show']);
    Route::patch('/me', [ProfileController::class, 'update']);
});

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware(['web', 'auth'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/user', [AuthController::class, 'user']);
});

// Stripe's settlement notifications. Stripe calls this, so it sits outside the
// session guard - the signature on the raw body is what authenticates it.
Route::post('/payments/stripe/webhook', [PaymentController::class, 'webhook'])
    ->name('payments.stripe.webhook');
