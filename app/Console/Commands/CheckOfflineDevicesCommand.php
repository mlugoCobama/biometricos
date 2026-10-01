<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Device;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CheckOfflineDevicesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'devices:check-offline-alert
                            {--days=2 : Días de inactividad sin conexión para detonar la alerta}
                            {--email= : Correo electrónico destinatario específico}
                            {--force : Forzar reenvío de alerta ignorando el intervalo de 24h}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verifica biométricos sin conexión por más de 2 días y envía notificación por correo electrónico.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $this->info('===========================================================');
        $this->info('   VERIFICACIÓN DE BIOMÉTRICOS INACTIVOS / DESCONECTADOS   ');
        $this->info('===========================================================');

        try {
            $days = (int)($this->option('days') ?: 2);
            $customEmail = $this->option('email');
            $force = $this->option('force');

            $thresholdDate = Carbon::now()->subDays($days);
            $now = Carbon::now();

            $this->info("Buscando biométricos sin señal desde antes del: {$thresholdDate->format('Y-m-d H:i:s')} (más de {$days} días)");

            // Consultar biométricos cuyo último heartbeat supere el umbral o sea nulo
            $offlineDevices = Device::with('company')
                ->where(function ($q) use ($thresholdDate) {
                    $q->whereNull('last_heartbeat')
                      ->orWhere('last_heartbeat', '<=', $thresholdDate);
                })
                ->get();

            if ($offlineDevices->isEmpty()) {
                $this->info('✓ Todos los biométricos están operativos y conectados correctamente.');
                return Command::SUCCESS;
            }

            // Filtrar dispositivos que no hayan sido notificados en las últimas 24 horas (salvo que sea forzado)
            $devicesToNotify = $offlineDevices->filter(function ($device) use ($force, $now) {
                if ($force) {
                    return true;
                }
                if (is_null($device->last_alert_sent_at)) {
                    return true;
                }
                return $device->last_alert_sent_at->diffInHours($now) >= 24;
            });

            if ($devicesToNotify->isEmpty()) {
                $this->info('-> Se encontraron biométricos inactivos, pero ya fueron notificados en las últimas 24 horas.');
                return Command::SUCCESS;
            }

            $this->warn("Se encontraron {$devicesToNotify->count()} biométrico(s) inactivo(s) pendientes de notificación.");

            // Si se especificó un correo único global por opción --email
            if ($customEmail) {
                $recipients = array_map('trim', explode(',', $customEmail));
                $this->sendAlertEmail($recipients, $devicesToNotify, $days, 'Todas las Empresas');
                foreach ($devicesToNotify as $dev) {
                    $dev->update(['last_alert_sent_at' => $now]);
                }
            } else {
                // Agrupar por empresa y enviar a los destinatarios de cada empresa
                $groupedByCompany = $devicesToNotify->groupBy('company_id');

                foreach ($groupedByCompany as $companyId => $devices) {
                    $company = $devices->first()->company;
                    $companyName = $company ? $company->name : 'General / Sin Empresa';

                    $recipients = [];
                    if ($company && !empty($company->report_emails) && is_array($company->report_emails)) {
                        $recipients = $company->report_emails;
                    }

                    // Fallback a correo general en .env si no tiene correos asignados la empresa
                    if (empty($recipients) && env('ADMIN_ALERT_EMAIL')) {
                        $recipients = [env('ADMIN_ALERT_EMAIL')];
                    }

                    if (empty($recipients)) {
                        $this->line("  ⚠ Empresa '{$companyName}': No tiene report_emails configurados.");
                        continue;
                    }

                    $this->sendAlertEmail($recipients, $devices, $days, $companyName);

                    foreach ($devices as $dev) {
                        $dev->update(['last_alert_sent_at' => $now]);
                    }
                }
            }

            $this->info("\n===========================================================");
            $this->info('✓ Proceso de notificación de alertas finalizado exitosamente.');
            $this->info('===========================================================');

            return Command::SUCCESS;

        } catch (Exception $e) {
            $this->error('✘ Error al verificar estado de biométricos: ' . $e->getMessage());
            Log::error('CheckOfflineDevicesCommand exception: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return Command::FAILURE;
        }
    }

    /**
     * Construye y envía el correo HTML de alerta de biométricos fuera de línea
     */
    private function sendAlertEmail(array $recipients, $devices, int $daysThreshold, string $companyName): void
    {
        $nowStr = Carbon::now()->format('d/m/Y H:i:s');
        $deviceRowsHtml = '';

        foreach ($devices as $dev) {
            $lastSeen = $dev->last_heartbeat
                ? $dev->last_heartbeat->format('d/m/Y g:i A') . ' (' . $dev->last_heartbeat->diffForHumans() . ')'
                : 'Sin registros de conexión';

            $location = $dev->location ?: '-';
            $ip = $dev->ip_address ?: '-';

            $deviceRowsHtml .= "<tr>";
            $deviceRowsHtml .= "<td style='padding: 10px; border: 1px solid #cbd5e1; font-weight: bold; color: #0f172a;'>{$dev->name}</td>";
            $deviceRowsHtml .= "<td style='padding: 10px; border: 1px solid #cbd5e1; text-align: center; font-family: monospace;'>{$dev->serial_number}</td>";
            $deviceRowsHtml .= "<td style='padding: 10px; border: 1px solid #cbd5e1; text-align: center;'>{$location}</td>";
            $deviceRowsHtml .= "<td style='padding: 10px; border: 1px solid #cbd5e1; text-align: center;'>{$ip}</td>";
            $deviceRowsHtml .= "<td style='padding: 10px; border: 1px solid #cbd5e1; text-align: center; color: #be123c; font-weight: bold;'>{$lastSeen}</td>";
            $deviceRowsHtml .= "</tr>";
        }

        $htmlContent = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Alerta: Biométrico(s) Sin Conexión</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background-color: #f8fafc; margin: 0; padding: 20px; color: #1e293b; }
        .container { max-width: 850px; margin: 0 auto; background: #ffffff; border-radius: 8px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .header { background-color: #be123c; padding: 20px; text-align: center; color: #ffffff; }
        .header h1 { margin: 0; font-size: 20px; font-weight: bold; }
        .content { padding: 25px; }
        .alert-box { background-color: #fff1f2; border-left: 4px solid #be123c; padding: 12px 16px; margin-bottom: 20px; border-radius: 4px; color: #9f1239; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 15px; }
        th { background-color: #334155; color: #ffffff; padding: 10px; text-align: center; font-size: 12px; text-transform: uppercase; }
        tr:nth-child(even) { background-color: #f8fafc; }
        .footer { background-color: #f1f5f9; padding: 15px; text-align: center; font-size: 11px; color: #64748b; border-top: 1px solid #e2e8f0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>⚠️ ALERTA: BIOMÉTRICO(S) FORA DE LÍNEA / SIN CONEXIÓN</h1>
        </div>
        <div class="content">
            <p>Estimado Administrador,</p>
            <div class="alert-box">
                Se ha detectado que los siguientes equipos biométricos pertenecientes a <strong>{$companyName}</strong> llevan más de <strong>{$daysThreshold} días</strong> sin registrar actividad o heartbeat en la plataforma API.
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Nombre Biométrico</th>
                        <th>N° Serie</th>
                        <th>Ubicación</th>
                        <th>Dirección IP</th>
                        <th>Última Conexión Registrada</th>
                    </tr>
                </thead>
                <tbody>
                    {$deviceRowsHtml}
                </tbody>
            </table>

            <p style="margin-top: 25px; font-size: 12px; color: #475569;">
                <strong>Recomendaciones:</strong><br>
                • Verifique la conexión a internet y suministro de energía del dispositivo.<br>
                • Revise que los parámetros PUSH (Servidor IP / Puerto / ADMS) sigan configurados correctamente en el equipo.<br>
                • Reinicie el biométrico si se encuentra congelado.
            </p>
        </div>
        <div class="footer">
            Notificación generada automáticamente por ZKTeco Biometric API el {$nowStr}
        </div>
    </div>
</body>
</html>
HTML;

        foreach ($recipients as $email) {
            $email = trim($email);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;

            try {
                if (class_exists(Mail::class)) {
                    Mail::html($htmlContent, function ($message) use ($email, $companyName, $devices) {
                        $count = $devices->count();
                        $message->to($email)
                            ->subject("⚠️ ALERTA: {$count} Biométrico(s) Fuera de Línea (+2 Días) - {$companyName}");
                    });
                    $this->info("  ✓ Alerta enviada exitosamente por correo a: {$email}");
                }
            } catch (Exception $e) {
                $this->warn("  ⚠ Error al enviar correo a {$email}: " . $e->getMessage());
                Log::warning("Failed to send offline device alert to {$email}: " . $e->getMessage());
            }
        }
    }
}
