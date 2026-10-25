<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace AkauntingMcp\Tests;

use AkauntingMcp\Config;
use AkauntingMcp\Store;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OperationsBehaviorTest extends TestCase
{
    private string $sandbox;
    private const PROJECT = __DIR__.'/../..';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir().'/mcp-operations-'.bin2hex(random_bytes(8));
        mkdir($this->sandbox, 0700, true);
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->sandbox, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink()
                ? rmdir($item->getPathname())
                : unlink($item->getPathname());
        }
        rmdir($this->sandbox);
    }

    /** @return array{int, string, string} */
    private function command(array $command, ?string $cwd = null, array $changes = []): array
    {
        $environment = [
            'PATH'=>getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'HOME'=>$this->sandbox,
        ] + $_ENV;
        $process = proc_open($command, [
            0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w'],
        ], $pipes, $cwd ?? self::PROJECT, array_replace($environment, $changes));
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        return [proc_close($process), (string) $stdout, (string) $stderr];
    }

    public function testEncryptionKeyIsExclusiveAndRestricted(): void
    {
        $path = $this->sandbox.'/key';
        [$result] = $this->command(['php',self::PROJECT.'/bin/generate-key.php', $path]);
        self::assertSame(0, $result);
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        self::assertSame(32, strlen(base64_decode(trim($contents), true)));
        self::assertSame(0600, fileperms($path) & 0777);
        [$second] = $this->command(['php',self::PROJECT.'/bin/generate-key.php', $path]);
        self::assertNotSame(0, $second, 'Existing encryption keys must never be overwritten');
        self::assertSame($contents, file_get_contents($path));
    }

    public function testKeyGenerationRejectsUnsafeTargets(): void
    {
        $path = $this->sandbox.'/missing/key';
        [$badParent] = $this->command(['php',self::PROJECT.'/bin/generate-key.php', $path]);
        self::assertNotSame(0, $badParent);
        self::assertFileDoesNotExist($path);
        $symlink = $this->sandbox.'/linked-key';
        $target = $this->sandbox.'/target';
        file_put_contents($target, 'untouched');
        symlink($target, $symlink);
        [$linked] = $this->command(['php',self::PROJECT.'/bin/generate-key.php', $symlink]);
        self::assertNotSame(0, $linked);
        self::assertSame('untouched', file_get_contents($target));
    }

    public function testAdministrativeRevokeRemovesAccessAndRefreshTokens(): void
    {
        $key = random_bytes(32);
        $keyFile = $this->sandbox.'/encryption.key';
        file_put_contents($keyFile, base64_encode($key)."\n");
        chmod($keyFile, 0600);
        $data = $this->sandbox.'/state';
        $config = new Config(
            'https://accounting.example.invalid',
            'https://accounting.example.invalid/mcp-auth',
            'https://api.openai.com/v1/mcp/tunnel_test',
            'synthetic-client',
            'https://chatgpt.com/connector/oauth/synthetic',
            $data,
            $key,
            ['localhost'],
        );
        $store = new Store($config);
        $subject = $store->saveLogin('reader@example.invalid', 'synthetic-password', 1);
        $store->state(static function (array &$db) use ($subject): void {
            $db['refresh']['synthetic-refresh'] = ['subject'=>$subject,'expires'=>time()+3600];
            $db['codes']['synthetic-code'] = ['subject'=>$subject,'expires'=>time()+3600];
        });
        $environment = [
            'AKAUNTING_BASE_URL'=>$config->akauntingUrl,
            'MCP_OAUTH_ISSUER'=>$config->issuer,
            'MCP_RESOURCE_URI'=>$config->resource,
            'MCP_OAUTH_CLIENT_ID'=>$config->oauthClientId,
            'MCP_OAUTH_REDIRECT_URI'=>$config->oauthRedirectUri,
            'MCP_DATA_DIR'=>$data,
            'MCP_TOKEN_ENCRYPTION_KEY_FILE'=>$keyFile,
            'APP_ENV'=>'testing',
        ];
        [$bad] = $this->command(['php',self::PROJECT.'/bin/revoke-user.php','reader@example.invalid'],
            null, $environment);
        self::assertNotSame(0, $bad, 'Revocation must require explicit confirmation');
        self::assertSame('reader@example.invalid', $store->load($subject)['email']);
        [$result] = $this->command(
            ['php',self::PROJECT.'/bin/revoke-user.php','--confirm','reader@example.invalid'],
            null, $environment,
        );
        self::assertSame(0, $result);
        self::assertTrue($store->isBlocked($subject));
        $remaining = $store->state(static fn(array &$db): array => $db);
        self::assertSame([], $remaining['refresh']);
        self::assertSame([], $remaining['codes']);
        $this->expectException(RuntimeException::class);
        $store->load($subject);
    }

    public static function dependencyReadiness(): array
    {
        return [
            'ready service starts the tunnel' => ['0','0','2',0,true],
            'DNS not ready times out' => ['1','0','0',1,false],
            'HTTP metadata not ready times out' => ['0','1','0',1,false],
            'invalid wait time fails safely' => ['0','0','invalid',2,false],
            'zero-padded wait behaves as a number' => ['0','0','08',0,true],
            'excessive wait is rejected' => ['0','0','3601',2,false],
        ];
    }

    #[DataProvider('dependencyReadiness')]
    public function testTunnelOnlyStartsWhenDependenciesAreReady(
        string $dns, string $http, string $wait, int $expectedExit, bool $startExpected,
    ): void {
        $bin = $this->sandbox.'/bin';
        mkdir($bin,0700);
        foreach (['getent'=>$dns, 'wget'=>$http] as $name=>$status) {
            $script = "#!/bin/sh\nexit ".$status."\n";
            file_put_contents($bin.'/'.$name, $script);
            chmod($bin.'/'.$name,0700);
        }
        file_put_contents($bin.'/tunnel-client', "#!/bin/sh\nprintf 'started:%s\\n' \"\$*\"\n");
        chmod($bin.'/tunnel-client',0700);
        [$code,$out] = $this->command(
            ['sh',self::PROJECT.'/docker/tunnel/wait-for-mcp.sh','--synthetic'],
            null,
            ['PATH'=>$bin.':'.(getenv('PATH') ?: '/usr/bin:/bin'),
                'MCP_DEPENDENCY_WAIT_SECONDS'=>$wait],
        );
        self::assertSame($expectedExit, $code);
        self::assertSame($startExpected, str_contains($out,'started:'));
    }

    public function testTunnelCredentialInstallerNeverAcceptsNonInteractiveOrOverwriting(): void
    {
        $root = $this->sandbox.'/checkout';
        mkdir($root.'/bin',0700,true);
        copy(self::PROJECT.'/bin/save-runtime-key.sh', $root.'/bin/save-runtime-key.sh');
        [$result] = $this->command(['bash','bin/save-runtime-key.sh'], $root);
        self::assertNotSame(0, $result);
        self::assertFileDoesNotExist($root.'/secrets/openai_tunnel_runtime_api_key');

        file_put_contents($root.'/secrets/openai_tunnel_runtime_api_key','must-not-replace');
        [$again] = $this->command(['bash','bin/save-runtime-key.sh'], $root);
        self::assertNotSame(0, $again);
        self::assertSame('must-not-replace', file_get_contents($root.'/secrets/openai_tunnel_runtime_api_key'));
    }

    public function testTunnelCredentialCanOnlyBeInstalledOnceFromInteractiveSession(): void
    {
        $root = $this->sandbox.'/interactive-checkout';
        mkdir($root.'/bin',0700,true);
        copy(self::PROJECT.'/bin/save-runtime-key.sh', $root.'/bin/save-runtime-key.sh');
        $token = 'sk-'.str_repeat('S', 36); // Synthetic token, never a real credential.
        $process = proc_open(['bash','bin/save-runtime-key.sh'], [
            0=>['pty'], 1=>['pipe','w'], 2=>['pipe','w'],
        ], $pipes, $root);
        self::assertIsResource($process);
        // Wait until Bash has disabled terminal echo and is ready to read.
        // Sending a token before the prompt could echo it into the terminal.
        stream_set_blocking($pipes[2],false);
        $prompt='';
        $deadline=microtime(true)+5;
        do {
            $read=[$pipes[2]];
            $write=null;
            $exception=null;
            if (stream_select($read,$write,$exception,0,200000)>0) {
                $prompt.=(string)fread($pipes[2],4096);
            }
        } while (!str_contains($prompt,'entrada oculta') && microtime(true)<$deadline);
        if (!str_contains($prompt,'entrada oculta')) {
            proc_terminate($process);
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            self::fail('Interactive tunnel key prompt was not ready');
        }
        stream_set_blocking($pipes[2],true);
        self::assertSame(strlen($token) + 1, fwrite($pipes[0], $token."\n"));
        fflush($pipes[0]);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string)$stdout.' '.(string)$stderr);
        $path = $root.'/secrets/openai_tunnel_runtime_api_key';
        self::assertSame($token."\n", file_get_contents($path));
        self::assertSame(0600, fileperms($path) & 0777);
    }

    public function testSyntheticBenchmarkCompletesWithoutProductionServices(): void
    {
        [$status, $output] = $this->command(['php',self::PROJECT.'/tools/benchmark-local.php']);
        self::assertSame(0, $status);
        $data = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        self::assertTrue($data['synthetic']);
        self::assertSame('microseconds', $data['unit']);
        self::assertArrayHasKey('company_read_without_http', $data['components']);
        foreach ($data['components'] as $measurement) {
            self::assertGreaterThanOrEqual(0.0, $measurement['p50_us']);
            self::assertGreaterThanOrEqual($measurement['p50_us'], $measurement['p95_us']);
        }
    }

    public function testChecksumFailurePreventsExecutingUnverifiedActionlint(): void
    {
        $bin = $this->sandbox.'/download-bin';
        mkdir($bin,0700);
        // Simulate an upstream archive whose hash is different from the pinned checksum.
        file_put_contents($bin.'/curl', <<<'SH'
#!/bin/sh
previous=''
for value in "$@"; do
    if [ "$previous" = '--output' ]; then
        printf 'unsigned synthetic content' > "$value"
        exit 0
    fi
    previous=$value
done
exit 2
SH
        );
        chmod($bin.'/curl', 0700);
        [$status] = $this->command(['sh',self::PROJECT.'/tools/check-actionlint.sh'],
            self::PROJECT,
            ['PATH'=>$bin.':'.(getenv('PATH') ?: '/usr/bin:/bin')]);
        self::assertNotSame(0, $status, 'Checksum failure must block third-party execution');
    }

    public function testCoverageGateFailsWhenBehaviorCoverageRegresses(): void
    {
        $file = $this->sandbox.'/coverage.xml';
        file_put_contents($file, '<coverage><project><metrics statements="100" coveredstatements="79"/></project></coverage>');
        [$valid] = $this->command(['php',self::PROJECT.'/tools/check-coverage.php',$file,'77']);
        self::assertSame(0,$valid);
        [$low] = $this->command(['php',self::PROJECT.'/tools/check-coverage.php',$file,'80']);
        self::assertSame(1,$low);
        [$badThreshold] = $this->command(['php',self::PROJECT.'/tools/check-coverage.php',$file,'101']);
        self::assertSame(2,$badThreshold);
        file_put_contents($file,'not a Clover report');
        [$corrupt] = $this->command(['php',self::PROJECT.'/tools/check-coverage.php',$file,'77']);
        self::assertSame(2,$corrupt);
    }

    public function testDcoAndCommitTitlesAreValidatedIndependently(): void
    {
        $repo = $this->sandbox.'/repository';
        mkdir($repo,0700);
        [$init] = $this->command(['git','init','-q',$repo]);
        self::assertSame(0,$init);
        $git = fn(array $args): array => $this->command([
            'git','-c','user.name=Synthetic Dev','-c','user.email=dev@example.invalid',
            '-c','commit.gpgsign=false',...$args
        ], $repo);
        [$baseline] = $git(['commit','--allow-empty','-m','chore: initial synthetic fixture',
            '-m','Signed-off-by: Synthetic Dev <dev@example.invalid>']);
        self::assertSame(0,$baseline);
        [$sha,$base] = $git(['rev-parse','HEAD']);
        self::assertSame(0,$sha);
        [$valid] = $git(['commit','--allow-empty','-m','fix(auth): reject cross-company access',
            '-m','Signed-off-by: Synthetic Dev <dev@example.invalid>']);
        self::assertSame(0,$valid);
        foreach (['--dco','--conventional'] as $policy) {
            [$code] = $this->command(['sh',self::PROJECT.'/tools/check-commits.sh',$policy,trim($base),'HEAD'],$repo);
            self::assertSame(0,$code, $policy.' must accept a compliant contribution');
        }
        [$withoutDco] = $git(['commit','--allow-empty','-m','fix(auth): deny a revoked identity']);
        self::assertSame(0,$withoutDco);
        [$deniedDco] = $this->command(['sh',self::PROJECT.'/tools/check-commits.sh','--dco',trim($base),'HEAD'],$repo);
        self::assertNotSame(0,$deniedDco);
        [$badTitle] = $git(['commit','--allow-empty','-m','Some unstructured title',
            '-m','Signed-off-by: Synthetic Dev <dev@example.invalid>']);
        self::assertSame(0,$badTitle);
        [$deniedTitle] = $this->command(['sh',self::PROJECT.'/tools/check-commits.sh','--conventional',trim($base),'HEAD'],$repo);
        self::assertNotSame(0,$deniedTitle);
    }
}
