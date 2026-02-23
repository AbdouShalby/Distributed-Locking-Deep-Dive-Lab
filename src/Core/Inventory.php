<?php

declare(strict_types=1);

namespace DistributedLocking\Core;

use Redis;

/**
 * Simulated inventory stored in Redis.
 *
 * Uses Redis as the shared state store (simulating a database).
 * Provides atomic and non-atomic operations to demonstrate
 * the difference between safe and unsafe stock management.
 */
final class Inventory
{
    private Redis $redis;

    private const KEY_PREFIX = 'inventory:';

    public function __construct(Redis $redis)
    {
        $this->redis = $redis;
    }

    /**
     * Set the initial stock for a product.
     */
    public function setStock(string $productId, int $quantity): void
    {
        $this->redis->set($this->key($productId), (string) $quantity);
    }

    /**
     * Get current stock level.
     */
    public function getStock(string $productId): int
    {
        $value = $this->redis->get($this->key($productId));
        return $value !== false ? (int) $value : 0;
    }

    /**
     * Non-atomic decrement: GET → check → SET.
     *
     * This deliberately uses a read-modify-write pattern
     * to expose the race condition window:
     *
     *   Process A: GET stock → 1
     *   Process B: GET stock → 1       ← both see 1
     *   Process A: SET stock → 0
     *   Process B: SET stock → 0       ← lost update, should be -1
     *
     * The optional sleep simulates real-world processing delay
     * which widens the race window.
     *
     * @param int $simulatedDelayUs Microseconds of simulated work between read and write
     * @return bool True if decrement succeeded (stock was > 0 at read time)
     */
    public function decrementNonAtomic(string $productId, int $quantity = 1, int $simulatedDelayUs = 0): bool
    {
        $current = $this->getStock($productId);

        if ($current < $quantity) {
            return false;
        }

        // ⚠️  Race window: another process can read the SAME value here
        if ($simulatedDelayUs > 0) {
            usleep($simulatedDelayUs);
        }

        $this->redis->set($this->key($productId), (string) ($current - $quantity));

        return true;
    }

    /**
     * Atomic decrement using Redis DECRBY + Lua validation.
     *
     * Lua script ensures the check-and-decrement is atomic:
     * no other process can interleave between the read and write.
     */
    public function decrementAtomic(string $productId, int $quantity = 1): bool
    {
        $script = <<<'LUA'
            local current = tonumber(redis.call("GET", KEYS[1]) or "0")
            if current >= tonumber(ARGV[1]) then
                redis.call("DECRBY", KEYS[1], ARGV[1])
                return 1
            else
                return 0
            end
        LUA;

        $result = $this->redis->eval($script, [$this->key($productId), (string) $quantity], 1);
        return $result === 1;
    }

    /**
     * Restore stock (used when demonstrating cancel/rollback).
     */
    public function incrementStock(string $productId, int $quantity = 1): void
    {
        $this->redis->incrBy($this->key($productId), $quantity);
    }

    /**
     * Reset all inventory data.
     */
    public function reset(): void
    {
        $keys = $this->redis->keys(self::KEY_PREFIX . '*');
        if (!empty($keys)) {
            $this->redis->del($keys);
        }
    }

    private function key(string $productId): string
    {
        return self::KEY_PREFIX . $productId;
    }
}
