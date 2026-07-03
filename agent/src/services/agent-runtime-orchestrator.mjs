import {
  AgentApiFailureKind,
  AgentRuntimeStatusCode,
  DEFAULT_HEARTBEAT_INTERVAL_SECONDS,
} from '../constants.mjs';
import {
  hasAgentToken,
  hasEnrollmentToken,
  hasServerUrl,
} from './file-agent-configuration-store.mjs';

function normalizeInterval(intervalSeconds) {
  return intervalSeconds > 0 ? intervalSeconds : DEFAULT_HEARTBEAT_INTERVAL_SECONDS;
}

export function createAgentRuntimeOrchestrator({
  configurationStore,
  statusStore,
  inventoryCollector,
  apiClient,
  payloadFactory,
  backoffPolicy,
}) {
  let consecutiveFailures = 0;

  return {
    async executeOnce() {
      const configuration = await configurationStore.load();

      if (!hasServerUrl(configuration) || (!hasAgentToken(configuration) && !hasEnrollmentToken(configuration))) {
        await saveStatus(statusStore, AgentRuntimeStatusCode.NotConfigured, 'Server URL and enrollment token are required before the agent can start.');
        consecutiveFailures = 0;
        return DEFAULT_HEARTBEAT_INTERVAL_SECONDS;
      }

      const device = await inventoryCollector.captureDevice();

      if (!hasAgentToken(configuration)) {
        await saveStatus(statusStore, AgentRuntimeStatusCode.Enrolling, 'Enrolling the device with InfraControl.');

        const enrollResult = await apiClient.enroll(
          configuration,
          payloadFactory.createEnrollRequest(configuration, device),
        );

        if (!enrollResult.success || !enrollResult.response) {
          consecutiveFailures += 1;
          const statusCode =
            enrollResult.failureKind === AgentApiFailureKind.InvalidEnrollmentToken
              ? AgentRuntimeStatusCode.InvalidEnrollmentToken
              : enrollResult.failureKind === AgentApiFailureKind.ServerUnreachable
                ? AgentRuntimeStatusCode.ServerUnreachable
                : AgentRuntimeStatusCode.Error;

          await saveStatus(statusStore, statusCode, enrollResult.message ?? 'Enrollment failed.');
          return backoffPolicy.getDelaySeconds(consecutiveFailures);
        }

        configuration.agentToken = enrollResult.response.agentToken;
        configuration.enrollmentToken = null;
        configuration.heartbeatIntervalSeconds = normalizeInterval(enrollResult.response.intervalSeconds);
        await configurationStore.save(configuration);
        await saveStatus(statusStore, AgentRuntimeStatusCode.Enrolled, 'Enrollment completed successfully.');
        consecutiveFailures = 0;
      }

      const inventory = await inventoryCollector.captureInventory();
      const heartbeatResult = await apiClient.sendHeartbeat(
        configuration,
        payloadFactory.createHeartbeatRequest(device, inventory),
      );

      if (!heartbeatResult.success || !heartbeatResult.response) {
        consecutiveFailures += 1;
        const statusCode =
          heartbeatResult.failureKind === AgentApiFailureKind.ServerUnreachable
            ? AgentRuntimeStatusCode.ServerUnreachable
            : heartbeatResult.failureKind === AgentApiFailureKind.Unauthorized
              ? AgentRuntimeStatusCode.HeartbeatDegraded
              : AgentRuntimeStatusCode.Error;

        await saveStatus(statusStore, statusCode, heartbeatResult.message ?? 'Heartbeat failed.');
        return backoffPolicy.getDelaySeconds(consecutiveFailures);
      }

      configuration.heartbeatIntervalSeconds = normalizeInterval(heartbeatResult.response.nextIntervalSeconds);
      await configurationStore.save(configuration);
      await saveStatus(statusStore, AgentRuntimeStatusCode.HeartbeatHealthy, 'Heartbeat sent successfully.');
      consecutiveFailures = 0;

      return configuration.heartbeatIntervalSeconds;
    },
  };
}

async function saveStatus(statusStore, statusCode, message) {
  const current = await statusStore.load();
  current.statusCode = statusCode;
  current.message = message;
  await statusStore.save(current);
}
