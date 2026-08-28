<?php

namespace App\Console\Commands;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Exception;

class SendQuincenalAttendanceReportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance:quincenal-report
                            {--company_id= : ID de la empresa a filtrar}
                            {--intercompania= : Código de intercompañía a filtrar}
                            {--email= : Correo electrónico destino}
                            {--period=current_quincena : Periodo quincenal (current_quincena, previous_quincena, YYYY-MM-Q1, YYYY-MM-Q2)}
                            {--format=all : Formato de salida (table, html, csv, all)}
                            {--schedule_entry=08:00 : Hora oficial de entrada (HH:MM)}
                            {--tolerance=15 : Minutos de tolerancia para retardo}
                            {--output_dir= : Directorio personalizado para guardar reportes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Genera y envía reporte quincenal de asistencia por empresa contemplando 4 marcaciones, tiempo laborado (sin comida), retardos y acumulación al día actual.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $this->info('===========================================================');
        $this->info('   REPORTE QUINCENAL DE ASISTENCIA POR EMPRESA (HTML/EMAIL)');
        $this->info('===========================================================');

        try {
            // 1. Determinar rango de fechas quincenal (1 al 15 o 16 al fin de mes, acumulado al día de hoy)
            [$startDate, $endDate, $periodLabel] = $this->resolveQuincenalDateRange();
            $this->info("Periodo evaluado: {$startDate->format('Y-m-d')} al {$endDate->format('Y-m-d')} ({$periodLabel})");

            // 2. Obtener lista de empresas/intercompañías a procesar
            $companies = $this->resolveCompanies();

            if ($companies->isEmpty()) {
                $this->warn('No se encontraron empresas para procesar.');
                return Command::SUCCESS;
            }

            $outputDir = $this->option('output_dir') ?: storage_path('reports');
            if (!file_exists($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            $email = $this->option('email');
            $format = strtolower($this->option('format'));
            $scheduleEntry = $this->option('schedule_entry') ?: '08:00';
            $tolerance = (int)($this->option('tolerance') ?: 15);

            foreach ($companies as $company) {
                $this->info("\nProcesando Empresa: {$company->name} [ID: {$company->id} | Code/Inter: {$company->code}]");

                // Obtener empleados de la empresa
                $employees = Employee::query()
                    ->where(function ($q) use ($company) {
                        $q->where('company_id', $company->id);
                        if (!empty($company->intercompania)) {
                            $q->orWhere('intercompania', $company->intercompania);
                        }
                    })
                    ->orderBy('pin', 'asc')
                    ->get();

                if ($employees->isEmpty()) {
                    $this->line("  -> Sin empleados registrados.");
                    continue;
                }

                // Estructurar reporte quincenal para cada empleado
                $reportData = $this->buildCompanyQuincenalReport($company, $employees, $startDate, $endDate, $scheduleEntry, $tolerance);

                // Imprimir en consola si aplica
                if (in_array($format, ['table', 'all'])) {
                    $this->displayConsoleTable($company, $reportData);
                }

                // Generar CSV si aplica
                $csvFile = null;
                if (in_array($format, ['csv', 'all'])) {
                    $csvFile = $this->generateCsvReport($company, $reportData, $startDate, $endDate, $outputDir);
                    $this->info("  ✓ Archivo CSV generado: {$csvFile}");
                }

                // Generar HTML si aplica
                $htmlFile = null;
                $htmlContent = null;
                if (in_array($format, ['html', 'all'])) {
                    [$htmlFile, $htmlContent] = $this->generateHtmlReport($company, $reportData, $startDate, $endDate, $outputDir, $scheduleEntry, $tolerance, $periodLabel);
                    $this->info("  ✓ Archivo HTML generado: {$htmlFile}");
                }

                // Obtener destinatarios a quienes enviar el reporte
                $recipients = [];
                if ($email) {
                    $recipients = array_map('trim', explode(',', $email));
                } elseif (!empty($company->report_emails) && is_array($company->report_emails)) {
                    $recipients = $company->report_emails;
                }

                if (empty($recipients)) {
                    $this->line("  -> Sin destinatarios (report_emails) configurados para esta empresa.");
                } else {
                    foreach ($recipients as $recipientEmail) {
                        $recipientEmail = trim($recipientEmail);
                        if (filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                            $this->sendAndLogReportEmail($recipientEmail, $company, $startDate, $endDate, $htmlContent, $csvFile, $periodLabel);
                        }
                    }
                }
            }

            $this->info("\n===========================================================");
            $this->info('✓ Proceso de generación de reporte quincenal finalizado.');
            $this->info('===========================================================');

            return Command::SUCCESS;
        } catch (Exception $e) {
            $this->error('✘ Error al generar reporte quincenal de asistencia: ' . $e->getMessage());
            Log::error('Quincenal attendance report command error', ['exception' => $e]);
            return Command::FAILURE;
        }
    }

    /**
     * Resuelve el rango de fechas de la quincena (1 al 15 o 16 al fin de mes, hasta el día de hoy)
     */
    private function resolveQuincenalDateRange(): array
    {
        $periodOpt = $this->option('period') ?: 'current_quincena';
        $now = Carbon::now();

        if ($periodOpt === 'current_quincena') {
            if ($now->day <= 15) {
                $startDate = $now->copy()->startOfMonth();
                $endDate = $now->copy()->endOfDay();
                $label = "1ra Quincena de " . $this->getSpanishMonthName($now->month) . " {$now->year} (al día de hoy)";
            } else {
                $startDate = $now->copy()->setDay(16)->startOfDay();
                $endDate = $now->copy()->endOfDay();
                $label = "2da Quincena de " . $this->getSpanishMonthName($now->month) . " {$now->year} (al día de hoy)";
            }
        } elseif ($periodOpt === 'previous_quincena') {
            if ($now->day <= 15) {
                // Quincena anterior es la 2da quincena del mes pasado
                $prevMonth = $now->copy()->subMonth();
                $startDate = $prevMonth->copy()->setDay(16)->startOfDay();
                $endDate = $prevMonth->copy()->endOfMonth()->endOfDay();
                $label = "2da Quincena de " . $this->getSpanishMonthName($prevMonth->month) . " {$prevMonth->year}";
            } else {
                // Quincena anterior es la 1ra quincena del mes actual
                $startDate = $now->copy()->startOfMonth();
                $endDate = $now->copy()->setDay(15)->endOfDay();
                $label = "1ra Quincena de " . $this->getSpanishMonthName($now->month) . " {$now->year}";
            }
        } elseif (preg_match('/^(\d{4})-(\d{2})-(Q1|Q2)$/i', $periodOpt, $matches)) {
            $year = (int)$matches[1];
            $month = (int)$matches[2];
            $q = strtoupper($matches[3]);

            if ($q === 'Q1') {
                $startDate = Carbon::createFromDate($year, $month, 1)->startOfDay();
                $endDate = Carbon::createFromDate($year, $month, 15)->endOfDay();
                $label = "1ra Quincena de " . $this->getSpanishMonthName($month) . " {$year}";
            } else {
                $startDate = Carbon::createFromDate($year, $month, 16)->startOfDay();
                $endDate = Carbon::createFromDate($year, $month, 1)->endOfMonth()->endOfDay();
                $label = "2da Quincena de " . $this->getSpanishMonthName($month) . " {$year}";
            }
        } else {
            // Asume fecha dada YYYY-MM-DD
            $date = Carbon::parse($periodOpt);
            if ($date->day <= 15) {
                $startDate = $date->copy()->startOfMonth();
                $endDate = $date->copy()->setDay(15)->endOfDay();
                $label = "1ra Quincena de " . $this->getSpanishMonthName($date->month) . " {$date->year}";
            } else {
                $startDate = $date->copy()->setDay(16)->startOfDay();
                $endDate = $date->copy()->endOfMonth()->endOfDay();
                $label = "2da Quincena de " . $this->getSpanishMonthName($date->month) . " {$date->year}";
            }
        }

        return [$startDate->startOfDay(), $endDate->endOfDay(), $label];
    }

    /**
     * Resuelve la lista de empresas a procesar
     */
    private function resolveCompanies()
    {
        $companyId = $this->option('company_id');
        $intercompania = $this->option('intercompania');

        $query = Company::query();

        if ($companyId) {
            $query->where('id', $companyId);
        }

        if ($intercompania) {
            $query->where(function ($q) use ($intercompania) {
                $q->where('code', $intercompania)
                  ->orWhere('intercompania', $intercompania);
            });
        }

        return $query->get();
    }

    /**
     * Construye los datos del reporte quincenal agrupado por empleado y por día
     */
    private function buildCompanyQuincenalReport(Company $company, $employees, Carbon $startDate, Carbon $endDate, string $scheduleEntry, int $tolerance): array
    {
        $report = [];

        // 1. Cargar días festivos para esta empresa (o festivos globales)
        $holidays = \App\Models\CompanyHoliday::query()
            ->where(function ($q) use ($company) {
                $q->whereNull('company_id')
                  ->orWhere('company_id', $company->id);
            })
            ->whereBetween('holiday_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->get()
            ->keyBy(fn($h) => $h->holiday_date->format('Y-m-d'));

        // 2. Cargar horarios por departamento (globales o específicos por empresa)
        $deptSchedules = \App\Models\CompanyDepartmentSchedule::query()
            ->where(function ($q) use ($company) {
                $q->whereNull('company_id')
                  ->orWhere('company_id', $company->id);
            })
            ->where('is_active', true)
            ->orderBy('company_id', 'desc') // empresa específica invalida el global
            ->get()
            ->keyBy(fn($s) => strtolower(trim($s->department_name)));

        // Generar lista de los días del periodo quincenal
        $days = [];
        $current = $startDate->copy();
        while ($current->lte($endDate)) {
            $days[] = [
                'date_str' => $current->format('Y-m-d'),
                'day_name' => $this->getSpanishDayName($current->dayOfWeek),
                'formatted' => $current->format('d/m (D)'),
            ];
            $current->addDay();
        }

        foreach ($employees as $emp) {
            // Resolver horario personalizado por departamento del empleado si existe
            $empDeptKey = strtolower(trim($emp->department ?? ''));
            $empScheduleEntry = $scheduleEntry;
            $empTolerance = $tolerance;

            if ($empDeptKey && isset($deptSchedules[$empDeptKey])) {
                $deptConfig = $deptSchedules[$empDeptKey];
                $empScheduleEntry = substr($deptConfig->schedule_entry, 0, 5);
                $empTolerance = $deptConfig->tolerance_minutes;
            }

            $totalAssistances = 0;
            $totalTardiness = 0;
            $totalAbsences = 0;

            $empReport = [
                'employee_id' => $emp->id,
                'pin' => $emp->pin,
                'name' => $emp->full_name,
                'card' => $emp->card_number ?? '-',
                'department' => $emp->department ?? 'General',
                'schedule_entry' => $empScheduleEntry,
                'days' => [],
                'total_worked_seconds' => 0,
                'total_tardiness_count' => 0,
                'total_assistances' => 0,
                'total_tardiness' => 0,
                'total_absences' => 0,
            ];

            foreach ($days as $day) {
                $dayDate = $day['date_str'];
                $isHoliday = isset($holidays[$dayDate]);
                $holidayObj = $isHoliday ? $holidays[$dayDate] : null;
                $dayCarbon = Carbon::parse($dayDate);
                $isSunday = ($dayCarbon->dayOfWeek === 0);

                // Consultar marcaciones del empleado en ese día ordenadas cronológicamente
                $logs = AttendanceLog::query()
                    ->where('pin', $emp->pin)
                    ->whereDate('punch_time', $dayDate)
                    ->orderBy('punch_time', 'asc')
                    ->get();

                $punches = $this->map4DailyPunches($logs, $dayDate, $empScheduleEntry, $empTolerance, $holidayObj, $isSunday);

                $empReport['total_worked_seconds'] += $punches['worked_seconds'];

                if ($punches['total_punches'] > 0) {
                    $totalAssistances++;
                } elseif (!$isSunday && !$isHoliday) {
                    $totalAbsences++;
                }

                if ($punches['is_tardy']) {
                    $totalTardiness++;
                }

                $empReport['days'][$dayDate] = array_merge($day, $punches, [
                    'day_of_week' => $dayCarbon->dayOfWeek,
                    'is_sunday' => $isSunday,
                ]);
            }

            $empReport['total_assistances'] = $totalAssistances;
            $empReport['total_tardiness'] = $totalTardiness;
            $empReport['total_absences'] = $totalAbsences;
            $empReport['total_tardiness_count'] = $totalTardiness;
            $empReport['total_worked_formatted'] = $this->formatWorkedTime($empReport['total_worked_seconds']);

            $report[] = $empReport;
        }

        return $report;
    }

    /**
     * Mapea inteligentemente las marcaciones del día y calcula el tiempo laborado sin comida y retardo
     */
    private function map4DailyPunches($logs, string $dayDate, string $scheduleEntry, int $tolerance, ?\App\Models\CompanyHoliday $holiday = null, bool $isSunday = false): array
    {
        $result = [
            'entrada' => '-',
            'salida_comer' => '-',
            'entrada_comer' => '-',
            'salida' => '-',
            'entrada_12h' => '-',
            'salida_comer_12h' => '-',
            'entrada_comer_12h' => '-',
            'salida_12h' => '-',
            'total_punches' => count($logs),
            'worked_time' => '-',
            'worked_seconds' => 0,
            'tardiness_status' => 'Sin Registro',
            'is_tardy' => false,
            'is_holiday' => !is_null($holiday),
            'is_sunday' => $isSunday,
            'holiday_description' => $holiday?->description,
            'delay_minutes' => 0,
        ];

        if ($holiday) {
            $result['tardiness_status'] = "Festivo ({$holiday->description})";
        } elseif ($isSunday) {
            $result['tardiness_status'] = "DESCANSO";
        }

        if ($logs->isEmpty()) {
            return $result;
        }

        // 1. Mapear marcaciones
        $hasExplicitTypes = $logs->contains(fn($l) => in_array($l->punch_type, [1, 2, 3]));

        if ($hasExplicitTypes) {
            foreach ($logs as $log) {
                $time = $log->punch_time->format('H:i:s');
                switch ($log->punch_type) {
                    case 0:
                        if ($result['entrada'] === '-') $result['entrada'] = $time;
                        break;
                    case 2:
                        if ($result['salida_comer'] === '-') $result['salida_comer'] = $time;
                        break;
                    case 3:
                        if ($result['entrada_comer'] === '-') $result['entrada_comer'] = $time;
                        break;
                    case 1:
                        $result['salida'] = $time;
                        break;
                }
            }
        } else {
            $times = $logs->pluck('punch_time')->map(fn($t) => $t->format('H:i:s'))->toArray();

            if (isset($times[0])) $result['entrada'] = $times[0];
            if (isset($times[1])) $result['salida_comer'] = $times[1];
            if (isset($times[2])) $result['entrada_comer'] = $times[2];
            if (isset($times[3])) $result['salida'] = $times[3];
            if (count($times) > 4) $result['salida'] = end($times);
        }

        // Formato 12 Horas (AM/PM) para la matriz Excel/CSV (Imagen 2)
        $result['entrada_12h'] = ($result['entrada'] !== '-') ? Carbon::parse("{$dayDate} {$result['entrada']}")->format('g:i A') : '-';
        $result['salida_comer_12h'] = ($result['salida_comer'] !== '-') ? Carbon::parse("{$dayDate} {$result['salida_comer']}")->format('g:i A') : '-';
        $result['entrada_comer_12h'] = ($result['entrada_comer'] !== '-') ? Carbon::parse("{$dayDate} {$result['entrada_comer']}")->format('g:i A') : '-';
        $result['salida_12h'] = ($result['salida'] !== '-') ? Carbon::parse("{$dayDate} {$result['salida']}")->format('g:i A') : '-';

        // 2. Calcular Retardo (si no es festivo ni domingo)
        if ($result['entrada'] !== '-') {
            $entryTime = Carbon::parse("{$dayDate} {$result['entrada']}");
            $officialEntry = Carbon::parse("{$dayDate} {$scheduleEntry}:00");
            $maxAllowed = $officialEntry->copy()->addMinutes($tolerance);

            if ($entryTime->gt($maxAllowed)) {
                $delayMinutes = $officialEntry->diffInMinutes($entryTime);
                $result['is_tardy'] = (!$holiday && !$isSunday);
                $result['delay_minutes'] = $delayMinutes;
                $result['tardiness_status'] = "Retardo ({$delayMinutes} min)";
            } else {
                $result['tardiness_status'] = "A Tiempo";
            }
        }

        // 3. Calcular Tiempo Laborado (Excluyendo comida)
        $workedSeconds = 0;

        if ($result['entrada'] !== '-' && $result['salida_comer'] !== '-' && $result['entrada_comer'] !== '-' && $result['salida'] !== '-') {
            $morningSeconds = Carbon::parse("{$dayDate} {$result['entrada']}")->diffInSeconds(Carbon::parse("{$dayDate} {$result['salida_comer']}"));
            $afternoonSeconds = Carbon::parse("{$dayDate} {$result['entrada_comer']}")->diffInSeconds(Carbon::parse("{$dayDate} {$result['salida']}"));
            $workedSeconds = max(0, $morningSeconds + $afternoonSeconds);
        } elseif ($result['entrada'] !== '-' && $result['salida'] !== '-') {
            $workedSeconds = max(0, Carbon::parse("{$dayDate} {$result['entrada']}")->diffInSeconds(Carbon::parse("{$dayDate} {$result['salida']}")));
        }

        $result['worked_seconds'] = $workedSeconds;
        $result['worked_time'] = ($workedSeconds > 0) ? $this->formatWorkedTime($workedSeconds) : '-';

        return $result;
    }

    /**
     * Formatea segundos a HH:MM (ej. 85:30 hrs)
     */
    private function formatWorkedTime(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        return sprintf('%02d:%02d hrs', $hours, $minutes);
    }

    /**
     * Muestra reporte en formato tabla de consola
     */
    private function displayConsoleTable(Company $company, array $reportData): void
    {
        $headers = ['PIN', 'Empleado', 'Asistencias', 'Retardos', 'Faltas', 'Horas Laboradas'];
        $rows = [];

        foreach ($reportData as $emp) {
            $rows[] = [
                $emp['pin'],
                $emp['name'],
                $emp['total_assistances'],
                $emp['total_tardiness'],
                $emp['total_absences'],
                $emp['total_worked_formatted'],
            ];
        }

        $this->table($headers, array_slice($rows, 0, 35));
    }

    /**
     * Genera archivo CSV / Excel en formato Matriz estilo Imagen 2
     */
    private function generateCsvReport(Company $company, array $reportData, Carbon $startDate, Carbon $endDate, string $outputDir): string
    {
        $filename = "reporte_asistencia_{$company->id}_" . $startDate->format('Ymd') . "_" . $endDate->format('Ymd') . ".csv";
        $filepath = $outputDir . '/' . $filename;

        $fp = fopen($filepath, 'w');

        // Header UTF-8 BOM para Excel
        fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));

        // Renglón 1: Título principal
        fputcsv($fp, ['Reporte de Asistencia x Sucursal']);

        // Renglón 2 y 3: Rango de fechas
        $startDayName = $this->getSpanishDayName($startDate->dayOfWeek);
        $endDayName = $this->getSpanishDayName($endDate->dayOfWeek);
        $startMonthName = strtolower($this->getSpanishMonthName($startDate->month));
        $endMonthName = strtolower($this->getSpanishMonthName($endDate->month));

        $desdeStr = "{$startDayName} {$startDate->day} {$startMonthName} {$startDate->year}";
        $hastaStr = "{$endDayName} {$endDate->day} {$endMonthName} {$endDate->year}";

        fputcsv($fp, ['Desde', $desdeStr]);
        fputcsv($fp, ['Hasta', $hastaStr]);
        fputcsv($fp, []); // Renglón en blanco

        // Extraer la lista de días únicos del reporte
        $sampleEmp = $reportData[0] ?? null;
        $daysList = $sampleEmp ? array_values($sampleEmp['days']) : [];

        // Encabezado Renglón 5: Días del periodo
        $headerDaysRow = ['', '', '']; // Espacios para Número, Nombre, Sucursal
        foreach ($daysList as $d) {
            $dayCarbon = Carbon::parse($d['date_str']);
            $dDayName = strtolower($this->getSpanishDayName($dayCarbon->dayOfWeek));
            $dMonthName = strtolower($this->getSpanishMonthName($dayCarbon->month));
            $dayTitle = "{$dDayName} {$dayCarbon->day} {$dMonthName} {$dayCarbon->year}";

            // Se agregan 5 columnas por cada día
            $headerDaysRow[] = $dayTitle;
            $headerDaysRow[] = '';
            $headerDaysRow[] = '';
            $headerDaysRow[] = '';
            $headerDaysRow[] = '';
        }
        fputcsv($fp, $headerDaysRow);

        // Encabezado Renglón 6: Subcolumnas
        $headerSubcolsRow = ['Número', 'Nombre', 'Sucursal'];
        foreach ($daysList as $d) {
            $headerSubcolsRow[] = 'Entrada trabajo';
            $headerSubcolsRow[] = 'Retardo';
            $headerSubcolsRow[] = 'Salida a comer';
            $headerSubcolsRow[] = 'Regreso de comida';
            $headerSubcolsRow[] = 'Salida trabajo';
        }
        fputcsv($fp, $headerSubcolsRow);

        // Renglones de los empleados
        foreach ($reportData as $emp) {
            $row = [
                $emp['pin'],
                $emp['name'],
                $company->name,
            ];

            foreach ($emp['days'] as $dayDate => $day) {
                if ($day['is_sunday']) {
                    $row[] = 'DESCANSO';
                    $row[] = 'DESCANSO';
                    $row[] = 'DESCANSO';
                    $row[] = 'DESCANSO';
                    $row[] = 'DESCANSO';
                } elseif ($day['is_holiday']) {
                    $row[] = 'FESTIVO';
                    $row[] = 'FESTIVO';
                    $row[] = 'FESTIVO';
                    $row[] = 'FESTIVO';
                    $row[] = 'FESTIVO';
                } elseif ($day['total_punches'] === 0) {
                    $row[] = 'FALTA';
                    $row[] = 'FALTA';
                    $row[] = 'FALTA';
                    $row[] = 'FALTA';
                    $row[] = 'FALTA';
                } else {
                    $row[] = ($day['entrada_12h'] !== '-') ? $day['entrada_12h'] : 'FALTA';
                    $row[] = ($day['is_tardy']) ? 'Sí' : '';
                    $row[] = ($day['salida_comer_12h'] !== '-') ? $day['salida_comer_12h'] : 'FALTA';
                    $row[] = ($day['entrada_comer_12h'] !== '-') ? $day['entrada_comer_12h'] : 'FALTA';
                    $row[] = ($day['salida_12h'] !== '-') ? $day['salida_12h'] : 'FALTA';
                }
            }

            fputcsv($fp, $row);
        }

        fclose($fp);
        return $filepath;
    }

    /**
     * Genera reporte HTML de Resumen de Asistencia por Empleado para el cuerpo del correo (Imagen 1)
     */
    private function generateHtmlReport(Company $company, array $reportData, Carbon $startDate, Carbon $endDate, string $outputDir, string $scheduleEntry, int $tolerance, string $periodLabel): array
    {
        $filename = "reporte_resumen_{$company->id}_" . $startDate->format('Ymd') . "_" . $endDate->format('Ymd') . ".html";
        $filepath = $outputDir . '/' . $filename;

        $startDateStr = $startDate->format('d/m/Y');
        $endDateStr = $endDate->format('d/m/Y');

        $rowsHtml = '';
        foreach ($reportData as $emp) {
            $tardyStyle = ($emp['total_tardiness'] > 0)
                ? "style='background-color: #881337; color: #ffffff; font-weight: bold; text-align: center;'"
                : "style='text-align: center;'";

            $absenceStyle = ($emp['total_absences'] >= 5)
                ? "style='background-color: #881337; color: #ffffff; font-weight: bold; text-align: center;'"
                : (($emp['total_absences'] > 0)
                    ? "style='background-color: #ffe4e6; color: #9f1239; font-weight: bold; text-align: center;'"
                    : "style='text-align: center;'");

            $rowsHtml .= "<tr>";
            $rowsHtml .= "<td style='text-align: center; font-weight: bold; padding: 8px;'>{$emp['pin']}</td>";
            $rowsHtml .= "<td style='text-align: left; font-weight: bold; padding: 8px; color: #0f172a;'>{$emp['name']}</td>";
            $rowsHtml .= "<td style='text-align: center; padding: 8px;'>" . ($emp['total_assistances'] > 0 ? $emp['total_assistances'] : '') . "</td>";
            $rowsHtml .= "<td {$tardyStyle}>" . ($emp['total_tardiness'] > 0 ? $emp['total_tardiness'] : '') . "</td>";
            $rowsHtml .= "<td {$absenceStyle}>" . ($emp['total_absences'] > 0 ? $emp['total_absences'] : '') . "</td>";
            $rowsHtml .= "<td style='text-align: center; font-weight: bold; padding: 8px; color: #0284c7;'>{$emp['total_worked_formatted']}</td>";
            $rowsHtml .= "</tr>";
        }

        $htmlContent = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Asistencia - {$company->name}</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; background-color: #f8fafc; margin: 0; padding: 15px; color: #1e293b; }
        .container { max-width: 900px; margin: 0 auto; background: #ffffff; border-radius: 6px; border: 1px solid #cbd5e1; overflow: hidden; }
        .header { background-color: #0284c7; color: #ffffff; padding: 14px 20px; }
        .header h3 { margin: 0; font-size: 18px; text-transform: uppercase; font-weight: bold; }
        .header p { margin: 4px 0 0 0; font-size: 13px; color: #e0f2fe; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { border: 1px solid #94a3b8; padding: 8px 10px; text-align: center; }
        th.main-header { background-color: #0284c7; color: #ffffff; text-transform: uppercase; font-size: 12px; font-weight: bold; }
        th.group-header { background-color: #38bdf8; color: #0f172a; text-transform: uppercase; font-size: 12px; font-weight: bold; }
        th.sub-header { background-color: #7dd3fc; color: #0f172a; font-size: 11px; font-weight: bold; }
        td { border: 1px solid #cbd5e1; font-size: 12px; }
        tr:nth-child(even) { background-color: #f8fafc; }
        .footer { padding: 12px; font-size: 11px; color: #64748b; text-align: center; background-color: #f1f5f9; border-top: 1px solid #cbd5e1; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h3>Reporte de Asistencia x Sucursal</h3>
            <p>Empresa / Sucursal: <strong>{$company->name}</strong> | Periodo: <strong>{$periodLabel} ({$startDateStr} al {$endDateStr})</strong></p>
        </div>

        <table>
            <thead>
                <tr>
                    <th rowspan="2" class="main-header" style="width: 80px;">Número</th>
                    <th rowspan="2" class="main-header">Nombre</th>
                    <th colspan="4" class="group-header">Periodo</th>
                </tr>
                <tr>
                    <th class="sub-header" style="width: 90px;">Asistencias</th>
                    <th class="sub-header" style="width: 90px;">Retardos</th>
                    <th class="sub-header" style="width: 90px;">Faltas</th>
                    <th class="sub-header" style="width: 130px;">Horas Laboradas</th>
                </tr>
            </thead>
            <tbody>
                {$rowsHtml}
            </tbody>
        </table>

        <div class="footer">
            Generado automáticamente por ZKTeco Biometric API el {$startDate->format('Y-m-d H:i:s')}
        </div>
    </div>
</body>
</html>
HTML;

        file_put_contents($filepath, $htmlContent);

        return [$filepath, $htmlContent];
    }

    /**
     * Envía correo con el reporte adjunto o en cuerpo HTML y registra bitácora
     */
    private function sendAndLogReportEmail(string $email, Company $company, Carbon $startDate, Carbon $endDate, ?string $htmlContent, ?string $csvFile, string $periodLabel): void
    {
        $status = 'sent';
        $errorMessage = null;

        try {
            if ($htmlContent && class_exists(Mail::class)) {
                Mail::html($htmlContent, function ($message) use ($email, $company, $startDate, $endDate, $csvFile, $periodLabel) {
                    $message->to($email)
                        ->subject("Reporte Quincenal de Asistencia - {$company->name} ({$periodLabel})");

                    if ($csvFile && file_exists($csvFile)) {
                        $message->attach($csvFile);
                    }
                });
                $this->info("  ✓ Reporte enviado por correo a: {$email}");
            }
        } catch (Exception $e) {
            $status = 'failed';
            $errorMessage = $e->getMessage();
            $this->warn("  ⚠ Falló el envío a {$email}: {$errorMessage}");
            Log::warning("Failed to send quincenal attendance email to {$email}", ['error' => $errorMessage]);
        }

        // Registrar en la tabla attendance_report_logs
        try {
            \App\Models\AttendanceReportLog::create([
                'company_id' => $company->id,
                'intercompania' => $company->intercompania ?? $company->code,
                'report_type' => 'quincenal',
                'period_label' => $periodLabel,
                'recipient_email' => $email,
                'status' => $status,
                'error_message' => $errorMessage,
                'sent_at' => ($status === 'sent') ? Carbon::now() : null,
            ]);
        } catch (Exception $e) {
            Log::error("Error saving attendance report log: " . $e->getMessage());
        }
    }

    /**
     * Traduce el número de día de la semana a español
     */
    private function getSpanishDayName(int $dayOfWeek): string
    {
        $days = [
            0 => 'Domingo',
            1 => 'Lunes',
            2 => 'Martes',
            3 => 'Miércoles',
            4 => 'Jueves',
            5 => 'Viernes',
            6 => 'Sábado',
        ];
        return $days[$dayOfWeek] ?? 'Día';
    }

    /**
     * Traduce el número de mes a español
     */
    private function getSpanishMonthName(int $month): string
    {
        $months = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
        ];
        return $months[$month] ?? 'Mes';
    }
}
