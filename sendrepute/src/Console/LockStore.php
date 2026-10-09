<?php
// Generated from integrations/wordpress/portable-console/src by sync.php. Do not edit.

declare(strict_types=1);

namespace Drupal\sendrepute\Console;

/**
 * Atomic duplicate guard for paid submissions. Keys are opaque HMACs.
 */
interface LockStore
{
    /** Atomically claim a key for $ttl seconds; false if already held. */
    public function claim(string $key, int $ttl): bool;

    /** Mark a claimed key as completed (it stays held until expiry). */
    public function complete(string $key): void;
}
