<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserConfig;
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
            'ingridients' => 'nullable|array',
            'location_permission' => 'nullable|in:granted,denied,prompt,unknown',
            'location_source' => 'nullable|in:gps,user_input,database,unknown',
            'country' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'location' => 'nullable|array',
            'location.country' => 'nullable|string|max:100',
            'location.state' => 'nullable|string|max:100',
            'location.city' => 'nullable|string|max:100',
            'location.latitude' => 'nullable|numeric|between:-90,90',
            'location.longitude' => 'nullable|numeric|between:-180,180',
        ]);

        $user = auth()->user();

        $isIngredientMode = (bool) (
            $validated['idingridients']
            ?? $validated['isIngredient']
            ?? false
        );

        $providedIngredients = $validated['ingredients'] ?? $validated['ingridients'] ?? [];

        $locationInput = $validated['location'] ?? [];
        $locationContext = [
            'permission' => $validated['location_permission'] ?? 'unknown',
            'source' => $validated['location_source'] ?? 'unknown',
            'country' => $locationInput['country'] ?? ($validated['country'] ?? null),
            'state' => $locationInput['state'] ?? ($validated['state'] ?? null),
            'city' => $locationInput['city'] ?? ($validated['city'] ?? null),
            'latitude' => $locationInput['latitude'] ?? null,
            'longitude' => $locationInput['longitude'] ?? null,
        ];

        // Persist explicit user location input for future fallback (non-breaking merge into user_config.data).
        $hasLocationInput = filled($locationContext['country'])
            || filled($locationContext['state'])
            || filled($locationContext['city'])
            || !is_null($locationContext['latitude'])
            || !is_null($locationContext['longitude']);

        if ($hasLocationInput) {
            $existingConfig = UserConfig::where('user_id', $user->id)->first();
            $existingData = is_array($existingConfig?->data) ? $existingConfig->data : [];

            $existingData['location'] = array_filter([
                'country' => $locationContext['country'],
                'state' => $locationContext['state'],
                'city' => $locationContext['city'],
                'latitude' => $locationContext['latitude'],
                'longitude' => $locationContext['longitude'],
                'permission' => $locationContext['permission'],
                'source' => $locationContext['source'],
            ], fn ($value) => !is_null($value) && $value !== '');

            UserConfig::updateOrCreate(
                ['user_id' => $user->id],
                ['data' => $existingData]
            );
        }

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
            [
                'status' => $result['status'],
                'message' => $result['message'],
                'data' => $result['data'] ?? null,
            ],
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
}
