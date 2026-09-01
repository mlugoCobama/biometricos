<?php

/** @var \Laravel\Lumen\Routing\Router $router */

/*
|--------------------------------------------------------------------------
| Application Information Endpoint
|--------------------------------------------------------------------------
*/
$router->get('/', function () use ($router) {
    return response()->json([
        'name' => 'ZKTeco Biometric PUSH Lumen API',
        'status' => 'online',
        'version' => $router->app->version(),
        'server_time' => date('Y-m-d H:i:s'),
    ]);
});

/*
|--------------------------------------------------------------------------
| ZKTeco PUSH / ADMS Protocol Endpoints (Biometric Devices)
|--------------------------------------------------------------------------
*/
$router->group([], function ($router) {
    // Standard ADMS routes (/iclock/...)
    $router->get('/iclock/cdata', 'ZkTecoPushController@cdataGet');
    $router->post('/iclock/cdata', 'ZkTecoPushController@cdataPost');
    $router->get('/iclock/getrequest', 'ZkTecoPushController@getRequest');
    $router->post('/iclock/devicecmd', 'ZkTecoPushController@deviceCmd');

    // Direct root ADMS routes fallback
    $router->get('/cdata', 'ZkTecoPushController@cdataGet');
    $router->post('/cdata', 'ZkTecoPushController@cdataPost');
    $router->get('/getrequest', 'ZkTecoPushController@getRequest');
    $router->post('/devicecmd', 'ZkTecoPushController@deviceCmd');
});

/*
|--------------------------------------------------------------------------
| Admin REST API Endpoints (Management Application / Frontend)
|--------------------------------------------------------------------------
*/
$router->group(['prefix' => 'api/v1'], function ($router) {

    // Companies Management
    $router->get('/companies', 'CompanyController@index');
    $router->post('/companies', 'CompanyController@store');
    // SOPORTEZM Stored Procedure Integration & Reports
    $router->get('/companies/soportezm', 'CompanyController@getSoporteZmCompanies');
    $router->get('/companies/reports/biometrics', 'CompanyController@getBiometricsReport');
    $router->get('/companies/intercompania/{intercompania}/biometrics', 'CompanyController@getBiometricsByIntercompania');
    $router->get('/companies/intercompania/{intercompania}/employees', 'EmployeeController@getByIntercompania');

    $router->get('/companies/{id}', 'CompanyController@show');
    $router->get('/companies/{id}/employees', 'EmployeeController@getByCompany');
    $router->put('/companies/{id}', 'CompanyController@update');
    $router->delete('/companies/{id}', 'CompanyController@destroy');


    // Devices Management & Control
    $router->get('/devices', 'DeviceController@index');
    $router->post('/devices', 'DeviceController@store');
    $router->get('/devices/{id}', 'DeviceController@show');
    $router->put('/devices/{id}', 'DeviceController@update');
    $router->delete('/devices/{id}', 'DeviceController@destroy');
    $router->post('/devices/{id}/command', 'DeviceController@sendCommand');
    $router->get('/devices/{id}/commands', 'DeviceController@getCommands');

    // Employees Management & Remote Fingerprints
    $router->get('/employees', 'EmployeeController@index');
    $router->post('/employees', 'EmployeeController@store');
    $router->post('/employees/batch', 'EmployeeController@batchStore');
    $router->get('/employees/{id}', 'EmployeeController@show');
    $router->put('/employees/{id}', 'EmployeeController@update');
    $router->delete('/employees/{id}', 'EmployeeController@destroy');
    $router->post('/employees/{id}/push-to-device', 'EmployeeController@pushToDevice');
    $router->post('/employees/{id}/enroll-fingerprint', 'EmployeeController@enrollFingerprint');
    $router->get('/employees/{id}/fingerprints', 'EmployeeController@fingerprints');

    // Attendance Logs & Statistics
    $router->get('/attendance-logs', 'AttendanceLogController@index');
    $router->get('/attendance-logs/stats', 'AttendanceLogController@stats');

    // Attendance Reports (por parámetro query ?intercompania=XXX o ?company_id=X)
    $router->get('/reports/attendance/daily', 'AttendanceReportController@daily');
    $router->get('/reports/attendance/quincenal', 'AttendanceReportController@quincenal');
    $router->get('/reports/attendance/monthly', 'AttendanceReportController@monthly');

    // Attendance Reports (parámetro directo en la URL por intercompañía)
    $router->get('/companies/intercompania/{intercompania}/reports/daily', 'AttendanceReportController@dailyByIntercompania');
    $router->get('/companies/intercompania/{intercompania}/reports/quincenal', 'AttendanceReportController@quincenalByIntercompania');
    $router->get('/companies/intercompania/{intercompania}/reports/monthly', 'AttendanceReportController@monthlyByIntercompania');
});

/*
|--------------------------------------------------------------------------
| Global OPTIONS Catch-All for CORS Preflight Requests
|--------------------------------------------------------------------------
*/
$router->options('/{any:.*}', function () {
    return response('', 200);
});
