<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

/**
 * Fail closed when the staged public distribution contains deployment
 * identifiers or unexpected documentation/workflows. No Composer dependency.
 *
 * Both Git index bytes and working tree bytes are checked. Optional private
 * name markers must live outside this repository and are never printed.
 */
const RELEASE_ROOT = __DIR__ . '/..';
const PUBLIC_DOCUMENTS = [
    'docs/API-READ-CONTRACT.md',
    'docs/ARCHITECTURE.md',
    'docs/CONNECTING-CHATGPT.md',
    'docs/DEPLOY.md',
    'docs/DEVELOPMENT.md',
    'docs/LICENSING.md',
    'docs/SECURITY.md',
];
const PUBLIC_GITHUB = [
    '.github/dependabot.yml',
    '.github/workflows/actionlint.yml',
    '.github/workflows/commits.yml',
    '.github/workflows/composer-audit.yml',
    '.github/workflows/dco.yml',
    '.github/workflows/docker-smoke.yml',
    '.github/workflows/php-lint.yml',
    '.github/workflows/php-style.yml',
    '.github/workflows/phpstan.yml',
    '.github/workflows/phpunit.yml',
    '.github/workflows/reuse.yml',
    '.github/workflows/scope.yml',
    '.github/workflows/secrets.yml',
    '.github/workflows/shellcheck.yml',
    '.github/workflows/zizmor.yml',
];

function scopeBlockedPath(string $relative): bool
{
    $parts = explode('/', $relative);
    $name = (string) end($parts);
    if (($parts[0] === 'docs' && !in_array($relative, PUBLIC_DOCUMENTS, true))
        || ($parts[0] === '.github' && !in_array($relative, PUBLIC_GITHUB, true))
        || count(array_intersect($parts, ['integration-lab', 'secrets', 'var', 'private-evidence', 'private-deployment'])) > 0) {
        return true;
    }
    if (str_starts_with($name, '.env') && $name !== '.env.example') {
        return true;
    }
    if (in_array(strtolower((string) pathinfo($name, PATHINFO_EXTENSION)), [
        'key', 'pem', 'p12', 'pfx', 'sql', 'sqlite', 'db', 'dump', 'bak', 'backup', 'log',
    ], true)) {
        return true;
    }
    return $parts[0] === 'docs'
        && preg_match('~continuity|readiness|local-poc|quality-report|deployment-checkpoint~i', $name) === 1;
}

/** @return list<string> */
function scopeContentCategories(string $text, array $markers): array
{
    $patterns = [
        'machine-specific project path' => '~/(?:root|home/[^/]+)/' . 'projects/~i',
        'internal organization subdomain' => '~\\b(?:[a-z0-9-]+\\.)+librecode\\.coop\\b~i',
        'private IPv4' => '~\\b(?:10\\.(?:\\d{1,3}\\.){2}\\d{1,3}|192\\.168\\.(?:\\d{1,3}\\.)\\d{1,3}|172\\.(?:1[6-9]|2\\d|3[01])\\.(?:\\d{1,3}\\.)\\d{1,3})\\b~',
        'internal DNS' => '~\\b[a-z0-9-]+\\.(?:internal|lan)\\b~i',
        'deployment verification prose' => '~\\b(?:existing[ ]deployment[ ]confirmed|checks[ ]on[ ]the[ ]existing[ ]deployment|running[ ]production[ ]server|on[ ]our[ ]production[ ]server)\\b~i',
    ];
    $found = [];
    foreach ($patterns as $category => $pattern) {
        if (preg_match($pattern, $text) === 1) {
            $found[] = $category;
        }
    }
    foreach ($markers as $marker) {
        if ($marker !== '' && stripos($text, $marker) !== false) {
            $found[] = 'private marker';
            break;
        }
    }
    return $found;
}

/** @return list<string> */
function scopeLoadMarkers(?string $filename): array
{
    if ($filename === null || $filename === '') {
        return [];
    }
    $root = realpath(RELEASE_ROOT);
    $resolved = realpath($filename);
    if ($root === false || $resolved === false || !is_file($resolved)
        || $resolved === $root || str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Private marker file must exist outside the source tree');
    }
    $lines = file($resolved, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException('Cannot open private marker file');
    }
    return array_values(array_filter(array_map('trim', $lines),
        static fn(string $line): bool => $line !== '' && !str_starts_with($line, '#')));
}

/** Run git without invoking a shell so filenames never become shell code. */
function scopeGit(array $args): string
{
    $process = proc_open(['git', ...$args], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, RELEASE_ROOT);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start Git');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    // Never print Git stderr, which could contain identifying data.
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || !is_string($output)) {
        throw new RuntimeException('Git operation failed');
    }
    return $output;
}

