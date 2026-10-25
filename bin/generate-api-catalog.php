<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

/**
 * Convert Laravel's route:list --path=api --json output to a candidate
 * read-only MCP route catalog. This performs syntactic selection only.
 * Every returned route still requires manual ACL, tenant and side-effect review.
 */
function generateApiCatalog(array $registered): array
{
    $items = [];
    $seen = [];

    foreach ($registered as $route) {
        if (!is_array($route)) {
            continue;
        }
        $name = $route['name'] ?? null;
        $uri = $route['uri'] ?? null;
        $action = $route['action'] ?? null;
        if (!is_string($name) || !is_string($uri) || !is_string($action)) {
            continue;
        }
        $methods = $route['method'] ?? '';
        if (!is_string($methods) || !in_array('GET', explode('|', $methods), true)
            || !str_starts_with($name, 'api.') || !str_starts_with($uri, 'api/')) {
            continue;
        }
        $method = strrchr($action, '@');
        $method = $method === false ? $action : substr($method, 1);
        if (!in_array($method, ['index', 'show', 'all', 'file'], true)
            || preg_match('~^api/[a-z0-9-]+(?:/(?:[a-z0-9-]+|\\{[a-z_]+\\}))*$~D', $uri) !== 1
            || preg_match('~^api\\.[a-z0-9.-]+\\.(?:index|show|all|file)$~D', $name) !== 1) {
            continue;
        }
        if (isset($seen[$name])) {
            throw new RuntimeException('Duplicate API route name');
        }
        $seen[$name] = true;
        preg_match_all('/\\{([a-z_]+)\\}/', $uri, $parameters);
        $hints = [];
        foreach (($route['middleware'] ?? []) as $middleware) {
            if (!is_string($middleware)) {
                continue;
            }
            if (($offset = strpos($middleware, 'Permission:')) !== false) {
                $hints[] = substr($middleware, $offset + strlen('Permission:'));
            }
            if (str_starts_with($middleware, 'permission:')) {
                $hints[] = substr($middleware, strlen('permission:'));
            }
        }
        $items[] = [
            'name' => $name,
            'uri' => $uri,
            'params' => $parameters[1],
            'module' => str_contains($action, '\\Modules\\') || str_starts_with($action, 'Modules\\'),
            'permissions' => $hints,
        ];
    }

    usort($items, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

    return [
        'version' => 1,
        'source' => 'registered_akaunting_api_routes',
        'routes' => $items,
    ];
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $arguments = $_SERVER['argv'] ?? [];
    if (PHP_SAPI !== 'cli' || count($arguments) !== 2 || !is_file((string) $arguments[1])) {
        fwrite(STDERR, "Usage: php bin/generate-api-catalog.php <route-list.json>\n");
        exit(2);
    }
    try {
        $routes = json_decode((string) file_get_contents((string) $arguments[1]), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($routes) || !array_is_list($routes)) {
            throw new RuntimeException('Expected a JSON array of Laravel routes');
        }
        $catalog = generateApiCatalog($routes);
        echo json_encode($catalog, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        fwrite(STDERR, 'Selected candidate GET routes: ' . count($catalog['routes']) . "\n");
    } catch (Throwable $error) {
        fwrite(STDERR, 'Route catalog conversion failed: ' . get_class($error) . "\n");
        exit(1);
    }
}
