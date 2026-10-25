<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

use AkauntingMcp\Api;
use AkauntingMcp\Config;
use AkauntingMcp\OAuth;
use AkauntingMcp\Store;

require dirname(__DIR__).'/src/bootstrap.php';
ini_set('display_errors','0');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; style-src 'unsafe-inline'");

try {
    $config = Config::fromEnvironment();
    $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '',PHP_URL_PATH);
    $issuerPath = (string)parse_url($config->issuer,PHP_URL_PATH);
    $issuerHost = strtolower((string)parse_url($config->issuer,PHP_URL_HOST));
    $hostValue = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $hostParts = parse_url('https://'.$hostValue);
    $host = is_array($hostParts) ? ($hostParts['host'] ?? '') : '';
    $hostPort = is_array($hostParts) ? ($hostParts['port'] ?? null) : null;
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    // No public arbitrary host aliases, no generic MCP or internal token proxy.
    if ($host !== $issuerHost || ($hostPort !== null && $hostPort !== 443)) {
        http_response_code(421);
        exit;
    }
    $oauth = new OAuth($config,new Store($config),new Api($config));
    if ($path === '/.well-known/oauth-authorization-server'.$issuerPath && $method === 'GET') {
        header('Content-Type: application/json');
        echo json_encode($oauth->metadata(),JSON_THROW_ON_ERROR);
        exit;
    }
    if ($path === $issuerPath.'/authorize' && $method === 'GET') {
        $flow = $oauth->start($_GET, (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        setcookie('akaunting_mcp_flow', $flow['ticket'], [
            'expires'=>time()+300, 'path'=>$issuerPath, 'secure'=>true,
            'httponly'=>true, 'samesite'=>'Lax',
        ]);
        header('Content-Type: text/html; charset=utf-8');
        $ticket = htmlspecialchars($flow['ticket'],ENT_QUOTES,'UTF-8');
        $csrf = htmlspecialchars($flow['csrf'],ENT_QUOTES,'UTF-8');
        $action = htmlspecialchars($issuerPath.'/login',ENT_QUOTES,'UTF-8');
        echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>Conectar sua conta ao Akaunting</title>';
        echo '<style>';
        echo ':root{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#1d2939;background:#f5f7fa;font-size:16px}';
        echo 'body{min-height:100vh;margin:0;display:grid;place-items:center;padding:22px;box-sizing:border-box}';
        echo '.panel{box-sizing:border-box;width:min(100%,460px);background:#fff;border:1px solid #eaecf0;border-radius:16px;padding:34px;box-shadow:0 12px 38px #1018280d}';
        echo '.brand{font-size:.875rem;font-weight:700;color:#344054;letter-spacing:.02em;margin-bottom:24px}';
        echo 'h1{font-size:1.75rem;line-height:1.2;margin:0 0 12px}p{color:#475467;line-height:1.55;margin:0 0 25px}';
        echo 'label{display:block;font-size:.9rem;font-weight:600;margin:18px 0 7px}';
        echo 'input{box-sizing:border-box;display:block;width:100%;padding:11px 13px;font:inherit;border:1px solid #98a2b3;border-radius:8px;background:white;color:#101828}';
        echo 'input:focus{outline:3px solid #d1e9ff;outline-offset:1px;border-color:#1570ef}';
        echo '.help{font-size:.8rem;color:#667085;line-height:1.45;margin:7px 0 20px}';
        echo 'button{width:100%;border:0;border-radius:8px;background:#175cd3;color:#fff;font:600 1rem system-ui;padding:13px;cursor:pointer}';
        echo 'button:hover{background:#1849a9}button:focus-visible{outline:3px solid #84caff;outline-offset:2px}';
        echo '.footer{font-size:.78rem;color:#667085;text-align:center;margin:17px 0 0}@media(max-width:480px){.panel{padding:24px}}';
        echo '</style></head><body><main class="panel"><div class="brand">LibreCode · Akaunting</div>';
        echo '<h1>Conectar sua conta</h1><p>Use os dados da sua conta no Akaunting. A integração respeita suas permissões e permanece somente leitura.</p>';
        echo '<form method="post" action="'.$action.'">';
        echo '<input type="hidden" name="ticket" value="'.$ticket.'">';
        echo '<input type="hidden" name="csrf" value="'.$csrf.'">';
        echo '<label for="login-email">E-mail</label><input id="login-email" type="email" name="email" autocomplete="username" required maxlength="254">';
        echo '<label for="login-password">Senha</label><input id="login-password" type="password" name="password" autocomplete="current-password" required>';
        echo '<label for="company-id">ID da empresa</label><input id="company-id" type="number" min="1" max="2147483647" name="company_id" inputmode="numeric" required>';
        echo '<div class="help">Informe o ID numérico da empresa à qual você tem acesso no Akaunting.</div>';
        echo '<button type="submit">Conectar ao Akaunting</button></form>';
        echo '<p class="footer">Sua senha é transmitida por HTTPS e protegida no servidor privado da integração.</p></main></body></html>';
        exit;
    }
    if ($path === $issuerPath.'/login' && $method === 'POST') {
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) throw new RuntimeException('Login request too large');
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        $expected = parse_url($config->issuer, PHP_URL_SCHEME).'://'.$issuerHost;
        if ($origin !== null && $origin !== $expected) {
            // Embedded browsers can submit the form with an opaque or
            // host-app Origin. Do not trust that Origin; instead require the
            // exact host-only Secure/SameSite cookie and one-time form ticket.
            // OAuth::login() then verifies the independent server-side CSRF
            // secret, ticket expiration, attempt limits and upstream account.
            $ticket = $_POST['ticket'] ?? null;
            $cookie = $_COOKIE['akaunting_mcp_flow'] ?? null;
            $csrf = $_POST['csrf'] ?? null;
            if (!is_string($ticket) || !is_string($cookie) || !is_string($csrf)
                || $ticket === '' || $cookie === '' || $csrf === ''
                || !hash_equals($cookie, $ticket)) {
                throw new RuntimeException('Invalid login origin');
            }
        }
        foreach (['ticket','csrf','email','password'] as $key) {
            if (!is_string($_POST[$key] ?? null)) throw new RuntimeException('Invalid sign-in form');
        }
        $company = filter_var($_POST['company_id'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($company)) throw new RuntimeException('Invalid company');
        $url = $oauth->login($_POST['ticket'],$_POST['csrf'],(string)($_COOKIE['akaunting_mcp_flow'] ?? ''),
            $_POST['email'],$_POST['password'],$company,(string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        setcookie('akaunting_mcp_flow','',['expires'=>time()-3600,'path'=>$issuerPath,'secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
        header('Location: '.$url,true,303);
        exit;
    }
    if ($path === $issuerPath.'/token' && $method === 'POST') {
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) throw new RuntimeException('Token request too large');
        header('Content-Type: application/json');
        echo json_encode($oauth->token($_POST),JSON_THROW_ON_ERROR);
        exit;
    }
    http_response_code(404);
} catch (\Throwable $error) {
    // Browser login errors are not OAuth protocol errors. Classify known
    // failures without logging credentials, identities, cookies or tokens.
    if (($method ?? '') === 'POST' && ($path ?? '') === ($issuerPath ?? '').'/login') {
        $detail = $error->getMessage();
        $category = 'internal_failure';
        $message = 'Não foi possível concluir a conexão. Reinicie o processo de autenticação pelo ChatGPT.';
        if ($detail === 'Invalid browser OAuth session') {
            $category = empty($_COOKIE['akaunting_mcp_flow']) ? 'missing_session_cookie' : 'invalid_session_cookie';
            $message = 'A sessão de login não foi reconhecida. Reinicie a conexão pelo ChatGPT e permita cookies para o endereço do Akaunting.';
        } elseif ($detail === 'OAuth login expired or invalid') {
            $category = 'expired_or_invalid_session';
            $message = 'A sessão de login expirou ou já foi utilizada. Inicie uma nova conexão pelo ChatGPT.';
        } elseif ($detail === 'Invalid login origin') {
            $category = 'invalid_origin';
            $message = 'A origem do envio não é permitida. Abra o formulário novamente a partir do ChatGPT.';
        } elseif (in_array($detail, ['Invalid company', 'Invalid company ID'], true)) {
            $category = 'invalid_company';
            $message = 'Informe um ID de empresa válido e maior que zero.';
        } elseif ($detail === 'Invalid sign-in form' || $detail === 'Login request too large') {
            $category = 'invalid_form';
            $message = 'O formulário não pôde ser validado. Reabra a conexão pelo ChatGPT.';
        } elseif ($detail === 'OAuth login rate limit' || $detail === 'Too many sign-in attempts') {
            $category = 'rate_limit';
            $message = 'Muitas tentativas de acesso. Aguarde alguns minutos e reinicie o login pelo ChatGPT.';
        } elseif (preg_match('/^Akaunting request rejected \\(HTTP ([0-9]{3}|unavailable)\\)$/D', $detail, $matches)) {
            $category = 'upstream_http_'. $matches[1];
            $message = 'A API do Akaunting não autorizou o acesso. Confira e-mail, senha, ID da empresa e a permissão read-api da sua conta.';
        } elseif ($detail === 'Personal Akaunting authentication failed') {
            $category = 'upstream_unexpected_ping';
            $message = 'A API não confirmou sua autenticação. Confira suas credenciais e permissões no Akaunting.';
        } elseif ($detail === 'Invalid login' || $detail === 'Missing personal credentials') {
            $category = 'invalid_credentials_format';
            $message = 'Confira os dados informados e reinicie a conexão pelo ChatGPT.';
        }
        error_log('Akaunting MCP browser login rejected category='.$category.' class='.get_class($error));
        http_response_code(400);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>Não foi possível conectar ao Akaunting</title>';
        echo '<style>body{font:16px/1.6 system-ui,-apple-system,sans-serif;background:#f6f7fa;color:#1b2636;margin:0;padding:5vh 18px}main{max-width:500px;margin:auto;background:white;padding:32px;border-radius:14px;box-shadow:0 8px 35px #14243d12}h1{font-size:1.5rem;line-height:1.3}p{overflow-wrap:anywhere}a{color:#175cd3}</style></head>';
        echo '<body><main><h1>Não foi possível conectar ao Akaunting</h1><p>'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</p>';
        echo '<p>Por segurança, inicie uma nova tentativa de conexão no ChatGPT.</p>';
        echo '<p><a href="https://chatgpt.com/">Voltar ao ChatGPT</a></p></main></body></html>';
        exit;
    }
    // Authorization diagnostics record ONLY mismatching field names. Never log
    // OAuth request values, redirect URLs, state, PKCE, codes or credentials.
    if (isset($config, $oauth, $issuerPath) && ($method ?? '') === 'GET'
        && ($path ?? '') === $issuerPath.'/authorize') {
        $expected = [
            'response_type' => ($_GET['response_type'] ?? null) === 'code',
            'client_id' => is_string($_GET['client_id'] ?? null)
                && hash_equals($config->oauthClientId, $_GET['client_id']),
            'redirect_uri' => ($_GET['redirect_uri'] ?? null) === $config->oauthRedirectUri,
            'scope' => ($_GET['scope'] ?? null) === 'mcp:read',
            'resource' => !array_key_exists('resource', $_GET) || $_GET['resource'] === $config->resource,
            'code_challenge_method' => ($_GET['code_challenge_method'] ?? null) === 'S256',
            'code_challenge' => is_string($_GET['code_challenge'] ?? null)
                && preg_match('/^[a-zA-Z0-9_-]{43}$/D', $_GET['code_challenge']) === 1,
            'state' => is_string($_GET['state'] ?? null)
                && strlen($_GET['state']) >= 16 && strlen($_GET['state']) <= 512,
        ];
        $failed = array_keys(array_filter($expected, static fn(bool $ok): bool => !$ok));
        error_log('Akaunting MCP authorization reject invalid_fields='
            . ($failed === [] ? 'none' : implode(',', $failed))
            . ' exception='.get_class($error));
    } else {
        error_log('Akaunting MCP broker rejected request: '.get_class($error));
    }
    if (isset($oauth, $method, $path, $issuerPath) && $method === 'GET' && $path === $issuerPath.'/authorize') {
        try {
            header('Location: '.$oauth->authorizationError($_GET), true, 302);
            exit;
        } catch (\Throwable) {
            // Do not redirect requests with an unregistered client or callback.
        }
    }
    http_response_code(400);
    header('Content-Type: application/json');
    echo '{"error":"invalid_request"}';
}
