<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use App\Models\UserProfile;
use App\Models\UserConfig;
use Carbon\Carbon;

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
            // Later you will replace with real tables
            $caloriesDone = 0;
            $caloriesTotal = 2000;

            if ($config && isset($config->data['daily_calories'])) {
                $caloriesTotal = (int) $config->data['daily_calories'];
            }

            // Dummy values for now (future tables needed)
            $water = "0.0 L";
            $steps = "0";
            $protein = "0g";
            $mealsDone = "0 / 4";

            // Progress calculation (safe)
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
                    "progress" => $progress
                ]
            ], 200);

        } catch (\Exception $e) {

            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch summary',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}