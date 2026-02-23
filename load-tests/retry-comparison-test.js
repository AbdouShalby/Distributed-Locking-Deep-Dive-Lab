/**
 * k6 Load Test: Retry Strategy Comparison
 *
 * Simulates lock contention with different retry behaviors
 * by varying request patterns and measuring response times.
 *
 * Tests three scenarios:
 * 1. Burst (no backoff) — all requests hit simultaneously
 * 2. Staggered — requests arrive with slight delays
 * 3. Ramped — gradual increase in load
 *
 * Run:
 *   k6 run load-tests/retry-comparison-test.js
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Trend } from 'k6/metrics';

const burstSuccess = new Counter('burst_success');
const burstFailed = new Counter('burst_failed');
const burstDuration = new Trend('burst_duration_ms');

export const options = {
    scenarios: {
        // Scenario 1: Burst — maximum contention
        burst: {
            executor: 'shared-iterations',
            vus: 30,
            iterations: 30,
            maxDuration: '30s',
            exec: 'burstScenario',
            startTime: '0s',
        },
        // Scenario 2: Ramped — gradual load increase
        ramped: {
            executor: 'ramping-vus',
            startVUs: 1,
            stages: [
                { duration: '10s', target: 20 },
                { duration: '20s', target: 20 },
                { duration: '5s', target: 0 },
            ],
            exec: 'rampedScenario',
            startTime: '35s',
        },
    },
    thresholds: {
        'http_req_duration': ['p(95)<1000'],
        'burst_success': ['count>0'],
    },
};

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8080';

export function setup() {
    http.post(`${BASE_URL}/reset`, JSON.stringify({
        product_id: 'product_retry_test',
        stock: 15,
    }), { headers: { 'Content-Type': 'application/json' } });
}

export function burstScenario() {
    const start = Date.now();

    const res = http.post(`${BASE_URL}/purchase`, JSON.stringify({
        product_id: 'product_retry_test',
        quantity: 1,
        lock_strategy: 'safe',
    }), { headers: { 'Content-Type': 'application/json' } });

    burstDuration.add(Date.now() - start);

    if (res.status === 200) {
        const body = JSON.parse(res.body);
        body.success ? burstSuccess.add(1) : burstFailed.add(1);
    } else {
        burstFailed.add(1);
    }
}

export function rampedScenario() {
    const res = http.post(`${BASE_URL}/purchase`, JSON.stringify({
        product_id: 'product_retry_test',
        quantity: 1,
        lock_strategy: 'safe',
    }), { headers: { 'Content-Type': 'application/json' } });

    check(res, { 'ramped status 200': (r) => r.status === 200 });

    sleep(0.5); // Think time between requests
}
