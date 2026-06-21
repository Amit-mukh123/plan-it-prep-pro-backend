<?php

namespace App\Services\MealPlan;

use Illuminate\Support\Facades\Log;

class AllergyManager
{
    /**
     * Parse and format the allergy string into a highly explicit bulleted exclusion list for the system prompt.
     */
    public function buildAllergyInstruction(?string $allergies): string
    {
        if (blank($allergies) || strtolower(trim($allergies)) === 'none') {
            return '';
        }

        $parsed = $this->parseAllergies($allergies);
        if (empty($parsed)) {
            return '';
        }

        $derivatives = $this->getAllergyDerivatives($parsed);

        $bulletList = '';
        foreach ($parsed as $item) {
            $bulletList .= "\n  * {$item}";
        }

        $derivativesList = '';
        if (!empty($derivatives)) {
            $derivativesList = "\n  Specifically, you MUST NOT include any of the following ingredients, derivatives, or related terms: " . implode(', ', $derivatives) . ".";
        }

        return "\n- CRITICAL SAFETY RULE: The user is severely allergic to the following items:{$bulletList}{$derivativesList}\n  You MUST NOT recommend, list, or include any ingredients, foods, dishes, or products containing, derived from, or associated with any of these items (e.g. if allergic to egg, do not recommend omelettes or mayonnaise). This allergy constraint has the absolute highest priority. Double-check all ingredients, steps, and meal names to ensure complete safety and compliance.\n";
    }

    /**
     * Helper to decompose comma and space separated allergy inputs into clean distinct keywords/phrases.
     */
    public function parseAllergies(string $allergies): array
    {
        $terms = [];
        $segments = explode(',', $allergies);
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            $terms[] = $segment;

            $words = preg_split('/\s+/', $segment);
            if (count($words) > 1) {
                foreach ($words as $word) {
                    $word = trim($word);
                    if ($word !== '') {
                        $terms[] = $word;
                    }
                }
            }
        }

        $ignoredWords = ['and', 'or', 'none', 'no', 'free', 'allergy', 'allergies'];
        $uniqueTerms = [];
        foreach ($terms as $term) {
            $lower = strtolower($term);
            if (in_array($lower, $ignoredWords, true)) {
                continue;
            }
            $uniqueTerms[$lower] = $term;
        }

