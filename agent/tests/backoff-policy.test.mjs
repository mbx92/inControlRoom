import assert from 'node:assert/strict';
import test from 'node:test';
import { createBackoffPolicy } from '../src/services/backoff-policy.mjs';

const cases = [
  [0, 0],
  [1, 5],
  [2, 10],
  [3, 20],
  [6, 120],
];

for (const [failureCount, expectedSeconds] of cases) {
  test(`returns expected delay curve for ${failureCount} failures`, () => {
    const policy = createBackoffPolicy();
    assert.equal(policy.getDelaySeconds(failureCount), expectedSeconds);
  });
}
