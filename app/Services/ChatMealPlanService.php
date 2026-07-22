<?php

namespace App\Services;

use App\Models\DailyDietPlans;
use App\Models\User;
use App\Models\UserConfig;
use App\Models\UserProfile;
use App\Services\MealPlan\AllergyManager;
use App\Services\MealPlan\MealPlanNormalizer;
use App\Services\MealPlan\PromptBuilder;
use App\Services\MealPlan\MealPlanRepository;
use App\Services\MealPlan\AiMealGenerator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

class ChatMealPlanService
{
    public function __construct(
        protected AllergyManager $allergyManager,
        protected MealPlanNormalizer $normalizer,
        protected PromptBuilder $promptBuilder,
        protected MealPlanRepository $repository,
        protected AiMealGenerator $generator
    ) {
    }

    /**
     * Generate daily meal plan with refresh logic and persist into existing table.
     *
     * Logic:
     * - refresh = 0 and active data exists => return from DB
     * - refresh = 1 and active data exists => deactivate old, generate new, save new active row
     * - no active data => generate and save
     */
    public function generateAndSave(
        User $user,
        ?string $date = null,
        bool $refresh = false,
        bool $isIngredientMode = false,
        array $providedIngredients = [],
        array $locationContext = []
    ): array {
        set_time_limit(300);
        Log::info('Meal plan service started', [
            'user_id' => $user->id,
            'date' => $date,
            'refresh' => $refresh,
            'ingredient_mode' => $isIngredientMode,
        ]);

        if (!Schema::hasTable('daily_diet_plans')) {
            Log::error('Meal plan service aborted: daily_diet_plans table missing', [
                'user_id' => $user->id,
            ]);

            return [
                'status' => false,
                'message' => 'Table daily_diet_plans does not exist. Please create it in the database before generating meal plans.',
                'code' => 500,
            ];
        }

        $planDate = $date ? Carbon::parse($date)->toDateString() : now()->toDateString();

        $activePlans = $this->repository->getActivePlansForDate($user->id, $planDate);

        if (!$refresh && $activePlans->isNotEmpty()) {
            Log::info('Meal plan served from database', [
                'user_id' => $user->id,
                'date' => $planDate,
                'meal_count' => $activePlans->count(),
            ]);

            $aggregated = $this->repository->aggregatePlans($activePlans, $planDate);

            return [
                'status' => true,
                'message' => 'Meal plan fetched from DB.',
                'code' => 200,
                'data' => $aggregated + ['source' => 'db'],
            ];
        }

        $profile = UserProfile::where('user_id', $user->id)->first();
        $config = UserConfig::where('user_id', $user->id)->first();

        if ($profile === null) {
            Log::warning('Meal plan service aborted: profile not found', [
                'user_id' => $user->id,
            ]);

            return [
                'status' => false,
                'message' => 'User profile not found. Please complete profile first.',
                'code' => 422,
                'isProfileSetup' => false,
            ];
        }

        if ($config === null) {
            Log::warning('Meal plan service aborted: config not found', [
                'user_id' => $user->id,
            ]);

            return [
                'status' => false,
                'message' => 'User config not found. Please save preferences first.',
                'code' => 422,
                'isConfigSetup' => false,
            ];
        }

        $normalizedProvidedIngredients = $this->normalizer->normalizeProvidedIngredients($providedIngredients);

        if ($isIngredientMode && count($normalizedProvidedIngredients) === 0) {
            Log::warning('Meal plan service aborted: ingredient mode without ingredients', [
                'user_id' => $user->id,
            ]);

            return [
                'status' => false,
                'message' => 'Ingredient mode is enabled, but no valid ingredients were provided.',
                'code' => 422,
            ];
        }

        $configData = is_array($config->data ?? null) ? $config->data : [];
        $answers = is_array($configData['answers'] ?? null) ? $configData['answers'] : [];

        // Parse target_calorie (e.g., "1800 kcal (Moderate)")
        $targetCalorieString = $answers['target_calorie'] ?? $configData['target_calorie'] ?? '2000';
        preg_match('/\d+/', (string) $targetCalorieString, $calorieMatches);
        $calorieTarget = isset($calorieMatches[0]) ? (int) $calorieMatches[0] : 2000;

        // Parse meals_per_day (e.g., "4 meals")
        $mealsPerDayString = $answers['meals_per_day'] ?? $configData['meals_per_day'] ?? '3';
        preg_match('/\d+/', (string) $mealsPerDayString, $mealsMatches);
        $mealsPerDay = isset($mealsMatches[0]) ? (int) $mealsMatches[0] : 3;

        $promptPayload = $this->promptBuilder->buildPromptPayload(
            $user,
            $profile,
            $config,
            $planDate,
            $isIngredientMode,
            $normalizedProvidedIngredients,
            $locationContext,
            $calorieTarget,
            $mealsPerDay
        );

        $allergies = $answers['allergies'] ?? ($configData['allergies'] ?? 'None');
        $preferences = [
            'allergies' => $allergies,
            'food_preference' => $answers['food_pref'] ?? ($configData['food_pref'] ?? 'None'),
            'diet_preference' => $profile->diet_preference ?? 'None',
            'prep_style' => $answers['prep_style'] ?? ($configData['prep_style'] ?? 'None'),
            'appliances' => $answers['appliances'] ?? ($configData['appliances'] ?? 'Stove & Oven'),
            'cooking_time' => $answers['cooking_time'] ?? ($configData['cooking_time'] ?? 'Anytime'),
            'health_goal' => $answers['health_goal'] ?? ($configData['health_goal'] ?? 'None'),
        ];

        $systemPrompt = $this->promptBuilder->buildSystemPrompt($mealsPerDay, $calorieTarget, $isIngredientMode, $preferences);

        $apiKey = (string) config('app.chat_gpt_api_key');
        if ($apiKey === '') {
            Log::error('Meal plan service aborted: OpenAI key missing', [
                'user_id' => $user->id,
            ]);

            return [
                'status' => false,
                'message' => 'CHAT_GPT_API_KEY is not configured.',
                'code' => 500,
            ];
        }

        $userMessage = 'Generate my personalized meal plan from this user payload: ' . json_encode($promptPayload);
        if (filled($allergies) && strtolower(trim($allergies)) !== 'none') {
            $parsedAllergies = $this->allergyManager->parseAllergies($allergies);
            if (!empty($parsedAllergies)) {
                $derivatives = $this->allergyManager->getAllergyDerivatives($parsedAllergies);
                $userMessage .= "\n\nCRITICAL SAFETY RULE: The user is severely allergic to: " . implode(', ', $parsedAllergies) . ". You MUST NOT recommend, list, or include any ingredients, foods, dishes, or products containing, derived from, or associated with any of these items (such as: " . implode(', ', $derivatives) . "). Double-check all generated meal names, ingredients, and steps to ensure absolute compliance.";
            }
        }

        $result = $this->generator->generateMeals($user->id, $systemPrompt, $userMessage, $allergies);

        if (!$result['success']) {
            return [
                'status' => false,
                'message' => $result['message'],
                'code' => $result['code'],
            ];
        }

        $decoded = $result['decoded'];
        $meals = $result['meals'];

        $groceryRequirements = $this->normalizer->normalizeGroceryRequirements($decoded, $meals);

        // If refresh was requested, deactivate old active records first.
        if ($refresh) {
            $this->repository->deactivateActivePlans($user->id, $planDate);
        }

        // Store one row per meal because DB check-constraint allows only fixed meal_type values.
        $createdRows = collect();
        $model = (string) config('app.chat_gpt_model', 'gpt-4o-mini');

        foreach ($meals as $meal) {
            $mealType = $this->repository->resolveMealType((string) ($meal['mealType'] ?? 'snacks'));

            $storedPayload = [
                'meal' => $meal,
                'groceryRequirements' => $groceryRequirements,
                // Keep context for audit/debug without schema changes.
                'meta' => [
                    'profile_snapshot' => $profile->toArray(),
                    'user_config_snapshot' => $config->toArray(),
                    'location_context' => $promptPayload['location_context'] ?? null,
                    'ingredient_mode' => $isIngredientMode,
                    'provided_ingredients' => $normalizedProvidedIngredients,
                    'ai_prompt' => $systemPrompt,
                    'ai_model' => $model,
                    'raw_ai_response' => $decoded,
                    'generated_at' => now()->toDateTimeString(),
                ],
            ];

            $createdRows->push($this->repository->createPlan([
                'user_id' => $user->id,
                'date' => $planDate,
                'meal_type' => $mealType,
                'is_active' => true,
                'is_favourite' => false,
                'response' => $storedPayload,
            ]));
        }

        $aggregated = $this->repository->aggregatePlans($createdRows, $planDate);

        return [
            'status' => true,
            'message' => 'Meal plan generated and saved successfully.',
            'code' => 200,
            'data' => $aggregated + ['source' => 'ai'],
        ];
    }

