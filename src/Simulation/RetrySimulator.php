<?php

declare(strict_types=1);

namespace DistributedLocking\Simulation;

use DistributedLocking\Core\Inventory;
use DistributedLocking\Core\Logger;
use DistributedLocking\Core\RedisFactory;
use DistributedLocking\Lock\SafeRedisLock;

/**
 * Retry Strategy Comparison — Fixed vs Exponential vs Exponential+Jitter.
 *
 * Tests three retry approaches under lock contention:
 *
 * 1. Fixed delay:             sleep(100ms) between retries
 * 2. Exponential backoff:     sleep(100ms, 200ms, 400ms, 800ms, ...)
 * 3. Exponential + jitter:    sleep(random(0, backoff_ms))
 *
 * Measures:
 * - Total execution time
 * - Lock contention rate (failed acquisitions / total attempts)
 * - Fairness (variance in process completion times)
 * - Success rate
 */
final class RetrySimulator
{
    private const PRODUCT_ID = 'product_retry_test';

    /**
     * Run all three retry strategies and compare.
     *
     * @param int $concurrency  Number of concurrent processes
     * @param int $stock        Available stock (should be < concurrency for contention)
     * @param int $maxRetries   Maximum retry attempts per process
     * @param int $lockTtlMs    Lock TTL
     */
    public static function run(
        int $concurrency = 20,
        int $stock = 10,
        int $maxRetries = 15,
        int $lockTtlMs = 2000
    ): void {
        echo "\n🔬 Retry Strategy Comparison\n";
        echo "   Concurrency: {$concurrency} processes\n";
        echo "   Stock: {$stock}\n";
        echo "   Max retries: {$maxRetries}\n";
        echo "   Lock TTL: {$lockTtlMs}ms\n\n";

        $strategies = [
            'fixed'                  => 'Fixed Delay (100ms)',
            'exponential'            => 'Exponential Backoff',
            'exponential_jitter'     => 'Exponential Backoff + Jitter',
        ];

        $results = [];

        foreach ($strategies as $key => $label) {
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo "  Testing: {$label}\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

            $results[$key] = self::runStrategy($key, $concurrency, $stock, $maxRetries, $lockTtlMs);
        }

        self::printComparison($strategies, $results);
    }

    private static function runStrategy(
        string $strategy,
        int $concurrency,
        int $stock,
        int $maxRetries,
        int $lockTtlMs
    ): array {
        $redis = RedisFactory::create();
        $inventory = new Inventory($redis);

        // Reset
        $redis->flushDb();
        $inventory->setStock(self::PRODUCT_ID, $stock);

        $start = microtime(true);
        $processResults = [];

        if (function_exists('pcntl_fork')) {
            $processResults = self::runForked($strategy, $concurrency, $maxRetries, $lockTtlMs);
        } else {
            $processResults = self::runSequential($strategy, $concurrency, $maxRetries, $lockTtlMs);
        }

        $totalDuration = (microtime(true) - $start) * 1000;

        // Re-read final stock
        $redis2 = RedisFactory::create();
        $inv2 = new Inventory($redis2);
        $finalStock = $inv2->getStock(self::PRODUCT_ID);

        $successes = count(array_filter($processResults, fn($r) => $r['success']));
        $totalRetries = array_sum(array_column($processResults, 'retries'));
        $durations = array_column($processResults, 'duration_ms');
        $avgDuration = !empty($durations) ? array_sum($durations) / count($durations) : 0;
        $variance = self::variance($durations);

        return [
            'total_duration_ms' => $totalDuration,
            'successes'         => $successes,
            'failures'          => $concurrency - $successes,
            'total_retries'     => $totalRetries,
            'avg_retries'       => $totalRetries / max(1, $concurrency),
            'avg_duration_ms'   => $avgDuration,
            'fairness_variance' => $variance,
            'final_stock'       => $finalStock,
            'initial_stock'     => $stock,
        ];
    }

