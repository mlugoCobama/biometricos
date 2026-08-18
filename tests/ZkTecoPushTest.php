<?php

namespace Tests;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Models\EmployeeFingerprint;
use Laravel\Lumen\Testing\DatabaseMigrations;

class ZkTecoPushTest extends TestCase
{
    use DatabaseMigrations;

    public function test_handshake_cdata_get()
    {
        $response = $this->get('/iclock/cdata?SN=TEST_SN_001&pushver=3.0.1&options=all&ip=192.168.1.100');

        $response->assertResponseStatus(200);
        $this->assertStringContainsString('GET OPTION FROM: SN=TEST_SN_001', $response->response->getContent());

        $device = Device::where('serial_number', 'TEST_SN_001')->first();
        $this->assertNotNull($device);
        $this->assertEquals('online', $device->status);
    }

    public function test_push_attendance_log_cdata_post()
    {
        // 1. Handshake first to create device
        $this->get('/iclock/cdata?SN=TEST_SN_002');
        $device = Device::where('serial_number', 'TEST_SN_002')->first();

        // 2. Post ATTLOG line via call() with raw content parameter
        $rawLog = "101\t2026-08-14 09:15:00\t0\t1\t0\n";
        $response = $this->call('POST', '/iclock/cdata?SN=TEST_SN_002&table=ATTLOG', [], [], [], [], $rawLog);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('OK: 1', $response->getContent());

        $log = AttendanceLog::where('device_id', $device->id)->where('pin', '101')->first();
        $this->assertNotNull($log);
        $this->assertEquals('2026-08-14 09:15:00', $log->punch_time->format('Y-m-d H:i:s'));
        $this->assertEquals(0, $log->punch_type);
    }

    public function test_push_userinfo_cdata_post()
    {
        $this->get('/iclock/cdata?SN=TEST_SN_003');
        $device = Device::where('serial_number', 'TEST_SN_003')->first();

        $rawUser = "USER PIN=102\tName=Carlos Lopez\tPri=0\tPass=1234\tCard=998877\n";
        $response = $this->call('POST', '/iclock/cdata?SN=TEST_SN_003&table=USERINFO', [], [], [], [], $rawUser);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('OK: 1', $response->getContent());

        $employee = Employee::where('company_id', $device->company_id)->where('pin', '102')->first();
        $this->assertNotNull($employee);
        $this->assertEquals('Carlos Lopez', $employee->first_name);
        $this->assertEquals('998877', $employee->card_number);
    }

    public function test_getrequest_delivers_pending_commands()
    {
        $company = Company::create(['name' => 'Empresa Test', 'code' => 'EMP_TEST']);
        $device = Device::create([
            'company_id' => $company->id,
            'name' => 'Biométrico Entrada',
            'serial_number' => 'TEST_SN_004',
            'status' => 'online',
        ]);

        $cmd = DeviceCommand::create([
            'device_id' => $device->id,
            'command_type' => 'REBOOT',
            'command_text' => 'REBOOT',
            'status' => 'pending',
        ]);

        $response = $this->get('/iclock/getrequest?SN=TEST_SN_004');

        $response->assertResponseStatus(200);
        $this->assertStringContainsString("C:{$cmd->id}:REBOOT", $response->response->getContent());

        $cmd->refresh();
        $this->assertEquals('sent', $cmd->status);
    }

    public function test_devicecmd_callback_updates_command_status()
    {
        $company = Company::create(['name' => 'Empresa Test 2', 'code' => 'EMP_TEST_2']);
        $device = Device::create([
            'company_id' => $company->id,
            'name' => 'Biométrico Salida',
            'serial_number' => 'TEST_SN_005',
            'status' => 'online',
        ]);

        $cmd = DeviceCommand::create([
            'device_id' => $device->id,
            'command_type' => 'REBOOT',
            'command_text' => 'REBOOT',
            'status' => 'sent',
        ]);

        $callbackBody = "ID={$cmd->id}&Return=0&CMD=REBOOT";
        $response = $this->call('POST', '/iclock/devicecmd?SN=TEST_SN_005', [], [], [], [], $callbackBody);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getContent());

