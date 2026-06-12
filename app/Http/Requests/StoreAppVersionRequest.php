<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the body for POST /v2/versions/create.
 *
 * Mirrors `createVersionSchema` from versions.validation.ts:
 *   version      : required string
 *   platform     : 'android' | 'ios'
 *   is_stable    : boolean (default true, optional)
 *   release_notes: optional string
 *   update_message: optional string
 */
class StoreAppVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route-level auth (auth:sanctum) handles authentication.
        // Role-gating (superadmin) should be handled at the route/middleware level.
        return true;
    }

    public function rules(): array
    {
        return [
            'version'        => 'required|string|max:30',
            'platform'       => 'required|string|in:android,ios',
            'is_stable'      => 'boolean',
            'release_notes'  => 'nullable|string',
            'update_message' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'platform.in' => 'Invalid platform. Allowed values are "android" and "ios".',
        ];
    }
}
