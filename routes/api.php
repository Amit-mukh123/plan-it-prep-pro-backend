<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\RequestLoggingMiddleware;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\UserConfigController;
use App\Http\Controllers\Api\UserProfileController;
use App\Http\Controllers\Api\BatchMealController;
use App\Http\Controllers\Api\UserSummaryController;

Route::middleware([RequestLoggingMiddleware::class])->group(function () {

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

        // Route::post('send-otp', 'sendOtp');
        Route::post('send-email-otp', 'sendEmailOtp');
        // Route::post('verify-otp', 'verifyOtp');
        Route::post('verify-email-otp', 'verifyEmailOtp');

        Route::post('register', 'registerEmail');
        Route::post('login-email', 'loginEmail');

        Route::post('google-login', 'googleLogin');

        Route::post('refresh-token', 'refreshToken');
    });

    // ==============================
    // PROTECTED ROUTES (Need Token For Access)
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

        Route::post('/user-config-store', [UserConfigController::class, 'store']);

        Route::get('/user-config-show', [UserConfigController::class, 'show']);

        Route::post('/user-profile-store', [UserProfileController::class, 'store']);

        Route::post('/chat/generate-meal-plan', [ChatController::class, 'generateMealPlan']);

        Route::get('/chat/meal-plan', [ChatController::class, 'showMealPlan']);

        Route::post('/batch-meal-plan', [BatchMealController::class, 'generateBatchMeal']);

        Route::get('/get-user-summary', [UserSummaryController::class, 'getSummary']);

        // future APIs
        // Route::get('/dashboard', [DashboardController::class, 'index']);
    });
});

});