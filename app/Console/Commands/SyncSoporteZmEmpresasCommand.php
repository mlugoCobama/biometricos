<?php

namespace App\Console\Commands;

use App\Services\SoporteZmService;
use Illuminate\Console\Command;
use Exception;

class SyncSoporteZmEmpresasCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'empresas:sync-soportezm';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ejecuta el Stored Procedure SP_GetEmpresas de SOPORTEZM y sincroniza las empresas locales.';

    /**
     * Execute the console command.
     *
     * @param SoporteZmService $service
     * @return int
     */
    public function handle(SoporteZmService $service): int
    {
        $this->info('Iniciando sincronización de empresas desde BD SOPORTEZM (SP_GetEmpresas)...');

        try {
            $result = $service->syncEmpresas();

            $this->info("✓ Sincronización completada exitosamente.");
            $this->table(
                ['Métrica', 'Valor'],
                [
                    ['Total Procesadas', $result['total']],
                    ['Empresas Creadas', $result['created']],
                    ['Empresas Actualizadas', $result['updated']],
                ]
            );

            return Command::SUCCESS;
        } catch (Exception $e) {
            $this->error('✘ Error durante la sincronización: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
