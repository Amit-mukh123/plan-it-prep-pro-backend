<?php

namespace App\Services;

use App\Models\Maintenance;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;

class MaintenanceService
{
    const CACHE_KEY = 'maintenance:state';

    public function getMaintenanceState()
    {
        // Try to get from Redis/Cache first. If null, run the closure to calculate it.
        // This replaces your manual cacheService.get() and .set() boilerplate.
        return Cache::remember(self::CACHE_KEY, 30, function () {
            
            // Get the most recent upcoming or active maintenance
            $event = Maintenance::where('end_time', '>', now())->orderBy('start_time', 'asc')->first();

            if (!$event) return null;

            if ($event->is_emergency) {
                return [
                    'status' => 'active',
                    'type' => 'critical',
                    'message' => $event->title
                ];
            }

            $now = now();
            // Carbon allows fluent date math
            $notifyTime = $event->start_time->copy()->subMinutes($event->notify_before_minutes);
            $graceEnd = $event->start_time->copy()->addMinutes($event->grace_period_minutes);

            if ($now->between($notifyTime, $event->start_time)) {
                return [
                    'status' => 'upcoming',
                    'type' => $event->priority,
                    'startsIn' => $now->diffInMinutes($event->start_time),
                    'message' => $event->title
                ];
            }

            if ($now->between($event->start_time, $graceEnd)) {
                return [
                    'status' => 'grace',
                    'type' => $event->priority,
                    'message' => 'Maintenance starting shortly'
                ];
            }

            if ($now->between($graceEnd, $event->end_time)) {
                return [
                    'status' => 'active',
                    'type' => $event->priority,
                    'title' => $event->title,
                    'description' => $event->description
                ];
            }

            return null;
        });
    }

    public function createMaintenance(array $data, string $userId)
    {
        // Map camelCase DTO to snake_case DB columns
        $data['start_time'] = $data['startTime'];
        $data['end_time'] = $data['endTime'];
        $data['created_by'] = $userId;
        
        $maintenance = Maintenance::create($data);

        // Clear cache
        Cache::forget(self::CACHE_KEY);

        // In Laravel, instead of manual RabbitMQ enqueueJob, you dispatch a Job:
        // if ($maintenance->notify) {
        //     MaintenanceNotificationJob::dispatch($maintenance)->delay($maintenance->start_time->subMinutes($maintenance->notify_before_minutes));
        // }

        return $maintenance;
    }
    
    // Deleting automatically uses SoftDeletes because we added the trait to the Model
    public function deleteMaintenance(string $id)
    {
        $maintenance = Maintenance::findOrFail($id);
        $maintenance->update(['is_deleted' => true]);
        Cache::forget(self::CACHE_KEY);
        return $maintenance;
    }

    public function getAllMaintenances()
    {
        return Maintenance::where('is_deleted', false)->orderBy('start_time', 'desc')->get();
    }

    public function updateMaintenance(string $id, array $data)
    {
        $maintenance = Maintenance::findOrFail($id);
        
        // Map camelCase DTO to snake_case DB columns if they exist
        if (isset($data['startTime'])) {
            $data['start_time'] = $data['startTime'];
        }
        if (isset($data['endTime'])) {
            $data['end_time'] = $data['endTime'];
        }

        $maintenance->update($data);
        Cache::forget(self::CACHE_KEY);
        return $maintenance;
    }
}