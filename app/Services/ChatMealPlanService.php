<?php

namespace App\Services;

use App\Models\DailyDietPlans;
use App\Models\User;
use App\Models\UserConfig;
use App\Models\UserProfile;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

class ChatMealPlanService
{
    private const ALLOWED_MEAL_TYPES = ['breakfast', 'lunch', 'snacks', 'dinner'];

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
    ): array
    {
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

        $activePlans = DailyDietPlans::query()
            ->where('user_id', $user->id)
            ->where('date', $planDate)
            ->where('is_active', true)
            ->whereIn('meal_type', self::ALLOWED_MEAL_TYPES)
            ->orderBy('created_at')
            ->get();

        if (!$refresh && $activePlans->isNotEmpty()) {
            Log::info('Meal plan served from database', [
                'user_id' => $user->id,
                'date' => $planDate,
                'meal_count' => $activePlans->count(),
            ]);

            $aggregated = $this->aggregatePlans($activePlans, $planDate);

            return [
                'status' => true,
                'message' => 'Meal plan fetched from DB.',
                'code' => 200,
                'data' => $aggregated + ['source' => 'db'],
            ];
        }

        $profile = UserProfile::where('user_id', $user->id)->first();
        $config = UserConfig::where('user_id', $user->id)->first();

        if (!$profile) {
            Log::warning('Meal plan service aborted: profile not found', [
                'user_id' => $user->id,
            ]);

            return [
                'status' => false,
                'message' => 'User profile not found. Please complete profile first.',
                'code' => 422,
            ];
        }

        if (!$config) {
            Log::warning('Meal plan service aborted: config not found', [
                'user_id' => $user->id,
            ]);

            return [
                'status' => false,
                'message' => 'User config not found. Please save preferences first.',
                'code' => 422,
            ];
        }

        $normalizedProvidedIngredients = $this->normalizeProvidedIngredients($providedIngredients);

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

        $promptPayload = $this->buildPromptPayload(
            $user,
            $profile,
            $config,
            $planDate,
            $isIngredientMode,
            $normalizedProvidedIngredients,
            $locationContext
        );
        $systemPrompt = $this->buildSystemPrompt($isIngredientMode);

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

        $model = (string) config('app.chat_gpt_model', 'gpt-4o-mini');

        $response = Http::timeout(90)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
            ])
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    [
                        'role' => 'user',
                        'content' => 'Generate my personalized meal plan from this user payload: ' . json_encode($promptPayload),
                    ],
                ],
                'response_format' => ['type' => 'json_object'],
            ]);

        Log::info('OpenAI meal plan response received', [
            'user_id' => $user->id,
            'status' => $response->status(),
            'successful' => $response->successful(),
        ]);

        if (!$response->successful()) {
            Log::error('OpenAI meal plan request failed', [
                'user_id' => $user->id,
                'status' => $response->status(),
                'error' => $response->json('error.message'),
            ]);

            return [
                'status' => false,
                'message' => $response->json('error.message') ?? 'Failed to generate meal plan.',
                'code' => $response->status() ?: 500,
            ];
        }

        $rawContent = (string) data_get($response->json(), 'choices.0.message.content', '');
        $decoded = json_decode($rawContent, true);

        if (!is_array($decoded)) {
            Log::error('OpenAI meal plan returned invalid JSON', [
                'user_id' => $user->id,
            ]);

            return [
                'status' => false,
                'message' => 'Invalid AI response JSON.',
                'code' => 500,
            ];
        }

        $meals = $this->normalizeMeals($decoded);
        if (count($meals) === 0) {
            Log::error('OpenAI meal plan response contained no meals', [
                'user_id' => $user->id,
            ]);

            return [
                'status' => false,
                'message' => 'AI response did not contain meals.',
                'code' => 500,
            ];
        }

        $groceryRequirements = $this->normalizeGroceryRequirements($decoded, $meals);

        // If refresh was requested, deactivate old active records first.
        if ($refresh) {
            DailyDietPlans::query()
                ->where('user_id', $user->id)
                ->where('date', $planDate)
                ->where('is_active', true)
                ->whereIn('meal_type', self::ALLOWED_MEAL_TYPES)
                ->update(['is_active' => false]);
        }

        // Store one row per meal because DB check-constraint allows only fixed meal_type values.
        $createdRows = collect();

        foreach ($meals as $meal) {
            $mealType = $this->resolveMealType((string) ($meal['mealType'] ?? 'snacks'));

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

            $createdRows->push(DailyDietPlans::create([
                'user_id' => $user->id,
                'date' => $planDate,
                'meal_type' => $mealType,
                'is_active' => true,
                'is_favourite' => false,
                'response' => $storedPayload,
            ]));
        }

        $aggregated = $this->aggregatePlans($createdRows, $planDate);

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

        $query = DailyDietPlans::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->whereIn('meal_type', self::ALLOWED_MEAL_TYPES);

        if ($date) {
            $query->where('date', Carbon::parse($date)->toDateString());
            $rows = $query->orderBy('created_at')->get();

            return $rows->isEmpty() ? null : $this->aggregatePlans($rows, Carbon::parse($date)->toDateString());
        }

        $latest = (clone $query)->latest('date')->first();
        if (!$latest) {
            return null;
        }

        $rows = (clone $query)
            ->where('date', $latest->date)
            ->orderBy('created_at')
            ->get();

        return $rows->isEmpty() ? null : $this->aggregatePlans($rows, (string) $latest->date);
    }

    /**
     * Convert a human meal label to DB-allowed meal_type enum/check values.
     */
    private function resolveMealType(string $mealType): string
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
    private function aggregatePlans($rows, string $date): array
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

    /**
     * Build a compact payload used in prompt generation.
     */
    private function buildPromptPayload(
        User $user,
        UserProfile $profile,
        UserConfig $config,
        string $planDate,
        bool $isIngredientMode,
        array $providedIngredients,
        array $locationContext
    ): array
    {
        $configData = is_array($config->data ?? null) ? $config->data : [];
        $configLocation = is_array($configData['location'] ?? null) ? $configData['location'] : [];

        $country = $locationContext['country'] ?? $configLocation['country'] ?? ($configData['country'] ?? null);
        $state = $locationContext['state'] ?? $configLocation['state'] ?? ($configData['state'] ?? null);
        $city = $locationContext['city'] ?? $configLocation['city'] ?? ($configData['city'] ?? null);

        $country = isset($country) ? trim((string) $country) : null;
        $state = isset($state) ? trim((string) $state) : null;
        $city = isset($city) ? trim((string) $city) : null;

        $country = $country !== '' ? $country : null;
        $state = $state !== '' ? $state : null;
        $city = $city !== '' ? $city : null;

        $permission = $locationContext['permission']
            ?? ($configLocation['permission'] ?? 'unknown');

        $source = $locationContext['source']
            ?? ($configLocation['source'] ?? 'database');

        $latitude = $locationContext['latitude'] ?? ($configLocation['latitude'] ?? null);
        $longitude = $locationContext['longitude'] ?? ($configLocation['longitude'] ?? null);

        return [
            'date' => $planDate,
            'ingredient_context' => [
                'isIngredientMode' => $isIngredientMode,
                'providedIngredients' => $providedIngredients,
            ],
            'location_context' => [
                'permission' => $permission,
                'source' => $source,
                'country' => $country,
                'state' => $state,
                'city' => $city,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'suggestion_guidance' => [
                    'if_country_selected_then_suggest_only_that_country_states' => true,
                    'if_state_selected_then_suggest_only_that_state_cities' => true,
                    'india_example' => [
                        'country' => 'India',
                        'states_only_from_india' => true,
                    ],
                ],
                'fallback_guidance' => [
                    'prefer_current_location_when_permission_granted' => true,
                    'if_no_location_permission_use_user_selected_country_state_city' => true,
                    'if_missing_location_use_config_or_neutral_regional_assumptions' => true,
                ],
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
                'diet_preference' => $profile->diet_preference,
            ],
            'config' => $config->data ?? [],
        ];
    }

    /**
     * System prompt with strict response schema.
     */
    private function buildSystemPrompt(bool $isIngredientMode = false): string
    {
        if ($isIngredientMode) {
            return <<<PROMPT
Generate a practical 1-day meal plan from profile + config + provided ingredients.

Location rules:
- Use `location_context` to localize cuisine, ingredient availability, and meal style.
- If `location_context.permission = granted` and GPS/location fields are present, prioritize that location.
- If permission is denied/unavailable, use user-provided country/state/city from `location_context`.
- Apply suggestion logic semantics from payload:
    - If country is selected (e.g., India), state suggestions should only belong to that country.
    - If state is selected, city suggestions should only belong to that state.
- If location is incomplete, fall back to config/profile and keep meals broadly regional-safe.

You MUST prioritize the provided ingredients and suggest meals that can be made using them.
Also identify additional missing ingredients needed to complete cooking.

Must follow user allergies, diet, preferences, calorie target, meal count, prep style, appliances, and cooking time. Allergy safety has highest priority.

Output JSON only (no markdown/text) with exactly these top keys:
1) meals: array of meal objects with keys
id, mealType, time, name, emoji, bgColor, calories, protein, carbs, fat, prepTime, tags (array of strings), ingredients (array of strings), steps (array of strings).
2) groceryRequirements: array of objects with keys item, quantity, category.

