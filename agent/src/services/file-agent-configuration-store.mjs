import fs from 'node:fs/promises';
import path from 'node:path';
import { DEFAULT_HEARTBEAT_INTERVAL_SECONDS } from '../constants.mjs';

function normalize(value) {
  return typeof value === 'string' && value.trim() ? value.trim() : null;
}

function readField(source, snakeCase, camelCase) {
  return source?.[snakeCase] ?? source?.[camelCase] ?? null;
}

export function createFileAgentConfigurationStore(configPath, secretProtector) {
  return {
    async load() {
      try {
        const raw = await fs.readFile(configPath, 'utf8');
        const persisted = JSON.parse(raw);

        const agentTokenCipher = readField(persisted, 'agent_token', 'agentToken');
        const interval = readField(persisted, 'heartbeat_interval_seconds', 'heartbeatIntervalSeconds');

        return {
          serverUrl: readField(persisted, 'server_url', 'serverUrl'),
          enrollmentToken: readField(persisted, 'enrollment_token', 'enrollmentToken'),
          agentToken: agentTokenCipher ? secretProtector.unprotect(agentTokenCipher) : null,
          heartbeatIntervalSeconds: interval > 0 ? interval : DEFAULT_HEARTBEAT_INTERVAL_SECONDS,
        };
      } catch (error) {
        if (error?.code === 'ENOENT') {
          return {
            serverUrl: null,
            enrollmentToken: null,
            agentToken: null,
            heartbeatIntervalSeconds: DEFAULT_HEARTBEAT_INTERVAL_SECONDS,
          };
        }

        throw error;
      }
    },

    async save(configuration) {
      await fs.mkdir(path.dirname(configPath), { recursive: true });

      const persisted = {
        server_url: normalize(configuration.serverUrl),
        enrollment_token: normalize(configuration.enrollmentToken),
        agent_token: configuration.agentToken ? secretProtector.protect(configuration.agentToken) : null,
        heartbeat_interval_seconds:
          configuration.heartbeatIntervalSeconds > 0
            ? configuration.heartbeatIntervalSeconds
            : DEFAULT_HEARTBEAT_INTERVAL_SECONDS,
      };

      await fs.writeFile(configPath, `${JSON.stringify(persisted, null, 2)}\n`, 'utf8');
    },
  };
}

export function hasServerUrl(configuration) {
  return Boolean(configuration.serverUrl?.trim());
}

export function hasAgentToken(configuration) {
  return Boolean(configuration.agentToken?.trim());
}

export function hasEnrollmentToken(configuration) {
  return Boolean(configuration.enrollmentToken?.trim());
}
