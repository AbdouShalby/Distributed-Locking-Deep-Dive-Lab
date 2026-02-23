#!/usr/bin/env php
<?php

/**
 * Crash & TTL Scenario Runner
 *
 * Usage:
 *   php bin/crash.php                     # Crash recovery (TTL auto-expire)
 *   php bin/crash.php --ttl-edge          # TTL shorter than work duration
 *   php bin/crash.php --ttl=2000          # Custom TTL
 *   php bin/crash.php --work=5000         # Custom work duration (for --ttl-edge)
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use DistributedLocking\Simulation\CrashScenarioSimulator;

$options = getopt('', ['ttl-edge', 'ttl:', 'work:']);

$ttl  = (int) ($options['ttl'] ?? 2000);
$work = (int) ($options['work'] ?? 3000);

if (isset($options['ttl-edge'])) {
    CrashScenarioSimulator::runTtlExpiration($ttl, $work);
} else {
    CrashScenarioSimulator::runCrashRecovery($ttl);
}
