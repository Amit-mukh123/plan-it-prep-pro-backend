<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMaintenanceRequest;
use App\Services\MaintenanceService;
use Illuminate\Http\Request;

class MaintenanceController extends Controller
{
    protected $maintenanceService;

    // Dependency Injection (just like NestJS constructors)
    public function __construct(MaintenanceService $maintenanceService)
    {
        $this->maintenanceService = $maintenanceService;
    }

    public function create(StoreMaintenanceRequest $request)
    {
        // $request->validated() only contains safe, validated data defined in the rules
        $maintenance = $this->maintenanceService->createMaintenance(
            $request->validated(), 
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Maintenance created successfully',
            'data' => $maintenance
        ], 201);
    }

    public function fetchState()
    {
        $state = $this->maintenanceService->getMaintenanceState();
        
        return response()->json([
            'success' => true,
            'message' => 'Maintenance state fetched successfully',
            'data' => $state
        ]);
    }

    public function delete($id)
    {
        $maintenance = $this->maintenanceService->deleteMaintenance($id);
        
        return response()->json([
            'success' => true,
            'message' => 'Maintenance deleted successfully',
            'data' => $maintenance
        ]);
    }

    public function getAll()
    {
        $maintenance = $this->maintenanceService->getAllMaintenances();
        
        return response()->json([
            'success' => true,
            'message' => 'All maintenances fetched successfully',
            'data' => $maintenance
        ]);
    }

    public function update(StoreMaintenanceRequest $request, $id){
        $maintenance = $this->maintenanceService->updateMaintenance($id, $request->validated());
        
        return response()->json([
            'success' => true,
            'message' => 'Maintenance updated successfully',
            'data' => $maintenance
        ]);
    }

    public function schedule()
    {
        $schedule = $this->maintenanceService->getMaintenanceSchedule();
        
        return response()->json([
            'success' => true,
            'message' => 'Maintenance schedule fetched successfully',
            'data' => $schedule
        ]);
    }

    public function history()
    {
        $history = $this->maintenanceService->getMaintenanceHistory();
        
        return response()->json([
            'success' => true,
            'message' => 'Maintenance history fetched successfully',
            'data' => $history
        ]);
    }
}