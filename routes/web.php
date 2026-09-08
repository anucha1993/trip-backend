<?php

use App\Http\Controllers\Api\Auth\LineAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Registered under routes/web.php (not api.php) because Socialite's OAuth
// redirect/callback flow needs the session middleware to store/verify "state".
Route::prefix('api')->group(function () {
    Route::get('/auth/line/redirect', [LineAuthController::class, 'redirect']);
    Route::get('/auth/line/callback', [LineAuthController::class, 'callback']);
});
