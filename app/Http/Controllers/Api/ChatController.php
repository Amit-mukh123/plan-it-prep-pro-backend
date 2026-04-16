<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ChatMealPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        $validated = $request->validate([
            'date' => 'nullable|date',
            'refresh' => 'nullable|boolean',
            'isIngredients' => 'nullable|boolean',
            'ingredients' => 'nullable|array',
            'ingridients' => 'nullable|array',
        ]);

        $user = auth()->user();

        $isIngredientMode = (bool) (
            $validated['idingridients']
            ?? $validated['isIngredient']
            ?? false
        );

        $providedIngredients = $validated['ingredients'] ?? $validated['ingridients'] ?? [];

        $result = $this->mealPlanService->generateAndSave(
            $user,
            $validated['date'] ?? null,
            (bool) ($validated['refresh'] ?? false),
            $isIngredientMode,
            is_array($providedIngredients) ? $providedIngredients : []
        );

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
