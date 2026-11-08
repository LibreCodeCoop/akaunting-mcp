<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace AkauntingMcp;

use RuntimeException;

/**
 * Authenticated, live route declarations from the installed Akaunting server.
 * GET is not treated as safe by itself: each operation must carry an explicit
 * server-side read contract. MCP writes remain on their separate reviewed,
 * approval-gated catalog, irrespective of what this endpoint advertises.
 */
final class LiveApiDiscovery
{
    public function __construct(private readonly Api $api) {}

    /** @param array<string,string> $creds
     * @return list<array<string,mixed>>
     */
    public function routes(array $creds, int $companyId): array
    {
        try {
            $manifest = $this->api->get(
                $creds['email'], $creds['password'], '/nfse/discovery',
                ['company_id'=>$companyId],
            );
        } catch (RuntimeException $error) {
            // Older module releases have no discovery endpoint. Preserve the
            // existing reviewed catalog instead of disrupting known reads.
            if ($error->getMessage() === 'Akaunting request rejected (HTTP 404)') {
                return [];
            }
            throw $error;
        }

        if (($manifest['version'] ?? null) !== 1
            || ($manifest['company_id'] ?? null) !== $companyId
            || !is_array($manifest['routes'] ?? null)
            || !array_is_list($manifest['routes'])
            || count($manifest['routes']) > 200) {
            throw new RuntimeException('Invalid upstream API capability manifest');
        }

        $result = [];
        $seen = [];
        $reviewed = array_column((new RouteCatalog())->list(), null, 'route');
        $reviewedWrites = array_column((new WriteRouteCatalog())->list(), null, 'route');
        foreach ($manifest['routes'] as $route) {
            $spec = $this->validate($route);
            if (isset($seen[$spec['name']])) {
                throw new RuntimeException('Duplicate discovered API route name');
            }
            $seen[$spec['name']] = true;
            if (isset($reviewedWrites[$spec['name']]) || self::reservedAction($spec['name'])) {
                throw new RuntimeException('Write or side-effecting route cannot be discovered as a read');
            }

            // Never let dynamic metadata override a previously reviewed
            // static route or quietly change its target path.
            $existing = $reviewed[$spec['name']] ?? null;
            if (is_array($existing)) {
                if ($existing['uri'] !== $spec['uri']
                    || $existing['parameters'] !== $spec['params']) {
                    throw new RuntimeException('Previously reviewed API route has drifted');
                }
                continue;
            }
            $result[] = $spec;
        }
        return $result;
    }

    public static function reservedAction(string $name): bool
    {
        return preg_match(
            '/\.(?:store|update|destroy|delete|disable|enable|emit|reemit|cancel|substitute|advance|dispatch|send|distribution|download|export|refresh|import|ignore|diagnostics|upload|issue|execute|approve|revoke)$/D',
            $name,
        ) === 1;
    }

