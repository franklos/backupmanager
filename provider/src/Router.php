<?php

declare(strict_types=1);

namespace BackupManager\Provider;

final class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
        ];
    }

    public function dispatch(string $method, string $path): void
    {
        $method = strtoupper($method);

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['pattern'], $path, $matches) === 1) {
                array_shift($matches);
                ($route['handler'])(...$matches);
                return;
            }
        }

        Response::json([
            'success' => false,
            'error' => 'Not found',
        ], 404);
    }
}
