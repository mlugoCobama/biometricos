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

                // Enviar correo si está configurado
                if ($email) {
                    $this->sendReportEmail($email, $company, $startDate, $endDate, $htmlContent, $csvFile, $periodLabel);
                    $this->info("  ✓ Reporte enviado por correo a: {$email}");
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
            $empReport = [
                'employee_id' => $emp->id,
                'pin' => $emp->pin,
                'name' => $emp->full_name,
                'card' => $emp->card_number ?? '-',
                'days' => [],
                'total_worked_seconds' => 0,
                'total_tardiness_count' => 0,
            ];

            foreach ($days as $day) {
                $dayDate = $day['date_str'];

                // Consultar marcaciones del empleado en ese día ordenadas cronológicamente
                $logs = AttendanceLog::query()
                    ->where('pin', $emp->pin)
                    ->whereDate('punch_time', $dayDate)
                    ->orderBy('punch_time', 'asc')
                    ->get();

                $punches = $this->map4DailyPunches($logs, $dayDate, $scheduleEntry, $tolerance);

                $empReport['total_worked_seconds'] += $punches['worked_seconds'];
                if ($punches['is_tardy']) {
                    $empReport['total_tardiness_count']++;
                }

                $empReport['days'][$dayDate] = array_merge($day, $punches);
            }

            $empReport['total_worked_formatted'] = $this->formatWorkedTime($empReport['total_worked_seconds']);
            $report[] = $empReport;
        }

        return $report;
    }

    /**
     * Mapea inteligentemente las marcaciones del día y calcula el tiempo laborado sin comida y retardo
     */
    private function map4DailyPunches($logs, string $dayDate, string $scheduleEntry, int $tolerance): array
    {
        $result = [
            'entrada' => '-',
            'salida_comer' => '-',
            'entrada_comer' => '-',
            'salida' => '-',
            'total_punches' => count($logs),
            'worked_time' => '-',
            'worked_seconds' => 0,
            'tardiness_status' => 'Sin Registro',
            'is_tardy' => false,
            'delay_minutes' => 0,
        ];

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

        // 2. Calcular Retardo
        if ($result['entrada'] !== '-') {
            $entryTime = Carbon::parse("{$dayDate} {$result['entrada']}");
            $officialEntry = Carbon::parse("{$dayDate} {$scheduleEntry}:00");
            $maxAllowed = $officialEntry->copy()->addMinutes($tolerance);

            if ($entryTime->gt($maxAllowed)) {
                $delayMinutes = $officialEntry->diffInMinutes($entryTime);
                $result['is_tardy'] = true;
                $result['delay_minutes'] = $delayMinutes;
                $result['tardiness_status'] = "Retardo ({$delayMinutes} min)";
            } else {
                $result['tardiness_status'] = "A Tiempo";
            }
        }

        // 3. Calcular Tiempo Laborado (Excluyendo comida)
        $workedSeconds = 0;

        if ($result['entrada'] !== '-' && $result['salida_comer'] !== '-' && $result['entrada_comer'] !== '-' && $result['salida'] !== '-') {
            // Caso completo (4 marcaciones)
            $morningSeconds = Carbon::parse("{$dayDate} {$result['entrada']}")->diffInSeconds(Carbon::parse("{$dayDate} {$result['salida_comer']}"));
            $afternoonSeconds = Carbon::parse("{$dayDate} {$result['entrada_comer']}")->diffInSeconds(Carbon::parse("{$dayDate} {$result['salida']}"));
            $workedSeconds = max(0, $morningSeconds + $afternoonSeconds);
        } elseif ($result['entrada'] !== '-' && $result['salida'] !== '-') {
            // Caso parcial (Sólo Entrada y Salida final)
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
        $headers = ['PIN', 'Empleado', 'Día', 'Entrada', 'Salida Comer', 'Entrada Comer', 'Salida', 'Tiempo Laborado', 'Estatus Retardo'];
        $rows = [];

        foreach ($reportData as $emp) {
            foreach ($emp['days'] as $day) {
                $rows[] = [
                    $emp['pin'],
                    $emp['name'],
                    $day['formatted'],
                    $day['entrada'],
                    $day['salida_comer'],
                    $day['entrada_comer'],
                    $day['salida'],
                    $day['worked_time'],
                    $day['tardiness_status'],
                ];
            }
        }

        $this->table($headers, array_slice($rows, 0, 35));
    }

    /**
     * Genera archivo CSV
     */
    private function generateCsvReport(Company $company, array $reportData, Carbon $startDate, Carbon $endDate, string $outputDir): string
    {
        $filename = "reporte_quincenal_{$company->id}_" . $startDate->format('Ymd') . "_" . $endDate->format('Ymd') . ".csv";
        $filepath = $outputDir . '/' . $filename;

        $fp = fopen($filepath, 'w');

        // Header UTF-8 BOM para Excel
        fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));

        fputcsv($fp, ['Empresa:', $company->name, 'Periodo Quincenal:', $startDate->format('Y-m-d') . ' al ' . $endDate->format('Y-m-d')]);
        fputcsv($fp, []);
        fputcsv($fp, ['PIN', 'Empleado', 'Tarjeta', 'Fecha', 'Día', '1. Entrada', '2. Salida Comer', '3. Entrada Comer', '4. Salida', 'Tiempo Laborado (Sin Comida)', 'Estatus Retardo', 'Total Marcaciones']);

        foreach ($reportData as $emp) {
            foreach ($emp['days'] as $day) {
                fputcsv($fp, [
                    $emp['pin'],
                    $emp['name'],
                    $emp['card'],
                    $day['date_str'],
                    $day['day_name'],
                    $day['entrada'],
                    $day['salida_comer'],
                    $day['entrada_comer'],
                    $day['salida'],
                    $day['worked_time'],
                    $day['tardiness_status'],
                    $day['total_punches'],
                ]);
            }
            fputcsv($fp, ['RESUMEN QUINCENAL', $emp['name'], 'Total Horas:', $emp['total_worked_formatted'], 'Total Retardos:', $emp['total_tardiness_count']]);
            fputcsv($fp, []);
        }

        fclose($fp);
        return $filepath;
    }

    /**
     * Genera reporte HTML estilizado (Propuesta 1)
     */
    private function generateHtmlReport(Company $company, array $reportData, Carbon $startDate, Carbon $endDate, string $outputDir, string $scheduleEntry, int $tolerance, string $periodLabel): array
    {
        $filename = "reporte_quincenal_{$company->id}_" . $startDate->format('Ymd') . "_" . $endDate->format('Ymd') . ".html";
        $filepath = $outputDir . '/' . $filename;

        $startDateStr = $startDate->format('d/m/Y');
        $endDateStr = $endDate->format('d/m/Y');

        $rowsHtml = '';
        foreach ($reportData as $emp) {
            $firstDay = true;
            $dayCount = count($emp['days']);

            foreach ($emp['days'] as $day) {
                $rowsHtml .= "<tr>";
                if ($firstDay) {
                    $rowsHtml .= "<td rowspan='{$dayCount}' class='emp-pin'><strong>{$emp['pin']}</strong></td>";
                    $rowsHtml .= "<td rowspan='{$dayCount}' class='emp-name'>
                                    <strong>{$emp['name']}</strong><br>
                                    <small>Tarj: {$emp['card']}</small><br>
                                    <div class='emp-summary'>
                                        <span class='summary-badge'><strong>{$emp['total_worked_formatted']}</strong> lab.</span>
                                        <span class='summary-badge " . ($emp['total_tardiness_count'] > 0 ? 'badge-danger' : 'badge-success') . "'>{$emp['total_tardiness_count']} retardos</span>
                                    </div>
                                  </td>";
                    $firstDay = false;
                }

                $badgeClass = ($day['total_punches'] >= 4) ? 'badge-success' : (($day['total_punches'] > 0) ? 'badge-warning' : 'badge-gray');

                $tardinessBadge = '-';
                if ($day['tardiness_status'] === 'A Tiempo') {
                    $tardinessBadge = "<span class='badge badge-success'>A Tiempo</span>";
                } elseif ($day['is_tardy']) {
                    $tardinessBadge = "<span class='badge badge-danger'>{$day['tardiness_status']}</span>";
                }

                $rowsHtml .= "<td>{$day['day_name']} <small>({$day['date_str']})</small></td>";
                $rowsHtml .= "<td class='time-cell'>" . ($day['entrada'] !== '-' ? "<span class='punch in'>{$day['entrada']}</span>" : '-') . "</td>";
                $rowsHtml .= "<td class='time-cell'>" . ($day['salida_comer'] !== '-' ? "<span class='punch out-break'>{$day['salida_comer']}</span>" : '-') . "</td>";
                $rowsHtml .= "<td class='time-cell'>" . ($day['entrada_comer'] !== '-' ? "<span class='punch in-break'>{$day['entrada_comer']}</span>" : '-') . "</td>";
                $rowsHtml .= "<td class='time-cell'>" . ($day['salida'] !== '-' ? "<span class='punch out'>{$day['salida']}</span>" : '-') . "</td>";
                $rowsHtml .= "<td class='time-cell'><strong>{$day['worked_time']}</strong></td>";
                $rowsHtml .= "<td class='text-center'>{$tardinessBadge}</td>";
                $rowsHtml .= "<td class='text-center'><span class='badge {$badgeClass}'>{$day['total_punches']}</span></td>";
                $rowsHtml .= "</tr>";
            }
        }

        $htmlContent = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte Quincenal de Asistencia - {$company->name}</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f6f9; margin: 0; padding: 20px; color: #333; }
        .container { max-width: 1250px; margin: 0 auto; background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .header { border-bottom: 2px solid #2563eb; padding-bottom: 15px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .header h2 { margin: 0; color: #1e3a8a; }
        .header p { margin: 5px 0 0 0; color: #64748b; font-size: 14px; }
        .config-info { font-size: 12px; background: #eff6ff; padding: 8px 12px; border-radius: 6px; border-left: 4px solid #2563eb; color: #1e40af; margin-bottom: 15px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 13px; }
        th, td { padding: 10px 12px; border: 1px solid #e2e8f0; text-align: left; }
        th { background-color: #1e293b; color: #fff; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; }
        tr:nth-child(even) { background-color: #f8fafc; }
        .emp-pin { background-color: #f1f5f9; text-align: center; }
        .emp-name { background-color: #f8fafc; }
        .emp-summary { margin-top: 6px; }
        .summary-badge { font-size: 10px; padding: 2px 6px; border-radius: 4px; background: #e2e8f0; color: #334155; margin-right: 4px; display: inline-block; }
        .time-cell { text-align: center; }
        .punch { padding: 3px 6px; border-radius: 4px; font-family: monospace; font-weight: bold; font-size: 12px; }
        .punch.in { background-color: #dcfce7; color: #15803d; }
        .punch.out-break { background-color: #fef3c7; color: #b45309; }
        .punch.in-break { background-color: #e0f2fe; color: #0369a1; }
        .punch.out { background-color: #fee2e2; color: #b91c1c; }
        .badge { padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; }
        .badge-success { background-color: #22c55e; color: #fff; }
        .badge-danger { background-color: #ef4444; color: #fff; }
        .badge-warning { background-color: #f59e0b; color: #fff; }
        .badge-gray { background-color: #94a3b8; color: #fff; }
        .text-center { text-align: center; }
        .footer { margin-top: 25px; text-align: center; font-size: 12px; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h2>Reporte Quincenal de Asistencia</h2>
                <p>Empresa: <strong>{$company->name}</strong> | Periodo: <strong>{$periodLabel} ({$startDateStr} al {$endDateStr})</strong></p>
            </div>
        </div>

        <div class="config-info">
            ⏱ <strong>Criterios de Evaluación Quincenal:</strong> Hora Oficial de Entrada: <strong>{$scheduleEntry} AM</strong> | Tolerancia de Retardo: <strong>{$tolerance} minutos</strong> (Tolerante hasta {$scheduleEntry} + {$tolerance}m) | Tiempo Laborado excluye lapso de comida.
        </div>

        <table>
            <thead>
                <tr>
                    <th>PIN</th>
                    <th>Empleado</th>
                    <th>Día / Fecha</th>
                    <th>1. Entrada</th>
                    <th>2. Salida Comer</th>
                    <th>3. Entrada Comer</th>
                    <th>4. Salida</th>
                    <th>Tiempo Laborado</th>
                    <th>Estatus Retardo</th>
                    <th>Marcaciones</th>
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
     * Envía correo con el reporte adjunto o en cuerpo HTML
     */
    private function sendReportEmail(string $email, Company $company, Carbon $startDate, Carbon $endDate, ?string $htmlContent, ?string $csvFile, string $periodLabel): void
    {
        try {
            if ($htmlContent && class_exists(Mail::class)) {
                Mail::html($htmlContent, function ($message) use ($email, $company, $startDate, $endDate, $csvFile, $periodLabel) {
                    $message->to($email)
                        ->subject("Reporte Quincenal de Asistencia - {$company->name} ({$periodLabel})");

                    if ($csvFile && file_exists($csvFile)) {
                        $message->attach($csvFile);
                    }
                });
            }
        } catch (Exception $e) {
            $this->warn("  ⚠ No se pudo enviar el correo a {$email}: " . $e->getMessage());
            Log::warning("Failed to send quincenal attendance email to {$email}", ['error' => $e->getMessage()]);
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
