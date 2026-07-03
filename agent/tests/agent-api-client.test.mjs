import assert from 'node:assert/strict';
import test from 'node:test';
import { createAgentApiClient } from '../src/services/agent-api-client.mjs';
import { AgentApiFailureKind } from '../src/constants.mjs';

function createRecordingFetch(handler) {
  let lastRequest = null;

  const fetchImpl = async (url, init) => {
    lastRequest = { url, init };
    return handler(url, init);
  };

  return {
    fetchImpl,
    getLastRequest: () => lastRequest,
  };
}

test('enroll maps invalid token response', async () => {
  const recording = createRecordingFetch(async () => ({
    ok: false,
    status: 422,
    json: async () => ({ message: 'bad token' }),
  }));

  const client = createAgentApiClient(recording.fetchImpl);
  const result = await client.enroll(
    { serverUrl: 'https://example.test' },
    { enroll_token: 'bad-token', device_id: 'device-1', hostname: 'CLIENT-01' },
  );

  assert.equal(result.success, false);
  assert.equal(result.failureKind, AgentApiFailureKind.InvalidEnrollmentToken);
  assert.equal(result.message, 'bad token');
  assert.equal(recording.getLastRequest()?.url, 'https://example.test/api/agents/enroll');
});

test('heartbeat sends bearer token and parses interval', async () => {
  const recording = createRecordingFetch(async () => ({
    ok: true,
    status: 200,
    json: async () => ({ ok: true, next_interval_seconds: 45, commands: [] }),
  }));

  const client = createAgentApiClient(recording.fetchImpl);
  const result = await client.sendHeartbeat(
    {
      serverUrl: 'https://example.test/',
      agentToken: 'agent-token-123',
    },
    {
      agent_version: '1.0.0',
      device_id: 'device-1',
      hostname: 'CLIENT-01',
      os: 'Windows',
      os_version: '11',
      arch: 'x64',
      timestamp: new Date().toISOString(),
    },
  );

  assert.equal(result.success, true);
  assert.equal(result.response.nextIntervalSeconds, 45);
  assert.equal(recording.getLastRequest()?.url, 'https://example.test/api/agents/heartbeat');
  assert.equal(recording.getLastRequest()?.init.headers.Authorization, 'Bearer agent-token-123');
});
