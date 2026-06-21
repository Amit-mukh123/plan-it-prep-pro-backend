<?php

namespace App\Services\MealPlan;

use App\Models\DailyDietPlans;
use Illuminate\Support\Collection;

class MealPlanRepository
{
    private const ALLOWED_MEAL_TYPES = ['breakfast', 'lunch', 'snacks', 'dinner'];

    /**
     * Get active plans for user on specific date.
     */
    public function getActivePlansForDate(int|string $userId, string $date): Collection
    {
        return DailyDietPlans::query()
            ->where('user_id', $userId)
            ->where('date', $date)
            ->where('is_active', true)
            ->whereIn('meal_type', self::ALLOWED_MEAL_TYPES)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Deactivate active plans for user on specific date and meal types.
     */
    public function deactivateActivePlans(int|string $userId, string $date, ?string $mealType = null): void
    {
        $query = DailyDietPlans::query()
            ->where('user_id', $userId)
            ->where('date', $date)
            ->where('is_active', true);

        if ($mealType !== null) {
            $query->where('meal_type', $mealType);
        } else {
            $query->whereIn('meal_type', self::ALLOWED_MEAL_TYPES);
        }

        $query->update(['is_active' => false]);
    }

    /**
     * Create a daily diet plan row.
     */
    public function createPlan(array $data): DailyDietPlans
    {
        return DailyDietPlans::create($data);
    }

    /**
     * Get the latest plan for a user.
     */
    public function getLatestActivePlan(int|string $userId): ?DailyDietPlans
    {
        return DailyDietPlans::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->whereIn('meal_type', self::ALLOWED_MEAL_TYPES)
            ->latest('date')
            ->first();
    }

    /**
     * Convert a human meal label to DB-allowed meal_type enum/check values.
     */
    public function resolveMealType(string $mealType): string
    {
        $normalized = strtolower(trim($mealType));

        if (str_contains($normalized, 'breakfast')) {
            return 'breakfast';
        }

        if (str_contains($normalized, 'lunch')) {
            return 'lunch';
        }

        if (str_contains($normalized, 'dinner')) {
            return 'dinner';
        }

        return 'snacks';
    }

    /**
     * Build a single API payload from multiple meal rows.
     */
    public function aggregatePlans(Collection $rows, string $date): array
    {
        $meals = [];
        $groceryRequirements = [];
        $firstId = null;

        foreach ($rows as $row) {
            $response = is_array($row->response) ? $row->response : [];
            $firstId ??= $row->id;

            // New format: one meal per row.
            if (isset($response['meal']) && is_array($response['meal'])) {
                $meals[] = $response['meal'];
            }

            // Backward compatibility: old format had full meals array in each row.
            if (isset($response['meals']) && is_array($response['meals'])) {
                foreach ($response['meals'] as $meal) {
                    if (is_array($meal)) {
                        $meals[] = $meal;
                    }
                }
            }

            if (empty($groceryRequirements) && isset($response['groceryRequirements']) && is_array($response['groceryRequirements'])) {
                $groceryRequirements = $response['groceryRequirements'];
            }
        }

        // De-duplicate meals by id+name while preserving order.
        $seen = [];
        $uniqueMeals = [];
        foreach ($meals as $meal) {
            $key = (($meal['id'] ?? '') . '|' . ($meal['name'] ?? ''));
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $uniqueMeals[] = $meal;
            }
        }

        return [
            'plan_id' => $firstId,
            'date' => $date,
            'mealType' => 'daily_plan',
            'meals' => $uniqueMeals,
            'groceryRequirements' => $groceryRequirements,
        ];
    }
}
