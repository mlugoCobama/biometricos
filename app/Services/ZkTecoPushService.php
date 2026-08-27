<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Models\EmployeeFingerprint;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ZkTecoPushService
{
    /**
     * Handle initial handshake / GET option request from ZKTeco device
     */
    public function handleHandshake(Device $device, array $queryParams): string
    {
        $now = Carbon::now();
        $device->update([
            'last_heartbeat' => $now,
            'status' => 'online',
            'ip_address' => $queryParams['ip'] ?? $device->ip_address,
            'push_version' => $queryParams['pushver'] ?? $device->push_version,
            'firmware_version' => $queryParams['language'] ?? $device->firmware_version,
        ]);

        return implode("\n", [
            "GET OPTION FROM: SN={$device->serial_number}",
            "SetTime={$now->format('Y-m-d H:i:s')}",
            "Stamp=9999",
            "OpStamp=9999",
            "PhotoStamp=9999",
            "ErrorDelay=60",
            "Delay=30",
            "TransTimes=00:00;23:59",
            "TransInterval=1",
            "TransFlag=1111111111",
            "RealTime=1",
            "Encrypt=0",
            "TimeZone=-6"
        ]) . "\n";
    }

    /**
     * Parse and store incoming Attendance Logs (ATTLOG)
     */
    public function parseAndStoreAttendanceLogs(Device $device, string $rawContent): int
    {
        $lines = explode("\n", trim($rawContent));
        $count = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            // Tab-separated or space-separated format:
            // PIN \t DateTime \t PunchState \t VerifyType \t WorkCode
            $parts = preg_split('/\t+|\s{2,}/', $line);

            if (count($parts) < 2) {
                // Try comma separated
                $parts = explode(',', $line);
            }

            if (count($parts) >= 2) {
                $pin = trim($parts[0]);
                $punchTimeStr = trim($parts[1]);
                $punchType = isset($parts[2]) ? (int)trim($parts[2]) : 0;
                $verifyType = isset($parts[3]) ? (int)trim($parts[3]) : 1;
                $workCode = isset($parts[4]) ? trim($parts[4]) : null;

                try {
                    $punchTime = Carbon::parse($punchTimeStr);
                } catch (\Exception $e) {
                    Log::error("Failed to parse punch_time: {$punchTimeStr}");
                    continue;
                }

                // Locate employee by company and PIN
                $employee = Employee::where('company_id', $device->company_id)
                    ->where('pin', $pin)
                    ->first();

                AttendanceLog::firstOrCreate(
                    [
                        'device_id' => $device->id,
                        'pin' => $pin,
                        'punch_time' => $punchTime->format('Y-m-d H:i:s'),
                    ],
                    [
                        'company_id' => $device->company_id,
                        'employee_id' => $employee?->id,
                        'punch_type' => $punchType,
                        'verify_type' => $verifyType,
                        'work_code' => $workCode,
                        'raw_line' => $line,
                    ]
                );

                $count++;
            }
        }

        $device->increment('att_log_count', $count);
        $device->update([
            'last_heartbeat' => Carbon::now(),
            'status' => 'online',
        ]);

        return $count;
    }

    /**
     * Parse and store User Info uploaded by device
     */
    public function parseAndStoreUsers(Device $device, string $rawContent): int
    {
        $lines = explode("\n", trim($rawContent));
        $count = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $data = $this->parseKeyValueOrTabLine($line);

            $pin = $data['PIN'] ?? $data['pin'] ?? $data['Pin'] ?? $data['user_id'] ?? $data['uid'] ?? $data['USERID'] ?? null;
            if (!$pin && isset($data[0])) {
                $pin = $data[0];
            }

            if ($pin) {
                $name = $data['Name'] ?? $data['name'] ?? ($data[1] ?? "User {$pin}");
                $privilege = isset($data['Pri']) ? (int)$data['Pri'] : (isset($data[2]) ? (int)$data[2] : 0);
                $password = $data['Passwd'] ?? $data['Pass'] ?? $data['password'] ?? ($data[3] ?? null);
                $card = $data['Card'] ?? $data['card'] ?? ($data[4] ?? null);

                $searchCriteria = ['pin' => (string)$pin];
                if ($device->company_id) {
                    $searchCriteria['company_id'] = $device->company_id;
                }
                if ($device->intercompania) {
                    $searchCriteria['intercompania'] = $device->intercompania;
                }

                $updateData = [
                    'first_name' => $name,
                    'privilege' => $privilege,
                    'password' => $password,
                    'card_number' => $card,
                    'status' => 'active',
                ];
                if ($device->company_id) {
                    $updateData['company_id'] = $device->company_id;
                }
                if ($device->intercompania) {
                    $updateData['intercompania'] = $device->intercompania;
                }

                Employee::updateOrCreate($searchCriteria, $updateData);
                $count++;
            }
        }

        $userCount = Employee::query()
            ->when($device->company_id, fn($q) => $q->where('company_id', $device->company_id))
            ->when($device->intercompania, fn($q) => $q->where('intercompania', $device->intercompania))
            ->count();

        $device->update([
            'user_count' => $userCount,
            'last_heartbeat' => Carbon::now(),
            'status' => 'online',
        ]);

        return $count;
    }

    /**
     * Parse and store Fingerprint Templates uploaded by device
     */
    public function parseAndStoreFingerprints(Device $device, string $rawContent): int
    {
        $lines = explode("\n", trim($rawContent));
        $count = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $data = $this->parseKeyValueOrTabLine($line);
            $pin = $data['PIN'] ?? $data['pin'] ?? $data['Pin'] ?? $data['user_id'] ?? $data['uid'] ?? null;
            $fingerIndex = isset($data['FID']) ? (int)$data['FID'] : (isset($data['finger_index']) ? (int)$data['finger_index'] : 0);
            $templateData = $data['Tmp'] ?? $data['template'] ?? $data['Template'] ?? null;

            if ($pin && $templateData) {
                $employee = Employee::query()
                    ->when($device->company_id, fn($q) => $q->where('company_id', $device->company_id))
                    ->when($device->intercompania, fn($q) => $q->where('intercompania', $device->intercompania))
                    ->where('pin', (string)$pin)
                    ->first();

                if ($employee) {
                    EmployeeFingerprint::updateOrCreate(
                        [
                            'employee_id' => $employee->id,
                            'finger_index' => $fingerIndex,
                        ],
                        [
                            'template_data' => $templateData,
                            'template_version' => (int)($data['Valid'] ?? 10),
                            'size' => strlen($templateData),
                            'valid' => true,
                        ]
                    );
                    $count++;
                }
            }
        }

        $device->update([
            'fingerprint_count' => EmployeeFingerprint::whereHas('employee', function ($q) use ($device) {
                if ($device->company_id) {
                    $q->where('company_id', $device->company_id);
                }
                if ($device->intercompania) {
                    $q->where('intercompania', $device->intercompania);
                }
            })->count(),
            'last_heartbeat' => Carbon::now(),
            'status' => 'online',
        ]);

        return $count;
    }

    /**
     * Fetch pending commands formatted for ZKTeco ADMS poll (/iclock/getrequest)
     */
    public function getPendingCommandsResponse(Device $device): string
    {
        $device->update([
            'last_heartbeat' => Carbon::now(),
            'status' => 'online',
        ]);

        $pendingCommands = DeviceCommand::where('device_id', $device->id)
            ->where('status', 'pending')
            ->orderBy('id', 'asc')
            ->get();

        if ($pendingCommands->isEmpty()) {
            return "OK\n";
        }

        $responseLines = [];
        foreach ($pendingCommands as $cmd) {
            $responseLines[] = "C:{$cmd->id}:{$cmd->command_text}";
            $cmd->update([
                'status' => 'sent',
                'sent_at' => Carbon::now(),
            ]);
        }

        return implode("\n", $responseLines) . "\n";
    }

    /**
     * Process callback response from device for executed commands (/iclock/devicecmd)
     */
    public function processCommandResponse(Device $device, string $content, array $queryParams): void
    {
        $device->update([
            'last_heartbeat' => Carbon::now(),
            'status' => 'online',
        ]);

        // Content format: ID=101&Return=0&CMD=DATA UPDATE USERINFO
        parse_str($content, $parsed);
        if (empty($parsed)) {
            parse_str(parse_url('?' . $content, PHP_URL_QUERY) ?? '', $parsed);
        }

        $cmdId = $parsed['ID'] ?? $queryParams['ID'] ?? null;
        $returnCode = isset($parsed['Return']) ? (int)$parsed['Return'] : (isset($queryParams['Return']) ? (int)$queryParams['Return'] : 0);

        if ($cmdId) {
            $command = DeviceCommand::where('device_id', $device->id)
                ->where('id', $cmdId)
                ->first();

            if ($command) {
                $command->update([
                    'status' => ($returnCode === 0) ? 'success' : 'error',
                    'return_code' => $returnCode,
                    'executed_at' => Carbon::now(),
                    'response_text' => $content,
                ]);
            }
        }
    }

    /**
     * Create command to sync employee to device
     */
    public function queueSyncEmployeeCommand(Device $device, Employee $employee): DeviceCommand
    {
        $name = $employee->full_name;
        $pri = $employee->privilege;
        $pass = $employee->password ?? '';
        $card = $employee->card_number ?? '';

        $cmdText = "DATA UPDATE USERINFO PIN={$employee->pin}\tName={$name}\tPri={$pri}\tPass={$pass}\tCard={$card}";

        return DeviceCommand::create([
            'device_id' => $device->id,
            'command_type' => 'USERINFO',
            'command_text' => $cmdText,
            'status' => 'pending',
        ]);
    }

    /**
     * Create command to delete employee from device
     */
    public function queueDeleteEmployeeCommand(Device $device, string $pin): DeviceCommand
    {
        $cmdText = "DATA DELETE USERINFO PIN={$pin}";

        return DeviceCommand::create([
            'device_id' => $device->id,
            'command_type' => 'DELETE_USER',
            'command_text' => $cmdText,
            'status' => 'pending',
        ]);
    }

    /**
     * Create command to sync fingerprint template to device
     */
    public function queueSyncFingerprintCommand(Device $device, Employee $employee, EmployeeFingerprint $fingerprint): DeviceCommand
    {
        $cmdText = "DATA UPDATE FINGERTEMPLATE PIN={$employee->pin}\tFID={$fingerprint->finger_index}\tSize={$fingerprint->size}\tValid=1\tTmp={$fingerprint->template_data}";

        return DeviceCommand::create([
            'device_id' => $device->id,
            'command_type' => 'FINGERTEMPLATE',
            'command_text' => $cmdText,
            'status' => 'pending',
        ]);
    }

    /**
     * Create command to trigger fingerprint enrollment on device
     */
    public function queueEnrollFingerprintCommand(Device $device, string $pin, int $fingerIndex = 0): DeviceCommand
    {
        $cmdText = "ENROLL_FP PIN={$pin}\tFID={$fingerIndex}\tRETRY=3";

        return DeviceCommand::create([
            'device_id' => $device->id,
            'command_type' => 'ENROLL_FP',
            'command_text' => $cmdText,
            'status' => 'pending',
        ]);
    }

    /**
     * Create command to query information from device
     */
    public function queueQueryInfoCommand(Device $device): DeviceCommand
    {
        $cmdText = "INFO";

        return DeviceCommand::create([
            'device_id' => $device->id,
            'command_type' => 'INFO',
            'command_text' => $cmdText,
            'status' => 'pending',
        ]);
    }

    /**
     * Create command to reboot device
     */
    public function queueRebootCommand(Device $device): DeviceCommand
    {
        $cmdText = "REBOOT";

        return DeviceCommand::create([
            'device_id' => $device->id,
            'command_type' => 'REBOOT',
            'command_text' => $cmdText,
            'status' => 'pending',
        ]);
    }

    /**
     * Create command to set/synchronize device date & time to current server time
     */
    public function queueSyncTimeCommand(Device $device): DeviceCommand
    {
        $nowStr = Carbon::now()->format('Y-m-d H:i:s');

        return DeviceCommand::create([
            'device_id' => $device->id,
            'command_type' => 'SET_TIME',
            'command_text' => "DATA UPDATE OPTION SetTime={$nowStr}",
            'status' => 'pending',
        ]);
    }

    /**
     * Check if device clock is desynchronized compared to server time, and queue sync command if offset > maxOffset
     */
    public function checkAndSyncDeviceTime(Device $device, ?string $deviceTimeStr = null, int $maxOffsetSeconds = 120): bool
    {
        if (empty($deviceTimeStr)) {
            return false;
        }

        try {
            $deviceTime = Carbon::parse($deviceTimeStr);
            $serverTime = Carbon::now();
            $diffSeconds = abs($serverTime->diffInSeconds($deviceTime));

            if ($diffSeconds > $maxOffsetSeconds) {
                Log::warning("Reloj del biométrico desincronizado", [
                    'sn' => $device->serial_number,
                    'device_time' => $deviceTime->format('Y-m-d H:i:s'),
                    'server_time' => $serverTime->format('Y-m-d H:i:s'),
                    'offset_seconds' => $diffSeconds
                ]);

                $this->queueSyncTimeCommand($device);
                return true;
            }
        } catch (\Exception $e) {
            Log::error("Error al validar fecha/hora del biométrico: " . $e->getMessage());
        }

        return false;
    }

    /**
     * Helper to parse key-value lines or tab-separated lines
     */
    private function parseKeyValueOrTabLine(string $line): array
    {
        $result = [];
        $line = preg_replace('/^(USER|BIODATA|OPERLOG|OPLOG|OPER_LOG)\s+/i', '', trim($line));

        if (str_contains($line, '=')) {
            $parts = preg_split('/\t+/', $line);
            if (count($parts) === 1 && str_contains($line, ' ')) {
                preg_match_all('/([a-zA-Z_]+)=([^\s]*)/', $line, $matches, PREG_SET_ORDER);
                foreach ($matches as $m) {
                    $result[trim($m[1])] = trim($m[2]);
                }
            } else {
                foreach ($parts as $part) {
                    if (str_contains($part, '=')) {
                        [$key, $val] = explode('=', $part, 2);
                        $result[trim($key)] = trim($val);
                    }
                }
            }
        } else {
            $parts = preg_split('/\t+/', $line);
            foreach ($parts as $idx => $part) {
                $result[$idx] = trim($part);
            }
        }
        return $result;
    }
}
