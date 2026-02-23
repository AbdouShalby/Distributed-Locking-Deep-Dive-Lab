/**
 * k6 Load Test: Lock Contention Under High Load
 *
 * Tests the system under sustained high load:
 * - Ramp up to 100 VUs over 30 seconds
 * - Hold at 100 VUs for 60 seconds
 * - Ramp down over 10 seconds
 *
 * Measures:
 * - Response time under load (p95 < 500ms)
 * - Error rate under contention (< 10% HTTP errors)
 * - Lock contention behavior
 *
 * Run:
 *   k6 run load-tests/high-load-test.js
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Trend } from 'k6/metrics';

const lockContentionTime = new Trend('lock_contention_ms');
const purchaseSuccess = new Counter('purchase_success_total');
const purchaseFailed = new Counter('purchase_failed_total');

export const options = {
    stages: [
        { duration: '30s', target: 100 },  // Ramp up
        { duration: '60s', target: 100 },  // Sustain
        { duration: '10s', target: 0 },    // Ramp down
    ],
    thresholds: {
        'http_req_duration': ['p(95)<500', 'p(99)<1000'],
        'http_req_failed': ['rate<0.1'],
        'purchase_success_total': ['count>0'],
    },
};

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8080';

export function setup() {
    // Set high stock for sustained load test
    http.post(`${BASE_URL}/reset`, JSON.stringify({
        product_id: 'product_load_test',
        stock: 10000,
    }), { headers: { 'Content-Type': 'application/json' } });
}

export default function () {
    const start = Date.now();

    const res = http.post(`${BASE_URL}/purchase`, JSON.stringify({
        product_id: 'product_load_test',
        quantity: 1,
        lock_strategy: 'safe',
    }), { headers: { 'Content-Type': 'application/json' } });

    const duration = Date.now() - start;

    check(res, {
        'status is 200': (r) => r.status === 200,
        'response has success field': (r) => {
            try { return JSON.parse(r.body).hasOwnProperty('success'); }
            catch { return false; }
        },
    });

    if (res.status === 200) {
        const body = JSON.parse(res.body);
        lockContentionTime.add(duration);

        if (body.success) {
            purchaseSuccess.add(1);
        } else {
            purchaseFailed.add(1);
        }
    }

    sleep(0.1); // Small think time
}

export function teardown() {
    const res = http.get(`${BASE_URL}/stock/product_load_test`);
    if (res.status === 200) {
        const body = JSON.parse(res.body);
        console.log(`Final stock: ${body.stock} (started at 10000)`);
        console.log(`Orders processed: ${10000 - body.stock}`);
    }
}
