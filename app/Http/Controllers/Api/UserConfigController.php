<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\UserConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class UserConfigController extends Controller
{
    /**
     * Store or update user config
     */
    public function store(Request $request): JsonResponse
    {
        try {
            Log::info('User config store requested', [
                'user_id' => auth()->id(),
            ]);

            // Validate request
            $validated = $request->validate([
                'data' => 'required|array'
            ]);

            // Get authenticated user
            $user = auth()->user();

            // Create or update config (one per user)
            $config = UserConfig::updateOrCreate(
                ['user_id' => $user->id],
                ['data' => $validated['data']]
            );

            return response()->json([
                'status' => true,
                'message' => 'User config saved successfully',
                'data' => $config
            ], 200);

        } catch (\Exception $e) {

            Log::error('User config store failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to save config',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user config
     */
    public function show(): JsonResponse
    {
        try {
            $user = auth()->user();

            Log::info('User config fetch requested', [
                'user_id' => $user->id,
            ]);

            $config = UserConfig::where('user_id', $user->id)->first();

            return response()->json([
                'status' => true,
                'data' => $config
            ], 200);

        } catch (\Exception $e) {

            Log::error('User config fetch failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch config',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete user config
     */
    public function destroy(): JsonResponse
    {
        try {
            $user = auth()->user();

            Log::info('User config delete requested', [
                'user_id' => $user->id,
            ]);

            UserConfig::where('user_id', $user->id)->delete();

            return response()->json([
                'status' => true,
                'message' => 'User config deleted successfully'
            ], 200);

        } catch (\Exception $e) {

            Log::error('User config delete failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to delete config',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}