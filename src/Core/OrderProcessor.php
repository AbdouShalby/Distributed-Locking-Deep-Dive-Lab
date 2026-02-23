<?php

declare(strict_types=1);

namespace DistributedLocking\Core;

use DistributedLocking\Lock\LockInterface;

/**
 * Processes a purchase attempt using a given locking strategy.
 *
 * The OrderProcessor coordinates:
 * 1. Lock acquisition (using the provided strategy)
 * 2. Stock check
 * 3. Stock decrement
 * 4. Lock release
 *
 * By injecting different LockInterface implementations,
 * we can observe different concurrency behaviors:
 * - NoLock → race conditions, overselling
 * - NaiveRedisLock → partial safety, crash-vulnerable
 * - SafeRedisLock → correct mutual exclusion
 */
final class OrderProcessor
{
    private Inventory $inventory;
    private LockInterface $lock;

    /** @var int Simulated processing delay in microseconds */
    private int $processingDelayUs;

    public function __construct(
        Inventory $inventory,
        LockInterface $lock,
        int $processingDelayUs = 1000
    ) {
        $this->inventory = $inventory;
        $this->lock = $lock;
        $this->processingDelayUs = $processingDelayUs;
    }

    /**
     * Attempt to purchase a product.
     *
     * @return array{
     *     success: bool,
     *     process_id: string,
     *     lock_acquired: bool,
     *     stock_before: int,
     *     stock_after: int,
     *     duration_ms: float,
     *     error: string|null
     * }
     */
    public function purchase(string $productId, int $quantity = 1, string $processId = ''): array
    {
        $start = microtime(true);
        $result = [
            'success'       => false,
            'process_id'    => $processId ?: uniqid('proc_'),
            'lock_acquired' => false,
            'stock_before'  => -1,
            'stock_after'   => -1,
            'duration_ms'   => 0.0,
            'error'         => null,
        ];

        try {
            // Step 1: Acquire lock
            $lockAcquired = $this->lock->acquire($productId);
            $result['lock_acquired'] = $lockAcquired;

            if (!$lockAcquired) {
                $result['error'] = 'Failed to acquire lock';
                return $this->finalize($result, $start);
            }

            // Step 2: Read current stock
            $result['stock_before'] = $this->inventory->getStock($productId);

            // Step 3: Check and decrement stock (non-atomic to expose race conditions)
            $decremented = $this->inventory->decrementNonAtomic(
                $productId,
                $quantity,
                $this->processingDelayUs
            );

            if (!$decremented) {
                $result['error'] = 'Insufficient stock';
                $result['stock_after'] = $this->inventory->getStock($productId);
                return $this->finalize($result, $start);
            }

            // Step 4: Success
            $result['success'] = true;
            $result['stock_after'] = $this->inventory->getStock($productId);

            return $this->finalize($result, $start);
        } finally {
            // Step 5: Always release the lock
            if ($result['lock_acquired']) {
                $this->lock->release($productId);
            }
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private function finalize(array $result, float $start): array
    {
        $result['duration_ms'] = round((microtime(true) - $start) * 1000, 3);
        return $result;
    }
}
