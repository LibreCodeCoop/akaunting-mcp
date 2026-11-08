<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Compare API route registration with the MCP's reviewed route catalog.
 * No HTTP requests, credentials, database access or financial mutations.
 */
final class ApiParityAudit
{
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /** @return list<array{name:string,method:string,uri:string}> */
    public static function fromLaravel(array $routes): array
    {
        $result = [];
        foreach ($routes as $route) {
            if (!is_array($route)) {
                throw new RuntimeException('Invalid Laravel route row');
            }
            $name = $route['name'] ?? null;
            if (!is_string($name) || !str_starts_with($name, 'api.')) {
                continue;
            }
            $uri = $route['uri'] ?? null;
            $method = $route['method'] ?? null;
            if (!is_string($uri) || !is_string($method)) {
                throw new RuntimeException('Incomplete named API route: ' . $name);
            }
            $verbs = array_values(array_intersect(explode('|', $method), self::METHODS));
            if ($verbs === []) {
                throw new RuntimeException('Unsupported API method: ' . $name);
            }
            foreach ($verbs as $verb) {
                $result[] = ['name'=>$name, 'method'=>$verb, 'uri'=>self::normalUri($uri)];
            }
        }
        return $result;
    }

    /** Parse Laravel's named module route declarations without bootstrapping Akaunting. */
    public static function fromModuleSource(string $source): array
    {
        $expr = '~\bRoute::(get|post|put|patch|delete)\s*\(\s*([\x27\x22])([^\x27\x22]+)\2[^;]*?->name\(\s*([\x27\x22])([^\x27\x22]+)\4\s*\)~s';
        preg_match_all($expr, $source, $matches, PREG_SET_ORDER);
        preg_match_all('~\bRoute::(?:get|post|put|patch|delete)\s*\(~', $source, $calls);
        if ($matches === [] || count($matches) !== count($calls[0])) {
            throw new RuntimeException('Unrecognized or unnamed NFS-e route registration');
        }
        $routes = [];
        foreach ($matches as $match) {
            $entry = [
                'name'=>'api.nfse.' . $match[5],
                'method'=>strtoupper($match[1]),
                'uri'=>'api/nfse/' . $match[3],
            ];
            // Route owners explicitly mark safe JSON GET contracts. The
            // absence of an annotation remains a pending operation.
            if (str_contains($match[0], "->defaults('mcp_read_contract'")) {
                $entry['discovered'] = true;
            }
            $routes[] = $entry;
        }
        return $routes;
    }

    public static function normalUri(string $uri): string
    {
        $uri = ltrim($uri, '/');
        if (str_starts_with($uri, '{company_id}/api/')) {
            $uri = substr($uri, strlen('{company_id}/'));
        }
        return $uri;
    }

