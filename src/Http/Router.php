<?php

declare(strict_types=1);

namespace MaiMind\Http;

use Closure;

final class Router
{
    /** @var list<array{method:string,regex:string,handler:Closure,auth:bool}> */
    private array $routes = [];

    public function get(string $path, Closure $handler, bool $auth = true): void
    {
        $this->add('GET', $path, $handler, $auth);
    }

    public function post(string $path, Closure $handler, bool $auth = true): void
    {
        $this->add('POST', $path, $handler, $auth);
    }

    private function add(string $method, string $path, Closure $handler, bool $auth): void
    {
        // /api/entries/{uid} → #^/api/entries/(?P<uid>[^/]+)$#
        $regex = preg_replace('/\{([a-z_]+)\}/', '(?P<$1>[^/]+)', $path);

        $this->routes[] = [
            'method'  => $method,
            'regex'   => '#^' . $regex . '$#',
            'handler' => $handler,
            'auth'    => $auth,
        ];
    }

    /**
     * @return array{handler:Closure,auth:bool,params:array<string,string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            $params = array_filter($matches, is_string(...), ARRAY_FILTER_USE_KEY);

            return [
                'handler' => $route['handler'],
                'auth'    => $route['auth'],
                'params'  => array_map(strval(...), $params),
            ];
        }

        return null;
    }

    /** ¿Existe la ruta con otro método? Sirve para responder 405 en vez de 404. */
    public function pathExists(string $path): bool
    {
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path) === 1) {
                return true;
            }
        }

        return false;
    }
}
