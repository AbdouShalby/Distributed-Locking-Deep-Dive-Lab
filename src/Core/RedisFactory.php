<?php

declare(strict_types=1);

namespace DistributedLocking\Core;

use Redis;

/**
 * Factory to create a Redis connection.
 *
 * Reads REDIS_HOST and REDIS_PORT from environment
 * with sensible Docker defaults.
 */
final class RedisFactory
{
    public static function create(): Redis
    {
        $redis = new Redis();

        $host = getenv('REDIS_HOST') ?: 'redis';
        $port = (int) (getenv('REDIS_PORT') ?: 6379);

        $connected = $redis->connect($host, $port, 5.0);

        if (!$connected) {
            throw new \RuntimeException("Cannot connect to Redis at {$host}:{$port}");
        }

        // Use a dedicated database for the lab to avoid collisions
        $redis->select(1);

        return $redis;
    }
}
