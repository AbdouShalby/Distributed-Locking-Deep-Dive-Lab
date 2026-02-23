<?php

declare(strict_types=1);

namespace DistributedLocking\Simulation;

use DistributedLocking\Core\Inventory;
use DistributedLocking\Core\RedisFactory;
use DistributedLocking\Lock\SafeRedisLock;

/**
 * Crash Scenario Simulator — Demonstrates TTL expiration edge cases.
 *
 * Simulates scenarios where:
 * 1. A process acquires a lock and crashes (lock auto-expires via TTL)
 * 2. A lock TTL is shorter than the critical section execution time
 * 3. Process A's lock expires, Process B enters, both think they have exclusive access
 *
 * This proves:
 * - TTL must be chosen carefully (longer than max execution time)
 * - Lock safety depends on the execution window
 * - Even "safe" locks can fail if TTL is misconfigured
 */
final class CrashScenarioSimulator
{
    private const PRODUCT_ID = 'product_crash_test';

    /**
     * Scenario 1: Process crash — lock auto-expires via TTL.
     *
     * Demonstrates that TTL-based locks are crash-safe:
     * the lock doesn't persist forever after a crash.
     */
    public static function runCrashRecovery(int $lockTtlMs = 2000): void
    {
        $redis = RedisFactory::create();
        $inventory = new Inventory($redis);
        $lock = new SafeRedisLock($redis);

        // Reset
        $redis->flushDb();
        $inventory->setStock(self::PRODUCT_ID, 5);

        echo "\n🔬 Crash Recovery Simulation\n";
        echo "🔐 Lock TTL: {$lockTtlMs}ms\n\n";

        // Process 1: acquire lock + "crash" (don't release)
        echo "  [Process-1] Acquiring lock...\n";
        $acquired = $lock->acquire(self::PRODUCT_ID, $lockTtlMs);
        echo "  [Process-1] Lock acquired: " . ($acquired ? 'YES' : 'NO') . "\n";
        echo "  [Process-1] 💥 CRASH! (lock NOT released)\n\n";

        // Simulate crash: don't call release(). The lock object still holds the token,
        // but in a real crash the process would be dead. Create a new lock instance.
        $lock2 = new SafeRedisLock($redis);

        // Process 2: tries to acquire immediately — should fail
        echo "  [Process-2] Attempting lock immediately after crash...\n";
        $acquired2 = $lock2->acquire(self::PRODUCT_ID, $lockTtlMs);
        echo "  [Process-2] Lock acquired: " . ($acquired2 ? 'YES' : 'NO') . " (expected: NO)\n\n";

        // Wait for TTL to expire
        $waitSec = (int) ceil($lockTtlMs / 1000) + 1;
        echo "  ⏳ Waiting {$waitSec}s for TTL expiration...\n\n";
        sleep($waitSec);

        // Process 2: tries again after TTL — should succeed
        echo "  [Process-2] Retrying after TTL expiration...\n";
        $acquired3 = $lock2->acquire(self::PRODUCT_ID, $lockTtlMs);
        echo "  [Process-2] Lock acquired: " . ($acquired3 ? 'YES ✅' : 'NO ❌') . " (expected: YES)\n\n";

        if ($acquired3) {
            // Process 2: can now safely do work
            $stock = $inventory->getStock(self::PRODUCT_ID);
            echo "  [Process-2] Stock is {$stock}, decrementing...\n";
            $inventory->decrementAtomic(self::PRODUCT_ID);
            $lock2->release(self::PRODUCT_ID);
            echo "  [Process-2] Work done, lock released. Final stock: " . $inventory->getStock(self::PRODUCT_ID) . "\n";
        }

        echo <<<EOT

        ╔══════════════════════════════════════════════════════════╗
        ║           CRASH RECOVERY RESULTS                        ║
        ╠══════════════════════════════════════════════════════════╣
        ║  Process 1 crashed without releasing lock               ║
        ║  Process 2 was blocked until TTL expired ({$lockTtlMs}ms)          ║
        ║  Process 2 then acquired the lock and completed work    ║
        ╠══════════════════════════════════════════════════════════╣
        ║  ✅  TTL-based locks provide crash recovery             ║
        ║  ⚠️   Recovery time = TTL duration (availability cost)  ║
        ╚══════════════════════════════════════════════════════════╝

        EOT;
    }

