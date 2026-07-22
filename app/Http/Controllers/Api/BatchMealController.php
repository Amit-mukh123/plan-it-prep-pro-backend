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

        $totalMeals = $gap * $mealsPerDay;
        $totalCalories = $dailyCalories * $gap;
        $totalProtein = $proteinTarget * $gap;

        $payload = $this->buildPromptPayload(
            $user,
            $profile,
            $config,
            $gap,
            $mealsPerDay,
            $totalMeals,
            $totalCalories,
            $proteinTarget,
            $totalProtein
        );

        $response = $this->callGPT($payload);

        if (!$response) {
            Log::error('Batch meal GPT call failed', [
                'user_id' => $user->id,
            ]);

            return response()->json(['status' => false, 'msg' => 'GPT failed'], 500);
        }

        if (!$this->isValidGeneratedBatch($response, $totalMeals)) {
            Log::warning('Batch meal GPT response invalid', [
                'user_id' => $user->id,
                'expected_meals' => $totalMeals,
            ]);

            return response()->json([
                'status' => false,
                'msg' => 'Invalid batch meal response from AI'
            ], 422);
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
        if (!is_array($cookingDays) || count($cookingDays) === 0) {
            Log::warning('Batch meal generation aborted: cooking days missing', [
                'today' => $today,
                'cooking_days' => $cookingDays,
            ]);

            return 0;
        }

        $cookingDays = array_values(array_filter($cookingDays, fn ($day) => is_numeric($day)));

        if (count($cookingDays) === 0) {
            Log::warning('Batch meal generation aborted: cooking days invalid', [
                'today' => $today,
                'cooking_days' => $cookingDays,
            ]);

            return 0;
        }

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
    private function buildPromptPayload($user, $profile, $config, $gap, $mealsPerDay, $totalMeals, $totalCalories, $proteinTarget, $totalProtein)
    {
        $configData = $config->data;

        if (is_string($configData)) {
            $configData = json_decode($configData, true);
        }

        $answers = $configData['answers'] ?? [];

        $heightCm = $answers['height_cm'] ?? ($answers['height'] ?? ($configData['height_cm'] ?? ($configData['height'] ?? null)));
        $weightKg = $answers['weight_kg'] ?? ($answers['weight'] ?? ($configData['weight_kg'] ?? ($configData['weight'] ?? null)));
        $targetWeightKg = $answers['target_weight_kg'] ?? ($answers['target_weight'] ?? ($configData['target_weight_kg'] ?? ($configData['target_weight'] ?? null)));

        return [
            'user_id' => $user->id,
            'profile' => [
                'full_name' => $profile->full_name,
                'age' => $profile->age,
                'gender' => $profile->gender,
                'height_cm' => $heightCm,
                'weight_kg' => $weightKg,
                'target_weight_kg' => $targetWeightKg,
                'diet_preference' => $profile->diet_preference,
            ],
            'config' => [
                'meals_per_day' => (int) filter_var($config->meals_per_day, FILTER_SANITIZE_NUMBER_INT),
                'target_calorie' => (int) filter_var($config->target_calorie, FILTER_SANITIZE_NUMBER_INT),
                'cooking_days' => $answers['cooking_day'] ?? [],
                'food_pref' => $answers['food_pref'] ?? null,
                'allergies' => $answers['allergies'] ?? null,
                'prep_style' => $answers['prep_style'] ?? null,
                'appliances' => $answers['appliances'] ?? null,
                'cooking_time' => $answers['cooking_time'] ?? null,
                'health_goal' => $answers['health_goal'] ?? null,
            ],
            'cooking_gap_days' => $gap,
            'meals_per_day' => $mealsPerDay,
            'total_meals' => $totalMeals,
            'daily_calories' => (int) filter_var($config->target_calorie, FILTER_SANITIZE_NUMBER_INT),
            'total_calories' => $totalCalories,
            'protein_target_per_day' => $proteinTarget,
            'total_protein' => $totalProtein
        ];
    }

    // ============================
    // GPT CALL
    // ============================
    private function callGPT($payload)
    {
        $apiKey = config('app.chat_gpt_api_key');

        $systemPrompt = 'You are a meal-planning nutrition AI.' . PHP_EOL . PHP_EOL
            . 'Create one batch meal plan for the next cooking cycle.' . PHP_EOL . PHP_EOL
            . 'Hard rules:' . PHP_EOL
            . '- Return valid JSON only.' . PHP_EOL
            . '- Create exactly ' . $payload['total_meals'] . ' meals total.' . PHP_EOL
            . '- That equals ' . $payload['meals_per_day'] . ' meals per day for ' . $payload['cooking_gap_days'] . ' day(s).' . PHP_EOL
            . '- The meals must cover ' . $payload['cooking_gap_days'] . ' day(s) until the next cooking day.' . PHP_EOL
            . '- Respect the user\'s diet preference, allergies, food preference, prep style, and appliances when possible.' . PHP_EOL
            . '- Distribute ' . $payload['total_calories'] . ' total calories and ' . $payload['total_protein'] . ' total protein across the meals.' . PHP_EOL
            . '- Keep each meal batch-friendly and suitable for storage/reheating.' . PHP_EOL
            . '- Use concise but useful steps.' . PHP_EOL . PHP_EOL
            . 'Output schema:' . PHP_EOL
            . '{' . PHP_EOL
            . '    "batches": [' . PHP_EOL
            . '        {' . PHP_EOL
            . '            "meals": [' . PHP_EOL
            . '                {' . PHP_EOL
            . '                    "name": "string",' . PHP_EOL
            . '                    "mealType": "breakfast|lunch|dinner|snack",' . PHP_EOL
            . '                    "calories": 0,' . PHP_EOL
            . '                    "protein": 0,' . PHP_EOL
            . '                    "prepTime": "string",' . PHP_EOL
            . '                    "steps": ["string"]' . PHP_EOL
            . '                }' . PHP_EOL
            . '            ],' . PHP_EOL
            . '            "grocery_list": ["string"]' . PHP_EOL
            . '        }' . PHP_EOL
            . '    ]' . PHP_EOL
            . '}';

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
    // RESPONSE VALIDATION
    // ============================
    private function isValidGeneratedBatch(array $response, int $expectedMealCount): bool
    {
        if (!isset($response['batches'][0])) {
            return false;
        }

        $batch = $response['batches'][0];

        if (!isset($batch['meals']) || !is_array($batch['meals'])) {
            return false;
        }

        if (count($batch['meals']) !== $expectedMealCount) {
            return false;
        }

        if (!isset($batch['grocery_list']) || !is_array($batch['grocery_list'])) {
            return false;
        }

        return true;
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