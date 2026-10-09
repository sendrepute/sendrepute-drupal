<?php
// Generated from integrations/wordpress/portable-console/src by sync.php. Do not edit.

declare(strict_types=1);

namespace Drupal\sendrepute\Console;

/**
 * Single-node lock store using exclusive file creation in a private directory.
 * Multi-node installs should supply a shared LockStore (see platform adapters).
 */
final class FileLockStore implements LockStore
{
    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/\\');
    }

    public function claim(string $key, int $ttl): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $key)) {
            return false;
        }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            return false;
        }
        $file = $this->dir . '/' . $key . '.lock';
        if (is_file($file)) {
            $data = json_decode((string) @file_get_contents($file), true);
            if (!is_array($data) || (int) ($data['expires'] ?? 0) > time()) {
                return false;
            }
            @unlink($file);
        }
        $handle = @fopen($file, 'x');
        if (false === $handle) {
            return false;
        }
        fwrite($handle, (string) json_encode(['status' => 'pending', 'expires' => time() + $ttl]));
        fclose($handle);
        return true;
    }

    public function complete(string $key): void
    {
        $file = $this->dir . '/' . $key . '.lock';
        $data = json_decode((string) @file_get_contents($file), true);
        if (is_array($data)) {
            $data['status'] = 'complete';
            @file_put_contents($file, (string) json_encode($data));
        }
    }
}
