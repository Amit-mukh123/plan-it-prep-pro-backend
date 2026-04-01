<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
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

    Route::controller(AuthController::class)->group(function () {

        Route::post('send-otp', 'sendOtp');
        Route::post('verify-otp', 'verifyOtp');

        Route::post('register-email', 'registerEmail');
        Route::post('login-email', 'loginEmail');

        Route::post('google-login', 'googleLogin');

        Route::post('refresh-token', 'refreshToken');
    });

});