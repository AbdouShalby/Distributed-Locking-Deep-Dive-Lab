/**
 * k6 Load Test: Overselling Detection
 *
 * Simulates 50 VUs (virtual users) racing to purchase the same product
 * with stock = 1 through a simple HTTP facade.
 *
 * This test validates that the distributed lock prevents overselling
 * when many concurrent requests hit simultaneously.
 *
 * Run:
 *   k6 run load-tests/oversell-test.js
 *   k6 run --out json=results/oversell.json load-tests/oversell-test.js
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter } from 'k6/metrics';

// Custom metrics
const ordersCreated = new Counter('orders_created_total');
const ordersRejected = new Counter('orders_rejected_total');

export const options = {
    scenarios: {
        oversell_burst: {
            executor: 'shared-iterations',
            vus: 50,
            iterations: 50,
            maxDuration: '30s',
        },
    },
    thresholds: {
        'orders_created_total': ['count == 1'],   // Exactly 1 should succeed
        'orders_rejected_total': ['count == 49'],  // 49 should be rejected
    },
};

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8080';

export function setup() {
    // Reset inventory to stock = 1
    const res = http.post(`${BASE_URL}/reset`, JSON.stringify({
        product_id: 'product_oversell_test',
        stock: 1,
    }), { headers: { 'Content-Type': 'application/json' } });

    check(res, { 'reset succeeded': (r) => r.status === 200 });
}

export default function () {
    const res = http.post(`${BASE_URL}/purchase`, JSON.stringify({
        product_id: 'product_oversell_test',
        quantity: 1,
        lock_strategy: 'safe',
    }), { headers: { 'Content-Type': 'application/json' } });

    if (res.status === 200) {
        const body = JSON.parse(res.body);
        if (body.success) {
            ordersCreated.add(1);
        } else {
            ordersRejected.add(1);
        }
    } else {
        ordersRejected.add(1);
    }
}

export function teardown() {
    // Check final stock
    const res = http.get(`${BASE_URL}/stock/product_oversell_test`);
    if (res.status === 200) {
        const body = JSON.parse(res.body);
        console.log(`Final stock: ${body.stock} (expected: 0)`);
    }
}
