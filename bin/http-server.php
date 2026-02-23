#!/usr/bin/env php
<?php

/**
 * Lightweight HTTP server for k6 load testing.
 *
 * Exposes a minimal API that wraps the lock/inventory logic
 * so k6 can test via HTTP requests.
 *
 * Endpoints:
 *   POST /reset       — Reset inventory stock
 *   POST /purchase    — Attempt a purchase with a lock strategy
 *   GET  /stock/:id   — Get current stock level
 *
 * Usage:
 *   php bin/http-server.php                    # Start on port 8080
 *   php bin/http-server.php --port=9090        # Custom port
 *
 * This is NOT a production server. It is a testing facade only.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use DistributedLocking\Core\Inventory;
use DistributedLocking\Core\OrderProcessor;
use DistributedLocking\Core\RedisFactory;
use DistributedLocking\Lock\NaiveRedisLock;
use DistributedLocking\Lock\NoLock;
use DistributedLocking\Lock\SafeRedisLock;

$options = getopt('', ['port:']);
$port = (int) ($options['port'] ?? 8080);

$host = '0.0.0.0';

echo "🚀 Starting HTTP test server on {$host}:{$port}\n";
echo "   Endpoints:\n";
echo "   POST /reset      — Reset inventory\n";
echo "   POST /purchase   — Attempt purchase\n";
echo "   GET  /stock/:id  — Check stock\n\n";

$server = stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);

if ($server === false) {
    die("❌ Could not start server: {$errstr} ({$errno})\n");
}

echo "✅ Listening on {$host}:{$port}...\n\n";

while ($client = @stream_socket_accept($server, -1)) {
    $request = '';
    while ($line = fgets($client)) {
        $request .= $line;
        if (trim($line) === '') {
            break;
        }
    }

    // Parse Content-Length for body reading
    $contentLength = 0;
    if (preg_match('/Content-Length:\s*(\d+)/i', $request, $m)) {
        $contentLength = (int) $m[1];
    }

    $body = '';
    if ($contentLength > 0) {
        $body = fread($client, $contentLength);
    }

    // Parse method and path
    $firstLine = strtok($request, "\r\n");
    [$method, $path] = explode(' ', $firstLine);

    $responseBody = '';
    $statusCode = 200;

    try {
        $redis = RedisFactory::create();
        $inventory = new Inventory($redis);

        if ($method === 'POST' && $path === '/reset') {
            $data = json_decode($body, true);
            $productId = $data['product_id'] ?? 'default';
            $stock = (int) ($data['stock'] ?? 1);

            $inventory->setStock($productId, $stock);
            $responseBody = json_encode(['status' => 'ok', 'product_id' => $productId, 'stock' => $stock]);

        } elseif ($method === 'POST' && $path === '/purchase') {
            $data = json_decode($body, true);
            $productId = $data['product_id'] ?? 'default';
            $quantity = (int) ($data['quantity'] ?? 1);
            $lockStrategy = $data['lock_strategy'] ?? 'safe';

            $lock = match ($lockStrategy) {
                'naive' => new NaiveRedisLock($redis),
                'safe'  => new SafeRedisLock($redis),
                default => new NoLock(),
            };

            $processor = new OrderProcessor($inventory, $lock, 1000);
            $result = $processor->purchase($productId, $quantity);
            $responseBody = json_encode($result);

        } elseif ($method === 'GET' && str_starts_with($path, '/stock/')) {
            $productId = substr($path, 7);
            $stock = $inventory->getStock($productId);
            $responseBody = json_encode(['product_id' => $productId, 'stock' => $stock]);

        } else {
            $statusCode = 404;
            $responseBody = json_encode(['error' => 'Not found']);
        }
    } catch (\Throwable $e) {
        $statusCode = 500;
        $responseBody = json_encode(['error' => $e->getMessage()]);
    }

    $statusText = match ($statusCode) {
        200 => 'OK',
        404 => 'Not Found',
        500 => 'Internal Server Error',
        default => 'Unknown',
    };

    $response = "HTTP/1.1 {$statusCode} {$statusText}\r\n"
        . "Content-Type: application/json\r\n"
        . "Content-Length: " . strlen($responseBody) . "\r\n"
        . "Connection: close\r\n"
        . "\r\n"
        . $responseBody;

    fwrite($client, $response);
    fclose($client);
}
