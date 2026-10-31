<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

/**
 * Produce reviewable candidates for native API writes from a real Laravel
 * route:list snapshot. No route is enabled until this catalog is reviewed,
 * tested and committed. Unnamed and non-API routes are intentionally excluded.
 */
function generateWriteCatalog(array $registered): array
{
    $routes = [];
    $seen = [];
    foreach ($registered as $route) {
        if (!is_array($route)) {
            continue;
        }
        $name = $route['name'] ?? null;
        $uri = $route['uri'] ?? null;
        $action = $route['action'] ?? null;
        $method = $route['method'] ?? '';
        if (!is_string($name) || !is_string($uri) || !is_string($method) || !is_string($action)
            || !preg_match('/^api\.[a-z0-9.-]+\.(?:store|update|destroy)$/D', $name)
            || !preg_match('~^api/[a-z0-9-]+(?:/(?:[a-z0-9-]+|\{[a-z_]+\}))*$~D', $uri)) {
            continue;
        }
        $verbs = array_values(array_intersect(explode('|', $method), ['POST', 'PUT', 'PATCH', 'DELETE']));
        if ($verbs === []) {
            continue;
        }
        $actionName = strrchr($action, '@');
        $actionName = $actionName === false ? $action : substr($actionName, 1);
        if ($actionName !== substr($name, strrpos($name, '.') + 1)) {
            continue;
        }
        if (isset($seen[$name])) {
            throw new RuntimeException('Duplicate mutating API route');
        }
        $seen[$name] = true;
        preg_match_all('/\{([a-z_]+)\}/', $uri, $matches);
        $permissions = [];
        foreach (($route['middleware'] ?? []) as $middleware) {
            if (is_string($middleware) && str_contains($middleware, 'Permission:')) {
                $permissions[] = substr($middleware, strpos($middleware, 'Permission:') + strlen('Permission:'));
            } elseif (is_string($middleware) && str_starts_with($middleware, 'permission:')) {
                $permissions[] = substr($middleware, strlen('permission:'));
            }
        }
        $routes[] = [
            'name'=>$name,
            'uri'=>$uri,
            'params'=>$matches[1],
            'methods'=>$verbs,
            'module'=>str_contains($action, '\\Modules\\') || str_starts_with($action, 'Modules\\'),
            'permissions'=>$permissions,
        ];
    }
    usort($routes, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
    return ['version'=>1,'source'=>'registered_akaunting_api_routes','routes'=>$routes];
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $args = $_SERVER['argv'] ?? [];
    if (PHP_SAPI !== 'cli' || count($args) !== 2 || !is_file((string)$args[1])) {
        fwrite(STDERR, "Usage: php bin/generate-write-catalog.php <route-list.json>\n");
        exit(2);
    }
    try {
        $registered = json_decode((string)file_get_contents($args[1]), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($registered) || !array_is_list($registered)) {
            throw new RuntimeException('Invalid route registration');
        }
        $data = generateWriteCatalog($registered);
        echo json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        fwrite(STDERR, 'Candidate mutating API routes: '.count($data['routes'])."\n");
    } catch (Throwable $error) {
        fwrite(STDERR, 'Cannot generate mutating catalog: '.get_class($error)."\n");
        exit(1);
    }
}
