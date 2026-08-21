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

class SendWeeklyAttendanceReportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance:weekly-report
                            {--company_id= : ID de la empresa a filtrar}
                            {--intercompania= : Código de intercompañía a filtrar}
                            {--email= : Correo electrónico destino}
                            {--week=previous : Semana a generar (previous, current, o YYYY-MM-DD)}
                            {--format=all : Formato de salida (table, html, csv, all)}
                            {--output_dir= : Directorio personalizado para guardar reportes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Genera y envía reporte semanal de asistencia por empresa contemplando 4 marcaciones diarias (Entrada, Salida Comer, Entrada Comer, Salida).';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $this->info('===========================================================');
        $this->info('      REPORTE SEMANAL DE ASISTENCIA (4 MARCACIONES)        ');
        $this->info('===========================================================');

        try {
            // 1. Determinar rango de fechas de la semana (Lunes a Domingo)
            [$startDate, $endDate, $weekLabel] = $this->resolveDateRange();
            $this->info("Periodo evaluado: {$startDate->format('Y-m-d')} al {$endDate->format('Y-m-d')} ({$weekLabel})");

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

                // Estructurar reporte semanal para cada empleado
                $reportData = $this->buildCompanyWeeklyReport($company, $employees, $startDate, $endDate);

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
                    [$htmlFile, $htmlContent] = $this->generateHtmlReport($company, $reportData, $startDate, $endDate, $outputDir);
                    $this->info("  ✓ Archivo HTML generado: {$htmlFile}");
                }

                // Enviar correo si está configurado
                if ($email) {
                    $this->sendReportEmail($email, $company, $startDate, $endDate, $htmlContent, $csvFile);
                    $this->info("  ✓ Reporte enviado por correo a: {$email}");
                }
            }

            $this->info("\n===========================================================");
            $this->info('✓ Proceso de generación de reporte finalizado con éxito.');
            $this->info('===========================================================');

            return Command::SUCCESS;
        } catch (Exception $e) {
            $this->error('✘ Error al generar reporte de asistencia: ' . $e->getMessage());
            Log::error('Attendance report command error', ['exception' => $e]);
            return Command::FAILURE;
        }
    }

    /**
     * Resuelve el rango de fechas (Lunes a Domingo) según opción --week
     */
    private function resolveDateRange(): array
    {
        $weekOpt = $this->option('week') ?: 'previous';

        if ($weekOpt === 'current') {
            $startDate = Carbon::now()->startOfWeek(Carbon::MONDAY);
            $endDate = Carbon::now()->endOfWeek(Carbon::SUNDAY);
            $label = "Semana Actual";
        } elseif ($weekOpt === 'previous') {
            $startDate = Carbon::now()->subWeek()->startOfWeek(Carbon::MONDAY);
            $endDate = Carbon::now()->subWeek()->endOfWeek(Carbon::SUNDAY);
            $label = "Semana Anterior";
        } else {
            // Asume fecha dada YYYY-MM-DD
            $date = Carbon::parse($weekOpt);
            $startDate = $date->copy()->startOfWeek(Carbon::MONDAY);
            $endDate = $date->copy()->endOfWeek(Carbon::SUNDAY);
            $label = "Semana del {$startDate->format('d/m/Y')}";
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
     * Construye los datos del reporte semanal agrupado por empleado y por día (Lunes a Domingo)
     * contemplando las 4 marcaciones diarias: Entrada, Salida Comer, Entrada Comer, Salida.
     */
    private function buildCompanyWeeklyReport(Company $company, $employees, Carbon $startDate, Carbon $endDate): array
    {
        $report = [];

        // Generar lista de los 7 días de la semana
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
                'days' => []
            ];

            foreach ($days as $day) {
                $dayDate = $day['date_str'];

                // Consultar marcaciones del empleado en ese día ordenadas cronológicamente
                $logs = AttendanceLog::query()
                    ->where('pin', $emp->pin)
                    ->whereDate('punch_time', $dayDate)
                    ->orderBy('punch_time', 'asc')
                    ->get();

                $punches = $this->map4DailyPunches($logs);

                $empReport['days'][$dayDate] = array_merge($day, $punches);
            }

            $report[] = $empReport;
        }

        return $report;
    }

    /**
     * Mapea inteligentemente las marcaciones del día a 4 columnas:
     * 1. Entrada
     * 2. Salida Comer
     * 3. Entrada Comer
     * 4. Salida
     */
    private function map4DailyPunches($logs): array
    {
        $result = [
            'entrada' => '-',
            'salida_comer' => '-',
            'entrada_comer' => '-',
            'salida' => '-',
            'total_punches' => count($logs)
        ];

        if ($logs->isEmpty()) {
            return $result;
        }

        // Si los registros traen punch_type explícito (0: Entrada, 2: Salida Comer, 3: Entrada Comer, 1: Salida)
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
                        $result['salida'] = $time; // última salida
                        break;
                }
            }
        } else {
            // Mapeo cronológico por posición (1ra=Entrada, 2da=Salida Comer, 3ra=Entrada Comer, 4ta=Salida)
            $times = $logs->pluck('punch_time')->map(fn($t) => $t->format('H:i:s'))->toArray();

            if (isset($times[0])) $result['entrada'] = $times[0];
            if (isset($times[1])) $result['salida_comer'] = $times[1];
            if (isset($times[2])) $result['entrada_comer'] = $times[2];
            if (isset($times[3])) $result['salida'] = $times[3];

            // Si hay más de 4 marcaciones, la última se asigna como salida
            if (count($times) > 4) {
                $result['salida'] = end($times);
            }
        }

        return $result;
    }

    /**
     * Muestra reporte en formato tabla de consola
     */
    private function displayConsoleTable(Company $company, array $reportData): void
    {
        $headers = ['PIN', 'Empleado', 'Día', 'Entrada', 'Salida Comer', 'Entrada Comer', 'Salida'];
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
                ];
            }
        }

        $this->table($headers, array_slice($rows, 0, 35)); // muestra vista previa en consola
    }

    /**
     * Genera archivo CSV
     */
    private function generateCsvReport(Company $company, array $reportData, Carbon $startDate, Carbon $endDate, string $outputDir): string
    {
        $filename = "reporte_asistencia_{$company->id}_" . $startDate->format('Ymd') . "_" . $endDate->format('Ymd') . ".csv";
        $filepath = $outputDir . '/' . $filename;

        $fp = fopen($filepath, 'w');

        // Header UTF-8 BOM para Excel
        fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));

        fputcsv($fp, ['Empresa:', $company->name, 'Periodo:', $startDate->format('Y-m-d') . ' al ' . $endDate->format('Y-m-d')]);
        fputcsv($fp, []);
        fputcsv($fp, ['PIN', 'Empleado', 'Tarjeta', 'Fecha', 'Día', '1. Entrada', '2. Salida Comer', '3. Entrada Comer', '4. Salida', 'Total Marcaciones']);

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
                    $day['total_punches'],
                ]);
            }
        }

        fclose($fp);
        return $filepath;
    }

    /**
     * Genera reporte HTML estilizado
     */
    private function generateHtmlReport(Company $company, array $reportData, Carbon $startDate, Carbon $endDate, string $outputDir): array
    {
        $filename = "reporte_asistencia_{$company->id}_" . $startDate->format('Ymd') . "_" . $endDate->format('Ymd') . ".html";
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
                    $rowsHtml .= "<td rowspan='{$dayCount}' class='emp-name'><strong>{$emp['name']}</strong><br><small>Tarj: {$emp['card']}</small></td>";
                    $firstDay = false;
                }

                $badgeClass = ($day['total_punches'] >= 4) ? 'badge-success' : (($day['total_punches'] > 0) ? 'badge-warning' : 'badge-gray');

                $rowsHtml .= "<td>{$day['day_name']} <small>({$day['date_str']})</small></td>";
                $rowsHtml .= "<td class='time-cell'>" . ($day['entrada'] !== '-' ? "<span class='punch in'>{$day['entrada']}</span>" : '-') . "</td>";
                $rowsHtml .= "<td class='time-cell'>" . ($day['salida_comer'] !== '-' ? "<span class='punch out-break'>{$day['salida_comer']}</span>" : '-') . "</td>";
                $rowsHtml .= "<td class='time-cell'>" . ($day['entrada_comer'] !== '-' ? "<span class='punch in-break'>{$day['entrada_comer']}</span>" : '-') . "</td>";
                $rowsHtml .= "<td class='time-cell'>" . ($day['salida'] !== '-' ? "<span class='punch out'>{$day['salida']}</span>" : '-') . "</td>";
                $rowsHtml .= "<td class='text-center'><span class='badge {$badgeClass}'>{$day['total_punches']}</span></td>";
                $rowsHtml .= "</tr>";
            }
        }

        $htmlContent = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte Semanal de Asistencia - {$company->name}</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f6f9; margin: 0; padding: 20px; color: #333; }
        .container { max-width: 1100px; margin: 0 auto; background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .header { border-bottom: 2px solid #2563eb; padding-bottom: 15px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .header h2 { margin: 0; color: #1e3a8a; }
        .header p { margin: 5px 0 0 0; color: #64748b; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 13px; }
        th, td { padding: 10px 12px; border: 1px solid #e2e8f0; text-align: left; }
        th { background-color: #1e293b; color: #fff; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; }
        tr:nth-child(even) { background-color: #f8fafc; }
        .emp-pin { background-color: #f1f5f9; text-align: center; }
        .emp-name { background-color: #f8fafc; }
        .time-cell { text-align: center; }
        .punch { padding: 3px 6px; border-radius: 4px; font-family: monospace; font-weight: bold; font-size: 12px; }
        .punch.in { background-color: #dcfce7; color: #15803d; }
        .punch.out-break { background-color: #fef3c7; color: #b45309; }
        .punch.in-break { background-color: #e0f2fe; color: #0369a1; }
        .punch.out { background-color: #fee2e2; color: #b91c1c; }
        .badge { padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; }
        .badge-success { background-color: #22c55e; color: #fff; }
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
                <h2>Reporte Semanal de Asistencia (4 Marcaciones)</h2>
                <p>Empresa: <strong>{$company->name}</strong> | Periodo: <strong>{$startDateStr} al {$endDateStr}</strong></p>
            </div>
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
    private function sendReportEmail(string $email, Company $company, Carbon $startDate, Carbon $endDate, ?string $htmlContent, ?string $csvFile): void
    {
        try {
            if ($htmlContent && class_exists(Mail::class)) {
                Mail::html($htmlContent, function ($message) use ($email, $company, $startDate, $endDate, $csvFile) {
                    $message->to($email)
                        ->subject("Reporte Semanal de Asistencia - {$company->name} ({$startDate->format('d/m/Y')} - {$endDate->format('d/m/Y')})");

                    if ($csvFile && file_exists($csvFile)) {
                        $message->attach($csvFile);
                    }
                });
            }
        } catch (Exception $e) {
            $this->warn("  ⚠ No se pudo enviar el correo a {$email}: " . $e->getMessage());
            Log::warning("Failed to send weekly attendance email to {$email}", ['error' => $e->getMessage()]);
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
}
