#!/usr/bin/env php
<?php

/**
 * Retry Strategy Comparison Runner
 *
 * Usage:
 *   php bin/retry.php                                     # Default settings
 *   php bin/retry.php --concurrency=30 --stock=15         # Custom parameters
 *   php bin/retry.php --max-retries=20 --ttl=3000         # Custom retries/TTL
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use DistributedLocking\Simulation\RetrySimulator;

$options = getopt('', ['concurrency:', 'stock:', 'max-retries:', 'ttl:']);

$concurrency = (int) ($options['concurrency'] ?? 20);
$stock       = (int) ($options['stock'] ?? 10);
$maxRetries  = (int) ($options['max-retries'] ?? 15);
$ttl         = (int) ($options['ttl'] ?? 2000);

RetrySimulator::run($concurrency, $stock, $maxRetries, $ttl);
