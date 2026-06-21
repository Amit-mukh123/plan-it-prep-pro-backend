<?php

namespace App\Services\MealPlan;

use App\Models\User;
use App\Models\UserConfig;
use App\Models\UserProfile;

class PromptBuilder
{
    public function __construct(protected AllergyManager $allergyManager)
    {
    }

    /**
     * Build a compact payload used in prompt generation.
     */
    public function buildPromptPayload(
        User $user,
        UserProfile $profile,
        UserConfig $config,
        string $planDate,
        bool $isIngredientMode,
        array $providedIngredients,
        array $locationContext,
        int $calorieTarget,
        int $mealsPerDay
    ): array {
        $country = $locationContext['country'] ?? null;
        $state = $locationContext['state'] ?? null;
        $city = $locationContext['city'] ?? null;
        $configData = is_array($config->data ?? null) ? $config->data : [];
        $answers = is_array($configData['answers'] ?? null) ? $configData['answers'] : [];
        $dietPreference = $profile->diet_preference;
        $allergies = $answers['allergies'] ?? ($configData['allergies'] ?? 'None');
        $foodPref = $answers['food_pref'] ?? ($configData['food_pref'] ?? 'None');
        $mealsPerDayString = $answers['meals_per_day'] ?? ($configData['meals_per_day'] ?? '3 meals');
        $prepStyle = $answers['prep_style'] ?? ($configData['prep_style'] ?? 'None');
        $appliances = $answers['appliances'] ?? ($configData['appliances'] ?? 'Stove & Oven');
        $cookingTime = $answers['cooking_time'] ?? ($configData['cooking_time'] ?? 'Anytime');
        $targetCalorieString = $answers['target_calorie'] ?? ($configData['target_calorie'] ?? '2000 kcal');
        $healthGoal = $answers['health_goal'] ?? ($configData['health_goal'] ?? 'None');

        return [
            'date' => $planDate,
            'ingredient_context' => [
                'isIngredientMode' => $isIngredientMode,
                'providedIngredients' => $providedIngredients,
            ],
            'location_context' => [
                'country' => $country,
                'state' => $state,
                'city' => $city,
            ],
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'phone_number' => $user->phone_number,
            ],
            'profile' => [
                'full_name' => $profile->full_name,
                'gender' => $profile->gender,
                'age' => $profile->age,
                'height_cm' => $profile->height_cm,
                'weight_kg' => $profile->weight_kg,
                'target_weight_kg' => $profile->target_weight_kg,
                'diet_preference' => $dietPreference,
            ],
            'config' => [
                'allergies' => $allergies,
                'food_preference' => $foodPref,
                'meals_per_day' => $mealsPerDayString,
                'prep_style' => $prepStyle,
                'appliances' => $appliances,
                'cooking_time' => $cookingTime,
                'target_calorie' => $targetCalorieString,
                'health_goal' => $healthGoal,
                'diet_preference' => $dietPreference,
                'raw_config_data' => $configData,
            ],
            'calorie_target' => $calorieTarget,
            'meals_per_day' => $mealsPerDay,
        ];
    }

    /**
     * System prompt with strict response schema (optimized).
     */
    public function buildSystemPrompt(
        int $mealsPerDay,
        int $calorieTarget,
        bool $isIngredientMode = false,
        array $preferences = []
    ): string {
        $allergies = $preferences['allergies'] ?? 'None';
        $foodPref = $preferences['food_preference'] ?? 'None';
        $dietPref = $preferences['diet_preference'] ?? 'None';
        $prepStyle = $preferences['prep_style'] ?? 'None';
        $appliances = $preferences['appliances'] ?? 'None';
        $cookingTime = $preferences['cooking_time'] ?? 'None';
        $healthGoal = $preferences['health_goal'] ?? 'None';

        $allergyInstruction = $this->allergyManager->buildAllergyInstruction($allergies);
        $allergyBlock = $allergyInstruction ? "{$allergyInstruction}\n" : '';

        $ingredientHeader = $isIngredientMode ? ' + provided ingredients' : '';

        $modeSpecificRules = $isIngredientMode
            ? "- Prioritize provided ingredients; suggest meals that can be made using them.\n- groceryRequirements must contain additional/missing ingredients needed; do not include ingredients already available.\n- Use categories (Vegetables/Fruits/Grains/Protein/Dairy/Spices/Pantry/Condiments)."
            : "- Main meals should usually have 5+ ingredients and clear steps.\n- groceryRequirements must be consolidated for the day, include oils/spices/sauces, merge duplicates, and use categories (Vegetables/Fruits/Grains/Protein/Dairy/Spices/Pantry/Condiments).";

        $allergyRuleAtEnd = '';
        if ($allergyInstruction) {
            $parsed = $this->allergyManager->parseAllergies($allergies);
            $derivatives = $this->allergyManager->getAllergyDerivatives($parsed);
            if (!empty($derivatives)) {
                $allergyRuleAtEnd = "\n- CRITICAL SAFETY RULE: The user is severely allergic to: " . implode(', ', $parsed) . ". Do NOT recommend, list, or include any ingredients, dishes, foods, or products containing or derived from: " . implode(', ', $derivatives) . ". Double-check all meal names, ingredients, and steps.";
            }
        }

        return <<<PROMPT
{$allergyBlock}Generate a 1-day meal plan from profile + config{$ingredientHeader}.

Localize cuisine/style via `location_context` (country, state, city) if available, else keep regional-safe.

Constraints:
- Target: Exactly {$mealsPerDay} meals.
- Daily calories: Combined sum MUST equal {$calorieTarget} kcal (tolerance: ±10).
- Calories: Distribute logically (e.g. Breakfast, Lunch, Dinner, Snack) with integer value.
- Preferences: Diet/Food: {$foodPref} (Diet: {$dietPref}), Prep Style: {$prepStyle}, Appliances: {$appliances}, Cooking: {$cookingTime}, Goal: {$healthGoal}.

Output JSON only (no markdown/text) with exactly these keys:
1) meals: array of meal objects with keys: id, mealType, time, name, emoji, bgColor, calories, protein, carbs, fat, prepTime, tags (array of strings), ingredients (array of strings), steps (array of strings).
2) groceryRequirements: array of objects with keys: item, quantity, category.

Rules:
- Meals count in array: exactly {$mealsPerDay}.
{$modeSpecificRules}
- Keep meals realistic for home cooking and nutritionally aligned to goal.{$allergyRuleAtEnd}
PROMPT;
    }
}