/** @return list<string> */
function scopeTrackedPaths(): array
{
    return array_values(array_filter(explode("\0", scopeGit(['ls-files', '--cached', '-z'])),
        static fn(string $path): bool => $path !== ''));
}

/** @return list<array{string, string}> */
function scopeCheck(array $markers): array
{
    $failures = [];
    foreach (scopeTrackedPaths() as $relative) {
        $path = RELEASE_ROOT . '/' . $relative;
        if (scopeBlockedPath($relative)) {
            $failures[] = [$relative, 'file type or private directory'];
            continue;
        }
        if (is_link($path)) {
            $failures[] = [$relative, 'tracked symlink requires provenance review'];
            continue;
        }
        if (!is_file($path)) {
            $failures[] = [$relative, 'tracked file missing in workspace'];
            continue;
        }
        if (filesize($path) > 5000000) {
            $failures[] = [$relative, 'large file requires manual review'];
            continue;
        }
        $index = scopeGit(['show', ':' . $relative]);
        if (strlen($index) > 5000000) {
            $failures[] = [$relative, 'oversized staged content'];
            continue;
        }
        $workspace = file_get_contents($path);
        if ($workspace === false) {
            $failures[] = [$relative, 'file cannot be read'];
            continue;
        }
        foreach (scopeContentCategories($index, $markers) as $category) {
            $failures[] = [$relative, 'index: ' . $category];
        }
        if ($workspace !== $index) {
            foreach (scopeContentCategories($workspace, $markers) as $category) {
                $failures[] = [$relative, 'workspace: ' . $category];
            }
        }
    }

    foreach (['docs', '.github'] as $folder) {
        $base = RELEASE_ROOT . '/' . $folder;
        if (!is_dir($base)) {
            continue;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $relative = $folder . '/' . substr($file->getPathname(), strlen($base) + 1);
            if ($file->isFile() && scopeBlockedPath(str_replace('\\', '/', $relative))) {
                $failures[] = [$relative, 'unapproved public-area file'];
            }
        }
    }
    if (file_exists(RELEASE_ROOT . '/integration-lab')) {
        $failures[] = ['integration-lab/', 'operational evidence inside source tree'];
    }
    return $failures;
}

function scopeSelfTest(): void
{
    $valid = [
        scopeBlockedPath('docs/CONTINUITY-sample.md'),
        scopeBlockedPath('integration-lab/fixture.txt'),
        scopeBlockedPath('.env.deploy'),
        !scopeBlockedPath('.env.example'),
        !scopeBlockedPath('docs/ARCHITECTURE.md'),
        scopeBlockedPath('docs/NEW-REPORT.md'),
        scopeBlockedPath('.github/workflows/debug.yml'),
        !scopeBlockedPath('.github/workflows/dco.yml'),
        count(scopeContentCategories('/root/' . 'projects/test', [])) > 0,
        count(scopeContentCategories('billing' . '.librecode.coop', [])) > 0,
        count(scopeContentCategories('192.168.' . '1.3', [])) > 0,
        scopeContentCategories('https://librecode.coop', []) === [],
    ];
    if (in_array(false, $valid, true)) {
        throw new RuntimeException('Scope checker self-test failed');
    }
    echo "Scope checker self-test: PASS\n";
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        $arguments = $_SERVER['argv'] ?? [];
        if (count($arguments) > 2 || (count($arguments) === 2 && $arguments[1] !== '--self-test')) {
            throw new InvalidArgumentException('Usage: php tools/check-release-scope.php [--self-test]');
        }
        if (count($arguments) === 2) {
            scopeSelfTest();
            exit(0);
        }
        $markers = scopeLoadMarkers(getenv('MCP_PUBLICATION_PRIVATE_MARKERS') ?: null);
        $issues = scopeCheck($markers);
        foreach ($issues as [$file, $reason]) {
            // Never print source text or private marker matches.
            fwrite(STDERR, "BLOCKED: {$file} ({$reason})\n");
        }
        if ($issues !== []) {
            fwrite(STDERR, 'Release scope check FAILED: ' . count($issues) . " findings\n");
            exit(1);
        }
        echo 'Release scope check PASS: ' . count(scopeTrackedPaths()) . " tracked files reviewed\n";
    } catch (Throwable $error) {
        fwrite(STDERR, 'Scope check could not complete: ' . get_class($error) . "\n");
        exit(2);
    }
}
