<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Meal;
use App\Models\User;
use App\Models\UserConfig;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class BatchMealController extends Controller
{
    // ============================
    // MAIN FUNCTION
    // ============================
    public function generateBatchMeal(Request $request)
    {
        Log::info('Batch meal generation requested', [
            'user_id' => $request->input('user_id'),
            'protein_target' => $request->input('protein_target'),
        ]);

        $user = User::find($request->user_id);

        if (!$user) {
            Log::warning('Batch meal generation aborted: user not found', [
                'user_id' => $request->input('user_id'),
            ]);

            return response()->json(['status' => false, 'msg' => 'User not found'], 404);
        }

        $config = UserConfig::where('user_id', $user->id)->first();
        $profile = UserProfile::where('user_id', $user->id)->first();

        if (!$config || !$profile) {
            Log::warning('Batch meal generation aborted: config/profile missing', [
                'user_id' => $user->id,
                'config_found' => (bool) $config,
                'profile_found' => (bool) $profile,
            ]);

            return response()->json(['status' => false, 'msg' => 'Config/Profile missing'], 400);
        }

        $today = Carbon::now()->dayOfWeekIso;

        $configData = $config->data;

        // If stored as JSON string, decode
        if (is_string($configData)) {
            $configData = json_decode($configData, true);
        }

        $cookingDays = $configData['answers']['cooking_day'] ?? [];

        $gap = $this->calculateGap($today, $cookingDays);

        if ($gap <= 0) {
            return response()->json(['status' => false, 'msg' => 'Invalid cooking gap'], 400);
        }

        $mealsPerDay = (int) filter_var($config->meals_per_day, FILTER_SANITIZE_NUMBER_INT);
        $dailyCalories = (int) filter_var($config->target_calorie, FILTER_SANITIZE_NUMBER_INT);
        $proteinTarget = (int) $request->protein_target;

        $totalCalories = $dailyCalories * $gap;
        $totalProtein = $proteinTarget * $gap;

        $payload = $this->buildPromptPayload(
            $user,
            $profile,
            $config,
            $gap,
            $mealsPerDay,
            $totalCalories,
            $totalProtein
        );

        $response = $this->callGPT($payload);

        if (!$response) {
            Log::error('Batch meal GPT call failed', [
                'user_id' => $user->id,
            ]);

            return response()->json(['status' => false, 'msg' => 'GPT failed'], 500);
        }

        DB::beginTransaction();

        try {

            // deactivate old meals
            Meal::where('user_id', $user->id)
                ->where('meal_mode', 'batch')
                ->update(['deleted_at' => now()]);

            $savedMeals = $this->storeMeals($user, $response, $gap);

            Log::info('Batch meals generated successfully', [
                'user_id' => $user->id,
                'meal_count' => count($savedMeals),
                'gap' => $gap,
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Batch meals generated',
                'data' => $savedMeals
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Batch meal generation failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['status' => false, 'msg' => $e->getMessage()], 500);
        }
    }

    // ============================
    // GAP CALCULATION
    // ============================
    private function calculateGap($today, $cookingDays)
    {
        sort($cookingDays);

        foreach ($cookingDays as $day) {
            if ($day > $today) {
                return $day - $today;
            }
        }

        return (7 - $today) + $cookingDays[0];
    }

    // ============================
    // PROMPT PAYLOAD
    // ============================
    private function buildPromptPayload($user, $profile, $config, $gap, $mealsPerDay, $totalCalories, $totalProtein)
    {
        return [
            'user' => $user->id,
            'profile' => $profile->toArray(),
            'config' => $config->toArray(),
            'gap' => $gap,
            'meals_per_day' => $mealsPerDay,
            'total_calories' => $totalCalories,
            'total_protein' => $totalProtein
        ];
    }

    // ============================
    // GPT CALL
    // ============================
    private function callGPT($payload)
    {
        $apiKey = config('app.chat_gpt_api_key');

        $systemPrompt = "
You are a nutrition AI.

Generate batch meals.

Rules:
- meals count = {$payload['meals_per_day']}
- servings = {$payload['gap']}
- total calories = {$payload['total_calories']}
- total protein = {$payload['total_protein']}

Return JSON:
{
  \"batches\": [
    {
      \"meals\": [],
      \"grocery_list\": []
    }
  ]
}
";

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
        ])->post('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => json_encode($payload)]
            ],
            'response_format' => ['type' => 'json_object']
        ]);

        if (!$response->successful()) {
            Log::error('Batch meal GPT response failed', [
                'status' => $response->status(),
            ]);

            return null;
        }

        Log::info('Batch meal GPT response received', [
            'status' => $response->status(),
            'successful' => true,
        ]);

        return json_decode($response['choices'][0]['message']['content'], true);
    }

    // ============================
    // STORE IN DB
    // ============================
    private function storeMeals($user, $data, $gap)
    {
        $result = [];

        foreach ($data['batches'][0]['meals'] as $meal) {

            $saved = Meal::create([
                'user_id' => $user->id,
                'title' => $meal['name'],
                'description' => 'Batch generated meal',
                'meal_type' => strtolower($meal['mealType']),
                'diet_preference' => 'custom',
                'meal_mode' => 'batch',
                'prep_time_min' => $this->extractMinutes($meal['prepTime']),
                'cook_time_min' => 20,
                'servings' => $gap,
                'calories' => $meal['calories'],
                'protein_g' => $meal['protein'],
                'carbs_g' => 0,
                'fat_g' => 0,
                'fiber_g' => 0,
                'source' => 'ai',
                'meal_date' => now()->toDateString(),
                'prep_steps' => $meal['steps'],
                'metadata' => [
                    'grocery_list' => $data['batches'][0]['grocery_list']
                ],
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            $result[] = $saved;
        }

        return $result;
    }

    // ============================
    // HELPER
    // ============================
    private function extractMinutes($text)
    {
        preg_match('/\d+/', $text, $matches);
        return $matches[0] ?? 10;
    }
}