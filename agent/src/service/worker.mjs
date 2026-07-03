import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { AgentRuntimeStatusCode, AgentServiceState, DEFAULT_HEARTBEAT_INTERVAL_SECONDS } from '../constants.mjs';
import { configPath, statusPath } from '../paths.mjs';
import { createAgentApiClient } from '../services/agent-api-client.mjs';
import { createAgentRuntimeOrchestrator } from '../services/agent-runtime-orchestrator.mjs';
import { createBackoffPolicy } from '../services/backoff-policy.mjs';
import { createDpapiSecretProtector } from '../services/dpapi-secret-protector.mjs';
import { createFileAgentConfigurationStore } from '../services/file-agent-configuration-store.mjs';
import { createFileAgentStatusStore } from '../services/file-agent-status-store.mjs';
import { createHeartbeatPayloadFactory } from '../services/heartbeat-payload-factory.mjs';
import { createWindowsDeviceIdentityProvider } from '../services/windows-device-identity-provider.mjs';
import { createWindowsDeviceInventoryCollector } from '../services/windows-device-inventory-collector.mjs';

function readAgentVersion() {
  try {
    const packageJsonPath = fileURLToPath(new URL('../../package.json', import.meta.url));
    const packageJson = JSON.parse(readFileSync(packageJsonPath, 'utf8'));
    return packageJson.version ?? '1.0.0';
  } catch {
    return '1.0.0';
  }
}

export function createAgentRuntime() {
  const secretProtector = createDpapiSecretProtector();
  const configurationStore = createFileAgentConfigurationStore(configPath, secretProtector);
  const statusStore = createFileAgentStatusStore(statusPath);
  const identityProvider = createWindowsDeviceIdentityProvider();
  const inventoryCollector = createWindowsDeviceInventoryCollector(identityProvider, readAgentVersion());

  const orchestrator = createAgentRuntimeOrchestrator({
    configurationStore,
    statusStore,
    inventoryCollector,
    apiClient: createAgentApiClient(),
    payloadFactory: createHeartbeatPayloadFactory(),
    backoffPolicy: createBackoffPolicy(),
  });

  return {
    configurationStore,
    statusStore,
    orchestrator,
  };
}

export async function runAgentWorker({ orchestrator, statusStore, shouldStop, onError = console.error }) {
  await updateServiceState(statusStore, AgentServiceState.Running, AgentRuntimeStatusCode.NotConfigured, 'Service is starting.');

  while (!shouldStop()) {
    try {
      let delaySeconds = await orchestrator.executeOnce();
      if (delaySeconds <= 0) {
        delaySeconds = DEFAULT_HEARTBEAT_INTERVAL_SECONDS;
      }

      await sleep(delaySeconds * 1000, shouldStop);
    } catch (error) {
      if (shouldStop()) {
        break;
      }

      onError('Unhandled exception in agent worker loop.', error);
      await updateServiceState(statusStore, AgentServiceState.Running, AgentRuntimeStatusCode.Error, error.message);
      await sleep(15_000, shouldStop);
    }
  }

  await updateServiceState(statusStore, AgentServiceState.Stopped, AgentRuntimeStatusCode.ServiceStopped, 'Service stopped.');
}

async function updateServiceState(statusStore, serviceState, fallbackStatusCode, fallbackMessage) {
  const snapshot = await statusStore.load();
  snapshot.serviceState = serviceState;

  if (
    snapshot.statusCode === AgentRuntimeStatusCode.NotConfigured ||
    snapshot.statusCode === AgentRuntimeStatusCode.ServiceStopped ||
    snapshot.statusCode === AgentRuntimeStatusCode.Error
  ) {
    snapshot.statusCode = fallbackStatusCode;
    snapshot.message = fallbackMessage;
  }

  await statusStore.save(snapshot);
}

function sleep(ms, shouldStop) {
  return new Promise((resolve) => {
    const startedAt = Date.now();

    const tick = () => {
      if (shouldStop()) {
        resolve();
        return;
      }

      const elapsed = Date.now() - startedAt;
      if (elapsed >= ms) {
        resolve();
        return;
      }

      setTimeout(tick, Math.min(250, ms - elapsed));
    };

    tick();
  });
}