Requirements:
- Include at least Breakfast, Lunch, Dinner.
- Meals should clearly reflect usage of provided ingredients when possible.
- groceryRequirements must contain additional/missing ingredients needed to cook the selected meals.
- Do not include ingredients that are already sufficiently available in provided ingredients.
- Use categories (Vegetables/Fruits/Grains/Protein/Dairy/Spices/Pantry/Condiments).
- Keep meals realistic for home cooking and nutritionally aligned to goal.
PROMPT;
        }

        return <<<PROMPT
Generate a practical 1-day meal plan from profile + config.

Location rules:
- Use `location_context` to localize cuisine, ingredient availability, and meal style.
- If `location_context.permission = granted` and GPS/location fields are present, prioritize that location.
- If permission is denied/unavailable, use user-provided country/state/city from `location_context`.
- Apply suggestion logic semantics from payload:
    - If country is selected (e.g., India), state suggestions should only belong to that country.
    - If state is selected, city suggestions should only belong to that state.
- If location is incomplete, fall back to config/profile and keep meals broadly regional-safe.

Must follow user allergies, diet, preferences, calorie target, meal count, prep style, appliances, and cooking time. Allergy safety has highest priority.

Output JSON only (no markdown/text) with exactly these top keys:
1) meals: array of meal objects with keys
id, mealType, time, name, emoji, bgColor, calories, protein, carbs, fat, prepTime, tags (array of strings), ingredients (array of strings), steps (array of strings).
2) groceryRequirements: array of objects with keys item, quantity, category.

