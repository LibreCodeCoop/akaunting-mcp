<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace AkauntingMcp\Tests;

require_once dirname(__DIR__, 2) . '/bin/generate-api-catalog.php';
require_once dirname(__DIR__, 2) . '/bin/generate-write-catalog.php';
require_once dirname(__DIR__, 2) . '/tools/check-release-scope.php';
require_once dirname(__DIR__, 2) . '/tools/check-reuse-headers.php';
require_once dirname(__DIR__) . '/integration/check-docker-smoke.php';

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ToolingTest extends TestCase
{
    public function testRouteCatalogGeneratorPreservesReadOnlyContract(): void
    {
        $result = \generateApiCatalog([
            ['name'=>'api.users.index', 'uri'=>'api/users', 'method'=>'GET|HEAD',
                'action'=>'App\\Http\\Controllers\\Users@index', 'middleware'=>['Permission:read-auth-users']],
            ['name'=>'api.departments.show', 'uri'=>'api/departments/{department}', 'method'=>'GET',
                'action'=>'Modules\\Employees\\Department@show', 'middleware'=>['permission:read-departments']],
            ['name'=>'api.users.store', 'uri'=>'api/users', 'method'=>'POST',
                'action'=>'App\\Http\\Controllers\\Users@store'],
            ['name'=>'api.users.delete', 'uri'=>'api/users/{id}', 'method'=>'GET',
                'action'=>'App\\Http\\Controllers\\Users@delete'],
            ['name'=>'api.unsafe.show', 'uri'=>'api/unsafe/{not-a-param}', 'method'=>'GET',
                'action'=>'Users@show'],
        ]);
        self::assertSame('registered_akaunting_api_routes', $result['source']);
        self::assertCount(2, $result['routes']);
        self::assertSame('api.departments.show', $result['routes'][0]['name']);
        self::assertSame(['department'], $result['routes'][0]['params']);
        self::assertTrue($result['routes'][0]['module']);
        self::assertSame(['read-departments'], $result['routes'][0]['permissions']);
        self::assertSame('api.users.index', $result['routes'][1]['name']);
        self::assertFalse($result['routes'][1]['module']);
    }

    public function testRouteCatalogRejectsDuplicateNames(): void
    {
        $routes = [
            ['name'=>'api.items.index', 'uri'=>'api/items', 'method'=>'GET', 'action'=>'Items@index'],
            ['name'=>'api.items.index', 'uri'=>'api/items', 'method'=>'GET', 'action'=>'Items@index'],
        ];
        $this->expectException(RuntimeException::class);
        \generateApiCatalog($routes);
    }

    public function testWriteCatalogGeneratorRejectsUnreviewedActions(): void
    {
        $candidate=generateWriteCatalog([
            ['name'=>'api.contacts.store','uri'=>'api/contacts','method'=>'POST',
                'action'=>'App\\Contacts@store','middleware'=>['Permission:create-sales-customers']],
            ['name'=>'api.contacts.update','uri'=>'api/contacts/{contact}','method'=>'PUT|PATCH',
                'action'=>'App\\Contacts@update'],
            ['name'=>'api.contacts.destroy','uri'=>'api/contacts/{contact}','method'=>'DELETE',
                'action'=>'App\\Contacts@destroy'],
            ['name'=>'api.contacts.untrusted','uri'=>'api/contacts/delete-everything',
                'method'=>'GET','action'=>'App\\Contacts@untrusted'],
            ['name'=>'apps.api-key.destroy','uri'=>'3/apps/api-key/2',
                'method'=>'DELETE','action'=>'App\\Secrets@destroy'],
        ]);
        self::assertCount(3,$candidate['routes']);
        self::assertSame(['PUT','PATCH'],$candidate['routes'][2]['methods']);
        self::assertSame(['create-sales-customers'],$candidate['routes'][1]['permissions']);
        $this->expectException(RuntimeException::class);
        generateWriteCatalog([
            ['name'=>'api.contacts.store','uri'=>'api/contacts','method'=>'POST','action'=>'Contact@store'],
            ['name'=>'api.contacts.store','uri'=>'api/contacts','method'=>'POST','action'=>'Contact@store'],
        ]);
    }
    public function testPublicationCheckerRejectsTransientFiles(): void
    {
        self::assertTrue(\scopeBlockedPath('docs/CONTINUITY-test.md'));
        self::assertTrue(\scopeBlockedPath('.github/workflows/unreviewed.yml'));
        self::assertTrue(\scopeBlockedPath('secrets/private.key'));
        self::assertFalse(\scopeBlockedPath('docs/DEVELOPMENT.md'));
        self::assertFalse(\scopeBlockedPath('.github/workflows/phpunit.yml'));
        self::assertNotEmpty(\scopeContentCategories('/root/' . 'projects/internal', []));
        self::assertNotEmpty(\scopeContentCategories('private' . '.librecode.coop', []));
        self::assertNotEmpty(\scopeContentCategories('10.' . '1.2.3', []));
        self::assertNotEmpty(\scopeContentCategories('synthetic-marker-value', ['synthetic-marker-value']));
        self::assertSame([], \scopeContentCategories('https://librecode.coop', []));
    }

    public function testSpdxValidatorRequiresExplicitJsonAnnotations(): void
    {
        self::assertSame('json', \reuseKind('vendor-bin/phpstan/composer.lock'));
        self::assertSame('php', \reuseKind('tools/check-release-scope.php'));
        self::assertNotSame('', \reuseLeadingHeader(
            "<?php\n// SPDX-FileCopyrightText: Example\n// SPDX-License-Identifier: MIT\n",
            'php',
        ));
        $toml = (string) file_get_contents(dirname(__DIR__, 2) . '/REUSE.toml');
        $paths = \reuseJsonAnnotations($toml);
        self::assertContains('vendor-bin/phpunit/composer.lock', $paths);
        $this->expectException(RuntimeException::class);
        \reuseJsonAnnotations(str_replace('"composer.json"', '"**"', $toml));
    }

    public function testDockerSmokeValidatorRejectsPublishedMcp(): void
    {
        $previousDir = getenv('SMOKE_DIR');
        $previousProxy = getenv('MCP_PROXY_NETWORK');
        $previousSubnet = getenv('MCP_SMOKE_BACKEND_SUBNET');
        putenv('SMOKE_DIR=/tmp/mcp-fixture');
        putenv('MCP_PROXY_NETWORK=synthetic-proxy');
        putenv('MCP_SMOKE_BACKEND_SUBNET=198.18.88.0/24');
        $data = [
            'services'=>[
                'app'=>[
                    'image'=>'akaunting-mcp:ci', 'read_only'=>true, 'ports'=>[],
                    'environment'=>['AKAUNTING_BASE_URL'=>'https://accounting.example.invalid'],
                    'volumes'=>[['type'=>'volume','target'=>'/app/var']],
                ],
                'nginx'=>['read_only'=>true, 'ports'=>[]],
                'oauth-edge'=>['read_only'=>true, 'ports'=>['8080:8080']],
            ],
            'networks'=>[
                'backend'=>['internal'=>true,'ipam'=>['config'=>[['subnet'=>'198.18.88.0/24']]]],
                'existing_proxy'=>['name'=>'synthetic-proxy'],
            ],
            'secrets'=>['mcp_token_key'=>['file'=>'/tmp/mcp-fixture/secrets/mcp_token_key']],
        ];
        try {
            $this->expectException(RuntimeException::class);
            \checkDockerSmoke('config', $data);
        } finally {
            $previousDir === false ? putenv('SMOKE_DIR') : putenv('SMOKE_DIR=' . $previousDir);
            $previousProxy === false ? putenv('MCP_PROXY_NETWORK') : putenv('MCP_PROXY_NETWORK=' . $previousProxy);
            $previousSubnet === false ? putenv('MCP_SMOKE_BACKEND_SUBNET') : putenv('MCP_SMOKE_BACKEND_SUBNET=' . $previousSubnet);
        }
    }
}
