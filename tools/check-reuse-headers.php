<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

/**
 * Enforce real SPDX headers on commentable files, and exact REUSE.toml
 * annotations for JSON. Run in addition to the official reuse lint.
 */
const REUSE_ROOT = __DIR__ . '/..';

function reuseKind(string $file): string
{
    $name = basename($file);
    if (str_starts_with($file, 'LICENSES/') || preg_match('~^(?:LICENSE|LICENCE|COPYING)(?:[.-].+)?$~', $file)) {
        return 'excluded';
    }
    if (str_ends_with($file, '.json') || $name === 'composer.lock') {
        return 'json';
    }
    if (str_ends_with($file, '.md')) {
        return 'markdown';
    }
    if (str_ends_with($file, '.xml.dist') || str_ends_with($file, '.xml')) {
        return 'xml';
    }
    if (str_ends_with($file, '.php')) {
        return 'php';
    }
    foreach (['.sh', '.yml', '.yaml', '.toml', '.conf', '.neon.dist', '.conf.example'] as $suffix) {
        if (str_ends_with($file, $suffix)) {
            return 'hash';
        }
    }
    return in_array($name, ['Dockerfile', '.gitignore', '.dockerignore', '.env.example'], true)
        ? 'hash' : 'unknown';
}

function reuseLeadingHeader(string $content, string $kind): string
{
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
    if ($kind === 'markdown') {
        return preg_match('~^\s*<!--(.*?)-->~s', $content, $matches) === 1 ? $matches[1] : '';
    }
    if ($kind === 'xml') {
        return preg_match('~^\s*(?:<\?xml[^\n]*\?>\s*)?((?:<!--.*?-->\s*)+)~s', $content, $matches) === 1
            ? $matches[1] : '';
    }

    $lines = explode("\n", $content);
    if ($kind === 'php') {
        if ($lines[0] !== '<?php') {
            return '';
        }
        array_shift($lines);
        $prefixes = ['//', '#', '/*', '*', '*/'];
    } else {
        if (str_starts_with($lines[0], '#!')) {
            array_shift($lines);
        }
        $prefixes = ['#'];
    }
    $header = [];
    foreach ($lines as $line) {
        $trim = trim($line);
        if ($trim === '') {
            $header[] = $line;
            continue;
        }
        $comment = false;
        foreach ($prefixes as $prefix) {
            if (str_starts_with($trim, $prefix)) {
                $comment = true;
                break;
            }
        }
        if (!$comment) {
            break;
        }
        $header[] = $line;
    }
    return implode("\n", $header);
}

/** @return list<string> */
function reuseTrackedFiles(): array
{
    $proc = proc_open(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, REUSE_ROOT);
    if (!is_resource($proc)) {
        throw new RuntimeException('Cannot inspect tracked source');
    }
    fclose($pipes[0]);
    $data = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0 || !is_string($data)) {
        throw new RuntimeException('Cannot inspect repository');
    }
    $paths = array_values(array_filter(explode("\0", $data), static fn(string $x): bool => $x !== ''));
    sort($paths);
    return $paths;
}

/**
 * Parse only the explicit JSON paths in the repository's intentionally small
 * REUSE annotation block. Reject wildcard/implicit or broad file annotations.
 *
 * @return list<string>
 */
