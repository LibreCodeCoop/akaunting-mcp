<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace AkauntingMcp;

use RuntimeException;

/** Versioned, reviewed native API mutation routes. Never a generic HTTP proxy. */
final class WriteRouteCatalog
{
    /** @var array<string,array<string,mixed>> */
    private array $routes = [];

    public function __construct(?string $manifest = null)
    {
        $raw = @file_get_contents($manifest ?? __DIR__.'/api-write-routes.json');
        $value = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($value) || ($value['version'] ?? null) !== 1
            || !is_array($value['routes'] ?? null) || !array_is_list($value['routes'])) {
            throw new RuntimeException('Write API route catalog unavailable');
        }
        foreach ($value['routes'] as $route) {
            if (!is_array($route) || !is_string($route['name'] ?? null)
                || !preg_match('/^api\.[a-z0-9.-]+\.(store|update|destroy)$/D', $route['name'])
                || !is_string($route['uri'] ?? null)
                || !preg_match('~^api/[a-z0-9-]+(?:/(?:[a-z0-9-]+|\{[a-z_]+\}))*$~D', $route['uri'])
                || !is_array($route['params'] ?? null) || !array_is_list($route['params'])
                || !is_array($route['methods'] ?? null) || !array_is_list($route['methods'])
                || !is_bool($route['module'] ?? null) || isset($this->routes[$route['name']])) {
                throw new RuntimeException('Invalid write API route catalog');
            }
            preg_match_all('/\{([a-z_]+)\}/', $route['uri'], $matches);
            $action = substr($route['name'], strrpos($route['name'], '.')+1);
            $expected = match ($action) {
                'store'=>['POST'], 'update'=>['PUT','PATCH'], 'destroy'=>['DELETE'],
                default=>throw new RuntimeException('Unknown mutating action'),
            };
            if ($route['params'] !== $matches[1]
                || $route['methods'] === []
                || array_diff($route['methods'], $expected) !== []
                || count($route['methods']) !== count(array_unique($route['methods']))) {
                throw new RuntimeException('Untrusted write API route manifest');
            }
            $this->routes[$route['name']] = $route;
        }
    }

    public function list(): array
    {
        return array_values(array_map(static fn(array $r): array => [
            'route'=>$r['name'], 'methods'=>$r['methods'],
            'uri'=>$r['uri'], 'parameters'=>$r['params'],
            'source'=>$r['module']?'module':'core',
            'required_permission_hints'=>$r['permissions']??[],
            'human_approval'=>'required_for_every_request',
        ], $this->routes));
    }

    /** @return array{path:string,method:string,route:array<string,mixed>} */
    public function resolve(string $name, string $method, array $params): array
    {
        $route=$this->routes[$name] ?? null;
        if ($route === null || !in_array($method,$route['methods'],true)) {
            throw new RuntimeException('Mutating route not registered');
        }
        $expected=$route['params'];
        if (count($params)!==count($expected)
            || array_diff($expected,array_keys($params)) !== []
            || array_diff(array_keys($params),$expected) !== []) {
            throw new RuntimeException('Invalid mutation route parameters');
        }
        $uri=$route['uri'];
        foreach ($expected as $name) {
            $value=$params[$name];
            if (!is_int($value) || $value<=0 || $value>2147483647) {
                throw new RuntimeException('Mutating path identifiers must be positive integers');
            }
            $uri=str_replace('{'.$name.'}',(string)$value,$uri);
        }
        return ['path'=>substr($uri,3), 'method'=>$method, 'route'=>$route];
    }

    public function permits(string $method, string $path): bool
    {
        foreach ($this->routes as $route) {
            if (!in_array($method,$route['methods'],true)) continue;
            $pattern=preg_replace('/\\\{[a-z_]+\\\}/','[1-9][0-9]{0,9}',preg_quote(substr($route['uri'],3),'~'));
            if (is_string($pattern) && preg_match('~^'.$pattern.'$~D',$path)) return true;
        }
        return false;
    }
}
