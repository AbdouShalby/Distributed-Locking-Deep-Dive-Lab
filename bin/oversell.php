#!/usr/bin/env php
<?php

/**
 * Overselling Simulation Runner
 *
 * Usage:
 *   php bin/oversell.php                          # No lock (baseline)
 *   php bin/oversell.php --lock=naive             # Naive SETNX lock
 *   php bin/oversell.php --lock=safe              # Safe Redis lock
 *   php bin/oversell.php --lock=safe --stock=5 --concurrency=100
 *   php bin/oversell.php --all                    # Run all three strategies
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use DistributedLocking\Simulation\OversellSimulator;

$options = getopt('', ['lock:', 'stock:', 'concurrency:', 'delay:', 'all', 'quiet', 'output:']);

$lockType    = $options['lock'] ?? 'none';
$stock       = (int) ($options['stock'] ?? 1);
$concurrency = (int) ($options['concurrency'] ?? 50);
$delay       = (int) ($options['delay'] ?? 5000);
$verbose     = !isset($options['quiet']);
$output      = $options['output'] ?? null;
$runAll      = isset($options['all']);

if ($runAll) {
    echo "╔══════════════════════════════════════════════════════════╗\n";
    echo "║      OVERSELLING SIMULATION — ALL STRATEGIES            ║\n";
    echo "╚══════════════════════════════════════════════════════════╝\n";

    foreach (['none', 'naive', 'safe'] as $type) {
        $outFile = $output ? dirname($output) . "/{$type}_" . basename($output) : null;
        OversellSimulator::run($type, $stock, $concurrency, $delay, $verbose, $outFile);
        echo str_repeat('─', 60) . "\n";
    }
} else {
    OversellSimulator::run($lockType, $stock, $concurrency, $delay, $verbose, $output);
}
