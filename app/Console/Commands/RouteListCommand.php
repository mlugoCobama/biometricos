<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class RouteListCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'route:list 
                            {--method= : Filtrar rutas por método HTTP (GET, POST, PUT, DELETE, etc.)} 
                            {--path= : Filtrar rutas por coincidencia en la URI}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Muestra el listado de todas las rutas activas registradas en la aplicación.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $router = app('router');
        $rawRoutes = $router->getRoutes();

        $routes = [];
        $filterMethod = $this->option('method') ? strtoupper($this->option('method')) : null;
        $filterPath = $this->option('path') ? strtolower($this->option('path')) : null;

        foreach ($rawRoutes as $route) {
            $method = $route['method'];
            $uri = $route['uri'];
            $action = $route['action'];

            if ($filterMethod && $method !== $filterMethod) {
                continue;
            }

            if ($filterPath && strpos(strtolower($uri), $filterPath) === false) {
                continue;
            }

            // Determine action representation
            if (isset($action['uses'])) {
                if (is_string($action['uses'])) {
                    $actionText = $action['uses'];
                } elseif (is_callable($action['uses'])) {
                    $actionText = 'Closure';
                } else {
                    $actionText = gettype($action['uses']);
                }
            } else {
                $actionText = 'Closure';
            }

            // Determine middlewares
            $middleware = [];
            if (isset($action['middleware'])) {
                $middleware = is_array($action['middleware']) ? $action['middleware'] : [$action['middleware']];
            }

            $routes[] = [
                'method' => $method,
                'uri' => $uri === '' ? '/' : $uri,
                'action' => $actionText,
                'middleware' => implode(', ', $middleware),
            ];
        }

        if (empty($routes)) {
            $this->warn('No se encontraron rutas activas que coincidan con los criterios.');
            return Command::SUCCESS;
        }

        // Sort routes by URI and method
        usort($routes, function ($a, $b) {
            return strcmp($a['uri'], $b['uri']) ?: strcmp($a['method'], $b['method']);
        });

        $this->info('Rutas activas registradas en la aplicación:');
        $this->table(
            ['Método', 'URI', 'Acción / Controlador', 'Middleware'],
            $routes
        );

        $this->comment('Total de rutas activas: ' . count($routes));

        return Command::SUCCESS;
    }
}
