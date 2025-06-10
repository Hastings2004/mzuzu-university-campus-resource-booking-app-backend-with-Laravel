<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BinarySearchController;
use App\Http\Controllers\BookingApprovalController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Reservation;
use App\Http\Controllers\ResourceController;
use App\Http\Controllers\UserController;
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
});

// Route::middleware(['auth', 'admin'])->group(function () {
//     Route::get('/bookings/approval', [BookingApprovalController::class, 'index']);
//     Route::get('/bookings/{id}/approval', [BookingApprovalController::class, 'show']);
//     Route::post('/bookings/{id}/approve', [BookingApprovalController::class, 'approve']);
//     Route::post('/bookings/{id}/reject', [BookingApprovalController::class, 'reject']);
//     Route::post('/bookings/{id}/cancel', [BookingApprovalController::class, 'cancel']);
//     Route::post('/bookings/bulk-approve', [BookingApprovalController::class, 'bulkApprove']);
// });

Route::prefix('search')->group(function () {
    Route::post('/', [BinarySearchController::class, 'search']);
    Route::post('/multi-field', [BinarySearchController::class, 'multiFieldSearch']);
    Route::post('/global', [BinarySearchController::class, 'globalSearch']);
    Route::delete('/cache', [BinarySearchController::class, 'clearCache']);
    Route::post('/perform-multi-search', [BinarySearchController::class, 'performMultiSearch']);
});
$user = Auth::user();

$admin = $user && $user->user_type === 'admin'; // Check if the user is an admin
Route::middleware(['auth:sanctum', $admin])->group(function () { // Make sure 'admin' middleware is correctly applied
    Route::post('/bookings/{id}/approve', [BookingApprovalController::class, 'approve']);
    Route::post('/bookings/{id}/reject', [BookingApprovalController::class, 'reject']);
    Route::post('/bookings/{id}/cancel', [BookingApprovalController::class, 'cancel']); // For specific cancellation logic
    Route::delete('/bookings/{id}', [BookingController::class, 'destroy']); // Assuming a general delete in a different controller or here if you want a hard delete by admin
    Route::post('/bookings/bulk-approve', [BookingApprovalController::class, 'bulkApprove']);
});
 


