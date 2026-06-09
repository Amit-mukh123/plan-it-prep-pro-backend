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
            $event = Maintenance::where('is_deleted', false)
                ->where('end_time', '>', now())
                ->orderBy('start_time', 'asc')
                ->first();

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

    private function mapData(array $data): array
    {
        $mapped = [];
        $mappings = [
            'startTime' => 'start_time',
            'endTime' => 'end_time',
            'appType' => 'app_type',
            'affectedServices' => 'affected_services',
            'allowWhitelist' => 'allow_whitelist',
            'notifyBeforeMinutes' => 'notify_before_minutes',
            'gracePeriodMinutes' => 'grace_period_minutes',
            'isEmergency' => 'is_emergency'
        ];

        foreach ($data as $key => $value) {
            $mappedKey = $mappings[$key] ?? $key;
            $mapped[$mappedKey] = $value;
        }

        return $mapped;
    }

    public function createMaintenance(array $data, string $userId)
    {
        $mappedData = $this->mapData($data);
        $mappedData['created_by'] = $userId;
        
        $maintenance = Maintenance::create($mappedData);

        // Clear cache
        Cache::forget(self::CACHE_KEY);

        return $maintenance;
    }
    
    // Deleting automatically uses SoftDeletes because we added the trait to the Model
    public function deleteMaintenance(string $id)
    {
        $maintenance = Maintenance::findOrFail($id);
        $maintenance->is_deleted = true;
        $maintenance->save();
        $maintenance->delete();
        
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
        
        $mappedData = $this->mapData($data);

        $maintenance->update($mappedData);
        Cache::forget(self::CACHE_KEY);
        return $maintenance;
    }

    public function getMaintenanceSchedule()
    {
        $now = now();
        
        // Upcoming/active maintenance events (end_time is in the future)
        $schedule = Maintenance::where('is_deleted', false)
            ->where('end_time', '>', $now)
            ->orderBy('start_time', 'asc')
            ->get();

        // // The very next upcoming maintenance event (start_time is in the future)
        // $next = Maintenance::where('is_deleted', false)
        //     ->where('start_time', '>', $now)
        //     ->orderBy('start_time', 'asc')
        //     ->first();

        return [
            'schedule' => $schedule,
            // 'next' => $next
        ];
    }

    public function getMaintenanceHistory()
    {
        $now = now();
        
        // Past maintenance events (end_time is in the past)
        return Maintenance::where('is_deleted', false)
            ->where('end_time', '<=', $now)
            ->orderBy('start_time', 'desc')
            ->get();
    }
}