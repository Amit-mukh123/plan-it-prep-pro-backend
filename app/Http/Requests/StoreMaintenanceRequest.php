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
        $isUpdate = $this->isMethod('put') || $this->isMethod('patch');

        return [
            'title' => ($isUpdate ? 'sometimes|' : '') . 'required|string',
            'description' => 'nullable|string',
            
            'startTime' => ($isUpdate ? 'sometimes|' : '') . 'required_without:start_time|date',
            'start_time' => ($isUpdate ? 'sometimes|' : '') . 'required_without:startTime|date',
            
            'endTime' => ($isUpdate ? 'sometimes|' : '') . 'required_without:end_time|date',
            'end_time' => ($isUpdate ? 'sometimes|' : '') . 'required_without:endTime|date',
            
            'priority' => ($isUpdate ? 'sometimes|' : '') . 'required|in:low,high,critical',
            
            'app_type' => 'nullable',
            'appType' => 'nullable',
            
            'scope' => 'nullable|in:global,api_only,feature',
            
            'affected_services' => 'nullable|array',
            'affectedServices' => 'nullable|array',
            
            'allow_whitelist' => 'boolean',
            'allowWhitelist' => 'boolean',
            
            'notify_before_minutes' => 'nullable|integer|min:0',
            'notifyBeforeMinutes' => 'nullable|integer|min:0',
            
            'grace_period_minutes' => 'nullable|integer|min:0',
            'gracePeriodMinutes' => 'nullable|integer|min:0',
            
            'is_emergency' => 'boolean',
            'isEmergency' => 'boolean',
            
            'notify' => 'boolean'
        ];
    }
}