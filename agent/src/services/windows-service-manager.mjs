import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { AgentServiceState } from '../constants.mjs';

const execFileAsync = promisify(execFile);

export function createWindowsServiceManager(serviceName) {
  return {
    async getSnapshot() {
      try {
        const { stdout } = await execFileAsync('sc', ['query', serviceName], { windowsHide: true });
        const state = parseServiceState(stdout);

        return {
          installed: true,
          state,
          displayText: formatState(state),
        };
      } catch {
        return {
          installed: false,
          state: AgentServiceState.NotInstalled,
          displayText: 'Not installed',
        };
      }
    },

    async start() {
      try {
        await execFileAsync('sc', ['start', serviceName], { windowsHide: true });
      } catch (error) {
        const stderr = error?.stderr?.toString?.() ?? '';
        if (/Access is denied|FAILED 5/i.test(stderr)) {
          throw new Error('Access denied. Start the service from an elevated InfraControl Agent Config window or an Administrator PowerShell.');
        }

        throw new Error(stderr.trim() || error.message || 'Failed to start service.');
      }

      await waitForState(serviceName, AgentServiceState.Running);
    },

    async stop() {
      try {
        await execFileAsync('sc', ['stop', serviceName], { windowsHide: true });
      } catch (error) {
        const stderr = error?.stderr?.toString?.() ?? '';
        if (/Access is denied|FAILED 5/i.test(stderr)) {
          throw new Error('Access denied. Stop the service from an elevated InfraControl Agent Config window or an Administrator PowerShell.');
        }

        throw new Error(stderr.trim() || error.message || 'Failed to stop service.');
      }

      await waitForState(serviceName, AgentServiceState.Stopped);
    },
  };
}

function parseServiceState(output) {
  const stateLine = output
    .split(/\r?\n/)
    .map((line) => line.trim())
    .find((line) => line.startsWith('STATE'));

  if (!stateLine) {
    return AgentServiceState.Unknown;
  }

  if (stateLine.includes('RUNNING')) {
    return AgentServiceState.Running;
  }

  if (stateLine.includes('STOPPED')) {
    return AgentServiceState.Stopped;
  }

  if (stateLine.includes('START_PENDING')) {
    return AgentServiceState.StartPending;
  }

  if (stateLine.includes('STOP_PENDING')) {
    return AgentServiceState.StopPending;
  }

  return AgentServiceState.Unknown;
}

function formatState(state) {
  switch (state) {
    case AgentServiceState.Running:
      return 'Running';
    case AgentServiceState.Stopped:
      return 'Stopped';
    case AgentServiceState.StartPending:
      return 'StartPending';
    case AgentServiceState.StopPending:
      return 'StopPending';
    default:
      return 'Unknown';
  }
}

async function waitForState(serviceName, desiredState, timeoutMs = 20_000) {
  const startedAt = Date.now();

  while (Date.now() - startedAt < timeoutMs) {
    const snapshot = await createWindowsServiceManager(serviceName).getSnapshot();
    if (snapshot.state === desiredState) {
      return;
    }

    await new Promise((resolve) => setTimeout(resolve, 500));
  }

  throw new Error(`Timed out waiting for service ${serviceName} to reach ${formatState(desiredState)}.`);
}
