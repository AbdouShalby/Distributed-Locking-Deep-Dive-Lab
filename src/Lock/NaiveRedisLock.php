<?php

declare(strict_types=1);

namespace DistributedLocking\Lock;

use Redis;

/**
 * Naive Redis lock using SETNX + separate EXPIRE.
 *
 * Implementation:
 *   SETNX lock_key 1
 *   EXPIRE lock_key 5
 *
 * Known vulnerabilities:
 * 1. Non-atomic SET + EXPIRE — crash between them → permanent deadlock
 * 2. No ownership token — any process can release any lock
 * 3. Lock may expire before critical section completes
 * 4. Another process may release someone else's lock
 *
 * This implementation intentionally preserves these flaws
 * to demonstrate why naive locking is insufficient.
 */
final class NaiveRedisLock implements LockInterface
{
    private Redis $redis;

    /** @var array<string, bool> Track which resources we think we locked */
    private array $held = [];

    public function __construct(Redis $redis)
    {
        $this->redis = $redis;
    }

    /**
     * VULNERABILITY: SETNX and EXPIRE are two separate commands.
     * If the process crashes after SETNX but before EXPIRE,
     * the lock persists forever → deadlock.
     */
    public function acquire(string $resource, int $ttlMs = 5000): bool
    {
        $key = $this->key($resource);

        // Step 1: SETNX (Set if Not eXists)
        $acquired = $this->redis->setnx($key, '1');

        if ($acquired) {
            // Step 2: EXPIRE — NOT atomic with SETNX!
            // ⚠️  If crash happens HERE, lock has no TTL → permanent deadlock
            $this->redis->expire($key, (int) ceil($ttlMs / 1000));
            $this->held[$resource] = true;

            return true;
        }

        return false;
    }

    /**
     * VULNERABILITY: No ownership check.
     * Any process can delete the key, even if it doesn't own the lock.
     * This can cause another process's critical section to overlap.
     */
    public function release(string $resource): bool
    {
        $key = $this->key($resource);

        // ⚠️  Deletes unconditionally — no ownership verification
        $this->redis->del($key);
        unset($this->held[$resource]);

        return true;
    }

    public function isHeld(string $resource): bool
    {
        return $this->held[$resource] ?? false;
    }

    public function getName(): string
    {
        return 'Naive Redis Lock (SETNX + EXPIRE — Unsafe)';
    }

    private function key(string $resource): string
    {
        return "lock:{$resource}";
    }
}
