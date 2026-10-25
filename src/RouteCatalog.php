<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace AkauntingMcp;

use RuntimeException;

/**
 * Versioned catalog captured from the actual Akaunting API route registration.
 * Routes become callable only after explicit classification as read-only.
 * This is NOT an arbitrary path/URL proxy and GET routes with side effects
 * must never be present. A module update requires regenerating and reviewing
 * the snapshot before the next MCP deployment.
 */
final class RouteCatalog
{
    /** @var array<string,array<string,mixed>> */
    private array $routes = [];

    public function __construct(?string $manifest = null)
    {
        $source = $manifest ?? __DIR__.'/api-routes.json';
        $raw = is_file($source) ? file_get_contents($source) : false;
        $value = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($value) || ($value['version'] ?? null) !== 1 || !is_array($value['routes'] ?? null)) {
            throw new RuntimeException('Akaunting API route catalog unavailable');
        }
        foreach ($value['routes'] as $r) {
            if (!is_array($r) || !is_string($r['name'] ?? null) || !is_string($r['uri'] ?? null)
                || !is_array($r['params'] ?? null) || !is_bool($r['module'] ?? null)
                || !preg_match('/^api\.[a-z0-9.-]+\.(?:index|show|all|file)$/D', $r['name'])
                || !preg_match('~^api/[a-z0-9-]+(?:/(?:[a-z0-9-]+|\{[a-z_]+\}))*$~D', $r['uri'])) {
                throw new RuntimeException('Invalid read API route catalog');
            }
            if (isset($this->routes[$r['name']])) {
                throw new RuntimeException('Duplicate read API route');
            }
            $paramNames=[];
            if (preg_match_all('/\{([a-z_]+)\}/', $r['uri'], $matches)) {
                $paramNames=$matches[1];
            }
            if ($paramNames !== $r['params']) {
                throw new RuntimeException('Invalid API route placeholders');
            }
            $this->routes[$r['name']] = $r;
        }
    }

    public function list(): array
    {
        return array_values(array_map(static fn (array $route): array => [
            'route'=>$route['name'],
            'uri'=>$route['uri'],
            'source'=>$route['module'] ? 'module' : 'core',
            'parameters'=>$route['params'],
            'required_permission_hints'=>$route['permissions'] ?? [],
        ], $this->routes));
    }

    /** @return array{path:string,route:array<string,mixed>} */
    public function resolve(string $name, array $params): array
    {
        $route=$this->routes[$name] ?? null;
        if ($route === null) {
            throw new RuntimeException('Read API route not registered');
        }
        $expected=$route['params'];
        if (count($expected) !== count($params)
            || array_diff($expected,array_keys($params)) !== []
            || array_diff(array_keys($params),$expected) !== []) {
            throw new RuntimeException('Invalid route parameters');
        }
        $path=$route['uri'];
        foreach ($expected as $key) {
            $raw=$params[$key];
            if ($key === 'locale') {
                if (!is_string($raw) || !preg_match('/^[a-z]{2}(?:[-_][A-Za-z]{2})?$/D',$raw)) {
                    throw new RuntimeException('Invalid locale');
                }
                $v=$raw;
            } elseif ($key === 'file') {
                if (!is_string($raw) || !preg_match('/^[a-z][a-z0-9_-]{0,49}$/D',$raw)) {
                    throw new RuntimeException('Invalid translation file');
                }
                $v=$raw;
            } elseif (is_int($raw) && $raw > 0 && $raw <= 2147483647) {
                $v=(string)$raw;
            } else {
                throw new RuntimeException('API route IDs must be positive integers');
            }
            $path=str_replace('{'.$key.'}',$v,$path);
        }
        return ['path'=>substr($path,3), 'route'=>$route];
    }

    public function permitsPath(string $path): bool
    {
        foreach ($this->routes as $route) {
            $template=substr($route['uri'],3);
            $parts=preg_split('/(\{[a-z_]+\})/', $template,-1,PREG_SPLIT_DELIM_CAPTURE);
            $safe='';
            foreach($parts as $part) {
                if (preg_match('/^\{([a-z_]+)\}$/D',$part,$match)) {
                    $safe.=match ($match[1]) {
                        'locale'=>'[a-z]{2}(?:[-_][A-Za-z]{2})?',
                        'file'=>'[a-z][a-z0-9_-]{0,49}',
                        default=>'[1-9][0-9]{0,9}',
                    };
                } else {
                    $safe.=preg_quote($part,'~');
                }
            }
            if (preg_match('~^'.$safe.'$~D',$path)) {
                return true;
            }
        }
        return false;
    }
}
