#!/usr/bin/env php
<?php

/**
 * Deadlock Simulation Runner
 *
 * Usage:
 *   php bin/deadlock.php                  # Without mitigation (shows deadlock)
 *   php bin/deadlock.php --mitigate       # With sorted ordering (prevents deadlock)
 *   php bin/deadlock.php --ttl=5000       # Custom lock TTL
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use DistributedLocking\Simulation\DeadlockSimulator;

$options = getopt('', ['mitigate', 'ttl:']);

$useMitigation = isset($options['mitigate']);
$ttl = (int) ($options['ttl'] ?? 3000);

DeadlockSimulator::run($useMitigation, $ttl);
