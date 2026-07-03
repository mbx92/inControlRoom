import http from 'node:http';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { AgentRuntimeStatusCode, AgentServiceState, SERVICE_NAME } from '../constants.mjs';
import { configPath, statusPath } from '../paths.mjs';
import { createDpapiSecretProtector } from '../services/dpapi-secret-protector.mjs';
import { createFileAgentConfigurationStore } from '../services/file-agent-configuration-store.mjs';
import { createFileAgentStatusStore } from '../services/file-agent-status-store.mjs';
import { createWindowsServiceManager } from '../services/windows-service-manager.mjs';

const execFileAsync = promisify(execFile);
const publicDirectory = fileURLToPath(new URL('./public', import.meta.url));

const secretProtector = createDpapiSecretProtector();
const configurationStore = createFileAgentConfigurationStore(configPath, secretProtector);
const statusStore = createFileAgentStatusStore(statusPath);
const serviceManager = createWindowsServiceManager(SERVICE_NAME);

export async function startConfigServer() {
  const server = http.createServer(async (request, response) => {
    try {
      await routeRequest(request, response);
    } catch (error) {
      sendJson(response, 500, { message: error.message });
    }
  });

  await new Promise((resolve) => {
    server.listen(0, '127.0.0.1', resolve);
  });

  const address = server.address();
  const port = typeof address === 'object' && address ? address.port : 0;
  const url = `http://127.0.0.1:${port}/`;

  await openBrowser(url);

  return { server, url };
}

async function routeRequest(request, response) {
  const url = new URL(request.url ?? '/', `http://${request.headers.host ?? '127.0.0.1'}`);

  if (request.method === 'GET' && (url.pathname === '/' || url.pathname === '/index.html')) {
    return sendFile(response, path.join(publicDirectory, 'index.html'), 'text/html; charset=utf-8');
  }

  if (request.method === 'GET' && url.pathname === '/app.js') {
    return sendFile(response, path.join(publicDirectory, 'app.js'), 'application/javascript; charset=utf-8');
  }

  if (request.method === 'GET' && url.pathname === '/styles.css') {
    return sendFile(response, path.join(publicDirectory, 'styles.css'), 'text/css; charset=utf-8');
  }

  if (request.method === 'GET' && url.pathname === '/api/config') {
    const configuration = await configurationStore.load();
    return sendJson(response, 200, {
      server_url: configuration.serverUrl ?? '',
      enrollment_token: configuration.enrollmentToken ?? '',
    });
  }

  if (request.method === 'GET' && url.pathname === '/api/status') {
    const status = await statusStore.load();
    const service = await serviceManager.getSnapshot();

    return sendJson(response, 200, {
      service_status: service.displayText,
      agent_status: mapStatusCode(status.statusCode),
      last_updated: formatTimestamp(status.updatedAt),
      message: status.message ?? '-',
      can_start:
        service.installed &&
        service.state !== AgentServiceState.Running &&
        service.state !== AgentServiceState.StartPending,
      can_stop: service.installed && service.state === AgentServiceState.Running,
    });
  }

  if (request.method === 'POST' && url.pathname === '/api/config') {
    const body = await readJsonBody(request);
    const current = await configurationStore.load();
    const enrollmentToken = normalize(body.enrollment_token);
    current.serverUrl = normalize(body.server_url);
    current.enrollmentToken = enrollmentToken;
    if (enrollmentToken) {
      current.agentToken = null;
    }
    await configurationStore.save(current);
    return sendJson(response, 200, { ok: true, message: 'Configuration saved' });
  }

  if (request.method === 'POST' && url.pathname === '/api/service/start') {
    await serviceManager.start();
    return sendJson(response, 200, { ok: true, message: 'Service started' });
  }

  if (request.method === 'POST' && url.pathname === '/api/service/stop') {
    await serviceManager.stop();
    return sendJson(response, 200, { ok: true, message: 'Service stopped' });
  }

  sendJson(response, 404, { message: 'Not found' });
}

async function sendFile(response, filePath, contentType) {
  const content = await readFile(filePath);
  response.writeHead(200, { 'Content-Type': contentType });
  response.end(content);
}

function sendJson(response, statusCode, payload) {
  response.writeHead(statusCode, { 'Content-Type': 'application/json; charset=utf-8' });
  response.end(JSON.stringify(payload));
}

async function readJsonBody(request) {
  const chunks = [];

  for await (const chunk of request) {
    chunks.push(chunk);
  }

  const raw = Buffer.concat(chunks).toString('utf8');
  return raw ? JSON.parse(raw) : {};
}

function normalize(value) {
  return typeof value === 'string' && value.trim() ? value.trim() : null;
}

function mapStatusCode(statusCode) {
  switch (statusCode) {
    case AgentRuntimeStatusCode.NotConfigured:
      return 'Not configured';
    case AgentRuntimeStatusCode.Enrolling:
      return 'Enrolling';
    case AgentRuntimeStatusCode.Enrolled:
      return 'Enrolled';
    case AgentRuntimeStatusCode.InvalidEnrollmentToken:
      return 'Invalid token';
    case AgentRuntimeStatusCode.ServerUnreachable:
      return 'Server unreachable';
    case AgentRuntimeStatusCode.HeartbeatHealthy:
      return 'Heartbeat healthy';
    case AgentRuntimeStatusCode.HeartbeatDegraded:
      return 'Heartbeat degraded';
    case AgentRuntimeStatusCode.ServiceStopped:
      return 'Service stopped';
    default:
      return 'Error';
  }
}

function formatTimestamp(value) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return '-';
  }

  const pad = (part) => String(part).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
}

async function openBrowser(url) {
  try {
    await execFileAsync('cmd', ['/c', 'start', '', url], { windowsHide: true });
  } catch {
    // Browser launch is best-effort.
  }
}
