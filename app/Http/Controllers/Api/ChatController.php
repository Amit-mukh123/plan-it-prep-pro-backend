<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserConfig;
use App\Models\DailyDietPlans;
use App\Services\ChatMealPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    public function __construct(private readonly ChatMealPlanService $mealPlanService)
    {
    }

    /**
     * Generate daily meal plan using user profile + user config and persist to DB.
     */
    public function generateMealPlan(Request $request): JsonResponse
    {
        Log::info('Meal plan generation requested', [
            'user_id' => auth()->id(),
            'date' => $request->input('date'),
            'refresh' => (bool) $request->input('refresh', false),
            'ingredient_mode' => (bool) $request->input('isIngredients', false),
            'country' => $request->input('country'),
            'state' => $request->input('state'),
            'city' => $request->input('city'),
        ]);

        $validated = $request->validate([
            'date' => 'nullable|date',
            'refresh' => 'nullable|boolean',
            'isIngredients' => 'nullable|boolean',
            'ingredients' => 'nullable|array',
        ]);

        $user = auth()->user();

        $isIngredientMode = (bool) (
            $validated['idingridients']
            ?? $validated['isIngredient']
            ?? false
        );

        $providedIngredients = $validated['ingredients'] ?? $validated['ingridients'] ?? [];

        // Retrieve location context (country, state, city) strictly from config JSON.
        $config = UserConfig::where('user_id', $user->id)->first();
        $configData = is_array($config?->data) ? $config->data : [];
        $configLocation = is_array($configData['location'] ?? null) ? $configData['location'] : [];
        $configAnswers = is_array($configData['answers'] ?? null) ? $configData['answers'] : [];

        $country = $configLocation['country'] ?? ($configAnswers['country'] ?? ($configData['country'] ?? null));
        $state = $configLocation['state'] ?? ($configAnswers['state'] ?? ($configData['state'] ?? null));
        $city = $configLocation['city'] ?? ($configAnswers['city'] ?? ($configData['city'] ?? null));

        $locationContext = [
            'country' => $country ? trim((string) $country) : null,
            'state' => $state ? trim((string) $state) : null,
            'city' => $city ? trim((string) $city) : null,
        ];

        $result = $this->mealPlanService->generateAndSave(
            $user,
            $validated['date'] ?? null,
            (bool) ($validated['refresh'] ?? false),
            $isIngredientMode,
            is_array($providedIngredients) ? $providedIngredients : [],
            $locationContext
        );

        Log::info('Meal plan generation finished', [
            'user_id' => $user->id,
            'status' => $result['status'] ?? null,
            'code' => $result['code'] ?? 200,
            'source' => $result['data']['source'] ?? null,
        ]);

        return response()->json(
            array_merge(
                [
                    'status' => $result['status'],
                    'message' => $result['message'],
                    'data' => $result['data'] ?? null,
                ],
                array_diff_key($result, array_flip(['code', 'data']))
            ),
            $result['code'] ?? 200
        );
    }

    /**
     * Fetch saved plan for a date, or latest plan for authenticated user.
     */
    public function showMealPlan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'nullable|date',
        ]);

        Log::info('Meal plan fetch requested', [
            'user_id' => auth()->id(),
            'date' => $validated['date'] ?? null,
        ]);

        $plan = $this->mealPlanService->getSavedPlan(
            auth()->user(),
            $validated['date'] ?? null
        );

        if (!$plan) {
            return response()->json([
                'status' => false,
                'message' => 'Meal plan not found.',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Meal plan fetched successfully.',
            'data' => $plan,
        ]);
    }

    /**
     * Refresh a single meal by ID (specific to breakfast/lunch/dinner/snacks).
     */
    public function refreshSingleMeal(Request $request, string $mealId): JsonResponse
    {
        Log::info('Single meal refresh requested', [
            'user_id' => auth()->id(),
            'meal_id' => $mealId,
        ]);

        $validated = $request->validate([
            'isIngredients' => 'nullable|boolean',
            'ingredients' => 'nullable|array',
        ]);


        $user = auth()->user();

        // Validate that the meal exists and belongs to this user before attempting refresh.
        $existing = DailyDietPlans::where('id', $mealId)
            ->where('user_id', $user->id)
            ->first();

        if (!$existing) {
            Log::warning('Single meal refresh - meal not found or not owned by user', [
                'user_id' => $user->id,
                'meal_id' => $mealId,
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Meal not found or does not belong to the authenticated user.',
                'data' => null,
            ], 404);
        }

        $isIngredientMode = (bool) (
            $validated['isIngredients']
            ?? false
        );

        $providedIngredients = $validated['ingredients'] ?? [];

        // Retrieve location context (country, state, city) strictly from config JSON.
        $config = UserConfig::where('user_id', $user->id)->first();
        $configData = is_array($config?->data) ? $config->data : [];
        $configLocation = is_array($configData['location'] ?? null) ? $configData['location'] : [];
        $configAnswers = is_array($configData['answers'] ?? null) ? $configData['answers'] : [];

        $country = $configLocation['country'] ?? ($configAnswers['country'] ?? ($configData['country'] ?? null));
        $state = $configLocation['state'] ?? ($configAnswers['state'] ?? ($configData['state'] ?? null));
        $city = $configLocation['city'] ?? ($configAnswers['city'] ?? ($configData['city'] ?? null));

        $locationContext = [
            'country' => $country ? trim((string) $country) : null,
            'state' => $state ? trim((string) $state) : null,
            'city' => $city ? trim((string) $city) : null,
        ];

        $result = $this->mealPlanService->refreshSingleMealById(
            $user,
            $mealId,
            $isIngredientMode,
            is_array($providedIngredients) ? $providedIngredients : [],
            $locationContext
        );

        Log::info('Single meal refresh finished', [
            'user_id' => $user->id,
            'meal_id' => $mealId,
            'status' => $result['status'] ?? null,
        ]);

        return response()->json(
            array_merge(
                [
                    'status' => $result['status'],
                    'message' => $result['message'],
                    'data' => $result['data'] ?? null,
                ],
                array_diff_key($result, array_flip(['code', 'data']))
            ),
            $result['code'] ?? 200
        );
    }
}
