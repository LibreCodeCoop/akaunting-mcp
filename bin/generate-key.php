<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

$arguments = $_SERVER['argv'] ?? [];
if (PHP_SAPI !== 'cli' || count($arguments) !== 2 || $arguments[1] === '') {
    fwrite(STDERR, "Usage: php bin/generate-key.php /private/path/to/key\n");
    exit(2);
}
$path = $arguments[1];
$previousMask = umask(0077);
$file = false;
$created = false;
try {
    // Exclusive creation is atomic: a prior file must never be replaced,
    // including when concurrent invocations race or a symlink is present.
    $file = @fopen($path, 'x');
    if (!is_resource($file)) {
        throw new RuntimeException('Refusing to replace or create an inaccessible encryption key');
    }
    $created = true;
    $key = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES))."\n";
    if (fwrite($file, $key) !== strlen($key) || !fflush($file)) {
        throw new RuntimeException('Could not write encryption key');
    }
    if (!chmod($path, 0600)) {
        throw new RuntimeException('Could not restrict encryption key permissions');
    }
} catch (Throwable) {
    if (is_resource($file)) {
        fclose($file);
    }
    if ($created) {
        @unlink($path);
    }
    umask($previousMask);
    fwrite(STDERR, "Encryption key could not be created safely.\n");
    exit(2);
}
fclose($file);
umask($previousMask);
echo "Created the encryption key file with mode 0600. Keep backups secure.\n";
