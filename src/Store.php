<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace AkauntingMcp;

use RuntimeException;

/** Encrypted personal credential store and locked, one-time OAuth state ledger. */
final class Store
{
    public function __construct(private readonly Config $config)
    {
        foreach (['users', 'locks'] as $dir) {
            $path = $this->config->dataDir.'/'.$dir;
            if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
                throw new RuntimeException('Could not create private storage');
            }
            @chmod($path, 0700);
        }
    }

    public function subject(string $email): string
    {
        $normalized = strtolower(trim($email));
        if (!filter_var($normalized, FILTER_VALIDATE_EMAIL) || strlen($normalized) > 254) {
            throw new RuntimeException('Invalid login');
        }
        return hash_hmac('sha256', $normalized, $this->config->encryptionKey);
    }

    public function saveLogin(string $email, string $password, int $companyId): string
    {
        if (strlen($password) > 2048 || $password === '') {
            throw new RuntimeException('Invalid login');
        }
        $subject = $this->subject($email);
        $file = $this->config->dataDir.'/users/'.$subject.'.json';
        $this->locked('user-'.$subject, function () use ($file, $email, $password, $companyId, $subject): void {
            $old = is_file($file) ? $this->readUserFile($file) : null;
            if ($this->isBlocked($subject)) {
                throw new RuntimeException('This identity has been disabled');
            }
            $payload = json_encode(['email'=>strtolower(trim($email)), 'password'=>$password, 'company_id'=>$companyId], JSON_THROW_ON_ERROR);
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = base64_encode($nonce.sodium_crypto_secretbox($payload, $nonce, $this->config->encryptionKey));
            $record = ['encrypted'=>$cipher, 'generation'=>((int)($old['generation'] ?? 0)) + 1];
            $this->atomic($file, json_encode($record, JSON_THROW_ON_ERROR));
        });
        return $subject;
    }

    public function load(string $subject): array
    {
        self::assertSubject($subject);
        if ($this->isBlocked($subject)) {
            throw new RuntimeException('Identity disabled');
        }
        $file = $this->config->dataDir.'/users/'.$subject.'.json';
        $record = $this->readUserFile($file);
        $cipher = base64_decode((string)($record['encrypted'] ?? ''), true);
        if (!is_string($cipher) || strlen($cipher) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new RuntimeException('Invalid personal credential vault');
        }
        $plain = sodium_crypto_secretbox_open(substr($cipher,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($cipher,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$this->config->encryptionKey);
        if (!is_string($plain)) {
            throw new RuntimeException('Credential decryption failed');
        }
        $data = json_decode($plain, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_string($data['email'] ?? null) || !is_string($data['password'] ?? null)
            || !is_int($data['company_id'] ?? null)) {
            throw new RuntimeException('Invalid credential payload');
        }
        return $data + ['generation'=>(int)$record['generation']];
    }

    public function generation(string $subject): int
    {
        self::assertSubject($subject);
        if ($this->isBlocked($subject)) {
            throw new RuntimeException('Identity disabled');
        }
        return (int)($this->readUserFile($this->config->dataDir.'/users/'.$subject.'.json')['generation'] ?? 0);
    }

    public function isBlocked(string $subject): bool
    {
        self::assertSubject($subject);
        return is_file($this->config->dataDir.'/users/'.$subject.'.blocked');
    }

    public function revoke(string $email): void
    {
        $subject = $this->subject($email);
        $this->locked('user-'.$subject, function () use ($subject): void {
            $path = $this->config->dataDir.'/users/'.$subject;
            $this->atomic($path.'.blocked', "revoked\n");
            if (is_file($path.'.json')) {
                unlink($path.'.json');
            }
        });
        $this->state(function (array &$data) use ($subject): void {
            foreach (['codes','refresh','tickets','approvals'] as $type) {
                foreach ($data[$type] as $key=>$value) {
                    if (($value['subject'] ?? null) === $subject) {
                        unset($data[$type][$key]);
                    }
                }
            }
        });
    }

    /** Mutate the shared short-lived state under an exclusive, process-safe lock. */
    public function state(callable $callback): mixed
    {
        return $this->locked('oauth-state', function () use ($callback): mixed {
            $file = $this->config->dataDir.'/oauth-state.json';
            $data = ['tickets'=>[], 'codes'=>[], 'refresh'=>[], 'login_attempts'=>[], 'authorize_attempts'=>[], 'approvals'=>[]];
            if (is_file($file)) {
                $decoded = json_decode((string)file_get_contents($file), true, 24, JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) {
                    throw new RuntimeException('Corrupted OAuth state');
                }
                $data = array_merge($data, $decoded);
            }
            foreach (['tickets','codes','refresh','login_attempts','authorize_attempts','approvals'] as $type) {
                foreach ($data[$type] as $key=>$item) {
                    if (($item['expires'] ?? 0) < time()) {
                        unset($data[$type][$key]);
                    }
                }
            }
            $result = $callback($data);
            $this->atomic($file, json_encode($data, JSON_THROW_ON_ERROR));
            return $result;
        });
    }

    /** Financial approval plans are encrypted at rest, never plaintext in OAuth state. */
    public function sealPlan(array $plan): string
    {
        $plain=json_encode($plan,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key=hash_hkdf('sha256',$this->config->encryptionKey,32,'akaunting-mcp/approval-v1');
        return base64_encode($nonce.sodium_crypto_secretbox($plain,$nonce,$key));
    }

    public function openPlan(string $sealed): array
    {
        $blob=base64_decode($sealed,true);
        if (!is_string($blob) || strlen($blob)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES+SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new RuntimeException('Invalid approval state');
        }
        $key=hash_hkdf('sha256',$this->config->encryptionKey,32,'akaunting-mcp/approval-v1');
        $plain=sodium_crypto_secretbox_open(
            substr($blob,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($blob,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$key
        );
        if (!is_string($plain))throw new RuntimeException('Approval state authentication failed');
        $decoded=json_decode($plain,true,16,JSON_THROW_ON_ERROR);
        if (!is_array($decoded))throw new RuntimeException('Invalid approval plan');
        return $decoded;
    }
    private function readUserFile(string $file): array
    {
        $raw = @file_get_contents($file);
        if (!is_string($raw)) {
            throw new RuntimeException('Personal Akaunting login required');
        }
        $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid credential storage');
        }
        return $data;
    }

    private function locked(string $name, callable $callback): mixed
    {
        $path = $this->config->dataDir.'/locks/'.hash('sha256',$name).'.lock';
        $fd = fopen($path,'c');
        if ($fd === false) {
            throw new RuntimeException('Could not lock private state');
        }
        @chmod($path, 0600);
        try {
            if (!flock($fd, LOCK_EX)) {
                throw new RuntimeException('Cannot lock private state');
            }
            return $callback();
        } finally {
            flock($fd, LOCK_UN);
            fclose($fd);
        }
    }

    private function atomic(string $path, string $content): void
    {
        $tmp = tempnam(dirname($path), '.tmp-');
        if (!is_string($tmp)) {
            throw new RuntimeException('Private storage not writable');
        }
        try {
            @chmod($tmp, 0600);
            if (file_put_contents($tmp,$content,LOCK_EX) !== strlen($content) || !rename($tmp,$path)) {
                throw new RuntimeException('Private storage write failed');
            }
        } finally {
            if (is_file($tmp)) unlink($tmp);
        }
    }

    public static function assertSubject(string $subject): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $subject)) {
            throw new RuntimeException('Invalid identity');
        }
        return $subject;
    }
}
