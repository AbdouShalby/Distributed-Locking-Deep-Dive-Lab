<?php

declare(strict_types=1);

namespace DistributedLocking\Lock;

/**
 * No-op lock implementation — provides no mutual exclusion.
 *
 * Purpose: baseline to demonstrate what happens when concurrent
 * processes access shared state without any coordination.
 *
 * Expected behavior under contention:
 * - Race conditions
 * - Lost updates
 * - Overselling / negative stock
 */
final class NoLock implements LockInterface
{
    public function acquire(string $resource, int $ttlMs = 5000): bool
    {
        // Always "succeeds" — no actual coordination
        return true;
    }

    public function release(string $resource): bool
    {
        // Nothing to release
        return true;
    }

    public function isHeld(string $resource): bool
    {
        // Never actually held
        return false;
    }

    public function getName(): string
    {
        return 'NoLock (Baseline — No Coordination)';
    }
}
