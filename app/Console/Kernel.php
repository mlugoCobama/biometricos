<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Laravel\Lumen\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        Commands\SyncSoporteZmEmpresasCommand::class,
        Commands\RouteListCommand::class,
        Commands\SendWeeklyAttendanceReportCommand::class,
        Commands\SendQuincenalAttendanceReportCommand::class,
        Commands\CheckOfflineDevicesCommand::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Verificación diaria de biométricos fuera de línea por más de 2 días a las 08:30 AM
        $schedule->command('devices:check-offline-alert --days=2')->dailyAt('08:30');

        // Ejecutar reporte semanal automáticamente cada Lunes a las 08:00 AM
        $schedule->command('attendance:weekly-report --week=previous --format=all')->weeklyOn(1, '08:00');

        // Ejecutar reporte quincenal por las noches a las 23:59 PM (acumulado al día actual)
        $schedule->command('attendance:quincenal-report --period=current_quincena --format=all')->dailyAt('23:59');
    }
}