        $cmd->refresh();
        $this->assertEquals('success', $cmd->status);
        $this->assertEquals(0, $cmd->return_code);
    }

    public function test_admin_api_companies_crud()
    {
        // Create company
        $response = $this->post('/api/v1/companies', [
            'name' => 'Grupo Industrial S.A.',
            'code' => 'GINDUSTRIAL'
        ]);
        $response->assertResponseStatus(201);

        // List companies
        $response = $this->get('/api/v1/companies');
        $response->assertResponseStatus(200);
        $this->assertStringContainsString('Grupo Industrial S.A.', $response->response->getContent());
    }

    public function test_admin_api_employee_and_remote_command()
    {
        $company = Company::create(['name' => 'Empresa API', 'code' => 'EMP_API']);
        $device = Device::create([
            'company_id' => $company->id,
            'name' => 'Biométrico Sitio A',
            'serial_number' => 'SN_API_001',
            'status' => 'online',
        ]);

        // Create employee with sync_devices flag
        $response = $this->post('/api/v1/employees', [
            'company_id' => $company->id,
            'pin' => '2001',
            'first_name' => 'Maria',
            'last_name' => 'Gonzalez',
            'sync_devices' => true,
        ]);

        $response->assertResponseStatus(201);

        // Check command queued for device
        $cmd = DeviceCommand::where('device_id', $device->id)->where('command_type', 'USERINFO')->first();
        $this->assertNotNull($cmd);
        $this->assertStringContainsString('PIN=2001', $cmd->command_text);
        $this->assertStringContainsString('Maria Gonzalez', $cmd->command_text);
    }

    public function test_admin_api_batch_employee_creation()
    {
        $company = Company::create(['name' => 'Empresa Batch', 'code' => 'EMP_BATCH']);
        $device = Device::create([
            'company_id' => $company->id,
            'name' => 'Biométrico Sitio Batch',
            'serial_number' => 'SN_BATCH_001',
            'status' => 'online',
        ]);

        $batchPayload = [
            'company_id' => $company->id,
            'sync_devices' => true,
            'employees' => [
                ['pin' => '3001', 'first_name' => 'Ana', 'last_name' => 'Torres', 'card_number' => '111'],
                ['pin' => '3002', 'first_name' => 'Luis', 'last_name' => 'Ramirez', 'card_number' => '222'],
                ['pin' => '3003', 'first_name' => 'Sofia', 'last_name' => 'Castro', 'card_number' => '333'],
            ]
        ];

        $response = $this->post('/api/v1/employees/batch', $batchPayload);
        $response->assertResponseStatus(201);
        $json = json_decode($response->response->getContent(), true);
        $this->assertEquals(3, $json['count']);
        $this->assertTrue($json['success']);

        $this->assertEquals(3, Employee::where('company_id', $company->id)->count());
        $this->assertEquals(3, DeviceCommand::where('device_id', $device->id)->where('command_type', 'USERINFO')->count());
    }

    public function test_admin_api_specific_device_employee_creation()
    {
        $company = Company::create(['name' => 'Empresa Multi Equipos', 'code' => 'EMP_MULTI']);
        $device1 = Device::create([
            'company_id' => $company->id,
            'name' => 'Biométrico Recepción',
            'serial_number' => 'SN_MULTI_001',
            'status' => 'online',
        ]);
        $device2 = Device::create([
            'company_id' => $company->id,
            'name' => 'Biométrico Almacén',
            'serial_number' => 'SN_MULTI_002',
            'status' => 'online',
        ]);

        // Create employee specifying ONLY device1
        $response = $this->post('/api/v1/employees', [
            'company_id' => $company->id,
            'pin' => '4001',
            'first_name' => 'Pedro',
            'last_name' => 'Navaja',
            'device_id' => $device1->id,
        ]);

        $response->assertResponseStatus(201);

        // Command should ONLY exist for device1, NOT for device2
        $this->assertEquals(1, DeviceCommand::where('device_id', $device1->id)->where('command_type', 'USERINFO')->count());
        $this->assertEquals(0, DeviceCommand::where('device_id', $device2->id)->where('command_type', 'USERINFO')->count());
    }
}
