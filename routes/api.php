<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\Auth\AdminAuthController;
use App\Http\Controllers\Api\Auth\LineAuthController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\LeaveRequestController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\WorkSettingController;
use Illuminate\Support\Facades\Route;

// Note: /auth/line/redirect and /auth/line/callback are registered in
// routes/web.php (they need the session middleware for Socialite's OAuth state).

// --- Public QR image for a location (meant to be displayed/printed) ---
Route::get('/locations/{location}/qr', [LocationController::class, 'qrImage'])->name('locations.qr');

// --- SuperAdmin login ---
Route::post('/admin/login', [AdminAuthController::class, 'login'])->middleware('throttle:6,1');

// --- Authenticated employee (LINE) routes ---
Route::middleware(['auth:sanctum', 'employee'])->group(function () {
    Route::get('/me', [LineAuthController::class, 'me']);
    Route::post('/logout', [LineAuthController::class, 'logout']);
    Route::post('/attendance/scan', [AttendanceController::class, 'scan']);
    Route::get('/attendance/history', [AttendanceController::class, 'myHistory']);
});

// --- Authenticated SuperAdmin routes ---
Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/me', [AdminAuthController::class, 'me']);
    Route::post('/logout', [AdminAuthController::class, 'logout']);
    Route::post('/change-password', [AdminAuthController::class, 'changePassword']);

    Route::get('/employees', [EmployeeController::class, 'index']);
    Route::get('/employees/{employee}', [EmployeeController::class, 'show']);
    Route::patch('/employees/{employee}', [EmployeeController::class, 'update']);
    Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy']);

    Route::get('/locations', [LocationController::class, 'index']);
    Route::post('/locations', [LocationController::class, 'store']);
    Route::patch('/locations/{location}', [LocationController::class, 'update']);
    Route::delete('/locations/{location}', [LocationController::class, 'destroy']);
    Route::post('/locations/{location}/regenerate-token', [LocationController::class, 'regenerateToken']);

    Route::get('/reports/daily', [ReportController::class, 'daily']);
    Route::get('/reports/daily/export', [ReportController::class, 'dailyExport']);
    Route::get('/reports/monthly', [ReportController::class, 'monthly']);
    Route::get('/reports/monthly/export', [ReportController::class, 'monthlyExport']);

    Route::post('/attendance', [AttendanceController::class, 'storeManual']);
    Route::delete('/attendance/{attendance}', [AttendanceController::class, 'destroyManual']);

    Route::get('/work-settings', [WorkSettingController::class, 'show']);
    Route::put('/work-settings', [WorkSettingController::class, 'update']);

    Route::get('/holidays', [HolidayController::class, 'index']);
    Route::post('/holidays', [HolidayController::class, 'store']);
    Route::delete('/holidays/{holiday}', [HolidayController::class, 'destroy']);

    Route::get('/leaves', [LeaveRequestController::class, 'index']);
    Route::post('/leaves', [LeaveRequestController::class, 'store']);
    Route::delete('/leaves/{leaveRequest}', [LeaveRequestController::class, 'destroy']);
});
