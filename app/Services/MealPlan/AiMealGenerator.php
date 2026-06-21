<?php

namespace App\Services\MealPlan;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiMealGenerator
{
    public function __construct(
        protected AllergyManager $allergyManager,
        protected MealPlanNormalizer $normalizer
    ) {
    }

    /**
     * Call OpenAI chat completion endpoint to generate meal(s) and normalize the response.
     * Retries up to 3 times if API fails, returned JSON is invalid, or allergy constraints are violated.
     */
    public function generateMeals(
        int|string $userId,
        string $systemPrompt,
        string $userMessage,
        ?string $allergies,
        int $maxAttempts = 3
    ): array {
        $apiKey = (string) config('app.chat_gpt_api_key');
        $model = (string) config('app.chat_gpt_model', 'gpt-4o-mini');

        $attempt = 0;
        $decoded = [];
        $meals = [];

        while ($attempt < $maxAttempts) {
            $attempt++;
            Log::info("OpenAI meal plan generation attempt {$attempt} of {$maxAttempts}", [
                'user_id' => $userId,
            ]);

            $response = Http::timeout(90)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                ])
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                    'response_format' => ['type' => 'json_object'],
                ]);

            Log::info('OpenAI meal plan response received', [
                'user_id' => $userId,
                'status' => $response->status(),
                'successful' => $response->successful(),
                'attempt' => $attempt,
            ]);

            if (!$response->successful()) {
                Log::error('OpenAI meal plan request failed', [
                    'user_id' => $userId,
                    'status' => $response->status(),
                    'error' => $response->json('error.message'),
                    'attempt' => $attempt,
                ]);

                if ($attempt >= $maxAttempts) {
                    return [
                        'success' => false,
                        'message' => $response->json('error.message') ?? 'Failed to generate meal plan.',
                        'code' => $response->status() ?: 500,
                    ];
                }
                continue;
            }

            $rawContent = (string) data_get($response->json(), 'choices.0.message.content', '');
            $decoded = json_decode($rawContent, true);

            if (!is_array($decoded)) {
                Log::error('OpenAI meal plan returned invalid JSON', [
                    'user_id' => $userId,
                    'attempt' => $attempt,
                ]);

                if ($attempt >= $maxAttempts) {
                    return [
                        'success' => false,
                        'message' => 'Invalid AI response JSON.',
                        'code' => 500,
                    ];
                }
                continue;
            }

            $meals = $this->normalizer->normalizeMeals($decoded);
            if (count($meals) === 0) {
                Log::error('OpenAI meal plan response contained no meals', [
                    'user_id' => $userId,
                    'attempt' => $attempt,
                ]);

                if ($attempt >= $maxAttempts) {
                    return [
                        'success' => false,
                        'message' => 'AI response did not contain meals.',
                        'code' => 500,
                    ];
                }
                continue;
            }

            // Validate allergies in the generated response
            $violatedAllergy = $this->allergyManager->checkAllergyViolations($meals, $allergies ?? 'None');
            if ($violatedAllergy !== null) {
                if ($attempt >= $maxAttempts) {
                    return [
                        'success' => false,
                        'message' => "AI generated meal plan contains allergic ingredient or derivative: {$violatedAllergy}. Please try generating again.",
                        'code' => 422,
                    ];
                }
                continue;
            }

            // If we reached here without continuing, it's successful!
            break;
        }

        return [
            'success' => true,
            'decoded' => $decoded,
            'meals' => $meals,
        ];
    }
}
