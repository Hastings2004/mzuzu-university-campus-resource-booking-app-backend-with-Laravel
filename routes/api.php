<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BinarySearchController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Reservation;
use App\Http\Controllers\ResourceController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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
});

Route::prefix('search')->group(function () {
    Route::post('/', [BinarySearchController::class, 'search']);
    Route::post('/multi-field', [BinarySearchController::class, 'multiFieldSearch']);
    Route::post('/global', [BinarySearchController::class, 'globalSearch']);
    Route::delete('/cache', [BinarySearchController::class, 'clearCache']);
    Route::post('/perform-multi-search', [BinarySearchController::class, 'performMultiSearch']);
});

 


