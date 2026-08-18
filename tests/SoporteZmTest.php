<?php

namespace Tests;

use App\Models\Device;
use App\Models\Employee;
use App\Models\AttendanceLog;
use App\Services\SoporteZmService;
use Laravel\Lumen\Testing\DatabaseMigrations;
use Mockery;

class SoporteZmTest extends TestCase
{
    use DatabaseMigrations;

    public function test_soporte_zm_service_fetches_empresas_and_generates_report()
    {
        $service = Mockery::mock(SoporteZmService::class)->makePartial();
        $service->shouldReceive('getEmpresasFromProcedure')
            ->once()
            ->andReturn([
                ['name' => 'Empresa Alpha', 'intercompania' => 'INT-1001', 'raw' => []],
                ['name' => 'Empresa Beta', 'intercompania' => 'INT-1002', 'raw' => []],
            ]);

        Device::create([
            'intercompania' => 'INT-1001',
            'name' => 'Biometrico Principal',
            'serial_number' => 'SN-1001',
            'status' => 'online',
        ]);

        Employee::create([
            'intercompania' => 'INT-1001',
            'pin' => '101',
            'first_name' => 'Carlos',
            'last_name' => 'Gomez',
            'status' => 'active',
        ]);

        $report = $service->getReporteBiometricos();

        $this->assertCount(2, $report);
        $this->assertEquals('INT-1001', $report[0]['intercompania']);
        $this->assertEquals('Empresa Alpha', $report[0]['name']);
        $this->assertEquals(1, $report[0]['total_devices']);
        $this->assertEquals(1, $report[0]['total_employees']);
    }

    public function test_get_biometrics_by_intercompania_endpoint()
    {
        $mockService = Mockery::mock(SoporteZmService::class)->makePartial();
        $mockService->shouldReceive('getEmpresasFromProcedure')
            ->andReturn([
                ['name' => 'Empresa Biometrica S.A.', 'intercompania' => 'INT-5000', 'raw' => []],
            ]);

        $this->app->instance(SoporteZmService::class, $mockService);

        Device::create([
            'intercompania' => 'INT-5000',
            'name' => 'Biometrico Acceso Principal',
            'serial_number' => 'SN-99887766',
            'ip_address' => '192.168.1.50',
            'status' => 'online',
        ]);

        Employee::create([
            'intercompania' => 'INT-5000',
            'pin' => '101',
            'first_name' => 'Juan',
            'last_name' => 'Perez',
            'status' => 'active',
        ]);

        AttendanceLog::create([
            'intercompania' => 'INT-5000',
            'device_id' => 1,
            'pin' => '101',
            'punch_time' => date('Y-m-d H:i:s'),
            'punch_type' => 0,
            'verify_type' => 1,
        ]);

        $this->get('/api/v1/companies/intercompania/INT-5000/biometrics');

        $this->assertResponseOk();
        $this->seeJsonContains(['success' => true]);
        $this->seeJsonContains(['intercompania' => 'INT-5000']);
        $this->seeJsonContains(['name' => 'Empresa Biometrica S.A.']);
        $this->seeJsonContains(['serial_number' => 'SN-99887766']);
        $this->seeJsonContains(['first_name' => 'Juan']);
    }
}
