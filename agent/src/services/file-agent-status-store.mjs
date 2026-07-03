import fs from 'node:fs/promises';
import path from 'node:path';
import { AgentRuntimeStatusCode, AgentServiceState } from '../constants.mjs';

function readField(source, snakeCase, camelCase, fallback) {
  return source?.[snakeCase] ?? source?.[camelCase] ?? fallback;
}

export function createFileAgentStatusStore(statusPath) {
  return {
    async load() {
      try {
        const raw = await fs.readFile(statusPath, 'utf8');
        const persisted = JSON.parse(raw);

        return {
          statusCode: readField(persisted, 'status_code', 'statusCode', AgentRuntimeStatusCode.NotConfigured),
          message: readField(persisted, 'message', 'message', 'Agent is not configured yet.'),
          updatedAt: readField(persisted, 'updated_at', 'updatedAt', new Date().toISOString()),
          serviceState: readField(persisted, 'service_state', 'serviceState', AgentServiceState.Unknown),
        };
      } catch (error) {
        if (error?.code === 'ENOENT') {
          return createDefaultStatus();
        }

        throw error;
      }
    },

    async save(status) {
      await fs.mkdir(path.dirname(statusPath), { recursive: true });

      const persisted = {
        status_code: status.statusCode,
        message: status.message,
        updated_at: new Date().toISOString(),
        service_state: status.serviceState,
      };

      await fs.writeFile(statusPath, `${JSON.stringify(persisted, null, 2)}\n`, 'utf8');
    },
  };
}

function createDefaultStatus() {
  return {
    statusCode: AgentRuntimeStatusCode.NotConfigured,
    message: 'Agent is not configured yet.',
    updatedAt: new Date().toISOString(),
    serviceState: AgentServiceState.Unknown,
  };
}
