<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AttendanceLogController extends Controller
{
    public function index(Request $request)
    {
        $query = AttendanceLog::with(['company', 'device', 'employee']);

        if ($request->has('intercompania') || $request->has('company_id')) {
            $intercompania = $request->input('intercompania') ?: $request->input('company_id');
            $company = \App\Models\Company::query()
                ->where('intercompania', (string)$intercompania)
                ->orWhere('code', (string)$intercompania)
                ->orWhere('id', (string)$intercompania)
                ->first();

            if ($company) {
                $query->where('company_id', $company->id);
            }
        }

        if ($request->has('device_id')) {
            $query->where('device_id', $request->input('device_id'));
        }

        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->input('employee_id'));
        }

        if ($request->has('pin')) {
            $query->where('pin', $request->input('pin'));
        }

        if ($request->has('punch_type')) {
            $query->where('punch_type', $request->input('punch_type'));
        }

        if ($request->has('start_date')) {
            $query->where('punch_time', '>=', Carbon::parse($request->input('start_date'))->startOfDay());
        }

        if ($request->has('end_date')) {
            $query->where('punch_time', '<=', Carbon::parse($request->input('end_date'))->endOfDay());
        }

        $logs = $query->orderBy('punch_time', 'desc')
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $logs
        ]);
    }

    public function stats(Request $request)
    {
        $intercompania = $request->input('intercompania') ?: $request->input('company_id');
        $companyId = null;

        if ($intercompania) {
            $company = \App\Models\Company::query()
                ->where('intercompania', (string)$intercompania)
                ->orWhere('code', (string)$intercompania)
                ->orWhere('id', (string)$intercompania)
                ->first();
            $companyId = $company?->id;
        }

        $today = Carbon::today();

        $logsQuery = AttendanceLog::query();
        $devicesQuery = Device::query();
        $employeesQuery = Employee::query();

        if ($companyId) {
            $logsQuery->where('company_id', $companyId);
            $devicesQuery->where('company_id', $companyId);
            $employeesQuery->where('company_id', $companyId);
        }

        $todayPunches = (clone $logsQuery)->whereDate('punch_time', $today)->count();
        $todayCheckIns = (clone $logsQuery)->whereDate('punch_time', $today)->where('punch_type', 0)->count();
        $todayCheckOuts = (clone $logsQuery)->whereDate('punch_time', $today)->where('punch_type', 1)->count();

        $devices = $devicesQuery->get();
        $onlineDevices = $devices->filter(function ($d) {
            return $d->last_heartbeat && $d->last_heartbeat->diffInMinutes(Carbon::now()) <= 2;
        })->count();

        return response()->json([
            'success' => true,
            'data' => [
                'total_employees' => $employeesQuery->count(),
                'total_devices' => $devices->count(),
                'online_devices' => $onlineDevices,
                'offline_devices' => $devices->count() - $onlineDevices,
                'today_total_punches' => $todayPunches,
                'today_check_ins' => $todayCheckIns,
                'today_check_outs' => $todayCheckOuts,
            ]
        ]);
    }
}
