<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BinarySearchController;
use App\Http\Controllers\BookingApprovalController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\Reservation;
use App\Http\Controllers\ResourceController;
use App\Http\Controllers\UserController;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/logout', [AuthController::class, 'logout']);
    Route::apiResource('/resources', ResourceController::class);    
    Route::apiResource('/bookings', BookingController::class);
    Route::post('/bookings/check-availability', [BookingController::class, 'checkAvailability']);
    Route::get('/user/upcoming-booking', [BookingController::class, 'getUserBookings']);
    Route::get('/profile', [UserController::class, 'getProfile']);
    Route::put('/users/{id}/update', [UserController::class, 'update']);
    Route::get('/users', [UserController::class, 'index']);
    Route::patch('user/change-password', [UserController::class, 'changePassword']);
    Route::get('/dashboard-stats', [DashboardController::class, 'index']);
    Route::patch('/bookings/{booking}/cancel', [BookingController::class, 'cancelBooking']);
    Route::get('/bookings/cancellable', [BookingController::class, 'getCancellableBookings']);
    Route::get('/bookings/{booking}/can-cancel', [BookingController::class, 'checkCancellationEligibility']);
    Route::post('/reservations', [Reservation::class, 'store']); 
    Route::get('/search/global', [BinarySearchController::class, 'globalSearch']);
    Route::post('/bookings/{id}/approve', [BookingApprovalController::class, 'approve']);
    Route::get('/resources/{id}/bookings', [ResourceController::class, 'getResourceBookings']);
    Route::post('/bookings/{id}/reject', [BookingApprovalController::class, 'reject']);
    Route::post('/bookings/{id}/cancel', [BookingApprovalController::class, 'cancel']); 
    Route::delete('/bookings/{id}', [BookingController::class, 'destroy']); 
    Route::post('/bookings/bulk-approve', [BookingApprovalController::class, 'bulkApprove']);
    Route::get('/reports/resource-utilization', [ReportController::class, 'getResourceUtilization']);



    // Route::get('/email/verify', [AuthController::class, 'verifyNotice']); // Throttle to prevent abuse

    // Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');

    // Route::post('/email/verification-notification', [AuthController::class, 'sendVerificationEmail'])
    //     ->middleware(['auth:sanctum', 'throttle:6,1'])
    //     ->name('verification.send');

    Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware(['signed', 'throttle:6,1']) // 'signed' middleware validates the URL signature
        ->name('verification.verify');

    // Route to resend verification email
    Route::post('/email/verification-notification', [AuthController::class, 'sendVerificationEmail'])
        ->middleware(['auth:sanctum', 'throttle:6,1']) // Auth and throttle to prevent abuse
        ->name('verification.send');


    Route::post('/resend-verification-email', [AuthController::class, 'resendVerificationPublic']);

    
});

Route::prefix('search')->group(function () {
    Route::post('/', [BinarySearchController::class, 'search']);
    Route::post('/multi-field', [BinarySearchController::class, 'multiFieldSearch']);
    Route::post('/global', [BinarySearchController::class, 'globalSearch']);
    Route::delete('/cache', [BinarySearchController::class, 'clearCache']);
    Route::post('/perform-multi-search', [BinarySearchController::class, 'performMultiSearch']);
});

 


