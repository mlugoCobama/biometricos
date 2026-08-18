<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Services\ZkTecoPushService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    protected ZkTecoPushService $pushService;

    public function __construct(ZkTecoPushService $pushService)
    {
        $this->pushService = $pushService;
    }

    public function index(Request $request)
    {
        $query = Device::with('company');

        if ($request->has('company_id')) {
            $query->where('company_id', $request->input('company_id'));
        }

        $devices = $query->get()->map(function ($device) {
            // Check if device is online (heartbeat within 2 minutes)
            $isOnline = $device->last_heartbeat && $device->last_heartbeat->diffInMinutes(Carbon::now()) <= 2;
            $device->status = $isOnline ? 'online' : 'offline';
            return $device;
        });

        return response()->json([
            'success' => true,
            'data' => $devices
        ]);
    }

    public function store(Request $request)
    {
        $this->validate($request, [
            'company_id' => 'required|exists:companies,id',
            'name' => 'required|string|max:255',
            'serial_number' => 'required|string|unique:devices,serial_number',
            'location' => 'nullable|string|max:255',
        ]);

        $device = Device::create([
            'company_id' => $request->input('company_id'),
            'name' => $request->input('name'),
            'serial_number' => $request->input('serial_number'),
            'location' => $request->input('location'),
            'status' => 'offline',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Biométrico registrado exitosamente',
            'data' => $device
        ], 201);
    }

    public function show($id)
    {
        $device = Device::with(['company', 'pendingCommands'])->find($id);
        if (!$device) {
            return response()->json(['success' => false, 'message' => 'Biométrico no encontrado'], 404);
        }

        $isOnline = $device->last_heartbeat && $device->last_heartbeat->diffInMinutes(Carbon::now()) <= 2;
        $device->status = $isOnline ? 'online' : 'offline';

        return response()->json([
            'success' => true,
            'data' => $device
        ]);
    }

    public function update(Request $request, $id)
    {
        $device = Device::find($id);
        if (!$device) {
            return response()->json(['success' => false, 'message' => 'Biométrico no encontrado'], 404);
        }

        $this->validate($request, [
            'company_id' => 'sometimes|required|exists:companies,id',
            'name' => 'sometimes|required|string|max:255',
            'serial_number' => "sometimes|required|string|unique:devices,serial_number,{$id}",
            'location' => 'nullable|string|max:255',
        ]);

        $device->update($request->only(['company_id', 'name', 'serial_number', 'location']));

        return response()->json([
            'success' => true,
            'message' => 'Biométrico actualizado exitosamente',
            'data' => $device
        ]);
    }

    public function destroy($id)
    {
        $device = Device::find($id);
        if (!$device) {
            return response()->json(['success' => false, 'message' => 'Biométrico no encontrado'], 404);
        }

        $device->delete();

        return response()->json([
            'success' => true,
            'message' => 'Biométrico eliminado exitosamente'
        ]);
    }

    /**
     * Send remote command to biometric (reboot, query info, clear logs, custom)
     */
    public function sendCommand(Request $request, $id)
    {
        $device = Device::find($id);
        if (!$device) {
            return response()->json(['success' => false, 'message' => 'Biométrico no encontrado'], 404);
        }

        $this->validate($request, [
            'type' => 'required|string|in:reboot,info,query_users,query_fingerprints,query_all,clear_logs,custom',
            'custom_command' => 'required_if:type,custom|string',
        ]);

        $type = $request->input('type');
        $command = null;

        switch ($type) {
            case 'reboot':
                $command = $this->pushService->queueRebootCommand($device);
                break;
            case 'info':
                $command = $this->pushService->queueQueryInfoCommand($device);
                break;
            case 'query_users':
                $command = DeviceCommand::create([
                    'device_id' => $device->id,
                    'command_type' => 'QUERY_USERS',
                    'command_text' => 'DATA QUERY USERINFO',
                    'status' => 'pending',
                ]);
                break;
            case 'query_fingerprints':
                $command = DeviceCommand::create([
                    'device_id' => $device->id,
                    'command_type' => 'QUERY_FINGERPRINTS',
                    'command_text' => 'DATA QUERY FINGERTEMPLATE',
                    'status' => 'pending',
                ]);
                break;
            case 'query_all':
                DeviceCommand::create([
                    'device_id' => $device->id,
                    'command_type' => 'QUERY_USERS',
                    'command_text' => 'DATA QUERY USERINFO',
                    'status' => 'pending',
                ]);
                $command = DeviceCommand::create([
                    'device_id' => $device->id,
                    'command_type' => 'QUERY_FINGERPRINTS',
                    'command_text' => 'DATA QUERY FINGERTEMPLATE',
                    'status' => 'pending',
                ]);
                break;
            case 'clear_logs':
                $command = DeviceCommand::create([
                    'device_id' => $device->id,
                    'command_type' => 'CLEAR_LOGS',
                    'command_text' => 'CLEAR LOG',
                    'status' => 'pending',
                ]);
                break;
            case 'custom':
                $command = DeviceCommand::create([
                    'device_id' => $device->id,
                    'command_type' => 'CUSTOM',
                    'command_text' => $request->input('custom_command'),
                    'status' => 'pending',
                ]);
                break;
        }

        return response()->json([
            'success' => true,
            'message' => 'Comando encolado exitosamente para el biométrico',
            'data' => $command
        ]);
    }

    /**
     * List queued and executed commands for device
     */
    public function getCommands($id)
    {
        $device = Device::find($id);
        if (!$device) {
            return response()->json(['success' => false, 'message' => 'Biométrico no encontrado'], 404);
        }

        $commands = DeviceCommand::where('device_id', $device->id)
            ->orderBy('id', 'desc')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $commands
        ]);
    }
}