    /**
     * Fetch a persisted plan by date (or latest if date omitted).
     */
    public function getSavedPlan(User $user, ?string $date = null): ?array
    {
        if (!Schema::hasTable('daily_diet_plans')) {
            return null;
        }

        if ($date) {
            $planDate = Carbon::parse($date)->toDateString();
            $rows = $this->repository->getActivePlansForDate($user->id, $planDate);

            return $rows->isEmpty() ? null : $this->repository->aggregatePlans($rows, $planDate);
        }

        $latest = $this->repository->getLatestActivePlan($user->id);
        if (!$latest) {
            return null;
        }

        $rows = $this->repository->getActivePlansForDate($user->id, (string) $latest->date);

        return $rows->isEmpty() ? null : $this->repository->aggregatePlans($rows, (string) $latest->date);
    }

    /**
     * Refresh a single daily meal record by meal ID.
     */
    public function refreshSingleMealById(
        User $user,
        string $mealId,
        bool $isIngredientMode = false,
        array $providedIngredients = [],
        array $locationContext = []
    ): array {
        Log::info('Single meal refresh service started', [
            'user_id' => $user->id,
            'meal_id' => $mealId,
        ]);

        $meal = DailyDietPlans::query()->find($mealId);

        if (!$meal) {
            return [
                'status' => false,
                'message' => 'Meal not found.',
                'code' => 404,
            ];
        }

        if ($meal->user_id !== $user->id) {
            return [
                'status' => false,
                'message' => 'Unauthorized meal refresh request.',
                'code' => 403,
            ];
        }

        $profile = UserProfile::query()->where('user_id', $user->id)->first();
        $config = UserConfig::query()->where('user_id', $user->id)->first();

        if ($profile === null) {
            return [
                'status' => false,
                'message' => 'User profile not found.',
                'code' => 422,
                'isProfileSetup' => false,
            ];
        }

        if ($config === null) {
            return [
                'status' => false,
                'message' => 'User config not found.',
                'code' => 422,
                'isConfigSetup' => false,
            ];
        }

        $normalizedIngredients = $this->normalizer->normalizeProvidedIngredients($providedIngredients);

        if ($isIngredientMode && count($normalizedIngredients) === 0) {
            return [
                'status' => false,
                'message' => 'Ingredient mode enabled, but no ingredients were provided.',
                'code' => 422,
            ];
        }

        $apiKey = (string) config('app.chat_gpt_api_key');
        if ($apiKey === '') {
            return [
                'status' => false,
                'message' => 'CHAT_GPT_API_KEY is not configured.',
                'code' => 500,
            ];
        }

        $mealType = (string) $meal->meal_type;
        $mealLabel = match ($mealType) {
            'breakfast' => 'Breakfast',
            'lunch' => 'Lunch',
            'dinner' => 'Dinner',
            default => 'Snack',
        };

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

        $heightCm = $answers['height_cm'] ?? ($answers['height'] ?? ($configData['height_cm'] ?? ($configData['height'] ?? null)));
        $weightKg = $answers['weight_kg'] ?? ($answers['weight'] ?? ($configData['weight_kg'] ?? ($configData['weight'] ?? null)));
        $targetWeightKg = $answers['target_weight_kg'] ?? ($answers['target_weight'] ?? ($configData['target_weight_kg'] ?? ($configData['target_weight'] ?? null)));

        $prompt = [
            'meal_id' => $meal->id,
            'meal_type' => $mealType,
            'date' => $meal->date,
            'location' => [
                'country' => $locationContext['country'] ?? null,
                'state' => $locationContext['state'] ?? null,
                'city' => $locationContext['city'] ?? null,
            ],
            'ingredient_mode' => $isIngredientMode,
            'ingredients' => $normalizedIngredients,
            'profile' => [
                'full_name' => $profile->full_name,
                'gender' => $profile->gender,
                'age' => $profile->age,
                'height_cm' => $heightCm,
                'weight_kg' => $weightKg,
                'target_weight_kg' => $targetWeightKg,
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
        ];

        $allergyInstruction = $this->allergyManager->buildAllergyInstruction($allergies);

        $systemPrompt = $isIngredientMode
            ? "Generate exactly one {$mealLabel} meal using the provided ingredients. Return JSON only with keys: meals and groceryRequirements. The meals array must contain exactly one meal object. The meal must strictly adhere to the following user preferences: Food Preference: {$foodPref}, Diet Preference: {$dietPreference}, Prep Style: {$prepStyle}, Appliances: {$appliances}, Cooking Time: {$cookingTime}, Health Goal: {$healthGoal}.{$allergyInstruction}"
            : "Generate exactly one {$mealLabel} meal. Return JSON only with keys: meals and groceryRequirements. The meals array must contain exactly one meal object. The meal must strictly adhere to the following user preferences: Food Preference: {$foodPref}, Diet Preference: {$dietPreference}, Prep Style: {$prepStyle}, Appliances: {$appliances}, Cooking Time: {$cookingTime}, Health Goal: {$healthGoal}.{$allergyInstruction}";

        $userMessage = json_encode($prompt);
        if (filled($allergies) && strtolower(trim($allergies)) !== 'none') {
            $parsedAllergies = $this->allergyManager->parseAllergies($allergies);
            if (!empty($parsedAllergies)) {
                $derivatives = $this->allergyManager->getAllergyDerivatives($parsedAllergies);
                $userMessage .= "\n\nCRITICAL SAFETY RULE: The user is severely allergic to: " . implode(', ', $parsedAllergies) . ". You MUST NOT recommend, list, or include any ingredients, foods, dishes, or products containing, derived from, or associated with any of these items (such as: " . implode(', ', $derivatives) . "). Double-check the generated meal to ensure absolute compliance.";
            }
        }

        $result = $this->generator->generateMeals($user->id, $systemPrompt, $userMessage, $allergies);

        if (!$result['success']) {
            return [
                'status' => false,
                'message' => $result['message'],
                'code' => $result['code'],
            ];
        }

        $decoded = $result['decoded'];
        $meals = $result['meals'];
        $newMeal = $meals[0];

        $groceryRequirements = $this->normalizer->normalizeGroceryRequirements($decoded, [$newMeal]);

        // Deactivate ALL active meals of the same type on the same date for this user.
        // This ensures only the new meal is active for that type/date combination.
        $this->repository->deactivateActivePlans($user->id, $meal->date, $mealType);

        $saved = $this->repository->createPlan([
            'user_id' => $user->id,
            'date' => $meal->date,
            'meal_type' => $mealType,
            'is_active' => true,
            'is_favourite' => false,
            'response' => [
                'meal' => $newMeal,
                'groceryRequirements' => $groceryRequirements,
                'meta' => [
                    'refreshed_from_meal_id' => $meal->id,
                    'generated_at' => now()->toDateTimeString(),
                    'ingredient_mode' => $isIngredientMode,
                    'provided_ingredients' => $normalizedIngredients,
                ],
            ],
        ]);

        return [
            'status' => true,
            'message' => 'Meal refreshed successfully.',
            'code' => 200,
            'data' => [
                'id' => $saved->id,
                'meal_type' => $saved->meal_type,
                'date' => $saved->date,
                'response' => $saved->response,
            ],
        ];
    }
}
