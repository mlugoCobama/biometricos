<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyDepartmentSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentScheduleController extends Controller
{
    /**
     * Listar horarios por área/departamento (globales o filtrados por empresa/intercompañía)
     * GET /api/v1/department-schedules
     */
    public function index(Request $request): JsonResponse
    {
        $query = CompanyDepartmentSchedule::with('company');

        if ($request->filled('company_id') || $request->filled('intercompania')) {
            $company = $this->resolveCompany($request);
            if ($company) {
                $query->where(function ($q) use ($company) {
                    $q->where('company_id', $company->id)
                      ->orWhereNull('company_id');
                });
            }
        }

        $schedules = $query->orderBy('company_id', 'desc')->orderBy('department_name', 'asc')->get();

        return response()->json([
            'success' => true,
            'total' => count($schedules),
            'data' => $schedules,
        ]);
    }

    /**
     * Listar horarios por intercompañía directo en URL
     * GET /api/v1/companies/intercompania/{intercompania}/schedules
     */
    public function getByIntercompania(string $intercompania, Request $request): JsonResponse
    {
        $request->merge(['intercompania' => $intercompania]);
        return $this->index($request);
    }

    /**
     * Crear o actualizar el horario y tolerancia de un departamento/área
     * POST /api/v1/department-schedules
     */
    public function store(Request $request): JsonResponse
    {
        $this->validate($request, [
            'company_id' => 'nullable|exists:companies,id',
            'intercompania' => 'nullable|string',
            'department_name' => 'required|string|max:100',
            'schedule_entry' => 'required|string', // ej: "09:00" o "09:00:00"
            'schedule_exit' => 'nullable|string',  // ej: "18:00" o "18:00:00"
            'meal_start' => 'nullable|string',
            'meal_end' => 'nullable|string',
            'tolerance_minutes' => 'nullable|integer|min:0|max:240',
            'expected_daily_hours' => 'nullable|numeric|min:1|max:24',
            'is_active' => 'nullable|boolean',
        ]);

        $company = $this->resolveCompany($request);
        $companyId = $company ? $company->id : $request->input('company_id');

        $schedule = CompanyDepartmentSchedule::updateOrCreate(
            [
                'company_id' => $companyId,
                'department_name' => trim($request->input('department_name')),
            ],
            [
                'schedule_entry' => $request->input('schedule_entry', '09:00:00'),
                'schedule_exit' => $request->input('schedule_exit', '18:00:00'),
                'meal_start' => $request->input('meal_start'),
                'meal_end' => $request->input('meal_end'),
                'tolerance_minutes' => (int)$request->input('tolerance_minutes', 15),
                'expected_daily_hours' => (float)$request->input('expected_daily_hours', 8.00),
                'is_active' => $request->boolean('is_active', true),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Horario de área/departamento guardado exitosamente',
            'data' => $schedule,
        ], 201);
    }

    /**
     * Actualizar un horario por su ID
     * PUT /api/v1/department-schedules/{id}
     */
    public function update(Request $request, $id): JsonResponse
    {
        $schedule = CompanyDepartmentSchedule::find($id);
        if (!$schedule) {
            return response()->json(['success' => false, 'message' => 'Horario no encontrado'], 404);
        }

        $this->validate($request, [
            'department_name' => 'sometimes|required|string|max:100',
            'schedule_entry' => 'sometimes|required|string',
            'schedule_exit' => 'nullable|string',
            'meal_start' => 'nullable|string',
            'meal_end' => 'nullable|string',
            'tolerance_minutes' => 'nullable|integer|min:0|max:240',
            'expected_daily_hours' => 'nullable|numeric|min:1|max:24',
            'is_active' => 'nullable|boolean',
        ]);

        $schedule->update($request->only([
            'department_name',
            'schedule_entry',
            'schedule_exit',
            'meal_start',
            'meal_end',
            'tolerance_minutes',
            'expected_daily_hours',
            'is_active',
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Horario actualizado exitosamente',
            'data' => $schedule,
        ]);
    }

    /**
     * Eliminar un horario por su ID
     * DELETE /api/v1/department-schedules/{id}
     */
    public function destroy($id): JsonResponse
    {
        $schedule = CompanyDepartmentSchedule::find($id);
        if (!$schedule) {
            return response()->json(['success' => false, 'message' => 'Horario no encontrado'], 404);
        }

        $schedule->delete();

        return response()->json([
            'success' => true,
            'message' => 'Horario eliminado exitosamente',
        ]);
    }

    /**
     * Resuelve el modelo Company por intercompañía o company_id
     */
    private function resolveCompany(Request $request): ?Company
    {
        $val = $request->input('intercompania') ?: $request->input('company_id');
        if (!$val) {
            return null;
        }

        return Company::query()
            ->where('intercompania', (string)$val)
            ->orWhere('code', (string)$val)
            ->orWhere('id', $val)
            ->first();
    }
}
