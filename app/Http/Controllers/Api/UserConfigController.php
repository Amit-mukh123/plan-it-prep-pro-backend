<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\UserConfig;
use Illuminate\Http\JsonResponse;

class UserConfigController extends Controller
{
    /**
     * Store or update user config
     */
    public function store(Request $request): JsonResponse
    {
        try {
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

            $config = UserConfig::where('user_id', $user->id)->first();

            return response()->json([
                'status' => true,
                'data' => $config
            ], 200);

        } catch (\Exception $e) {

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

            UserConfig::where('user_id', $user->id)->delete();

            return response()->json([
                'status' => true,
                'message' => 'User config deleted successfully'
            ], 200);

        } catch (\Exception $e) {

            return response()->json([
                'status' => false,
                'message' => 'Failed to delete config',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}