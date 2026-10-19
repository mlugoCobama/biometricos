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
            'report_slots' => 'nullable|integer|in:2,4',
        ]);

        $company = $this->resolveCompany($request);
        if (!$company) {
            return response()->json(['success' => false, 'message' => 'Empresa/Intercompañía no encontrada'], 404);
        }

        $date = $request->input('date') ? Carbon::parse($request->input('date')) : Carbon::now();
        $scheduleEntry = $request->input('schedule_entry', '09:00');
        $tolerance = (int)$request->input('tolerance', 15);
        $reportSlots = $request->has('report_slots') ? (int)$request->input('report_slots') : null;

        $reportData = $this->reportService->generateDailyReport($company, $date, $scheduleEntry, $tolerance, $reportSlots);

        return response()->json([
            'success' => true,
            'data' => $reportData,
        ]);
    }

    /**
     * Reporte Quincenal de Asistencia por Empresa
     * GET /api/v1/reports/attendance/quincenal
     */
    public function quincenal(Request $request)
    {
        $this->validate($request, [
            'intercompania' => 'required_without:company_id',
            'company_id' => 'required_without:intercompania',
            'period' => 'nullable|string',
            'schedule_entry' => 'nullable|string',
            'tolerance' => 'nullable|integer',
            'report_slots' => 'nullable|integer|in:2,4',
            'format' => 'nullable|string|in:json,csv',
        ]);

        $company = $this->resolveCompany($request);
        if (!$company) {
            return response()->json(['success' => false, 'message' => 'Empresa/Intercompañía no encontrada'], 404);
        }

        [$startDate, $endDate, $periodLabel] = $this->resolveQuincenalRange($request);
        $scheduleEntry = $request->input('schedule_entry', '09:00');
        $tolerance = (int)$request->input('tolerance', 15);
        $reportSlots = $request->has('report_slots') ? (int)$request->input('report_slots') : null;

        $reportData = $this->reportService->generatePeriodReport(
            $company,
            $startDate,
            $endDate,
            $scheduleEntry,
            $tolerance,
            'quincenal',
            $periodLabel,
            $reportSlots
        );

        if ($request->input('format') === 'csv') {
            return $this->downloadPeriodCsvResponse($company, $reportData, $startDate, $endDate, 'quincenal');
        }

        return response()->json([
            'success' => true,
            'data' => $reportData,
        ]);
    }

    /**
     * Descargar Reporte Quincenal en CSV directamente
     * GET /api/v1/reports/attendance/quincenal/csv
     */
    public function quincenalCsv(Request $request)
    {
        $request->merge(['format' => 'csv']);
        return $this->quincenal($request);
    }

    /**
     * Reporte Mensual de Asistencia por Empresa
     * GET /api/v1/reports/attendance/monthly
     */
    public function monthly(Request $request)
    {
        $this->validate($request, [
            'intercompania' => 'required_without:company_id',
            'company_id' => 'required_without:intercompania',
            'month' => 'nullable|string',
            'period' => 'nullable|string',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d',
            'year' => 'nullable|integer',
            'schedule_entry' => 'nullable|string',
            'tolerance' => 'nullable|integer',
            'report_slots' => 'nullable|integer|in:2,4',
            'format' => 'nullable|string|in:json,csv',
        ]);

        $company = $this->resolveCompany($request);
        if (!$company) {
            return response()->json(['success' => false, 'message' => 'Empresa/Intercompañía no encontrada'], 404);
        }

        [$startDate, $endDate, $periodLabel] = $this->resolveMonthlyRange($request);
        $scheduleEntry = $request->input('schedule_entry', '09:00');
        $tolerance = (int)$request->input('tolerance', 15);
        $reportSlots = $request->has('report_slots') ? (int)$request->input('report_slots') : null;

        $reportData = $this->reportService->generatePeriodReport(
            $company,
            $startDate,
            $endDate,
            $scheduleEntry,
            $tolerance,
            'monthly',
            $periodLabel,
            $reportSlots
        );

        if ($request->input('format') === 'csv') {
            return $this->downloadPeriodCsvResponse($company, $reportData, $startDate, $endDate, 'mensual');
        }

        return response()->json([
            'success' => true,
            'data' => $reportData,
        ]);
    }

    /**
     * Descargar Reporte Mensual en CSV directamente
     * GET /api/v1/reports/attendance/monthly/csv
     */
    public function monthlyCsv(Request $request)
    {
        $request->merge(['format' => 'csv']);
        return $this->monthly($request);
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
    public function quincenalByIntercompania(string $intercompania, Request $request)
    {
        $request->merge(['intercompania' => $intercompania]);
        return $this->quincenal($request);
    }

    /**
     * Descargar Reporte Quincenal en CSV por Intercompañía
     * GET /api/v1/companies/intercompania/{intercompania}/reports/quincenal/csv
     */
    public function quincenalCsvByIntercompania(string $intercompania, Request $request)
    {
        $request->merge(['intercompania' => $intercompania, 'format' => 'csv']);
        return $this->quincenal($request);
    }

    /**
     * Reporte Mensual por Intercompañía (parámetro en URL)
     * GET /api/v1/companies/intercompania/{intercompania}/reports/monthly
     */
    public function monthlyByIntercompania(string $intercompania, Request $request)
    {
        $request->merge(['intercompania' => $intercompania]);
        return $this->monthly($request);
    }

    /**
     * Descargar Reporte Mensual en CSV por Intercompañía
     * GET /api/v1/companies/intercompania/{intercompania}/reports/monthly/csv
     */
    public function monthlyCsvByIntercompania(string $intercompania, Request $request)
    {
        $request->merge(['intercompania' => $intercompania, 'format' => 'csv']);
        return $this->monthly($request);
    }

    /**
     * Construye la respuesta HTTP para descarga directa del archivo CSV
     */
    private function downloadPeriodCsvResponse(Company $company, array $reportData, Carbon $startDate, Carbon $endDate, string $prefix = 'quincenal')
    {
        $csvContent = $this->reportService->generatePeriodCsvString($company, $reportData, $startDate, $endDate);
        $intercompania = $company->intercompania ?? $company->code ?? $company->id;
        $filename = "reporte_{$prefix}_{$intercompania}_" . $startDate->format('Ymd') . "_" . $endDate->format('Ymd') . ".csv";

        return response($csvContent, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
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
     * Resuelve rango de fechas quincenal (actuales, anteriores o históricas)
     */
    private function resolveQuincenalRange(Request $request): array
    {
        $now = Carbon::now();

        // 1. Rango explícito con start_date y end_date
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $startDate = Carbon::parse($request->input('start_date'))->startOfDay();
            $endDate = Carbon::parse($request->input('end_date'))->endOfDay();
            $label = "Rango Personalizado: " . $startDate->format('d/m/Y') . " al " . $endDate->format('d/m/Y');
            return [$startDate, $endDate, $label];
        }

        // 2. Parámetros separados: year, month, quincena (1 o 2)
        if ($request->filled('year') && $request->filled('month')) {
            $year = (int)$request->input('year');
            $month = (int)$request->input('month');
            $quincena = (int)$request->input('quincena', 1);

            if ($quincena === 2) {
                $startDate = Carbon::createFromDate($year, $month, 16)->startOfDay();
                $endDate = Carbon::createFromDate($year, $month, 1)->endOfMonth()->endOfDay();
                $label = "2da Quincena de " . $this->reportService->getSpanishMonthName($month) . " {$year}";
            } else {
                $startDate = Carbon::createFromDate($year, $month, 1)->startOfDay();
                $endDate = Carbon::createFromDate($year, $month, 15)->endOfDay();
                $label = "1ra Quincena de " . $this->reportService->getSpanishMonthName($month) . " {$year}";
            }
            return [$startDate, $endDate, $label];
        }

        $periodOpt = (string)($request->input('period') ?: $request->input('quincena', 'current_quincena'));

        // 3. Quincena actual
        if ($periodOpt === 'current_quincena' || $periodOpt === '0') {
            if ($now->day <= 15) {
                $startDate = $now->copy()->startOfMonth();
                $endDate = $now->copy()->endOfDay();
                $label = "1ra Quincena de " . $this->reportService->getSpanishMonthName($now->month) . " {$now->year} (al día de hoy)";
            } else {
                $startDate = $now->copy()->setDay(16)->startOfDay();
                $endDate = $now->copy()->endOfDay();
                $label = "2da Quincena de " . $this->reportService->getSpanishMonthName($now->month) . " {$now->year} (al día de hoy)";
            }
            return [$startDate->startOfDay(), $endDate->endOfDay(), $label];
        }

        // 4. Quincena anterior o N quincenas atrás (ej: previous_quincena, -1, -2, -3)
        if ($periodOpt === 'previous_quincena' || (is_numeric($periodOpt) && (int)$periodOpt < 0)) {
            $stepsBack = is_numeric($periodOpt) ? abs((int)$periodOpt) : 1;
            $currentDate = $now->copy();

            for ($i = 0; $i < $stepsBack; $i++) {
                if ($currentDate->day <= 15) {
                    $currentDate->subMonth()->setDay(16);
                } else {
                    $currentDate->setDay(1);
                }
            }

            if ($currentDate->day <= 15) {
                $startDate = $currentDate->copy()->startOfMonth();
                $endDate = $currentDate->copy()->setDay(15)->endOfDay();
                $label = "1ra Quincena de " . $this->reportService->getSpanishMonthName($currentDate->month) . " {$currentDate->year}";
            } else {
                $startDate = $currentDate->copy()->setDay(16)->startOfDay();
                $endDate = $currentDate->copy()->endOfMonth()->endOfDay();
                $label = "2da Quincena de " . $this->reportService->getSpanishMonthName($currentDate->month) . " {$currentDate->year}";
            }
            return [$startDate->startOfDay(), $endDate->endOfDay(), $label];
        }

        // 5. Formato YYYY-MM-Q1 o YYYY-MM-Q2
        if (preg_match('/^(\d{4})-(\d{1,2})-(Q1|Q2)$/i', $periodOpt, $matches)) {
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
            return [$startDate, $endDate, $label];
        }

        // 6. Formato de cualquier fecha YYYY-MM o YYYY-MM-DD
        try {
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
            return [$startDate->startOfDay(), $endDate->endOfDay(), $label];
        } catch (\Exception $e) {
            return $this->resolveQuincenalRange(new Request(['period' => 'current_quincena']));
        }
    }

    /**
     * Resuelve rango de fechas mensual (actuales, anteriores o históricos)
     */
    private function resolveMonthlyRange(Request $request): array
    {
        $now = Carbon::now();

        // 1. Rango explícito con start_date y end_date
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $startDate = Carbon::parse($request->input('start_date'))->startOfDay();
            $endDate = Carbon::parse($request->input('end_date'))->endOfDay();
            $label = "Mes Rango Personalizado: " . $startDate->format('d/m/Y') . " al " . $endDate->format('d/m/Y');
            return [$startDate, $endDate, $label];
        }

        // 2. Parámetros separados: year y month
        if ($request->filled('year') && $request->filled('month') && is_numeric($request->input('month'))) {
            $year = (int)$request->input('year');
            $month = (int)$request->input('month');

            $startDate = Carbon::createFromDate($year, $month, 1)->startOfDay();
            $endDate = Carbon::createFromDate($year, $month, 1)->endOfMonth()->endOfDay();
            $label = "Mes de " . $this->reportService->getSpanishMonthName($month) . " {$year}";
            return [$startDate, $endDate, $label];
        }

        $monthOpt = (string)($request->input('month') ?: $request->input('period', 'current_month'));

        // 3. Mes actual
        if ($monthOpt === 'current_month' || $monthOpt === '0') {
            $startDate = $now->copy()->startOfMonth()->startOfDay();
            $endDate = $now->copy()->endOfDay();
            $label = "Mes de " . $this->reportService->getSpanishMonthName($now->month) . " {$now->year} (al día de hoy)";
            return [$startDate, $endDate, $label];
        }

        // 4. Mes anterior o N meses atrás (ej: previous_month, -1, -2, -6, etc.)
        if ($monthOpt === 'previous_month' || (is_numeric($monthOpt) && (int)$monthOpt < 0)) {
            $monthsBack = is_numeric($monthOpt) ? abs((int)$monthOpt) : 1;
            $target = $now->copy()->subMonths($monthsBack);
            $startDate = $target->copy()->startOfMonth()->startOfDay();
            $endDate = $target->copy()->endOfMonth()->endOfDay();
            $label = "Mes de " . $this->reportService->getSpanishMonthName($target->month) . " {$target->year}";
            return [$startDate, $endDate, $label];
        }

        // 5. Formato YYYY-MM
        if (preg_match('/^(\d{4})-(\d{1,2})$/', $monthOpt, $matches)) {
            $year = (int)$matches[1];
            $month = (int)$matches[2];
            $startDate = Carbon::createFromDate($year, $month, 1)->startOfDay();
            $endDate = Carbon::createFromDate($year, $month, 1)->endOfMonth()->endOfDay();
            $label = "Mes de " . $this->reportService->getSpanishMonthName($month) . " {$year}";
            return [$startDate, $endDate, $label];
        }

        // 6. Formato de cualquier fecha YYYY-MM-DD
        try {
            $date = Carbon::parse($monthOpt);
            $startDate = $date->copy()->startOfMonth()->startOfDay();
            $endDate = $date->copy()->endOfMonth()->endOfDay();
            $label = "Mes de " . $this->reportService->getSpanishMonthName($date->month) . " {$date->year}";
            return [$startDate, $endDate, $label];
        } catch (\Exception $e) {
            return $this->resolveMonthlyRange(new Request(['month' => 'current_month']));
        }
    }
}
