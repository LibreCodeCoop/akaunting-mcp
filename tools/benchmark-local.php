<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

use AkauntingMcp\Api;
use AkauntingMcp\Config;
use AkauntingMcp\Mcp;
use AkauntingMcp\OAuth;
use AkauntingMcp\Store;
use AkauntingMcp\Toolset;

require dirname(__DIR__).'/src/bootstrap.php';

// Benchmark synthetic-only CPU/serialization costs, not upstream or tunnel latency.
final class SyntheticBenchmarkApi extends Api
{
    public function get(string $email, string $password, string $path, array $query = []): array
    {
        if ($email !== 'synthetic@example.invalid' || $password !== 'synthetic-password') {
            throw new RuntimeException('Synthetic authentication rejected');
        }
        if (($query['company_id'] ?? 1) !== 1) {
            throw new RuntimeException('Synthetic company rejected');
        }
        if ($path === '/companies/1/owner') {
            return [];
        }
        if ($path === '/categories') {
            return ['data'=>[['id'=>5,'company_id'=>1,'name'=>'Synthetic category']]];
        }
        throw new RuntimeException('Unexpected benchmark endpoint');
    }
}

/** @return array{p50_us:float,p95_us:float} */
function measure(callable $callback, int $iterations = 1000): array
{
    for ($i=0; $i<25; $i++) {
        $callback();
    }
    $measurements=[];
    for ($i=0; $i<$iterations; $i++) {
        $start=hrtime(true);
        $callback();
        $measurements[]=(hrtime(true)-$start)/1000;
    }
    sort($measurements,SORT_NUMERIC);
    return [
        'p50_us'=>round($measurements[intdiv($iterations,2)],2),
        'p95_us'=>round($measurements[(int)floor($iterations*0.95)],2),
    ];
}

$privateDir = sys_get_temp_dir().'/akaunting-benchmark-'.bin2hex(random_bytes(8));
mkdir($privateDir,0700);
try {
    $config = new Config('https://accounting.example.invalid','https://accounting.example.invalid/mcp-auth',
        'https://api.openai.com/v1/mcp/tunnel_synthetic','synthetic-client',
        'https://chatgpt.com/connector/oauth/synthetic',$privateDir,random_bytes(32),['localhost']);
    $store = new Store($config);
    $subject = $store->saveLogin('synthetic@example.invalid','synthetic-password',1);
    $api = new SyntheticBenchmarkApi($config);
    $mcp = new Mcp(new Toolset($store,$api),
        'https://api.openai.com/.well-known/oauth-protected-resource/v1/mcp/tunnel_synthetic');
    $oauth = new OAuth($config,$store,$api);
    $now=time();
    $jwt=OAuth::jwt(['iss'=>$config->issuer,'aud'=>$config->resource,'sub'=>$subject,
        'scope'=>'mcp:read','token_use'=>'access','client_id'=>$config->oauthClientId,
        'generation'=>$store->generation($subject),'iat'=>$now,'nbf'=>$now,
        'exp'=>$now+600,'jti'=>'synthetic'], $config->encryptionKey);
    $resource = json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call',
        'params'=>['name'=>'akaunting_resources','arguments'=>new stdClass()]],JSON_THROW_ON_ERROR);
    $category = json_encode(['jsonrpc'=>'2.0','id'=>2,'method'=>'tools/call',
        'params'=>['name'=>'akaunting_list_records','arguments'=>['company_id'=>1,'resource'=>'categories','limit'=>1]]],
        JSON_THROW_ON_ERROR);
    $tools = json_encode(['jsonrpc'=>'2.0','id'=>3,'method'=>'tools/list'],JSON_THROW_ON_ERROR);
    $metrics=[
        'vault_decrypt'=>measure(static fn()=> $store->load($subject)),
        'jwt_check'=>measure(static fn()=> $oauth->bearer('Bearer '.$jwt)),
        'tools_list'=>measure(static fn()=> $mcp->execute($subject,$tools)),
        'resources_tool'=>measure(static fn()=> $mcp->execute($subject,$resource)),
        'company_read_without_http'=>measure(static fn()=> $mcp->execute($subject,$category)),
    ];
    echo json_encode(['iterations'=>1000,'unit'=>'microseconds','synthetic'=>true,
        'components'=>$metrics],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($privateDir,
        FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($privateDir);
}