function reuseJsonAnnotations(string $toml): array
{
    if (preg_match_all('/^\[\[annotations\]\]$/m', $toml, $blocks) !== 1) {
        throw new RuntimeException('Expected exactly one explicit REUSE annotation block');
    }
    $block = explode('[[annotations]]', $toml, 2)[1];
    if (preg_match('/^path\s*=\s*\[(.*?)\]/ms', $block, $found) !== 1) {
        throw new RuntimeException('REUSE annotation paths must be an explicit array');
    }
    if (preg_match_all('/"([^"]+)"/', $found[1], $quoted) === false) {
        throw new RuntimeException('Invalid annotation path list');
    }
    $paths = $quoted[1];
    $rest = preg_replace('/"[^"]+"|[,\s]/', '', $found[1]);
    if ($rest !== '' || $paths === []) {
        throw new RuntimeException('Unrecognized or empty REUSE annotation paths');
    }
    if (preg_match('/^precedence\s*=\s*"aggregate"$/m', $block) !== 1
        || preg_match('/^SPDX-FileCopyrightText\s*=\s*"[^"]+"$/m', $block) !== 1
        || preg_match('/^SPDX-License-Identifier\s*=\s*"[^"]+"$/m', $block) !== 1) {
        throw new RuntimeException('Missing aggregate attribution fields');
    }
    foreach ($paths as $path) {
        if (strpbrk($path, '*?[') !== false || reuseKind($path) !== 'json') {
            throw new RuntimeException('REUSE annotations must list exact JSON filenames only');
        }
    }
    if (count($paths) !== count(array_unique($paths))) {
        throw new RuntimeException('Duplicate JSON REUSE annotation');
    }
    return $paths;
}

function reuseVerify(): int
{
    $toml = file_get_contents(REUSE_ROOT . '/REUSE.toml');
    if ($toml === false) {
        throw new RuntimeException('Missing REUSE.toml');
    }
    $annotations = reuseJsonAnnotations($toml);
    $paths = reuseTrackedFiles();
    $jsonPaths = [];
    $checked = 0;
    $errors = [];

    foreach ($paths as $name) {
        $path = REUSE_ROOT . '/' . $name;
        if (is_link($path) || !is_file($path) || filesize($path) === 0) {
            continue;
        }
        $kind = reuseKind($name);
        if ($kind === 'excluded') {
            continue;
        }
        if ($kind === 'unknown') {
            $errors[] = $name . ': unknown file format';
            continue;
        }
        $content = file_get_contents($path);
        if (!is_string($content)) {
            $errors[] = $name . ': unreadable';
            continue;
        }
        if ($kind === 'json') {
            $jsonPaths[] = $name;
            try {
                json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $errors[] = $name . ': invalid JSON';
            }
            continue;
        }
        $header = reuseLeadingHeader($content, $kind);
        if (preg_match('/\bSPDX-FileCopyrightText:\s*\S+/', $header) !== 1
            || preg_match('/\bSPDX-License-' . 'Identifier:\s*\S+/', $header) !== 1) {
            $errors[] = $name . ': missing leading SPDX header';
        }
        ++$checked;
    }

    foreach (array_diff($jsonPaths, $annotations) as $name) {
        $errors[] = $name . ': missing JSON annotation';
    }
    foreach (array_diff($annotations, $jsonPaths) as $name) {
        $errors[] = $name . ': annotation without corresponding JSON file';
    }
    if ($errors !== []) {
        foreach ($errors as $error) {
            fwrite(STDERR, 'FAIL: ' . $error . "\n");
        }
        return 1;
    }
    echo "SPDX headers: {$checked} commentable files; " . count($jsonPaths) . " JSON annotations verified\n";
    return 0;
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        $arguments = $_SERVER['argv'] ?? [];
        if (($arguments[1] ?? null) === '--self-test' && count($arguments) === 2) {
            if (reuseKind('vendor-bin/phpunit/composer.json') !== 'json'
                || reuseKind('tests/run.php') !== 'php'
                || reuseLeadingHeader("<?php\n// SPDX-FileCopyrightText: Example\n// SPDX-License-Identifier: MIT\n", 'php') === '') {
                throw new RuntimeException('SPDX checker self-test failed');
            }
            echo "SPDX checker self-test: PASS\n";
            exit(0);
        }
        if (count($arguments) !== 1) {
            throw new InvalidArgumentException('Usage: php tools/check-reuse-headers.php [--self-test]');
        }
        exit(reuseVerify());
    } catch (Throwable $error) {
        fwrite(STDERR, 'SPDX checker failed: ' . get_class($error) . "\n");
        exit(2);
    }
}
