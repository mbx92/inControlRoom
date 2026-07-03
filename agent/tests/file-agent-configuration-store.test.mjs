import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { createFakeSecretProtector } from '../src/services/fake-secret-protector.mjs';
import { createFileAgentConfigurationStore } from '../src/services/file-agent-configuration-store.mjs';

test('save and load round trip encrypts agent token', async () => {
  const directory = await fs.mkdtemp(path.join(os.tmpdir(), 'infra-agent-tests-'));
  const configFilePath = path.join(directory, 'config.json');
  const store = createFileAgentConfigurationStore(configFilePath, createFakeSecretProtector());

  try {
    await store.save({
      serverUrl: 'https://example.test',
      enrollmentToken: 'enroll-once',
      agentToken: 'agent-secret',
      heartbeatIntervalSeconds: 45,
    });

    const rawJson = await fs.readFile(configFilePath, 'utf8');
    assert.doesNotMatch(rawJson, /agent-secret/);
    assert.match(rawJson, /encrypted::/);

    const loaded = await store.load();
    assert.equal(loaded.serverUrl, 'https://example.test');
    assert.equal(loaded.enrollmentToken, 'enroll-once');
    assert.equal(loaded.agentToken, 'agent-secret');
    assert.equal(loaded.heartbeatIntervalSeconds, 45);
  } finally {
    await fs.rm(directory, { recursive: true, force: true });
  }
});
