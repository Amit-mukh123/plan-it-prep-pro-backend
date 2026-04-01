<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;

Route::get('/db-test', function () {
    try {
        DB::connection()->getPdo();
        return response()->json([
            "status" => true,
            "message" => "Database connected successfully"
        ]);
    } catch (\Exception $e) {
        return response()->json([
            "status" => false,
            "error" => $e->getMessage()
        ]);
    }
});

Route::prefix('v1')->group(function () {

    // ==============================
    // PUBLIC ROUTES (No Token)
    // ==============================
    Route::controller(AuthController::class)->group(function () {

        Route::post('send-otp', 'sendOtp');
        Route::post('verify-otp', 'verifyOtp');

        Route::post('register-email', 'registerEmail');
        Route::post('login-email', 'loginEmail');

        Route::post('google-login', 'googleLogin');

        Route::post('refresh-token', 'refreshToken');
    });

    // ==============================
    // PROTECTED ROUTES (Need Token)
    // ==============================
    Route::middleware('auth:sanctum')->group(function () {

        Route::get('/profile', function (\Illuminate\Http\Request $request) {
            return response()->json([
                'status' => true,
                'user' => $request->user()
            ]);
        });

        // Example:
        Route::post('/logout', [AuthController::class, 'logout']);

        // future APIs
        // Route::get('/dashboard', [DashboardController::class, 'index']);
    });
});