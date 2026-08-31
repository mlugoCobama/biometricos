<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\CompanyDepartmentSchedule;
use App\Models\CompanyHoliday;
use App\Models\Employee;
use Carbon\Carbon;

class AttendanceReportService
{
    /**
     * Genera reporte diario de asistencia por empresa
     */
    public function generateDailyReport(Company $company, Carbon $date, string $scheduleEntry = '09:00', int $tolerance = 15): array
    {
        $dateStr = $date->format('Y-m-d');
        $isToday = $date->isToday();
        $now = Carbon::now();

        // 1. Cargar festivos y horarios de departamento
        $holidays = $this->loadHolidays($company, $date, $date);
        $deptSchedules = $this->loadDeptSchedules($company);

        $isHoliday = isset($holidays[$dateStr]);
        $holidayObj = $isHoliday ? $holidays[$dateStr] : null;
        $isSunday = ($date->dayOfWeek === 0);

        // 2. Obtener empleados de la empresa
        $employees = $this->getCompanyEmployees($company);

        $reportEmployees = [];
        $totalPresent = 0;
        $totalTardy = 0;
        $totalAbsent = 0;

        foreach ($employees as $emp) {
            // Resolver horario por departamento
            [$empScheduleEntry, $empTolerance] = $this->resolveEmployeeSchedule($emp, $deptSchedules, $scheduleEntry, $tolerance);

            // Consultar marcaciones del día
            $logs = AttendanceLog::query()
                ->where('pin', $emp->pin)
                ->whereDate('punch_time', $dateStr)
                ->orderBy('punch_time', 'asc')
                ->get();

            $punches = $this->map4DailyPunches($logs, $dateStr, $empScheduleEntry, $empTolerance, $holidayObj, $isSunday, $isToday, $now);

            if ($punches['total_punches'] > 0) {
                $totalPresent++;
            } elseif (!$isSunday && !$isHoliday) {
                $totalAbsent++;
            }

            if ($punches['is_tardy']) {
                $totalTardy++;
            }

            $reportEmployees[] = [
                'employee_id' => $emp->id,
                'pin' => $emp->pin,
                'name' => $emp->full_name,
                'card' => $emp->card_number ?? '-',
                'department' => $emp->department ?? 'General',
                'schedule_entry' => $empScheduleEntry,
                'tolerance_minutes' => $empTolerance,
                'date' => $dateStr,
                'day_name' => $this->getSpanishDayName($date->dayOfWeek),
                'punches' => $punches,
            ];
        }

        return [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'code' => $company->code,
                'intercompania' => $company->intercompania,
            ],
            'report_type' => 'daily',
            'date' => $dateStr,
            'is_today' => $isToday,
            'evaluated_at' => $now->format('Y-m-d H:i:s'),
            'summary' => [
                'total_employees' => count($employees),
                'total_present' => $totalPresent,
                'total_tardy' => $totalTardy,
                'total_absent' => $totalAbsent,
            ],
            'employees' => $reportEmployees,
        ];
    }

    /**
     * Genera reporte quincenal o mensual de asistencia por empresa
     */
    public function generatePeriodReport(Company $company, Carbon $startDate, Carbon $endDate, string $scheduleEntry = '09:00', int $tolerance = 15, string $reportType = 'quincenal', string $periodLabel = ''): array
    {
        // 1. Cargar festivos y horarios de departamento
        $holidays = $this->loadHolidays($company, $startDate, $endDate);
        $deptSchedules = $this->loadDeptSchedules($company);

        // Generar lista de días en el rango
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

        $employees = $this->getCompanyEmployees($company);

        $reportEmployees = [];

        foreach ($employees as $emp) {
            [$empScheduleEntry, $empTolerance] = $this->resolveEmployeeSchedule($emp, $deptSchedules, $scheduleEntry, $tolerance);

            $totalAssistances = 0;
            $totalTardiness = 0;
            $totalAbsences = 0;
            $totalWorkedSeconds = 0;
            $empDays = [];

            foreach ($days as $day) {
                $dayDate = $day['date_str'];
                $isHoliday = isset($holidays[$dayDate]);
                $holidayObj = $isHoliday ? $holidays[$dayDate] : null;
                $dayCarbon = Carbon::parse($dayDate);
                $isSunday = ($dayCarbon->dayOfWeek === 0);
                $isToday = $dayCarbon->isToday();
                $now = Carbon::now();

                $logs = AttendanceLog::query()
                    ->where('pin', $emp->pin)
                    ->whereDate('punch_time', $dayDate)
                    ->orderBy('punch_time', 'asc')
                    ->get();

                $punches = $this->map4DailyPunches($logs, $dayDate, $empScheduleEntry, $empTolerance, $holidayObj, $isSunday, $isToday, $now);

                $totalWorkedSeconds += $punches['worked_seconds'];

                if ($punches['total_punches'] > 0) {
                    $totalAssistances++;
                } elseif (!$isSunday && !$isHoliday) {
                    $totalAbsences++;
                }

                if ($punches['is_tardy']) {
                    $totalTardiness++;
                }

                $empDays[$dayDate] = array_merge($day, $punches, [
                    'day_of_week' => $dayCarbon->dayOfWeek,
                    'is_sunday' => $isSunday,
                ]);
            }

            $reportEmployees[] = [
                'employee_id' => $emp->id,
                'pin' => $emp->pin,
                'name' => $emp->full_name,
                'card' => $emp->card_number ?? '-',
                'department' => $emp->department ?? 'General',
                'schedule_entry' => $empScheduleEntry,
                'tolerance_minutes' => $empTolerance,
                'total_assistances' => $totalAssistances,
                'total_tardiness' => $totalTardiness,
                'total_absences' => $totalAbsences,
                'total_worked_seconds' => $totalWorkedSeconds,
                'total_worked_formatted' => $this->formatWorkedTime($totalWorkedSeconds),
                'days' => $empDays,
            ];
        }

        return [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'code' => $company->code,
                'intercompania' => $company->intercompania,
            ],
            'report_type' => $reportType,
            'period_label' => $periodLabel,
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'total_employees' => count($employees),
            'employees' => $reportEmployees,
        ];
    }

    /**
     * Mapea las 4 marcaciones del día y calcula retardos y tiempo laborado al momento
     */
    public function map4DailyPunches($logs, string $dayDate, string $scheduleEntry, int $tolerance, ?CompanyHoliday $holiday = null, bool $isSunday = false, bool $isToday = false, ?Carbon $now = null): array
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
            'is_currently_working' => false,
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

            if (count($times) === 2) {
                // Si sólo existen 2 marcaciones en el día: 1ra = Entrada, 2da = Salida
                $result['entrada'] = $times[0];
                $result['salida'] = $times[1];
            } else {
                if (isset($times[0])) $result['entrada'] = $times[0];
                if (isset($times[1])) $result['salida_comer'] = $times[1];
                if (isset($times[2])) $result['entrada_comer'] = $times[2];
                if (isset($times[3])) $result['salida'] = $times[3];
                if (count($times) > 4) $result['salida'] = end($times);
            }
        }

        // Formato 12 Horas
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

        // 3. Calcular Tiempo Laborado (Excluyendo comida y considerando el tiempo al momento si es HOY)
        $workedSeconds = 0;
        $nowTime = $now ?? Carbon::now();

        if ($result['entrada'] !== '-') {
            $tEntrada = Carbon::parse("{$dayDate} {$result['entrada']}");
            $tSalidaComer = ($result['salida_comer'] !== '-') ? Carbon::parse("{$dayDate} {$result['salida_comer']}") : null;
            $tEntradaComer = ($result['entrada_comer'] !== '-') ? Carbon::parse("{$dayDate} {$result['entrada_comer']}") : null;
            $tSalida = ($result['salida'] !== '-') ? Carbon::parse("{$dayDate} {$result['salida']}") : null;

            if ($tSalidaComer && $tEntradaComer && $tSalida) {
                // Caso completo
                $mSecs = $tEntrada->diffInSeconds($tSalidaComer);
                $aSecs = $tEntradaComer->diffInSeconds($tSalida);
                $workedSeconds = max(0, $mSecs + $aSecs);
            } elseif ($tSalidaComer && $tEntradaComer && $isToday) {
                // En curso por la tarde
                $mSecs = $tEntrada->diffInSeconds($tSalidaComer);
                $aSecs = $tEntradaComer->diffInSeconds($nowTime);
                $workedSeconds = max(0, $mSecs + $aSecs);
                $result['is_currently_working'] = true;
            } elseif ($tSalidaComer) {
                // Salió a comer
                $workedSeconds = max(0, $tEntrada->diffInSeconds($tSalidaComer));
            } elseif ($tSalida) {
                // Entrada y Salida final directa
                $workedSeconds = max(0, $tEntrada->diffInSeconds($tSalida));
            } elseif ($isToday) {
                // En curso por la mañana (al momento de la consulta)
                $workedSeconds = max(0, $tEntrada->diffInSeconds($nowTime));
                $result['is_currently_working'] = true;
            }
        }

        $result['worked_seconds'] = $workedSeconds;
        $result['worked_time'] = ($workedSeconds > 0) ? $this->formatWorkedTime($workedSeconds) : '-';

        return $result;
    }

    /**
     * Carga días festivos
     */
    private function loadHolidays(Company $company, Carbon $startDate, Carbon $endDate): array
    {
        return CompanyHoliday::query()
            ->where(function ($q) use ($company) {
                $q->whereNull('company_id')
                  ->orWhere('company_id', $company->id);
            })
            ->whereBetween('holiday_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->get()
            ->keyBy(fn($h) => $h->holiday_date->format('Y-m-d'))
            ->all();
    }

    /**
     * Carga horarios por departamento
     */
    private function loadDeptSchedules(Company $company): array
    {
        return CompanyDepartmentSchedule::query()
            ->where(function ($q) use ($company) {
                $q->whereNull('company_id')
                  ->orWhere('company_id', $company->id);
            })
            ->where('is_active', true)
            ->orderBy('company_id', 'desc')
            ->get()
            ->keyBy(fn($s) => strtolower(trim($s->department_name)))
            ->all();
    }

    /**
     * Obtiene los empleados de la empresa
     */
    private function getCompanyEmployees(Company $company)
    {
        return Employee::query()
            ->where(function ($q) use ($company) {
                $q->where('company_id', $company->id);
                if (!empty($company->intercompania)) {
                    $q->orWhere('intercompania', $company->intercompania);
                }
            })
            ->orderBy('pin', 'asc')
            ->get();
    }

    /**
     * Resuelve el horario oficial y tolerancia del empleado
     */
    private function resolveEmployeeSchedule(Employee $emp, array $deptSchedules, string $defaultScheduleEntry, int $defaultTolerance): array
    {
        $empDeptKey = strtolower(trim($emp->department ?? ''));
        if ($empDeptKey && isset($deptSchedules[$empDeptKey])) {
            $config = $deptSchedules[$empDeptKey];
            return [substr($config->schedule_entry, 0, 5), (int)$config->tolerance_minutes];
        }
        return [$defaultScheduleEntry, $defaultTolerance];
    }

    /**
     * Formatea segundos a HH:MM hrs
     */
    public function formatWorkedTime(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        return sprintf('%02d:%02d hrs', $hours, $minutes);
    }

    /**
     * Obtiene nombre del día en español
     */
    public function getSpanishDayName(int $dayOfWeek): string
    {
        $days = [0 => 'Domingo', 1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado'];
        return $days[$dayOfWeek] ?? 'Día';
    }

    /**
     * Obtiene nombre del mes en español
     */
    public function getSpanishMonthName(int $month): string
    {
        $months = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
            7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
        ];
        return $months[$month] ?? 'Mes';
    }
}