Requirements:
- Include at least Breakfast, Lunch, Dinner.
- Main meals should usually have 5+ ingredients and clear steps.
- groceryRequirements must be consolidated for the day, include oils/spices/sauces, merge duplicates, and use categories (Vegetables/Fruits/Grains/Protein/Dairy/Spices/Pantry/Condiments).
- Keep meals realistic for home cooking and nutritionally aligned to goal.
PROMPT;
    }

    /**
     * Normalize provided ingredient payload into a clean list of ingredient names.
     *
     * Supports:
     * - ["egg", "tomato"]
     * - [{"name":"egg"}, {"item":"tomato"}]
     * - {"egg": true, "tomato": false, "onion": true}
     */
    private function normalizeProvidedIngredients(array $providedIngredients): array
    {
        $result = [];

        if (array_is_list($providedIngredients)) {
            foreach ($providedIngredients as $entry) {
                if (is_string($entry)) {
                    $value = trim($entry);
                    if ($value !== '') {
                        $result[] = $value;
                    }
                    continue;
                }

                if (is_array($entry)) {
                    $available = $entry['available'] ?? $entry['is_available'] ?? $entry['selected'] ?? true;
                    if ($available === false || $available === 0 || $available === '0') {
                        continue;
                    }

                    $name = trim((string) ($entry['name'] ?? $entry['item'] ?? $entry['ingredient'] ?? ''));
                    if ($name !== '') {
                        $result[] = $name;
                    }
                }
            }
        } else {
            foreach ($providedIngredients as $key => $value) {
                if (!is_string($key) || trim($key) === '') {
                    continue;
                }

                if (is_bool($value)) {
                    if ($value) {
                        $result[] = trim($key);
                    }
                    continue;
                }

                if (is_numeric($value)) {
                    if ((int) $value === 1) {
                        $result[] = trim($key);
                    }
                    continue;
                }

                if (is_string($value)) {
                    $truthy = in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'y'], true);
                    if ($truthy) {
                        $result[] = trim($key);
                    }
                }
            }
        }

        $unique = [];
        $seen = [];
        foreach ($result as $item) {
            $clean = trim($item);
            if ($clean === '') {
                continue;
            }

            $k = mb_strtolower($clean);
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $unique[] = $clean;
            }
        }

        return $unique;
    }

    /**
     * Normalize AI output to expected meal list shape.
     */
    private function normalizeMeals(array $decoded): array
    {
        $meals = [];

        if (isset($decoded['meals']) && is_array($decoded['meals'])) {
            $meals = $decoded['meals'];
        } elseif (array_is_list($decoded)) {
            $meals = $decoded;
        }

        $normalized = [];
        $index = 1;

        foreach ($meals as $meal) {
            if (!is_array($meal)) {
                continue;
            }

            $normalized[] = [
                'id' => (string) ($meal['id'] ?? $index),
                'mealType' => (string) ($meal['mealType'] ?? 'Meal'),
                'time' => (string) ($meal['time'] ?? '12:00 PM'),
                'name' => (string) ($meal['name'] ?? 'Custom Meal'),
                'emoji' => (string) ($meal['emoji'] ?? '🍽️'),
                'bgColor' => (string) ($meal['bgColor'] ?? '0xFFFFFFFF'),
                'calories' => (int) ($meal['calories'] ?? 0),
                'protein' => (string) ($meal['protein'] ?? '0g'),
                'carbs' => (string) ($meal['carbs'] ?? '0g'),
                'fat' => (string) ($meal['fat'] ?? '0g'),
                'prepTime' => (string) ($meal['prepTime'] ?? '0 min'),
                'tags' => array_values(array_filter(array_map(function ($v) {
                    return is_string($v) ? $v : '';
                }, $meal['tags'] ?? []), fn ($v) => $v !== '')),
                'ingredients' => array_values(array_filter(array_map(function ($v) {
                    if (is_string($v)) return $v;
                    if (is_array($v)) return trim(($v['name'] ?? $v['item'] ?? '') . ' ' . ($v['amount'] ?? $v['quantity'] ?? ''));
                    return '';
                }, $meal['ingredients'] ?? []), fn ($v) => $v !== '')),
                'steps' => array_values(array_filter(array_map(function ($v) {
                    if (is_string($v)) return $v;
                    if (is_array($v)) return (string) ($v['step'] ?? $v['text'] ?? $v['description'] ?? '');
                    return '';
                }, $meal['steps'] ?? []), fn ($v) => $v !== '')),
            ];

            $index++;
        }

        return $normalized;
    }

    /**
     * Normalize grocery list; fallback to deriving from meal ingredients.
     */
    private function normalizeGroceryRequirements(array $decoded, array $meals): array
    {
        if (isset($decoded['groceryRequirements']) && is_array($decoded['groceryRequirements'])) {
            $normalized = [];
            foreach ($decoded['groceryRequirements'] as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $normalized[] = [
                    'item' => (string) ($item['item'] ?? ''),
                    'quantity' => (string) ($item['quantity'] ?? 'As needed'),
                    'category' => (string) ($item['category'] ?? 'General'),
                ];
            }

            $normalized = array_values(array_filter($normalized, fn ($v) => $v['item'] !== ''));
            if (count($normalized) > 0) {
                return $normalized;
            }
        }

        // Fallback: derive grocery requirements from unique ingredients.
        $items = [];
        foreach ($meals as $meal) {
            foreach (($meal['ingredients'] ?? []) as $ingredient) {
                $clean = trim((string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $ingredient));
                if ($clean === '') {
                    continue;
                }
                $key = mb_strtolower($clean);
                $items[$key] = [
                    'item' => $clean,
                    'quantity' => 'As needed',
                    'category' => 'General',
                ];
            }
        }

        return array_values($items);
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
    ): array
    {
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

        if (!$profile || !$config) {
            return [
                'status' => false,
                'message' => 'User profile or config not found.',
                'code' => 422,
            ];
        }

        $normalizedIngredients = $this->normalizeProvidedIngredients($providedIngredients);

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
                'height_cm' => $profile->height_cm,
                'weight_kg' => $profile->weight_kg,
                'target_weight_kg' => $profile->target_weight_kg,
                'diet_preference' => $profile->diet_preference,
            ],
            'config' => $config->data ?? [],
        ];

        $systemPrompt = $isIngredientMode
            ? "Generate exactly one {$mealLabel} meal using the provided ingredients. Return JSON only with keys: meals and groceryRequirements. The meals array must contain exactly one meal object."
            : "Generate exactly one {$mealLabel} meal. Return JSON only with keys: meals and groceryRequirements. The meals array must contain exactly one meal object.";

        $response = Http::timeout(90)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
            ])
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => (string) config('app.chat_gpt_model', 'gpt-4o-mini'),
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => json_encode($prompt)],
                ],
                'response_format' => ['type' => 'json_object'],
            ]);

        if (!$response->successful()) {
            return [
                'status' => false,
                'message' => 'Failed to regenerate meal.',
                'code' => 500,
            ];
        }

        $decoded = json_decode((string) data_get($response->json(), 'choices.0.message.content', '{}'), true);

        if (!is_array($decoded) || !isset($decoded['meals'][0])) {
            return [
                'status' => false,
                'message' => 'Invalid AI response.',
                'code' => 500,
            ];
        }

        $newMeal = $decoded['meals'][0];
        $groceryRequirements = $this->normalizeGroceryRequirements($decoded, [$newMeal]);

        // Deactivate ALL active meals of the same type on the same date for this user.
        // This ensures only the new meal is active for that type/date combination.
        DailyDietPlans::query()
            ->where('user_id', $user->id)
            ->where('date', $meal->date)
            ->where('meal_type', $mealType)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $saved = DailyDietPlans::create([
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
