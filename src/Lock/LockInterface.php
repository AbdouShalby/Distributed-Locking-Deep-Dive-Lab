<?php

declare(strict_types=1);

namespace DistributedLocking\Lock;

/**
 * Contract for all locking strategies.
 *
 * Every implementation must provide:
 * - acquire(): attempt to take the lock
 * - release(): give up the lock
 * - isHeld(): check if lock is currently held by this instance
 */
interface LockInterface
{
    /**
     * Attempt to acquire the lock.
     *
     * @param string $resource The resource identifier to lock
     * @param int    $ttlMs    Time-to-live in milliseconds
     *
     * @return bool True if lock was acquired
     */
    public function acquire(string $resource, int $ttlMs = 5000): bool;

    /**
     * Release the lock.
     *
     * @param string $resource The resource identifier to unlock
     *
     * @return bool True if lock was released by this owner
     */
    public function release(string $resource): bool;

    /**
     * Check if the lock is currently held by this instance.
     *
     * @param string $resource The resource identifier
     *
     * @return bool True if this instance holds the lock
     */
    public function isHeld(string $resource): bool;

    /**
     * Get the name of this locking strategy.
     *
     * @return string Human-readable strategy name
     */
    public function getName(): string;
}
