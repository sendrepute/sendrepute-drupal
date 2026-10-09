<?php

declare(strict_types=1);

namespace Drupal\sendrepute\Service;

use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\sendrepute\Console\LockStore;

/**
 * Paid-request duplicate guard on Drupal's expirable key-value store.
 * setWithExpireIfNotExists is an atomic insert in the database backend.
 */
final class ConsoleLockStore implements LockStore {

  public function __construct(private KeyValueStoreExpirableInterface $store) {}

  public function claim(string $key, int $ttl): bool {
    if (!preg_match('/^[a-f0-9]{64}$/D', $key) || $ttl < 1) {
      return FALSE;
    }
    return (bool) $this->store->setWithExpireIfNotExists($key, time(), $ttl);
  }

  public function complete(string $key): void {
    // Completed requests keep their guard for the full window on purpose.
  }

}
