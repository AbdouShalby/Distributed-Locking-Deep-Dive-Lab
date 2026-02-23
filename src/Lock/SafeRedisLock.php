<?php

declare(strict_types=1);

namespace DistributedLocking\Lock;

use Redis;

/**
 * Production-grade distributed lock using Redis SET NX EX + Lua release.
 *
 * Implementation:
 *   Acquire: SET lock_key <unique_token> NX EX <ttl>   (single atomic command)
 *   Release: Lua script — check token, delete only if owner
 *
 * Safety properties:
 * 1. Atomic SET + TTL — no gap between acquiring and setting expiry
 * 2. Unique token per instance — prevents accidental release by another process
 * 3. Lua-based release — compare-and-delete is atomic (no TOCTOU race)
 * 4. TTL-based deadlock prevention — lock auto-expires if holder crashes
 *
 * Remaining edge case:
 * - If critical section exceeds TTL, lock expires and another process enters
 * - Mitigation: choose TTL >> max execution time, or use fencing tokens
 */
final class SafeRedisLock implements LockInterface
{
    /**
     * Lua script for safe release.
     * Compares the stored token with the caller's token.
     * Deletes only if they match → prevents releasing someone else's lock.
     */
    private const RELEASE_SCRIPT = <<<'LUA'
        if redis.call("GET", KEYS[1]) == ARGV[1] then
            return redis.call("DEL", KEYS[1])
        else
            return 0
        end
    LUA;

    private Redis $redis;

    /** @var array<string, string> resource → token mapping */
    private array $tokens = [];

    public function __construct(Redis $redis)
    {
        $this->redis = $redis;
    }

    /**
     * Acquire lock atomically: SET key token NX EX ttl
     *
     * NX = only set if key does Not eXist
     * EX = set expiration in seconds
     *
     * Single command → no crash window between set and expire.
     */
    public function acquire(string $resource, int $ttlMs = 5000): bool
    {
        $key = $this->key($resource);
        $token = $this->generateToken();
        $ttlSeconds = (int) ceil($ttlMs / 1000);

        /** @var bool|Redis $result */
        $result = $this->redis->set($key, $token, ['NX', 'EX' => $ttlSeconds]);

        if ($result) {
            $this->tokens[$resource] = $token;
            return true;
        }

        return false;
    }

    /**
     * Release lock safely using Lua script.
     *
     * The Lua script runs atomically on the Redis server:
     * 1. GET the current value
     * 2. Compare with our token
     * 3. DEL only if match
     *
     * This prevents the TOCTOU (Time-of-Check-Time-of-Use) race:
     *   Process A checks token → match
     *   Lock expires
     *   Process B acquires same key with new token
     *   Process A deletes → releases Process B's lock ← BUG
     *
     * With Lua, steps 1-3 are atomic → no interleaving possible.
     */
    public function release(string $resource): bool
    {
        if (!isset($this->tokens[$resource])) {
            return false;
        }

        $key = $this->key($resource);
        $token = $this->tokens[$resource];

        /** @var int $result */
        $result = $this->redis->eval(self::RELEASE_SCRIPT, [$key, $token], 1);

        unset($this->tokens[$resource]);

        return $result === 1;
    }

    public function isHeld(string $resource): bool
    {
        if (!isset($this->tokens[$resource])) {
            return false;
        }

        $key = $this->key($resource);
        $storedToken = $this->redis->get($key);

        return $storedToken === $this->tokens[$resource];
    }

    public function getName(): string
    {
        return 'Safe Redis Lock (SET NX EX + Lua Release)';
    }

    /**
     * Generate a cryptographically random token for ownership.
     * Using bin2hex(random_bytes) for uniqueness across processes.
     */
    private function generateToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function key(string $resource): string
    {
        return "lock:{$resource}";
    }
}
