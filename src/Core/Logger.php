<?php

declare(strict_types=1);

namespace DistributedLocking\Core;

/**
 * Logger for simulation results.
 *
 * Outputs structured logs to stdout and optionally to a JSON file.
 * Each simulation produces a complete result report.
 */
final class Logger
{
    private bool $verbose;
    private ?string $outputFile;
    private array $entries = [];

    public function __construct(bool $verbose = true, ?string $outputFile = null)
    {
        $this->verbose = $verbose;
        $this->outputFile = $outputFile;
    }

    /**
     * Log a single attempt result.
     */
    public function logAttempt(array $result): void
    {
        $this->entries[] = $result;

        if ($this->verbose) {
            $status = $result['success'] ? '✅' : '❌';
            $lock = $result['lock_acquired'] ? '🔒' : '🔓';
            $error = $result['error'] ?? '-';

            fprintf(
                STDERR,
                "  %s %s [%s] stock: %d→%d  (%.1fms) %s\n",
                $status,
                $lock,
                $result['process_id'],
                $result['stock_before'],
                $result['stock_after'],
                $result['duration_ms'],
                $result['error'] ? "({$error})" : ''
            );
        }
    }

    /**
     * Print a summary report for the simulation.
     */
    public function printSummary(string $scenario, string $strategy, int $initialStock, int $finalStock, float $totalDurationMs): void
    {
        $total = count($this->entries);
        $successes = count(array_filter($this->entries, fn($e) => $e['success']));
        $failures = $total - $successes;
        $lockFailures = count(array_filter($this->entries, fn($e) => !$e['lock_acquired']));

        $report = <<<EOT

        ╔══════════════════════════════════════════════════════════╗
        ║              SIMULATION RESULTS                         ║
        ╠══════════════════════════════════════════════════════════╣
        ║  Scenario:        {$this->pad($scenario, 37)}║
        ║  Lock Strategy:   {$this->pad($strategy, 37)}║
        ╠══════════════════════════════════════════════════════════╣
        ║  Total Attempts:    {$this->pad((string)$total, 36)}║
        ║  Successful:        {$this->pad((string)$successes, 36)}║
        ║  Failed (stock):    {$this->pad((string)($failures - $lockFailures), 36)}║
        ║  Failed (lock):     {$this->pad((string)$lockFailures, 36)}║
        ╠══════════════════════════════════════════════════════════╣
        ║  Initial Stock:     {$this->pad((string)$initialStock, 36)}║
        ║  Final Stock:       {$this->pad((string)$finalStock, 36)}║
        ║  Expected Stock:    {$this->pad((string)max(0, $initialStock - $successes), 36)}║
        ╠══════════════════════════════════════════════════════════╣
        ║  Total Duration:    {$this->pad(round($totalDurationMs, 2) . ' ms', 36)}║
        ║  Contention Rate:   {$this->pad(round($lockFailures / max(1, $total) * 100, 1) . '%', 36)}║
        ╚══════════════════════════════════════════════════════════╝

        EOT;

        echo $report;

        // Correctness verdict
        $oversold = $finalStock < 0;
        $extraOrders = $successes > $initialStock;

        if ($oversold || $extraOrders) {
            echo "  ⚠️  OVERSELLING DETECTED!\n";
            echo "     Stock went negative or more orders succeeded than available stock.\n";
            echo "     This proves the locking strategy is UNSAFE.\n\n";
        } else {
            echo "  ✅ NO OVERSELLING — Locking strategy is CORRECT for this scenario.\n\n";
        }
    }

    /**
     * Export results to JSON file.
     */
    public function exportJson(string $scenario, string $strategy, int $initialStock, int $finalStock): void
    {
        if ($this->outputFile === null) {
            return;
        }

        $total = count($this->entries);
        $successes = count(array_filter($this->entries, fn($e) => $e['success']));

        $report = [
            'scenario'      => $scenario,
            'strategy'      => $strategy,
            'initial_stock' => $initialStock,
            'final_stock'   => $finalStock,
            'total_attempts' => $total,
            'successes'     => $successes,
            'failures'      => $total - $successes,
            'oversold'      => $finalStock < 0 || $successes > $initialStock,
            'entries'       => $this->entries,
            'timestamp'     => date('c'),
        ];

        $dir = dirname($this->outputFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->outputFile,
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
        );

        echo "  📄 Results exported to: {$this->outputFile}\n\n";
    }

    /**
     * Reset for a new simulation run.
     */
    public function reset(): void
    {
        $this->entries = [];
    }

    /**
     * Get all logged entries.
     */
    public function getEntries(): array
    {
        return $this->entries;
    }

    private function pad(string $text, int $width): string
    {
        return str_pad($text, $width);
    }
}
