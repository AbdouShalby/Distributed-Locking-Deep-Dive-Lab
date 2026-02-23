#!/usr/bin/env php
<?php

/**
 * Run ALL simulations sequentially.
 *
 * Usage:
 *   php bin/run-all.php
 *   php bin/run-all.php --output=results/  # Export JSON results
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use DistributedLocking\Simulation\CrashScenarioSimulator;
use DistributedLocking\Simulation\DeadlockSimulator;
use DistributedLocking\Simulation\OversellSimulator;
use DistributedLocking\Simulation\RetrySimulator;

$options = getopt('', ['output:']);
$outputDir = $options['output'] ?? null;

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║         🔐 DISTRIBUTED LOCKING DEEP DIVE LAB               ║\n";
echo "║         Running All Simulations                             ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

// ── Scenario 1: Overselling ──────────────────────────────────────
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "  SCENARIO 1: OVERSELLING (No Lock vs Naive vs Safe)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

foreach (['none', 'naive', 'safe'] as $type) {
    $outFile = $outputDir ? "{$outputDir}/oversell_{$type}.json" : null;
    OversellSimulator::run($type, 1, 50, 5000, false, $outFile);
}

// ── Scenario 2: Deadlock ─────────────────────────────────────────
echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "  SCENARIO 2: DEADLOCK\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

echo "\n── Without mitigation:\n";
DeadlockSimulator::run(false, 3000);

echo "\n── With mitigation (sorted ordering):\n";
DeadlockSimulator::run(true, 3000);

// ── Scenario 3: Crash Recovery ───────────────────────────────────
echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "  SCENARIO 3: CRASH RECOVERY & TTL EDGE CASE\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

CrashScenarioSimulator::runCrashRecovery(2000);
CrashScenarioSimulator::runTtlExpiration(1000, 3000);

// ── Scenario 4: Retry Strategies ─────────────────────────────────
echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "  SCENARIO 4: RETRY STRATEGY COMPARISON\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

RetrySimulator::run(20, 10, 15, 2000);

echo "\n╔══════════════════════════════════════════════════════════════╗\n";
echo "║         ✅ ALL SIMULATIONS COMPLETE                         ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";