    /** @return array{total:int,supported:int,pending:list<string>,changed:list<string>} */
    public static function compare(array $registered, array $catalog, array $knownGaps): array
    {
        $expected = [];
        foreach ($catalog as $item) {
            if (!is_array($item) || !is_string($item['route'] ?? null)
                || !is_string($item['uri'] ?? null)) {
                throw new RuntimeException('Invalid MCP catalog route');
            }
            $name = $item['route'];
            if (isset($expected[$name])) {
                throw new RuntimeException('Duplicate MCP route: ' . $name);
            }
            $verbs = $item['methods'] ?? ['GET'];
            if (!is_array($verbs) || $verbs === []) {
                throw new RuntimeException('Invalid MCP method set: ' . $name);
            }
            $expected[$name] = [
                'verbs'=>$verbs,
                'uri'=>self::normalUri($item['uri']),
            ];
        }
        $seen = [];
        $pending = [];
        $changed = [];
        $supported = 0;
        foreach ($registered as $item) {
            $name = $item['name'] ?? null;
            $method = $item['method'] ?? null;
            $uri = $item['uri'] ?? null;
            if (!is_string($name) || !is_string($method) || !is_string($uri)) {
                throw new RuntimeException('Malformed source API registration');
            }
            $key = $name . ' ' . $method;
            if (isset($seen[$key])) {
                throw new RuntimeException('Duplicate API registration: ' . $key);
            }
            $seen[$key] = true;
            if (!isset($expected[$name])) {
                $bootstrap = $name === 'api.nfse.discovery.index'
                    && $method === 'GET' && self::normalUri($uri) === 'api/nfse/discovery';
                $dynamic = ($item['discovered'] ?? false) === true
                    && $method === 'GET'
                    && preg_match('~^api/[a-z0-9-]+(?:/(?:[a-z0-9-]+|\{[a-z_]+\}))*$~D',
                        self::normalUri($uri)) === 1
                    && !\AkauntingMcp\LiveApiDiscovery::reservedAction($name);
                if ($bootstrap || $dynamic) {
                    ++$supported;
                } else {
                    $pending[$name] = true;
                }
            } elseif (!in_array($method, $expected[$name]['verbs'], true)
                || $expected[$name]['uri'] !== self::normalUri($uri)) {
                $changed[$name] = true;
            } else {
                ++$supported;
            }
        }
        $known = [];
        foreach ($knownGaps as $entry) {
            if (!is_array($entry) || !is_string($entry['route'] ?? null)
                || !is_string($entry['reason'] ?? null)
                || strlen(trim($entry['reason'])) < 20 || isset($known[$entry['route']])) {
                throw new RuntimeException('Invalid or duplicate known-gap entry');
            }
            $known[$entry['route']] = true;
        }
        $pendingNames = array_keys($pending);
        $changedNames = array_keys($changed);
        sort($pendingNames);
        sort($changedNames);
        $unclassified = array_values(array_diff($pendingNames, array_keys($known)));
        $stale = array_values(array_diff(array_keys($known), $pendingNames));
        if ($unclassified !== [] || $stale !== [] || $changedNames !== []) {
            throw new RuntimeException(json_encode([
                'unclassified'=>$unclassified,
                'stale_baseline'=>$stale,
                'contract_drift'=>$changedNames,
            ], JSON_THROW_ON_ERROR));
        }
        return [
            'total'=>count($seen),
            'supported'=>$supported,
            'pending'=>$pendingNames,
            'changed'=>$changedNames,
        ];
    }
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        $opts = getopt('', ['laravel:', 'module-source:', 'baseline:', 'strict', 'catalog:']);
        $laravel = $opts['laravel'] ?? null;
        $module = $opts['module-source'] ?? null;
        if ((is_string($laravel)) === (is_string($module))) {
            throw new RuntimeException('Supply exactly one of --laravel or --module-source');
        }
        $source = is_string($laravel) ? $laravel : $module;
        $contents = @file_get_contents($source);
        if (!is_string($contents)) {
            throw new RuntimeException('API registration source unavailable');
        }
        $registered = is_string($laravel)
            ? ApiParityAudit::fromLaravel(json_decode($contents, true, 512, JSON_THROW_ON_ERROR))
            : ApiParityAudit::fromModuleSource($contents);
        if (isset($opts['catalog'])) {
            $catalog = json_decode((string) file_get_contents($opts['catalog']), true, 512, JSON_THROW_ON_ERROR);
        } else {
            require_once __DIR__ . '/../src/bootstrap.php';
            $catalog = array_merge(
                (new \AkauntingMcp\RouteCatalog())->list(),
                (new \AkauntingMcp\WriteRouteCatalog())->list(),
            );
        }
        $known = isset($opts['baseline'])
            ? json_decode((string) file_get_contents($opts['baseline']), true, 512, JSON_THROW_ON_ERROR)
            : [];
        if (!is_array($catalog) || !is_array($known)) {
            throw new RuntimeException('Invalid catalog or known-gap baseline');
        }
        if (is_string($module)) {
            // Source-only checks do not require the unrelated Core gap baseline.
            $known = array_values(array_filter($known, static fn (mixed $entry): bool =>
                is_array($entry) && str_starts_with((string) ($entry['route'] ?? ''), 'api.nfse.')));
        }
        $report = ApiParityAudit::compare($registered, $catalog, $known);
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (isset($opts['strict']) && $report['pending'] !== []) {
            fwrite(STDERR, "Strict parity requires zero pending routes\n");
            exit(1);
        }
    } catch (Throwable $error) {
        fwrite(STDERR, 'API parity audit failed: ' . $error->getMessage() . "\n");
        exit(1);
    }
}
