<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\RequestLoggingMiddleware;
use App\Http\Middleware\MaintenanceCheckMiddleware;
use App\Http\Middleware\ForceUpdateMiddleware;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\UserConfigController;
use App\Http\Controllers\Api\UserProfileController;
use App\Http\Controllers\Api\BatchMealController;
use App\Http\Controllers\Api\UserSummaryController;
use App\Http\Controllers\Api\MaintenanceController;
use App\Http\Controllers\Api\VersionController;

Route::middleware([RequestLoggingMiddleware::class])->group(function () {

    Route::get('/db-test', function () {
        try {
            DB::connection()->getPdo();
            return response()->json([
                "status"  => true,
                "message" => "Database connected successfully",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                "status" => false,
                "error"  => $e->getMessage(),
            ]);
        }
    });

    // ══════════════════════════════════════════════════════
    // PUBLIC — No authentication required
    // ══════════════════════════════════════════════════════

    // // GET /api/v2/versions/latest?device=android|ios
    // // Mobile clients call this before login to check for updates.
    // Route::prefix('v2/versions')->group(function () {
    //     Route::get('/latest', [VersionController::class, 'getLatestVersion']);
    // });

    Route::prefix('v1')->group(function () {

        // ──────────────────────────────────────────────────
        // Public auth routes (no token needed)
        // ──────────────────────────────────────────────────
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

        // ──────────────────────────────────────────────────
        // Protected routes
        // Middleware stack (order matters):
        //   1. MaintenanceCheckMiddleware — blocks with 503 if maintenance is active
        //   2. ForceUpdateMiddleware      — blocks with 426 if client version is stale
        //   3. auth:sanctum              — validates the bearer token
        //
        // Mirrors the Node reference order:
        //   mainRouter.use(maintenanceMiddleware)   (line 59 of modules/index.ts)
        //   mainRouter.use(authenticate)            (line 62 of modules/index.ts)
        //   forceUpdateMiddleware applied per-router in versions.routes.ts
        // ──────────────────────────────────────────────────
        Route::middleware([
            MaintenanceCheckMiddleware::class,
            ForceUpdateMiddleware::class,
            'auth:sanctum',
        ])->group(function () {

            Route::get('/profile', function (\Illuminate\Http\Request $request) {
                return response()->json([
                    'status' => true,
                    'user'   => $request->user(),
                ]);
            });

            Route::post('/logout', [AuthController::class, 'logout']);

            Route::post('/user-config-store', [UserConfigController::class, 'store']);
            Route::get('/user-config-show',   [UserConfigController::class, 'show']);

            Route::post('/user-profile-store', [UserProfileController::class, 'store']);

            Route::post('/chat/generate-meal-plan',       [ChatController::class, 'generateMealPlan']);
            Route::get('/chat/meal-plan',                 [ChatController::class, 'showMealPlan']);
            Route::post('/chat/meal/{mealId}/refresh',    [ChatController::class, 'refreshSingleMeal']);

            Route::post('/batch-meal-plan',   [BatchMealController::class, 'generateBatchMeal']);
            Route::get('/get-user-summary',   [UserSummaryController::class, 'getSummary']);

            // future APIs
            // Route::get('/dashboard', [DashboardController::class, 'index']);

            // ── Maintenance admin routes ──────────────────
            Route::prefix('maintenance')->group(function () {
                Route::post('/create',        [MaintenanceController::class, 'create']);
                Route::delete('/delete/{id}', [MaintenanceController::class, 'delete']);
                Route::put('/update/{id}',    [MaintenanceController::class, 'update']);
                Route::get('/all',            [MaintenanceController::class, 'getAll']);
                Route::get('/state',          [MaintenanceController::class, 'fetchState']);
                Route::get('/schedule',       [MaintenanceController::class, 'schedule']);
                Route::get('/history',        [MaintenanceController::class, 'history']);
            });
        });
    });

    // ══════════════════════════════════════════════════════
    // SUPERADMIN — Version management
    // Intentionally outside ForceUpdateMiddleware so admins can still
    // push a new version or toggle force-update even when the app is blocked.
    // Mirrors the Node reference: /v2/versions is mounted BEFORE maintenanceMiddleware
    // (modules/index.ts line 78 vs line 59).
    // ══════════════════════════════════════════════════════
    Route::middleware(['auth:sanctum'])->prefix('v1/versions')->group(function () {
        Route::post('/create',        [VersionController::class, 'create']);
        Route::patch('/force-update', [VersionController::class, 'forceUpdate']);
        Route::get('/list',           [VersionController::class, 'getAllVersionsList']);
        Route::get('/latest', [VersionController::class, 'getLatestVersion']);
    });
});