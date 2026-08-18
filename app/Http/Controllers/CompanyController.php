<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    protected \App\Services\SoporteZmService $soporteZmService;

    public function __construct(\App\Services\SoporteZmService $soporteZmService)
    {
        $this->soporteZmService = $soporteZmService;
    }

    public function index()

    {
        $companies = Company::withCount(['devices', 'employees'])->get();
        return response()->json([
            'success' => true,
            'data' => $companies
        ]);
    }

    public function store(Request $request)
    {
        $this->validate($request, [
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50|unique:companies,code',
            'status' => 'nullable|string|in:active,inactive',
        ]);

        $company = Company::create([
            'name' => $request->input('name'),
            'code' => $request->input('code') ?? strtoupper(substr(md5(uniqid()), 0, 8)),
            'status' => $request->input('status', 'active'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Empresa creada exitosamente',
            'data' => $company
        ], 201);
    }

    public function show($id)
    {
        $company = Company::with(['devices', 'employees'])->find($id);

        if (!$company) {
            return response()->json([
                'success' => false,
                'message' => 'Empresa no encontrada'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $company
        ]);
    }

    public function update(Request $request, $id)
    {
        $company = Company::find($id);
        if (!$company) {
            return response()->json(['success' => false, 'message' => 'Empresa no encontrada'], 404);
        }

        $this->validate($request, [
            'name' => 'sometimes|required|string|max:255',
            'code' => "sometimes|nullable|string|max:50|unique:companies,code,{$id}",
            'status' => 'sometimes|string|in:active,inactive',
        ]);

        $company->update($request->only(['name', 'code', 'status']));

        return response()->json([
            'success' => true,
            'message' => 'Empresa actualizada exitosamente',
            'data' => $company
        ]);
    }

    public function destroy($id)
    {
        $company = Company::find($id);
        if (!$company) {
            return response()->json(['success' => false, 'message' => 'Empresa no encontrada'], 404);
        }

        $company->delete();

        return response()->json([
            'success' => true,
            'message' => 'Empresa eliminada exitosamente'
        ]);
    }

    /**
     * Consultar empresas en tiempo real desde el Stored Procedure CALL SP_GetEmpresas() de SOPORTEZM.
     */
    public function getSoporteZmCompanies()
    {
        try {
            $companies = $this->soporteZmService->getEmpresasFromProcedure();
            return response()->json([
                'success' => true,
                'total' => count($companies),
                'data' => $companies
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener información en tiempo real de la empresa desde el SP + sus biométricos locales.
     */
    public function getBiometricsByIntercompania($intercompania)
    {
        try {
            $result = $this->soporteZmService->getEmpresaByIntercompania($intercompania);

            return response()->json([
                'success' => true,
                'data' => $result
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generar reporte cruzando empresas del Stored Procedure con resumen de biométricos por intercompañía.
     */
    public function getBiometricsReport(Request $request)
    {
        try {
            $intercompania = $request->input('intercompania');
            $report = $this->soporteZmService->getReporteBiometricos($intercompania);

            return response()->json([
                'success' => true,
                'total' => count($report),
                'data' => $report
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }


}

