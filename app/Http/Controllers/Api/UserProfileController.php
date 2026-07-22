<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\UserProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class UserProfileController extends Controller
{
    /**
     * Store or Update User Profile
     */
    public function store(Request $request): JsonResponse
    {
        try {
            Log::info('User profile store requested', [
                'user_id' => auth()->id(),
            ]);

            //  Get authenticated user ID (from token)
            $userId = auth()->user()->id;

            //  Validate request (NO user_id needed from frontend)
            $validated = $request->validate([
                'data.full_name' => 'required|string|max:120',
                'data.gender' => 'required|string',
                'data.age' => 'required|integer|min:1|max:120',
                'data.diet' => 'required|string'
            ]);

            // Extract nested data
            $data = $validated['data'];

            // Update or create profile (one per user)
            $profile = UserProfile::updateOrCreate(
                ['user_id' => $userId], // condition
                [
                    'full_name' => $data['full_name'],
                    'gender' => strtolower($data['gender']), // match enum
                    'age' => $data['age'],
                    'diet_preference' => strtolower($data['diet']),
                    'updated_by' => $userId,
                    'created_by' => $userId
                ]
            );

            return response()->json([
                'status' => true,
                'message' => 'User profile saved successfully',
                'data' => $profile
            ], 200);

        } catch (\Exception $e) {

            Log::error('User profile store failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to save profile',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get Logged-in User Profile
     */
    public function show(): JsonResponse
    {
        try {
            // Get authenticated user ID
            $userId = auth()->user()->id;

            Log::info('User profile fetch requested', [
                'user_id' => $userId,
            ]);

            // Fetch profile
            $profile = UserProfile::where('user_id', $userId)->first();

            return response()->json([
                'status' => true,
                'data' => $profile
            ], 200);

        } catch (\Exception $e) {

            Log::error('User profile fetch failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch profile',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Soft Delete Logged-in User Profile
     */
    public function destroy(): JsonResponse
    {
        try {
            // Get authenticated user ID
            $userId = auth()->user()->id;

            Log::info('User profile delete requested', [
                'user_id' => $userId,
            ]);

            // Find profile
            $profile = UserProfile::where('user_id', $userId)->first();

            if (!$profile) {
                return response()->json([
                    'status' => false,
                    'message' => 'Profile not found'
                ], 404);
            }

            // 🗑 Soft delete (if SoftDeletes enabled in model)
            $profile->delete();

            return response()->json([
                'status' => true,
                'message' => 'User profile deleted successfully'
            ], 200);

        } catch (\Exception $e) {

            Log::error('User profile delete failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to delete profile',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}