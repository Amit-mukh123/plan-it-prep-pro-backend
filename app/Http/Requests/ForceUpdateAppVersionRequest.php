<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the body for PATCH /v2/versions/force-update.
 *
 * Mirrors `forceUpdateSchema` from versions.validation.ts:
 *   version  : required string
 *   platform : 'android' | 'ios'
 */
class ForceUpdateAppVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'version'  => 'required|string|max:30',
            'platform' => 'required|string|in:android,ios',
        ];
    }

    public function messages(): array
    {
        return [
            'platform.in' => 'Invalid platform. Allowed values are "android" and "ios".',
        ];
    }
}