    /**
     * Scenario 2: TTL shorter than execution time.
     *
     * Demonstrates the most dangerous edge case:
     * - Process A acquires lock with short TTL
     * - Process A's work takes longer than TTL
     * - Lock expires while Process A is still in critical section
     * - Process B acquires the now-free lock
     * - Both processes are in the critical section simultaneously
     */
    public static function runTtlExpiration(int $lockTtlMs = 1000, int $workDurationMs = 3000): void
    {
        echo "\n🔬 TTL Expiration Edge Case\n";
        echo "🔐 Lock TTL: {$lockTtlMs}ms\n";
        echo "⏱️  Work duration: {$workDurationMs}ms (longer than TTL!)\n\n";

        if (!function_exists('pcntl_fork')) {
            self::runTtlExpirationSequential($lockTtlMs, $workDurationMs);
            return;
        }

        $redis = RedisFactory::create();
        $inventory = new Inventory($redis);

        // Reset
        $redis->flushDb();
        $inventory->setStock(self::PRODUCT_ID, 1);

        echo "  Initial stock: 1\n\n";

        // Shared memory
        $shmKey = ftok(__FILE__, 't');
        $shmId = shmop_open($shmKey, 'c', 0644, 8192);

        $pid = pcntl_fork();

        if ($pid === -1) {
            die("❌ Fork failed\n");
        }

        if ($pid === 0) {
            // Child: Process B — waits for lock, then enters
            sleep(1); // Let Process A acquire first

            $childRedis = RedisFactory::create();
            $childInventory = new Inventory($childRedis);
            $childLock = new SafeRedisLock($childRedis);

            echo "  [Process-B] Waiting for lock...\n";

            $deadline = microtime(true) + ($lockTtlMs + $workDurationMs + 2000) / 1000;
            $acquired = false;

            while (microtime(true) < $deadline) {
                if ($childLock->acquire(self::PRODUCT_ID, $lockTtlMs)) {
                    $acquired = true;
                    break;
                }
                usleep(100_000);
            }

            $bResult = [
                'acquired' => $acquired,
                'stock_before' => $childInventory->getStock(self::PRODUCT_ID),
                'decremented' => false,
            ];

            if ($acquired) {
                echo "  [Process-B] 🔒 Lock acquired! (Process-A's lock expired)\n";
                echo "  [Process-B] Reading stock: {$bResult['stock_before']}\n";

                // Process B also decrements
                $bResult['decremented'] = $childInventory->decrementNonAtomic(self::PRODUCT_ID);
                $bResult['stock_after'] = $childInventory->getStock(self::PRODUCT_ID);

                echo "  [Process-B] Decrement result: " . ($bResult['decremented'] ? 'SUCCESS' : 'FAILED') . "\n";
                $childLock->release(self::PRODUCT_ID);
            }

            $data = str_pad(json_encode($bResult), 4096, "\0");
            shmop_write($shmId, $data, 0);
            exit(0);
        }

        // Parent: Process A — acquires lock, then takes too long
        $lockA = new SafeRedisLock($redis);

        echo "  [Process-A] Acquiring lock...\n";
        $acquiredA = $lockA->acquire(self::PRODUCT_ID, $lockTtlMs);
        echo "  [Process-A] Lock acquired: " . ($acquiredA ? 'YES' : 'NO') . "\n";

        $stockBefore = $inventory->getStock(self::PRODUCT_ID);
        echo "  [Process-A] Stock before: {$stockBefore}\n";
        echo "  [Process-A] Starting long operation ({$workDurationMs}ms)...\n";
        echo "  [Process-A] ⚠️  Lock will expire in {$lockTtlMs}ms!\n";

        // Simulate long work
        usleep($workDurationMs * 1000);

        // Process A finishes work and decrements — but lock already expired!
        echo "  [Process-A] Work done. Decrementing stock...\n";
        $decremented = $inventory->decrementNonAtomic(self::PRODUCT_ID);
        echo "  [Process-A] Decrement result: " . ($decremented ? 'SUCCESS' : 'FAILED') . "\n";

        $released = $lockA->release(self::PRODUCT_ID);
        echo "  [Process-A] Lock release: " . ($released ? 'SUCCESS' : 'FAILED (already expired)') . "\n";

        // Wait for child
        pcntl_waitpid($pid, $status);

        // Read Process B result
        $data = rtrim(shmop_read($shmId, 0, 4096), "\0");
        $bResult = json_decode($data, true);
        shmop_delete($shmId);

        // Final stock
        $redis2 = RedisFactory::create();
        $inv2 = new Inventory($redis2);
        $finalStock = $inv2->getStock(self::PRODUCT_ID);

        $bothDecremented = $decremented && ($bResult['decremented'] ?? false);

        $aResult = self::yesNo($decremented);
        $bDecResult = self::yesNo($bResult['decremented'] ?? false);

        echo "\n";
        echo "        ╔══════════════════════════════════════════════════════════╗\n";
        echo "        ║        TTL EXPIRATION EDGE CASE RESULTS                 ║\n";
        echo "        ╠══════════════════════════════════════════════════════════╣\n";
        echo "        ║  Lock TTL:         {$lockTtlMs}ms                                  ║\n";
        echo "        ║  Work Duration:    {$workDurationMs}ms                                ║\n";
        echo "        ║  TTL < Work:       YES ⚠️                                ║\n";
        echo "        ╠══════════════════════════════════════════════════════════╣\n";
        echo "        ║  Process A decrement: {$aResult}                            ║\n";
        echo "        ║  Process B decrement: {$bDecResult}                            ║\n";
        echo "        ║  Initial Stock:    1                                    ║\n";
        echo "        ║  Final Stock:      {$finalStock}                                    ║\n";
        echo "        ╠══════════════════════════════════════════════════════════╣\n";

        if ($bothDecremented) {
            echo "        ║  🔴 BOTH PROCESSES DECREMENTED!                       ║\n";
            echo "        ║     Lock expired during Process A's critical section. ║\n";
            echo "        ║     Process B entered while A was still working.      ║\n";
            echo "        ║     This is the TTL expiration vulnerability.         ║\n";
        } else {
            echo "        ║  🟢 Only one process decremented successfully.        ║\n";
        }

        echo "        ╠══════════════════════════════════════════════════════════╣\n";
        echo "        ║  💡 Fix: TTL must be >> max execution time              ║\n";
        echo "        ║     Or use fencing tokens for at-most-once execution    ║\n";
        echo "        ╚══════════════════════════════════════════════════════════╝\n\n";
    }

    private static function runTtlExpirationSequential(int $lockTtlMs, int $workDurationMs): void
    {
        echo "  ⚠️  pcntl_fork() not available. Showing expected behavior:\n\n";
        echo "  Timeline:\n";
        echo "  ├── 0ms       Process A acquires lock (TTL={$lockTtlMs}ms)\n";
        echo "  ├── 0ms       Process A starts work ({$workDurationMs}ms)\n";
        echo "  ├── {$lockTtlMs}ms     ⚠️  Lock EXPIRES (Process A still working!)\n";
        echo "  ├── {$lockTtlMs}ms     Process B acquires lock (key is free)\n";
        echo "  ├── {$lockTtlMs}ms     Process B reads stock=1, decrements to 0\n";
        echo "  ├── {$workDurationMs}ms    Process A finishes, decrements stock to -1\n";
        echo "  └── RESULT:   Stock = -1 (OVERSOLD!) ⚠️\n\n";
        echo "  💡 Fix: Set TTL >> max execution time, or use fencing tokens.\n\n";
    }

    private static function yesNo(bool $value): string
    {
        return $value ? 'YES' : 'NO';
    }
}
