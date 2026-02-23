<?php

declare(strict_types=1);

namespace DistributedLocking\Simulation;

use DistributedLocking\Core\RedisFactory;
use DistributedLocking\Lock\SafeRedisLock;

/**
 * Deadlock Simulator — Demonstrates classic circular wait.
 *
 * Two processes attempt to lock two resources in opposite order:
 *   Process 1: Lock A → Lock B
 *   Process 2: Lock B → Lock A
 *
 * Result: Both processes hold one lock and wait for the other → deadlock.
 *
 * Mitigation demonstrated:
 * - Sort resource IDs deterministically before locking
 * - TTL-based auto-expiration as a safety net
 */
final class DeadlockSimulator
{
    private const RESOURCE_A = 'product_A';
    private const RESOURCE_B = 'product_B';

    /**
     * Run the deadlock demonstration.
     *
     * @param bool $useMitigation If true, sort resources before locking (prevents deadlock)
     * @param int  $lockTtlMs     Lock TTL in milliseconds
     */
    public static function run(bool $useMitigation = false, int $lockTtlMs = 3000): void
    {
        $mode = $useMitigation ? 'WITH mitigation (sorted ordering)' : 'WITHOUT mitigation (opposite ordering)';
        echo "\n🔬 Deadlock Simulation — {$mode}\n";
        echo "🔐 Lock TTL: {$lockTtlMs}ms\n\n";

        if (!function_exists('pcntl_fork')) {
            echo "⚠️  pcntl_fork() not available. Running sequential demonstration.\n";
            self::runSequential($useMitigation, $lockTtlMs);
            return;
        }

        self::runForked($useMitigation, $lockTtlMs);
    }

    private static function runForked(bool $useMitigation, int $lockTtlMs): void
    {
        // Clean slate
        $redis = RedisFactory::create();
        $redis->del('lock:' . self::RESOURCE_A, 'lock:' . self::RESOURCE_B);

        // Shared memory for results
        $shmKey = ftok(__FILE__, 'd');
        $shmId = shmop_open($shmKey, 'c', 0644, 8192);

        $pid = pcntl_fork();

        if ($pid === -1) {
            die("❌ Fork failed\n");
        }

        if ($pid === 0) {
            // Child: Process 2
            $result = self::acquireTwoResources(
                'Process-2',
                $useMitigation ? self::RESOURCE_A : self::RESOURCE_B, // sorted or opposite
                $useMitigation ? self::RESOURCE_B : self::RESOURCE_A,
                $lockTtlMs
            );

            $data = str_pad(json_encode($result), 4096, "\0");
            shmop_write($shmId, $data, 4096);
            exit(0);
        }

        // Parent: Process 1
        $result1 = self::acquireTwoResources(
            'Process-1',
            self::RESOURCE_A,
            self::RESOURCE_B,
            $lockTtlMs
        );

        pcntl_waitpid($pid, $status);

        // Read child result
        $data = rtrim(shmop_read($shmId, 4096, 4096), "\0");
        $result2 = json_decode($data, true);

        shmop_delete($shmId);

        self::printResults($result1, $result2, $useMitigation);
    }

    /**
     * Attempt to acquire two resources in order, with retry timeout.
     */
    private static function acquireTwoResources(
        string $processName,
        string $first,
        string $second,
        int $lockTtlMs
    ): array {
        $redis = RedisFactory::create();
        $lock = new SafeRedisLock($redis);

        $result = [
            'process'       => $processName,
            'first'         => $first,
            'second'        => $second,
            'first_locked'  => false,
            'second_locked' => false,
            'deadlocked'    => false,
            'completed'     => false,
            'duration_ms'   => 0.0,
        ];

        $start = microtime(true);
        $timeoutMs = $lockTtlMs + 2000; // Wait slightly longer than TTL

        echo "  🔄 [{$processName}] Attempting: {$first} → {$second}\n";

        // Step 1: Lock first resource
        $result['first_locked'] = $lock->acquire($first, $lockTtlMs);

        if (!$result['first_locked']) {
            echo "  ❌ [{$processName}] Failed to acquire {$first}\n";
            $result['duration_ms'] = (microtime(true) - $start) * 1000;
            return $result;
        }

        echo "  🔒 [{$processName}] Acquired: {$first}\n";

        // Small delay to ensure both processes hold their first lock
        usleep(100_000); // 100ms

        // Step 2: Try to lock second resource with retries
        $retryDeadline = microtime(true) + ($timeoutMs / 1000);
        $acquired = false;

        while (microtime(true) < $retryDeadline) {
            if ($lock->acquire($second, $lockTtlMs)) {
                $acquired = true;
                break;
            }
            usleep(50_000); // 50ms retry delay
        }

        $result['second_locked'] = $acquired;

        if (!$acquired) {
            echo "  ⏱️  [{$processName}] TIMEOUT waiting for {$second} — DEADLOCK detected!\n";
            $result['deadlocked'] = true;
            $lock->release($first);
        } else {
            echo "  🔒 [{$processName}] Acquired: {$second}\n";
            echo "  ✅ [{$processName}] Both resources locked — executing critical section\n";
            usleep(50_000); // Simulate work
            $lock->release($second);
            $lock->release($first);
            $result['completed'] = true;
        }

        $result['duration_ms'] = (microtime(true) - $start) * 1000;
        return $result;
    }