        return array_values($uniqueTerms);
    }

    /**
     * Map allergy keywords to common synonyms, products, or derivatives.
     */
    public function getAllergyDerivatives(array $allergies): array
    {
        $derivatives = [];
        $map = [
            'egg' => ['egg', 'eggs', 'omelette', 'omelet', 'mayo', 'mayonnaise'],
            'chicken' => ['chicken', 'poultry'],
            'mutton' => ['mutton', 'lamb', 'goat'],
            'milk' => ['milk', 'cheese', 'butter', 'yogurt', 'curd', 'paneer', 'cream', 'dairy', 'whey'],
            'dairy' => ['milk', 'cheese', 'butter', 'yogurt', 'curd', 'paneer', 'cream', 'dairy', 'whey'],
            'wheat' => ['wheat', 'gluten', 'semolina', 'all-purpose flour', 'all purpose flour', 'wheat flour', 'white flour', 'plain flour', 'maida'],
            'gluten' => ['wheat', 'gluten', 'semolina', 'all-purpose flour', 'all purpose flour', 'wheat flour', 'white flour', 'plain flour', 'maida'],
            'fish' => ['fish', 'salmon', 'tuna', 'cod', 'seafood'],
            'seafood' => ['fish', 'salmon', 'tuna', 'cod', 'shrimp', 'prawn', 'lobster', 'crab', 'seafood'],
            'peanut' => ['peanut', 'peanuts'],
            'nut' => ['nut', 'nuts', 'almond', 'walnut', 'cashew', 'hazelnut', 'pistachio'],
            'nuts' => ['nut', 'nuts', 'almond', 'walnut', 'cashew', 'hazelnut', 'pistachio'],
            'soy' => ['soy', 'soya', 'tofu', 'tempeh'],
            'beef' => ['beef', 'steak'],
            'pork' => ['pork', 'bacon', 'ham'],
        ];

        foreach ($allergies as $allergy) {
            $lowerAllergy = strtolower($allergy);
            $derivatives[] = $lowerAllergy;

            // Check if we have mapped derivatives
            foreach ($map as $key => $values) {
                if (str_contains($lowerAllergy, $key) || str_contains($key, $lowerAllergy)) {
                    $derivatives = array_merge($derivatives, $values);
                }
            }
        }

        return array_values(array_unique($derivatives));
    }

    /**
     * Clean meal text to remove safe allergen alternatives/descriptors before validation.
     */
    public function cleanMealTextForAllergyCheck(string $mealText): string
    {
        $safePhrases = [
            'chickpea flour',
            'garbanzo flour',
            'rice flour',
            'almond flour',
            'coconut flour',
            'oat flour',
            'tapioca flour',
            'tapioca starch',
            'corn flour',
            'corn starch',
            'potato starch',
            'potato flour',
            'gluten-free flour',
            'gluten free flour',
            'gluten-free bread',
            'gluten free bread',
            'gluten-free pasta',
            'gluten free pasta',
            'almond milk',
            'coconut milk',
            'soy milk',
            'soymilk',
            'oat milk',
            'rice milk',
            'cashew milk',
            'pea milk',
            'hemp milk',
            'flax milk',
            'macadamia milk',
            'peanut butter',
            'almond butter',
            'cashew butter',
            'sunflower seed butter',
            'sunflower butter',
            'cocoa butter',
            'shea butter',
            'coconut butter',
            'vegan cheese',
            'soy cheese',
            'cashew cheese',
            'coconut cheese',
            'coconut yogurt',
            'soy yogurt',
            'almond yogurt',
            'oat yogurt',
            'coconut cream',
            'cashew cream',
            'vegan cream',
            'gluten-free',
            'gluten free',
            'egg-free',
            'egg free',
            'eggless',
            'dairy-free',
            'dairy free',
            'soy-free',
            'soy free',
            'wheat-free',
            'wheat free',
            'nut-free',
            'nut free',
            'peanut-free',
            'peanut free',
            'sugar-free',
            'sugar free',
            'fat-free',
            'fat free',
            'guilt-free',
            'guilt free',
            'lactose-free',
            'lactose free',
            'cholesterol-free',
            'cholesterol free',
        ];

        return str_replace($safePhrases, '', strtolower($mealText));
    }

    /**
     * Check if a text contains an allergy keyword with proper word boundaries.
     */
    public function hasAllergyMatch(string $text, string $allergy): bool
    {
        $allergyEscaped = preg_quote(strtolower($allergy), '/');
        // Match the allergen as a whole word with optional trailing 's' or 'es' for plurals.
        $pattern = '/\b' . $allergyEscaped . '(s|es)?\b/i';
        return (bool) preg_match($pattern, $text);
    }

    /**
     * Check if a list of meals has any allergy violations.
     * Returns the violated allergy string if a violation is found, null otherwise.
     */
    public function checkAllergyViolations(array $meals, string $allergies): ?string
    {
        if (blank($allergies) || strtolower(trim($allergies)) === 'none') {
            return null;
        }

        $parsedAllergies = $this->parseAllergies($allergies);
        $allergyChecks = $this->getAllergyDerivatives($parsedAllergies);

        foreach ($meals as $meal) {
            $mealText = ($meal['name'] ?? '') . ' ' . implode(' ', $meal['ingredients'] ?? []) . ' ' . implode(' ', $meal['steps'] ?? []);
            $cleanedMealText = $this->cleanMealTextForAllergyCheck($mealText);
            foreach ($allergyChecks as $allergy) {
                if ($this->hasAllergyMatch($cleanedMealText, $allergy)) {
                    Log::warning('AI generated meal violated allergy constraint during validation check', [
                        'allergy' => $allergy,
                        'meal_name' => $meal['name'] ?? 'Unknown',
                    ]);
                    return $allergy;
                }
            }
        }

        return null;
    }
}
