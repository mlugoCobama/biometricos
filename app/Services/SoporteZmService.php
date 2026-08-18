<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Employee;
use App\Models\AttendanceLog;
use Illuminate\Support\Facades\DB;
use Exception;
use Illuminate\Support\Facades\Log;

class SoporteZmService
{
    /**
     * Connection name for SOPORTEZM database.
     */
    protected string $connection = 'soportezm';

    /**
     * Stored Procedure name.
     */
    protected string $procedureName = 'SP_GetEmpresas';

    /**
     * Execute SP_GetEmpresas Stored Procedure from SOPORTEZM database (MySQL CALL SP_GetEmpresas()).
     *
     * @return array
     * @throws Exception
     */
    public function getEmpresasFromProcedure(): array
    {
        $driver = config("database.connections.{$this->connection}.driver", 'mysql');

        // Choose SQL syntax based on driver (CALL for MySQL/MariaDB, EXEC for SQL Server)
        if (in_array(strtolower($driver), ['sqlsrv', 'dblib', 'odbc'])) {
            $query = "EXEC {$this->procedureName}";
        } else {
            $query = "CALL {$this->procedureName}()";
        }

        try {
            $results = DB::connection($this->connection)->select($query);
        } catch (Exception $e) {
            Log::error("Error executing {$this->procedureName} on {$this->connection}: " . $e->getMessage());
            throw new Exception("Error al ejecutar el Stored Procedure '{$this->procedureName}' en la BD SOPORTEZM: " . $e->getMessage());
        }

        return array_map(function ($item) {
            $array = (array) $item;

            // Normalize array keys to lowercase
            $normalized = [];
            foreach ($array as $key => $val) {
                $normalized[strtolower($key)] = is_string($val) ? trim($val) : $val;
            }

            return [
                'name' => $normalized['name'] ?? $normalized['nombre'] ?? $normalized['empresa'] ?? null,
                'intercompania' => (string) ($normalized['intercompania'] ?? $normalized['code'] ?? $normalized['codigo'] ?? $normalized['id'] ?? ''),
                'raw' => $array,
            ];
        }, $results);
    }

    /**
     * Get live company details from SP_GetEmpresas() combined with its local biometric records.
     * Does NOT duplicate or persist company name in local database.
     *
     * @param string $intercompania
     * @return array|null
     */
    public function getEmpresaByIntercompania(string $intercompania): ?array
    {
        // 1. Fetch live empresas from SOPORTEZM SP
        $empresas = $this->getEmpresasFromProcedure();
        
        $empresaInfo = null;
        foreach ($empresas as $emp) {
            if ((string)$emp['intercompania'] === (string)$intercompania) {
                $empresaInfo = $emp;
                break;
            }
        }

        // Fallback if not found in SP list but has biometrics locally
        if (!$empresaInfo) {
            $empresaInfo = [
                'name' => "Empresa Intercompañía {$intercompania}",
                'intercompania' => $intercompania,
            ];
        }

        // 2. Fetch biometric devices, employees, and logs for this intercompania
        $devices = Device::where('intercompania', $intercompania)->get();
        $employees = Employee::where('intercompania', $intercompania)->withCount('fingerprints')->get();
        $attendanceLogs = AttendanceLog::where('intercompania', $intercompania)
            ->orderBy('punch_time', 'desc')
            ->take(50)
            ->get();

        return [
            'empresa' => [
                'name' => $empresaInfo['name'],
                'intercompania' => $empresaInfo['intercompania'],
                'summary' => [
                    'total_devices' => $devices->count(),
                    'total_employees' => $employees->count(),
                    'total_attendance_logs' => AttendanceLog::where('intercompania', $intercompania)->count(),
                ]
            ],
            'biometrics' => [
                'devices' => $devices,
                'employees' => $employees,
                'recent_logs' => $attendanceLogs,
            ]
        ];
    }

    /**
     * Generate biometric reports by merging SOPORTEZM companies from SP with local biometric data.
     *
     * @param string|null $intercompania
     * @return array
     */
    public function getReporteBiometricos(?string $intercompania = null): array
    {
        $empresas = $this->getEmpresasFromProcedure();
        $reporte = [];

        foreach ($empresas as $empresa) {
            $empId = (string) $empresa['intercompania'];

            if ($intercompania && $empId !== (string)$intercompania) {
                continue;
            }

            $devicesCount = Device::where('intercompania', $empId)->count();
            $employeesCount = Employee::where('intercompania', $empId)->count();
            $logsCount = AttendanceLog::where('intercompania', $empId)->count();
            $lastLog = AttendanceLog::where('intercompania', $empId)->latest('punch_time')->first();

            $reporte[] = [
                'intercompania' => $empId,
                'name' => $empresa['name'],
                'total_devices' => $devicesCount,
                'total_employees' => $employeesCount,
                'total_attendance_logs' => $logsCount,
                'last_punch_time' => $lastLog ? $lastLog->punch_time : null,
            ];
        }

        return $reporte;
    }
}
