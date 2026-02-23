<?php

declare(strict_types=1);

namespace DistributedLocking\Simulation;

use DistributedLocking\Core\Inventory;
use DistributedLocking\Core\Logger;
use DistributedLocking\Core\OrderProcessor;
use DistributedLocking\Core\RedisFactory;
use DistributedLocking\Lock\LockInterface;
use DistributedLocking\Lock\NaiveRedisLock;
use DistributedLocking\Lock\NoLock;
use DistributedLocking\Lock\SafeRedisLock;

/**
 * Overselling Simulator — The core race-condition demonstration.
 *
 * Simulates N concurrent purchase attempts against limited stock
 * using different locking strategies. Uses pcntl_fork() to create
 * real OS processes (not threads) that race against each other.
 *
 * Scenarios:
 * 1. No Lock       → proves race condition exists (overselling)
 * 2. Naive Lock    → shows partial improvement (still unsafe)
 * 3. Safe Lock     → demonstrates correct mutual exclusion
 */
final class OversellSimulator
{
    private const PRODUCT_ID = 'product_oversell_test';

    /**
     * Run the overselling simulation.
     *
     * @param string $lockType     'none' | 'naive' | 'safe'
     * @param int    $stock        Initial stock quantity
     * @param int    $concurrency  Number of concurrent processes
     * @param int    $delayUs      Simulated processing delay in microseconds
     * @param bool   $verbose      Show per-attempt logs
     * @param string|null $outputFile  Path for JSON export
     */
    public static function run(
        string $lockType = 'none',
        int $stock = 1,
        int $concurrency = 50,
        int $delayUs = 5000,
        bool $verbose = true,
        ?string $outputFile = null
    ): void {
        $redis = RedisFactory::create();
        $inventory = new Inventory($redis);
        $lock = self::createLock($lockType, $redis);
        $logger = new Logger($verbose, $outputFile);

        // Reset state
        $redis->flushDb();
        $inventory->setStock(self::PRODUCT_ID, $stock);

        $scenario = "Oversell Test ({$concurrency} processes, stock={$stock})";
        echo "\n🔬 Starting: {$scenario}\n";
        echo "🔐 Strategy: {$lock->getName()}\n";
        echo "⏱️  Processing delay: {$delayUs}μs\n\n";

        $start = microtime(true);
        $results = [];

        if (function_exists('pcntl_fork')) {
            $results = self::runForked($concurrency, $inventory, $lock, $delayUs);
        } else {
            $results = self::runSequential($concurrency, $inventory, $lock, $delayUs);
        }

        $totalDuration = (microtime(true) - $start) * 1000;

        // Re-read final stock (parent process needs fresh connection after forks)
        $redis2 = RedisFactory::create();
        $inv2 = new Inventory($redis2);
        $finalStock = $inv2->getStock(self::PRODUCT_ID);

        // Log results
        foreach ($results as $r) {
            $logger->logAttempt($r);
        }

        $logger->printSummary($scenario, $lock->getName(), $stock, $finalStock, $totalDuration);
        $logger->exportJson($scenario, $lock->getName(), $stock, $finalStock);
    }

    /**
     * Fork real OS processes for true concurrency.
     */
    private static function runForked(int $count, Inventory $inventory, LockInterface $lock, int $delayUs): array
    {
        $shmKey = ftok(__FILE__, 'o');
        $shmId = shmop_open($shmKey, 'c', 0644, $count * 4096);

        $pids = [];

        for ($i = 0; $i < $count; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                die("❌ Fork failed at process {$i}\n");
            }

            if ($pid === 0) {
                // Child process — new Redis connection required after fork
                $childRedis = RedisFactory::create();
                $childInventory = new Inventory($childRedis);
                $childLock = self::createLock(self::lockTypeFromInstance($lock), $childRedis);
                $processor = new OrderProcessor($childInventory, $childLock, $delayUs);

                $result = $processor->purchase(self::PRODUCT_ID, 1, "proc_{$i}");

                // Write result to shared memory
                $data = json_encode($result);
                $offset = $i * 4096;
                $padded = str_pad($data, 4096, "\0");
                shmop_write($shmId, $padded, $offset);

                exit(0);
            }

            $pids[] = $pid;
        }

        // Parent: wait for all children
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        // Read results from shared memory
        $results = [];
        for ($i = 0; $i < $count; $i++) {
            $offset = $i * 4096;
            $data = shmop_read($shmId, $offset, 4096);
            $data = rtrim($data, "\0");
            if (!empty($data)) {
                $results[] = json_decode($data, true);
            }
        }

        shmop_delete($shmId);

        return $results;
    }

    /**
     * Fallback: sequential simulation (if pcntl not available).
     */
    private static function runSequential(int $count, Inventory $inventory, LockInterface $lock, int $delayUs): array
    {
        $processor = new OrderProcessor($inventory, $lock, $delayUs);
        $results = [];

        for ($i = 0; $i < $count; $i++) {
            $results[] = $processor->purchase(self::PRODUCT_ID, 1, "proc_{$i}");
        }

        return $results;
    }

    private static function createLock(string $type, \Redis $redis): LockInterface
    {
        return match ($type) {
            'naive' => new NaiveRedisLock($redis),
            'safe'  => new SafeRedisLock($redis),
            default => new NoLock(),
        };
    }

    private static function lockTypeFromInstance(LockInterface $lock): string
    {
        return match (true) {
            $lock instanceof SafeRedisLock  => 'safe',
            $lock instanceof NaiveRedisLock => 'naive',
            default                         => 'none',
        };
    }
}