    /** @param mixed $route
     * @return array<string,mixed>
     */
    private function validate(mixed $route): array
    {
        if (!is_array($route) || !is_string($route['name'] ?? null)
            || !is_string($route['uri'] ?? null)
            || preg_match('/^api\.[a-z0-9.-]{4,110}$/D', $route['name']) !== 1
            || preg_match('~^(?:\{company_id\}/)?api/[a-z0-9-]+(?:/(?:[a-z0-9-]+|\{[a-z_]+\}))*$~D', $route['uri']) !== 1
            || ($route['method'] ?? null) !== 'GET'
            || !in_array($route['scope'] ?? null, ['invoice','company'], true)
            || !is_array($route['params'] ?? null)
            || !array_is_list($route['params'])
            || !is_array($route['permissions'] ?? null)
            || $route['permissions'] === []
            || !array_is_list($route['permissions'])
            || !is_array($route['query'] ?? null)
            || (array_is_list($route['query']) && $route['query'] !== [])
            || count($route['query']) > 20
            || !is_array($route['response_paths'] ?? null)
            || !array_is_list($route['response_paths'])
            || $route['response_paths'] === []
            || count($route['response_paths']) > 12) {
            throw new RuntimeException('Unsafe discovered API route');
        }

        preg_match_all('/\{([a-z_]+)\}/', $route['uri'], $matches);
        $expected = array_values(array_filter($matches[1], static fn (string $v): bool => $v !== 'company_id'));
        if ($expected !== $route['params'] || count(array_unique($expected)) !== count($expected)
            || ($route['scope'] === 'invoice' && !in_array('invoice', $expected, true))
            || ($route['scope'] === 'company' && in_array('invoice', $expected, true))) {
            throw new RuntimeException('Unsafe discovered API path parameters');
        }
        foreach ($route['permissions'] as $permission) {
            if (!is_string($permission) || preg_match('/^read-[a-z0-9-]+$/D', $permission) !== 1) {
                throw new RuntimeException('Discovered API route lacks read ACL');
            }
        }
        foreach ($route['response_paths'] as $path) {
            if (!is_string($path)
                || preg_match('~^/data(?:/[a-z_][a-z0-9_]*){0,4}$~D', $path) !== 1) {
                throw new RuntimeException('Unsafe discovered API response selector');
            }
            foreach ($route['response_paths'] as $other) {
                if ($other !== $path && is_string($other)
                    && str_starts_with($other, $path . '/')) {
                    throw new RuntimeException('Overlapping discovered API response selectors');
                }
            }
        }
        if (count(array_unique($route['response_paths'])) !== count($route['response_paths'])) {
            throw new RuntimeException('Duplicate discovered API response selector');
        }
        foreach ($route['query'] as $key => $type) {
            if (!is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $key) !== 1
                || $key === 'company_id'
                || !in_array($type, ['positive_integer','short_text'], true)) {
                throw new RuntimeException('Unsafe discovered API query schema');
            }
        }
        return $route;
    }

    /** @param array<string,mixed> $spec
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function resolve(array $spec, array $params, int $companyId): array
    {
        $expected = $spec['params'];
        if (count($params) !== count($expected)
            || array_diff($expected, array_keys($params)) !== []
            || array_diff(array_keys($params), $expected) !== []) {
            throw new RuntimeException('Invalid discovered API route parameters');
        }
        $uri = $spec['uri'];
        foreach ($expected as $key) {
            $value = $params[$key];
            if (!is_int($value) || $value < 1 || $value > 2147483647) {
                throw new RuntimeException('Discovered API IDs must be positive integers');
            }
            $uri = str_replace('{'.$key.'}', (string) $value, $uri);
        }
        $tenantPrefix = str_starts_with($uri, '{company_id}/api/');
        $path = preg_replace('~^(?:\{company_id\}/)?api~', '', $uri);
        if (!is_string($path) || preg_match('~^/[a-z0-9-]+(?:/[a-z0-9-]+)*$~D', $path) !== 1) {
            throw new RuntimeException('Discovered API path is invalid');
        }

        return ['name'=>$spec['name'],'path'=>$path,'company_id'=>$companyId,
            'tenant_prefix'=>$tenantPrefix,'scope'=>$spec['scope'],
            'params'=>$params,'query'=>$spec['query'],
            'response_paths'=>$spec['response_paths']];
    }

    /** @param array<string,mixed> $querySpec
     * @param array<array-key,mixed> $input
     * @return array<string,int|string>
     */
    public function query(array $querySpec, array $input): array
    {
        if (array_is_list($input) && $input !== []) {
            throw new RuntimeException('Discovered API query must be an object');
        }
        $result = [];
        foreach ($input as $key => $value) {
            $type = $querySpec[$key] ?? null;
            if ($type === 'positive_integer' && is_int($value)
                && $value > 0 && $value <= 10000) {
                $result[$key] = $value;
            } elseif ($type === 'short_text' && is_string($value)
                && strlen($value) <= 100
                && preg_match('/^[a-zA-Z0-9 ._:-]{1,100}$/D', $value) === 1) {
                $result[$key] = $value;
            } else {
                throw new RuntimeException('Discovered API query is not allowed');
            }
        }
        return $result;
    }
}
