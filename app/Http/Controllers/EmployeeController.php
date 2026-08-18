<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeFingerprint;
use App\Services\ZkTecoPushService;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    protected ZkTecoPushService $pushService;

    public function __construct(ZkTecoPushService $pushService)
    {
        $this->pushService = $pushService;
    }

    public function index(Request $request)
    {
        $query = Employee::with(['company'])->withCount('fingerprints');

        if ($request->has('company_id')) {
            $query->where('company_id', $request->input('company_id'));
        }

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('pin', 'like', "%{$search}%")
                  ->orWhere('document_number', 'like', "%{$search}%");
            });
        }

        $employees = $query->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $employees
        ]);
    }

    public function store(Request $request)
    {
        $this->validate($request, [
            'company_id' => 'required|exists:companies,id',
            'pin' => 'required|string',
            'first_name' => 'required|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'document_number' => 'nullable|string|max:50',
            'card_number' => 'nullable|string|max:50',
            'privilege' => 'nullable|integer|in:0,14',
            'password' => 'nullable|string|max:50',
        ]);

        // Ensure PIN is unique within company
        $exists = Employee::where('company_id', $request->input('company_id'))
            ->where('pin', $request->input('pin'))
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'El PIN del empleado ya existe en esta empresa'
            ], 422);
        }

        $employee = Employee::create([
            'company_id' => $request->input('company_id'),
            'pin' => $request->input('pin'),
            'first_name' => $request->input('first_name'),
            'last_name' => $request->input('last_name'),
            'document_number' => $request->input('document_number'),
            'card_number' => $request->input('card_number'),
            'privilege' => $request->input('privilege', 0),
            'password' => $request->input('password'),
            'status' => 'active',
        ]);

        // Auto sync to specified or all company devices if requested
        $devices = $this->resolveTargetDevices($employee->company_id, $request);
        foreach ($devices as $device) {
            $this->pushService->queueSyncEmployeeCommand($device, $employee);
        }

        return response()->json([
            'success' => true,
            'message' => 'Empleado creado exitosamente',
            'data' => $employee
        ], 201);
    }

    /**
     * Bulk/Batch creation of multiple employees at once
     */
    public function batchStore(Request $request)
    {
        $this->validate($request, [
            'company_id' => 'required|exists:companies,id',
            'device_id' => 'nullable|exists:devices,id',
            'device_ids' => 'nullable|array',
            'device_ids.*' => 'exists:devices,id',
            'employees' => 'required|array|min:1',
            'employees.*.pin' => 'required|string',
            'employees.*.first_name' => 'required|string|max:255',
            'employees.*.last_name' => 'nullable|string|max:255',
            'employees.*.document_number' => 'nullable|string|max:50',
            'employees.*.card_number' => 'nullable|string|max:50',
            'employees.*.privilege' => 'nullable|integer|in:0,14',
            'employees.*.password' => 'nullable|string|max:50',
        ]);

        $companyId = $request->input('company_id');
        $employeesData = $request->input('employees');

        $createdEmployees = [];
        $devices = $this->resolveTargetDevices($companyId, $request);

        foreach ($employeesData as $empData) {
            $employee = Employee::updateOrCreate(
                [
                    'company_id' => $companyId,
                    'pin' => (string)$empData['pin'],
                ],
                [
                    'first_name' => $empData['first_name'],
                    'last_name' => $empData['last_name'] ?? null,
                    'document_number' => $empData['document_number'] ?? null,
                    'card_number' => $empData['card_number'] ?? null,
                    'privilege' => $empData['privilege'] ?? 0,
                    'password' => $empData['password'] ?? null,
                    'status' => 'active',
                ]
            );

            foreach ($devices as $device) {
                $this->pushService->queueSyncEmployeeCommand($device, $employee);
            }

            $createdEmployees[] = $employee;
        }

        return response()->json([
            'success' => true,
            'message' => "Se crearon/actualizaron " . count($createdEmployees) . " empleados exitosamente",
            'count' => count($createdEmployees),
            'data' => $createdEmployees
        ], 201);
    }

    public function show($id)
    {
        $employee = Employee::with(['company', 'fingerprints', 'attendanceLogs' => function ($q) {
            $q->orderBy('punch_time', 'desc')->limit(20);
        }])->find($id);

        if (!$employee) {
            return response()->json(['success' => false, 'message' => 'Empleado no encontrado'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $employee
        ]);
    }

    public function update(Request $request, $id)
    {
        $employee = Employee::find($id);
        if (!$employee) {
            return response()->json(['success' => false, 'message' => 'Empleado no encontrado'], 404);
        }

        $this->validate($request, [
            'first_name' => 'sometimes|required|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'document_number' => 'nullable|string|max:50',
            'card_number' => 'nullable|string|max:50',
            'privilege' => 'nullable|integer|in:0,14',
            'password' => 'nullable|string|max:50',
            'status' => 'sometimes|string|in:active,inactive',
        ]);

        $employee->update($request->only([
            'first_name', 'last_name', 'document_number', 'card_number', 'privilege', 'password', 'status'
        ]));

        if ($request->boolean('sync_devices')) {
            $devices = Device::where('company_id', $employee->company_id)->get();
            foreach ($devices as $device) {
                $this->pushService->queueSyncEmployeeCommand($device, $employee);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Empleado actualizado exitosamente',
            'data' => $employee
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $employee = Employee::find($id);
        if (!$employee) {
            return response()->json(['success' => false, 'message' => 'Empleado no encontrado'], 404);
        }

        // Send delete command to biometric devices if requested
        if ($request->boolean('delete_from_devices', true)) {
            $devices = Device::where('company_id', $employee->company_id)->get();
            foreach ($devices as $device) {
                $this->pushService->queueDeleteEmployeeCommand($device, $employee->pin);
            }
        }

        $employee->delete();

        return response()->json([
            'success' => true,
            'message' => 'Empleado eliminado exitosamente'
        ]);
    }

    /**
     * Send employee data & fingerprints to company devices
     */
    public function pushToDevice(Request $request, $id)
    {
        $employee = Employee::with('fingerprints')->find($id);
        if (!$employee) {
            return response()->json(['success' => false, 'message' => 'Empleado no encontrado'], 404);
        }

        $devices = $this->resolveTargetDevices($employee->company_id, $request);

        if ($devices->isEmpty()) {
            // Default to all company devices if no filter specified
            $devices = Device::where('company_id', $employee->company_id)->get();
        }

        if ($devices->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'No se encontraron biométricos para sincronizar'], 400);
        }

        $queuedCount = 0;
        foreach ($devices as $device) {
            $this->pushService->queueSyncEmployeeCommand($device, $employee);
            $queuedCount++;

            foreach ($employee->fingerprints as $fp) {
                $this->pushService->queueSyncFingerprintCommand($device, $employee, $fp);
                $queuedCount++;
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Se encolaron {$queuedCount} comandos de sincronización para los biométricos",
        ]);
    }

    /**
     * Trigger remote fingerprint enrollment or store manually provided template
     */
    public function enrollFingerprint(Request $request, $id)
    {
        $employee = Employee::find($id);
        if (!$employee) {
            return response()->json(['success' => false, 'message' => 'Empleado no encontrado'], 404);
        }

        $this->validate($request, [
            'finger_index' => 'required|integer|between:0,9',
            'device_id' => 'required_without:template_data|exists:devices,id',
            'template_data' => 'required_without:device_id|string',
            'template_version' => 'nullable|integer',
        ]);

        $fingerIndex = (int)$request->input('finger_index');

        // Mode A: Direct template save via API
        if ($request->has('template_data')) {
            $fp = EmployeeFingerprint::updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'finger_index' => $fingerIndex,
                ],
                [
                    'template_data' => $request->input('template_data'),
                    'template_version' => $request->input('template_version', 10),
                    'size' => strlen($request->input('template_data')),
                    'valid' => true,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Plantilla de huella registrada correctamente',
                'data' => $fp
            ]);
        }

        // Mode B: Trigger remote enrollment mode on biometric device
        $device = Device::where('id', $request->input('device_id'))
            ->where('company_id', $employee->company_id)
            ->first();

        if (!$device) {
            return response()->json(['success' => false, 'message' => 'Biométrico no válido para esta empresa'], 400);
        }

        $cmd = $this->pushService->queueEnrollFingerprintCommand($device, $employee->pin, $fingerIndex);

        return response()->json([
            'success' => true,
            'message' => "Solicitud de enrolamiento remoto de huella enviada al biométrico '{$device->name}'",
            'data' => $cmd
        ]);
    }

    /**
     * List registered fingerprints for employee
     */
    public function fingerprints($id)
    {
        $employee = Employee::with('fingerprints')->find($id);
        if (!$employee) {
            return response()->json(['success' => false, 'message' => 'Empleado no encontrado'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $employee->fingerprints
        ]);
    }

    /**
     * Helper to resolve target devices (single device_id, array device_ids, or all company devices)
     */
    private function resolveTargetDevices(int $companyId, Request $request)
    {
        if ($request->has('device_id') && $request->input('device_id')) {
            return Device::where('company_id', $companyId)->where('id', $request->input('device_id'))->get();
        }

        if ($request->has('device_ids') && is_array($request->input('device_ids'))) {
            return Device::where('company_id', $companyId)->whereIn('id', $request->input('device_ids'))->get();
        }

        if ($request->boolean('sync_devices')) {
            return Device::where('company_id', $companyId)->get();
        }

        return collect();
    }
}
