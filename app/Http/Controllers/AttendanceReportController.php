<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\AttendanceReportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceReportController extends Controller
{
    protected AttendanceReportService $reportService;

    public function __construct(AttendanceReportService $reportService)
    {
        $this->reportService = $reportService;
    }

    /**
     * Reporte Diario de Asistencia por Empresa
     * GET /api/v1/reports/attendance/daily
     */
    public function daily(Request $request): JsonResponse
    {
        $this->validate($request, [
            'intercompania' => 'required_without:company_id',
            'company_id' => 'required_without:intercompania',
            'date' => 'nullable|date_format:Y-m-d',
            'schedule_entry' => 'nullable|string',
            'tolerance' => 'nullable|integer',
        ]);

        $company = $this->resolveCompany($request);
        if (!$company) {
            return response()->json(['success' => false, 'message' => 'Empresa/Intercompañía no encontrada'], 404);
        }

        $date = $request->input('date') ? Carbon::parse($request->input('date')) : Carbon::now();
        $scheduleEntry = $request->input('schedule_entry', '09:00');
        $tolerance = (int)$request->input('tolerance', 15);

        $reportData = $this->reportService->generateDailyReport($company, $date, $scheduleEntry, $tolerance);

        return response()->json([
            'success' => true,
            'data' => $reportData,
        ]);
    }

    /**
     * Reporte Quincenal de Asistencia por Empresa
     * GET /api/v1/reports/attendance/quincenal
     */
    public function quincenal(Request $request): JsonResponse
    {
        $this->validate($request, [
            'intercompania' => 'required_without:company_id',
            'company_id' => 'required_without:intercompania',
            'period' => 'nullable|string',
            'schedule_entry' => 'nullable|string',
            'tolerance' => 'nullable|integer',
        ]);

        $company = $this->resolveCompany($request);
        if (!$company) {
            return response()->json(['success' => false, 'message' => 'Empresa/Intercompañía no encontrada'], 404);
        }

        [$startDate, $endDate, $periodLabel] = $this->resolveQuincenalRange($request->input('period', 'current_quincena'));
        $scheduleEntry = $request->input('schedule_entry', '09:00');
        $tolerance = (int)$request->input('tolerance', 15);

        $reportData = $this->reportService->generatePeriodReport(
            $company,
            $startDate,
            $endDate,
            $scheduleEntry,
            $tolerance,
            'quincenal',
            $periodLabel
        );

        return response()->json([
            'success' => true,
            'data' => $reportData,
        ]);
    }

    /**
     * Reporte Mensual de Asistencia por Empresa
     * GET /api/v1/reports/attendance/monthly
     */
    public function monthly(Request $request): JsonResponse
    {
        $this->validate($request, [
            'intercompania' => 'required_without:company_id',
            'company_id' => 'required_without:intercompania',
            'month' => 'nullable|string',
            'schedule_entry' => 'nullable|string',
            'tolerance' => 'nullable|integer',
        ]);

        $company = $this->resolveCompany($request);
        if (!$company) {
            return response()->json(['success' => false, 'message' => 'Empresa/Intercompañía no encontrada'], 404);
        }

        [$startDate, $endDate, $periodLabel] = $this->resolveMonthlyRange($request->input('month', 'current_month'));
        $scheduleEntry = $request->input('schedule_entry', '09:00');
        $tolerance = (int)$request->input('tolerance', 15);

        $reportData = $this->reportService->generatePeriodReport(
            $company,
            $startDate,
            $endDate,
            $scheduleEntry,
            $tolerance,
            'monthly',
            $periodLabel
        );

        return response()->json([
            'success' => true,
            'data' => $reportData,
        ]);
    }

    /**
     * Reporte Diario por Intercompañía (parámetro en URL)
     * GET /api/v1/companies/intercompania/{intercompania}/reports/daily
     */
    public function dailyByIntercompania(string $intercompania, Request $request): JsonResponse
    {
        $request->merge(['intercompania' => $intercompania]);
        return $this->daily($request);
    }

    /**
     * Reporte Quincenal por Intercompañía (parámetro en URL)
     * GET /api/v1/companies/intercompania/{intercompania}/reports/quincenal
     */
    public function quincenalByIntercompania(string $intercompania, Request $request): JsonResponse
    {
        $request->merge(['intercompania' => $intercompania]);
        return $this->quincenal($request);
    }

    /**
     * Reporte Mensual por Intercompañía (parámetro en URL)
     * GET /api/v1/companies/intercompania/{intercompania}/reports/monthly
     */
    public function monthlyByIntercompania(string $intercompania, Request $request): JsonResponse
    {
        $request->merge(['intercompania' => $intercompania]);
        return $this->monthly($request);
    }

    /**
     * Resuelve el modelo Company buscando primeramente por número/código de intercompañía u opcionalmente company_id
     */
    private function resolveCompany(Request $request): ?Company
    {
        $intercompania = $request->input('intercompania') ?: $request->input('company_id');

        if (!$intercompania) {
            return null;
        }

        // 1. Buscar por número/código de intercompañía o por código de empresa
        $company = Company::query()
            ->where('intercompania', (string)$intercompania)
            ->orWhere('code', (string)$intercompania)
            ->first();

        if ($company) {
            return $company;
        }

        // 2. Fallback por ID de empresa si se envía numérico
        if (is_numeric($intercompania)) {
            return Company::find((int)$intercompania);
        }

        return null;
    }

    /**
     * Resuelve rango de fechas quincenal
     */
    private function resolveQuincenalRange(string $periodOpt): array
    {
        $now = Carbon::now();

        if ($periodOpt === 'current_quincena') {
            if ($now->day <= 15) {
                $startDate = $now->copy()->startOfMonth();
                $endDate = $now->copy()->endOfDay();
                $label = "1ra Quincena de " . $this->reportService->getSpanishMonthName($now->month) . " {$now->year} (al día de hoy)";
            } else {
                $startDate = $now->copy()->setDay(16)->startOfDay();
                $endDate = $now->copy()->endOfDay();
                $label = "2da Quincena de " . $this->reportService->getSpanishMonthName($now->month) . " {$now->year} (al día de hoy)";
            }
        } elseif ($periodOpt === 'previous_quincena') {
            if ($now->day <= 15) {
                $prevMonth = $now->copy()->subMonth();
                $startDate = $prevMonth->copy()->setDay(16)->startOfDay();
                $endDate = $prevMonth->copy()->endOfMonth()->endOfDay();
                $label = "2da Quincena de " . $this->reportService->getSpanishMonthName($prevMonth->month) . " {$prevMonth->year}";
            } else {
                $startDate = $now->copy()->startOfMonth();
                $endDate = $now->copy()->setDay(15)->endOfDay();
                $label = "1ra Quincena de " . $this->reportService->getSpanishMonthName($now->month) . " {$now->year}";
            }
        } elseif (preg_match('/^(\d{4})-(\d{2})-(Q1|Q2)$/i', $periodOpt, $matches)) {
            $year = (int)$matches[1];
            $month = (int)$matches[2];
            $q = strtoupper($matches[3]);

            if ($q === 'Q1') {
                $startDate = Carbon::createFromDate($year, $month, 1)->startOfDay();
                $endDate = Carbon::createFromDate($year, $month, 15)->endOfDay();
                $label = "1ra Quincena de " . $this->reportService->getSpanishMonthName($month) . " {$year}";
            } else {
                $startDate = Carbon::createFromDate($year, $month, 16)->startOfDay();
                $endDate = Carbon::createFromDate($year, $month, 1)->endOfMonth()->endOfDay();
                $label = "2da Quincena de " . $this->reportService->getSpanishMonthName($month) . " {$year}";
            }
        } else {
            $date = Carbon::parse($periodOpt);
            if ($date->day <= 15) {
                $startDate = $date->copy()->startOfMonth();
                $endDate = $date->copy()->setDay(15)->endOfDay();
                $label = "1ra Quincena de " . $this->reportService->getSpanishMonthName($date->month) . " {$date->year}";
            } else {
                $startDate = $date->copy()->setDay(16)->startOfDay();
                $endDate = $date->copy()->endOfMonth()->endOfDay();
                $label = "2da Quincena de " . $this->reportService->getSpanishMonthName($date->month) . " {$date->year}";
            }
        }

        return [$startDate->startOfDay(), $endDate->endOfDay(), $label];
    }

    /**
     * Resuelve rango de fechas mensual
     */
    private function resolveMonthlyRange(string $monthOpt): array
    {
        $now = Carbon::now();

        if ($monthOpt === 'current_month') {
            $startDate = $now->copy()->startOfMonth()->startOfDay();
            $endDate = $now->copy()->endOfDay();
            $label = "Mes de " . $this->reportService->getSpanishMonthName($now->month) . " {$now->year} (al día de hoy)";
        } elseif ($monthOpt === 'previous_month') {
            $prev = $now->copy()->subMonth();
            $startDate = $prev->copy()->startOfMonth()->startOfDay();
            $endDate = $prev->copy()->endOfMonth()->endOfDay();
            $label = "Mes de " . $this->reportService->getSpanishMonthName($prev->month) . " {$prev->year}";
        } elseif (preg_match('/^(\d{4})-(\d{2})$/', $monthOpt, $matches)) {
            $year = (int)$matches[1];
            $month = (int)$matches[2];
            $startDate = Carbon::createFromDate($year, $month, 1)->startOfDay();
            $endDate = Carbon::createFromDate($year, $month, 1)->endOfMonth()->endOfDay();
            $label = "Mes de " . $this->reportService->getSpanishMonthName($month) . " {$year}";
        } else {
            $date = Carbon::parse($monthOpt);
            $startDate = $date->copy()->startOfMonth()->startOfDay();
            $endDate = $date->copy()->endOfMonth()->endOfDay();
            $label = "Mes de " . $this->reportService->getSpanishMonthName($date->month) . " {$date->year}";
        }

        return [$startDate, $endDate, $label];
    }
}
