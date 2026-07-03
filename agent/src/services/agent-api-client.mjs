import { AgentApiFailureKind, HTTP_TIMEOUT_MS } from '../constants.mjs';

function buildEndpoint(serverUrl, relativePath) {
  const baseUrl = serverUrl?.trim() || 'http://localhost/';
  const normalized = baseUrl.endsWith('/') ? baseUrl : `${baseUrl}/`;
  return new URL(relativePath, normalized).toString();
}

async function readMessage(response) {
  try {
    const payload = await response.json();
    if (payload && typeof payload.message === 'string') {
      return payload.message;
    }
  } catch {
    const text = await response.text();
    return text.trim() || null;
  }

  return null;
}

export function createAgentApiClient(fetchImpl = fetch) {
  return {
    async enroll(configuration, request, signal) {
      try {
        const response = await fetchImpl(buildEndpoint(configuration.serverUrl, 'api/agents/enroll'), {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
          },
          body: JSON.stringify(request),
          signal: signal ?? AbortSignal.timeout(HTTP_TIMEOUT_MS),
        });

        if (response.ok) {
          const payload = await response.json();
          if (!payload) {
            return failure(AgentApiFailureKind.UnexpectedResponse, 'Server returned an empty enrollment payload.');
          }

          return {
            success: true,
            response: {
              agentId: payload.agent_id ?? payload.agentId ?? '',
              agentToken: payload.agent_token ?? payload.agentToken ?? '',
              siteId: payload.site_id ?? payload.siteId ?? '',
              intervalSeconds: payload.interval_seconds ?? payload.intervalSeconds ?? 0,
            },
          };
        }

        const message = await readMessage(response);

        if (response.status === 422) {
          return failure(AgentApiFailureKind.InvalidEnrollmentToken, message);
        }

        if (response.status === 400) {
          return failure(AgentApiFailureKind.ValidationError, message);
        }

        return failure(AgentApiFailureKind.UnexpectedResponse, message);
      } catch (error) {
        return failure(AgentApiFailureKind.ServerUnreachable, error.message);
      }
    },

    async sendHeartbeat(configuration, request, signal) {
      try {
        const response = await fetchImpl(buildEndpoint(configuration.serverUrl, 'api/agents/heartbeat'), {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            Authorization: `Bearer ${configuration.agentToken}`,
          },
          body: JSON.stringify(request),
          signal: signal ?? AbortSignal.timeout(HTTP_TIMEOUT_MS),
        });

        if (response.ok) {
          const payload = await response.json();
          if (!payload) {
            return failure(AgentApiFailureKind.UnexpectedResponse, 'Server returned an empty heartbeat payload.');
          }

          return {
            success: true,
            response: {
              nextIntervalSeconds: payload.next_interval_seconds ?? payload.nextIntervalSeconds ?? 0,
              commands: payload.commands ?? [],
            },
          };
        }

        const message = await readMessage(response);

        if (response.status === 401) {
          return failure(AgentApiFailureKind.Unauthorized, message);
        }

        if (response.status === 400) {
          return failure(AgentApiFailureKind.ValidationError, message);
        }

        return failure(AgentApiFailureKind.UnexpectedResponse, message);
      } catch (error) {
        return failure(AgentApiFailureKind.ServerUnreachable, error.message);
      }
    },
  };
}

function failure(kind, message) {
  return {
    success: false,
    failureKind: kind,
    message: message ?? undefined,
  };
}
