<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Device;
use App\Services\ZkTecoPushService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ZkTecoPushController extends Controller
{
    protected ZkTecoPushService $pushService;

    public function __construct(ZkTecoPushService $pushService)
    {
        $this->pushService = $pushService;
    }

    /**
     * Handle GET /iclock/cdata
     * Handshake and option retrieval for ZKTeco devices
     */
    public function cdataGet(Request $request)
    {
        $sn = $request->input('SN');
        if (!$sn) {
            return response('ERROR: Missing SN', 400);
        }

        $device = $this->resolveOrCreateDevice($sn, $request);

        $responseContent = $this->pushService->handleHandshake($device, $request->all());

        return response($responseContent, 200)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * Handle POST /iclock/cdata
     * Receive Attendance Logs (ATTLOG), User Info (USERINFO) or Fingerprints (FINGERTEMPLATE/BIODATA)
     */
    public function cdataPost(Request $request)
    {
        $sn = $request->input('SN');
        $table = strtoupper((string)$request->input('table', 'ATTLOG'));

        if (!$sn) {
            return response('ERROR: Missing SN', 400);
        }

        $device = $this->resolveOrCreateDevice($sn, $request);
        $rawContent = $request->getContent();

        \Illuminate\Support\Facades\Log::info("ZKTeco cdataPost received", [
            'sn' => $sn,
            'table' => $table,
            'bytes' => strlen($rawContent),
            'content_preview' => substr($rawContent, 0, 200)
        ]);

        $processed = 0;

        switch ($table) {
            case 'ATTLOG':
            case 'ATT_LOG':
            case 'ATTENDANCE':
                $processed = $this->pushService->parseAndStoreAttendanceLogs($device, $rawContent);
                break;
            case 'USERINFO':
            case 'USER':
            case 'USERS':
            case 'USER_INFO':
                $processed = $this->pushService->parseAndStoreUsers($device, $rawContent);
                break;
            case 'FINGERTEMPLATE':
            case 'BIODATA':
            case 'TEMPLATE':
            case 'TEMPLATES':
            case 'FP_TEMPLATE':
                $processed = $this->pushService->parseAndStoreFingerprints($device, $rawContent);
                break;
            default:
                \Illuminate\Support\Facades\Log::warning("ZKTeco unhandled table type: {$table}", [
                    'sn' => $sn,
                    'content' => $rawContent
                ]);
                break;
        }

        return response("OK: {$processed}", 200)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * Handle GET /iclock/getrequest
     * Biometric polling endpoint to receive queued commands from server
     */
    public function getRequest(Request $request)
    {
        $sn = $request->input('SN');
        if (!$sn) {
            return response('ERROR: Missing SN', 400);
        }

        $device = $this->resolveOrCreateDevice($sn, $request);
        $responseContent = $this->pushService->getPendingCommandsResponse($device);

        return response($responseContent, 200)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * Handle POST /iclock/devicecmd
     * Biometric callback when remote commands finish executing
     */
    public function deviceCmd(Request $request)
    {
        $sn = $request->input('SN');
        if (!$sn) {
            return response('ERROR: Missing SN', 400);
        }

        $device = $this->resolveOrCreateDevice($sn, $request);
        $rawContent = $request->getContent();

        $this->pushService->processCommandResponse($device, $rawContent, $request->all());

        return response("OK", 200)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * Helper to locate device by SN, or register default device if company exists
     */
    private function resolveOrCreateDevice(string $sn, Request $request): Device
    {
        $device = Device::where('serial_number', $sn)->first();

        if (!$device) {
            // Find or create default fallback company
            $company = Company::firstOrCreate(
                ['code' => 'DEFAULT'],
                ['name' => 'Empresa Principal', 'status' => 'active']
            );

            $device = Device::create([
                'company_id' => $company->id,
                'name' => "Biométrico {$sn}",
                'serial_number' => $sn,
                'ip_address' => $request->ip(),
                'push_version' => $request->input('pushver'),
                'status' => 'online',
                'last_heartbeat' => Carbon::now(),
            ]);
        }

        return $device;
    }
}