    private static function runForked(string $strategy, int $count, int $maxRetries, int $lockTtlMs): array
    {
        $shmKey = ftok(__FILE__, 'r');
        $shmId = shmop_open($shmKey, 'c', 0644, $count * 2048);
        $pids = [];

        for ($i = 0; $i < $count; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                die("❌ Fork failed\n");
            }

            if ($pid === 0) {
                $result = self::attemptWithRetry($strategy, $i, $maxRetries, $lockTtlMs);

                $data = str_pad(json_encode($result), 2048, "\0");
                shmop_write($shmId, $data, $i * 2048);
                exit(0);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $results = [];
        for ($i = 0; $i < $count; $i++) {
            $data = rtrim(shmop_read($shmId, $i * 2048, 2048), "\0");
            if (!empty($data)) {
                $results[] = json_decode($data, true);
            }
        }

        shmop_delete($shmId);
        return $results;
    }

    private static function runSequential(string $strategy, int $count, int $maxRetries, int $lockTtlMs): array
    {
        $results = [];
        for ($i = 0; $i < $count; $i++) {
            $results[] = self::attemptWithRetry($strategy, $i, $maxRetries, $lockTtlMs);
        }
        return $results;
    }

    private static function attemptWithRetry(string $strategy, int $processIndex, int $maxRetries, int $lockTtlMs): array
    {
        $redis = RedisFactory::create();
        $inventory = new Inventory($redis);
        $lock = new SafeRedisLock($redis);

        $start = microtime(true);
        $retries = 0;
        $success = false;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            if ($attempt > 0) {
                $retries++;
                $delay = self::calculateDelay($strategy, $attempt);
                usleep($delay);
            }

            if (!$lock->acquire(self::PRODUCT_ID, $lockTtlMs)) {
                continue;
            }

            // Got the lock — try to decrement
            $decremented = $inventory->decrementNonAtomic(self::PRODUCT_ID, 1, 1000);

            if ($decremented) {
                $success = true;
            }

            $lock->release(self::PRODUCT_ID);
            break;
        }

        return [
            'process_id'  => "proc_{$processIndex}",
            'success'     => $success,
            'retries'     => $retries,
            'duration_ms' => (microtime(true) - $start) * 1000,
        ];
    }

    /**
     * Calculate retry delay based on strategy.
     *
     * @return int Delay in microseconds
     */
    private static function calculateDelay(string $strategy, int $attempt): int
    {
        $baseMs = 100; // 100ms base delay

        return match ($strategy) {
            'fixed' => $baseMs * 1000, // Always 100ms

            'exponential' => (int) (min($baseMs * pow(2, $attempt - 1), 5000) * 1000), // Cap at 5s

            'exponential_jitter' => (int) (random_int(0, (int) min($baseMs * pow(2, $attempt - 1), 5000)) * 1000),

            default => $baseMs * 1000,
        };
    }

    private static function printComparison(array $strategies, array $results): void
    {
        echo "\n";
        echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
        echo "║                    RETRY STRATEGY COMPARISON                                ║\n";
        echo "╠═══════════════════════════╦════════════╦════════════╦════════════╦═══════════╣\n";
        echo "║ Strategy                  ║ Duration   ║ Successes  ║ Avg Retry  ║ Fairness  ║\n";
        echo "╠═══════════════════════════╬════════════╬════════════╬════════════╬═══════════╣\n";

        foreach ($strategies as $key => $label) {
            $r = $results[$key];
            $duration = str_pad(round($r['total_duration_ms']) . 'ms', 10);
            $successes = str_pad((string) $r['successes'], 10);
            $avgRetry = str_pad(round($r['avg_retries'], 1) . '', 10);
            $fairness = str_pad(round(sqrt($r['fairness_variance']), 1) . 'ms', 9);

            $shortLabel = str_pad(substr($label, 0, 25), 25);
            echo "║ {$shortLabel} ║ {$duration} ║ {$successes} ║ {$avgRetry} ║ {$fairness} ║\n";
        }

        echo "╚═══════════════════════════╩════════════╩════════════╩════════════╩═══════════╝\n\n";

        echo "📊 Interpretation:\n";
        echo "   • Duration: Lower is better (total wall-clock time)\n";
        echo "   • Successes: Higher is better (orders processed)\n";
        echo "   • Avg Retry: Lower means less contention waste\n";
        echo "   • Fairness: Lower σ means processes complete at similar times\n\n";

        echo "💡 Key Insight:\n";
        echo "   Exponential Backoff + Jitter typically wins because:\n";
        echo "   1. Exponential spacing reduces collision probability\n";
        echo "   2. Jitter breaks synchronization (thundering herd prevention)\n";
        echo "   3. Combined → less wasted work, more throughput, fairer access\n\n";
    }

    /**
     * Calculate variance of a numeric array.
     */
    private static function variance(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }

        $mean = array_sum($values) / count($values);
        $squaredDiffs = array_map(fn($v) => pow($v - $mean, 2), $values);

        return array_sum($squaredDiffs) / count($squaredDiffs);
    }
}
