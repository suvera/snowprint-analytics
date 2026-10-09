<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\infra;

use dev\winterframework\core\app\ApplicationReadyEvent;
use dev\winterframework\stereotype\Component;
use dev\winterframework\stereotype\OnApplicationReady;

/**
 * Secret for the operator console (bin/console.sh). A fresh random token is
 * written to var/operator.token (mode 0600) every time the server starts,
 * before workers fork; the console reads it from inside the container.
 */
#[Component]
#[OnApplicationReady]
class OperatorToken implements ApplicationReadyEvent {

    public const FILE = __DIR__ . '/../../var/operator.token';

    private string $token = '';

    public function onApplicationReady(): void {
        $token = bin2hex(random_bytes(32));
        $dir = dirname(self::FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $tmp = self::FILE . '.' . getmypid();
        file_put_contents($tmp, $token);
        chmod($tmp, 0600);
        rename($tmp, self::FILE);
        $this->token = $token;
    }

    public function matches(?string $candidate): bool {
        if ($this->token === '' && is_readable(self::FILE)) {
            $this->token = trim((string) file_get_contents(self::FILE));
        }
        return $this->token !== '' && $candidate !== null && hash_equals($this->token, $candidate);
    }
}
