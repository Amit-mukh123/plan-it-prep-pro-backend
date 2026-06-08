<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // We will handle authorization (superadmin) in the middleware/routes
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string',
            'description' => 'nullable|string',
            'startTime' => 'required|date',
            'endTime' => 'required|date|after:startTime', // Built-in validation that end > start!
            'priority' => 'required|in:low,high,critical',
            'app_type' => 'nullable|in:planeventz,planeventz_vendor',
            'scope' => 'nullable|in:global,api_only,feature',
            'notify' => 'boolean'
        ];
    }
}