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
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Ejecutar reporte semanal automáticamente cada Lunes a las 08:00 AM
        $schedule->command('attendance:weekly-report --week=previous --format=all')->weeklyOn(1, '08:00');

        // Ejecutar reporte quincenal por las noches a las 23:59 PM (acumulado al día actual)
        $schedule->command('attendance:quincenal-report --period=current_quincena --format=all')->dailyAt('23:59');
    }
}