    private static function runSequential(bool $useMitigation, int $lockTtlMs): void
    {
        echo "  Simulating Process 1 and Process 2 sequentially...\n\n";

        if ($useMitigation) {
            echo "  ✅ With sorted ordering, both processes lock A→B:\n";
            echo "     Process 1: Lock A → Lock B → work → release B → release A\n";
            echo "     Process 2: Lock A → Lock B → work → release B → release A\n";
            echo "     No circular wait → NO DEADLOCK\n\n";
        } else {
            echo "  ⚠️  Without mitigation, processes use opposite ordering:\n";
            echo "     Process 1: Lock A → (wait B) ──┐\n";
            echo "     Process 2: Lock B → (wait A) ──┘ DEADLOCK!\n";
            echo "     Both processes hold one lock and wait for the other indefinitely.\n";
            echo "     TTL expiration ({$lockTtlMs}ms) is the only escape.\n\n";
        }

        self::printResults(
            [
                'process' => 'Process-1', 'first' => self::RESOURCE_A,
                'second' => self::RESOURCE_B, 'first_locked' => true,
                'second_locked' => $useMitigation, 'deadlocked' => !$useMitigation,
                'completed' => $useMitigation, 'duration_ms' => $useMitigation ? 150.0 : (float)$lockTtlMs,
            ],
            [
                'process' => 'Process-2', 'first' => $useMitigation ? self::RESOURCE_A : self::RESOURCE_B,
                'second' => $useMitigation ? self::RESOURCE_B : self::RESOURCE_A, 'first_locked' => true,
                'second_locked' => $useMitigation, 'deadlocked' => !$useMitigation,
                'completed' => $useMitigation, 'duration_ms' => $useMitigation ? 320.0 : (float)$lockTtlMs,
            ],
            $useMitigation
        );
    }

    private static function printResults(array $result1, array $result2, bool $useMitigation): void
    {
        $deadlockDetected = ($result1['deadlocked'] ?? false) || ($result2['deadlocked'] ?? false);

        echo <<<EOT

        ╔══════════════════════════════════════════════════════════╗
        ║              DEADLOCK SIMULATION RESULTS                ║
        ╠══════════════════════════════════════════════════════════╣

        EOT;

        foreach ([$result1, $result2] as $r) {
            $status = ($r['completed'] ?? false) ? '✅ Completed' : (($r['deadlocked'] ?? false) ? '⏱️  Deadlocked (timeout)' : '❌ Failed');
            $order = "{$r['first']} → {$r['second']}";
            echo "        ║  {$r['process']}:\n";
            echo "        ║    Lock order: {$order}\n";
            echo "        ║    Status:     {$status}\n";
            echo "        ║    Duration:   " . round($r['duration_ms'], 1) . "ms\n";
            echo "        ║\n";
        }

        if ($deadlockDetected) {
            echo "        ║  🔴 DEADLOCK DETECTED!\n";
            echo "        ║  Both processes held one lock and timed out\n";
            echo "        ║  waiting for the other.\n";
        } else {
            echo "        ║  🟢 NO DEADLOCK — Both processes completed.\n";
        }

        echo "        ║\n";
        echo "        ║  Mitigation: " . ($useMitigation ? 'ENABLED (sorted resource ordering)' : 'DISABLED') . "\n";
        echo "        ╚══════════════════════════════════════════════════════════╝\n\n";

        if ($deadlockDetected && !$useMitigation) {
            echo "  💡 Fix: Sort resource IDs before acquiring locks.\n";
            echo "     Both processes lock [A, B] in alphabetical order.\n";
            echo "     Run with --mitigate to see the fix in action.\n\n";
        }
    }
}
