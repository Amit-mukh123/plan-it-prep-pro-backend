<?php

namespace App\Services\MealPlan;

class MealPlanNormalizer
{
    /**
     * Normalize provided ingredient payload into a clean list of ingredient names.
     *
     * Supports:
     * - ["egg", "tomato"]
     * - [{"name":"egg"}, {"item":"tomato"}]
     * - {"egg": true, "tomato": false, "onion": true}
     */
    public function normalizeProvidedIngredients(array $providedIngredients): array
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
    public function normalizeMeals(array $decoded): array
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
                }, $meal['tags'] ?? []), fn($v) => $v !== '')),
                'ingredients' => array_values(array_filter(array_map(function ($v) {
                    if (is_string($v))
                        return $v;
                    if (is_array($v))
                        return trim(($v['name'] ?? $v['item'] ?? '') . ' ' . ($v['amount'] ?? $v['quantity'] ?? ''));
                    return '';
                }, $meal['ingredients'] ?? []), fn($v) => $v !== '')),
                'steps' => array_values(array_filter(array_map(function ($v) {
                    if (is_string($v))
                        return $v;
                    if (is_array($v))
                        return (string) ($v['step'] ?? $v['text'] ?? $v['description'] ?? '');
                    return '';
                }, $meal['steps'] ?? []), fn($v) => $v !== '')),
            ];

            $index++;
        }

        return $normalized;
    }

    /**
     * Normalize grocery list; fallback to deriving from meal ingredients.
     */
    public function normalizeGroceryRequirements(array $decoded, array $meals): array
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

            $normalized = array_values(array_filter($normalized, fn($v) => $v['item'] !== ''));
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
