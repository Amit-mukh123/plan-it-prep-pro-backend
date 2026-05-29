<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use App\Models\UserProfile;
use App\Models\UserConfig;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class UserSummaryController extends Controller
{
    /**
     * Get user summary data for dashboard
     */
    public function getSummary(): JsonResponse
    {
        try {
            $user = auth()->user();
            $userId = $user->id;

            Log::info('User summary requested', [
                'user_id' => $userId,
            ]);

            // Fetch profile & config
            $profile = UserProfile::where('user_id', $userId)->first();
            $config = UserConfig::where('user_id', $userId)->first();

            // ─── DATE FORMAT ─────────────────────────────
            $date = Carbon::now()->format('l, d M'); // Tuesday, 14 Jan

            // ─── GREETING LOGIC ─────────────────────────
            $hour = Carbon::now()->hour;

            if ($hour < 12) {
                $greetingText = "Good morning";
            } elseif ($hour < 17) {
                $greetingText = "Good afternoon";
            } else {
                $greetingText = "Good evening";
            }

            $name = $profile->full_name ?? 'User';
            $greeting = $greetingText . ", " . $name . "! 🌿";

            // ─── DEFAULT VALUES (TEMPORARY) ─────────────
            $caloriesDone = 0;
            // Accessing the CORRECT nested key from your JSON
        $targetCalorieString = $config->data['answers']['target_calorie'] ?? '2000';
        
        // Extract digits (1500) from the string ("1500 kcal...")
        preg_match('/\d+/', $targetCalorieString, $matches);
        $caloriesTotal = isset($matches[0]) ? (int)$matches[0] : 2000;

            // Dummy values for now
            $water = "0.0 L";
            $steps = "0";
            $protein = "0g";
            $mealsDone = "0 / 4";

            // Progress calculation
            $progress = $caloriesTotal > 0
                ? round($caloriesDone / $caloriesTotal, 2)
                : 0;

            // ─── FINAL RESPONSE ─────────────────────────
            return response()->json([
                'status' => true,
                'data' => [
                    "date" => $date,
                    "greeting" => $greeting,
                    "caloriesDone" => $caloriesDone,
                    "caloriesTotal" => $caloriesTotal,
                    "water" => $water,
                    "steps" => $steps,
                    "protein" => $protein,
                    "mealsDone" => $mealsDone,
                    "progress" => $progress,
                    
                    // Specific Profile Data
                    "name" => $name,
                    "age" => $profile->age ?? null,
                    "gender" => $profile->gender ?? null,
                    "height" => $profile->height_cm ?? null,
                    "weight" => $profile->weight_kg ?? null,
                    "target_weight" => $profile->target_weight_kg ?? null,
                    "dietType" => $profile->diet_preference ?? null,
                    "avatar" => $profile->avatar_url ?? null,
                    
                    // Config Data
                    "config" => $config->data ?? []
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('User summary fetch failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch summary',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}