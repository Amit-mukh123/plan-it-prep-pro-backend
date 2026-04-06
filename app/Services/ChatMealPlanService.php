<?php

namespace App\Services;

use App\Models\DailyDietPlans;
use App\Models\User;
use App\Models\UserConfig;
use App\Models\UserProfile;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

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
    public function generateAndSave(User $user, ?string $date = null, bool $refresh = false): array
    {
        if (!Schema::hasTable('daily_diet_plans')) {
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
            return [
                'status' => false,
                'message' => 'User profile not found. Please complete profile first.',
                'code' => 422,
            ];
        }

        if (!$config) {
            return [
                'status' => false,
                'message' => 'User config not found. Please save preferences first.',
                'code' => 422,
            ];
        }

        $promptPayload = $this->buildPromptPayload($user, $profile, $config, $planDate);
        $systemPrompt = $this->buildSystemPrompt();

        $apiKey = (string) config('app.chat_gpt_api_key');
        if ($apiKey === '') {
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

        if (!$response->successful()) {
            return [
                'status' => false,
                'message' => $response->json('error.message') ?? 'Failed to generate meal plan.',
                'code' => $response->status() ?: 500,
            ];
        }

        $rawContent = (string) data_get($response->json(), 'choices.0.message.content', '');
        $decoded = json_decode($rawContent, true);

        if (!is_array($decoded)) {
            return [
                'status' => false,
                'message' => 'Invalid AI response JSON.',
                'code' => 500,
            ];
        }

        $meals = $this->normalizeMeals($decoded);
        if (count($meals) === 0) {
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
    private function buildPromptPayload(User $user, UserProfile $profile, UserConfig $config, string $planDate): array
    {
        return [
            'date' => $planDate,
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
    private function buildSystemPrompt(): string
    {
        return <<<PROMPT
Generate a practical 1-day meal plan from profile + config.

Must follow user allergies, diet, preferences, calorie target, meal count, prep style, appliances, and cooking time. Allergy safety has highest priority.

Output JSON only (no markdown/text) with exactly these top keys:
1) meals: array of meal objects with keys
id, mealType, time, name, emoji, bgColor, calories, protein, carbs, fat, prepTime, tags, ingredients, steps.
2) groceryRequirements: array of objects with keys item, quantity, category.

Requirements:
- Include at least Breakfast, Lunch, Dinner.
- Main meals should usually have 5+ ingredients and clear steps.
- groceryRequirements must be consolidated for the day, include oils/spices/sauces, merge duplicates, and use categories (Vegetables/Fruits/Grains/Protein/Dairy/Spices/Pantry/Condiments).
- Keep meals realistic for home cooking and nutritionally aligned to goal.
PROMPT;
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
                'tags' => array_values(array_filter($meal['tags'] ?? [], fn ($v) => is_string($v) && $v !== '')),
                'ingredients' => array_values(array_filter($meal['ingredients'] ?? [], fn ($v) => is_string($v) && $v !== '')),
                'steps' => array_values(array_filter($meal['steps'] ?? [], fn ($v) => is_string($v) && $v !== '')),
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
}
