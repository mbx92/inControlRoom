import fs from 'node:fs';
import http from 'node:http';
import net from 'node:net';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { WebSocket, WebSocketServer } from 'ws';

const rootDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const envFile = path.join(rootDir, '.env');
const fileEnv = readEnvFile(envFile);

const host = process.env.VNC_PROXY_HOST || fileEnv.VNC_PROXY_HOST || '127.0.0.1';
const port = Number(process.env.VNC_PROXY_PORT || fileEnv.VNC_PROXY_PORT || 8079);
const appUrl = process.env.APP_URL || fileEnv.APP_URL || 'http://127.0.0.1:8000';
const allowedAppOrigins = buildAllowedAppOrigins(appUrl);

const server = http.createServer((request, response) => {
    if (request.url === '/healthz') {
        response.writeHead(200, { 'Content-Type': 'application/json' });
        response.end(JSON.stringify({ ok: true }));
        return;
    }

    response.writeHead(404, { 'Content-Type': 'application/json' });
    response.end(JSON.stringify({ ok: false }));
});

const wss = new WebSocketServer({
    server,
    path: '/vnc',
    perMessageDeflate: false,
});

wss.on('connection', (client, request) => {
    if (!isAllowedBrowserOrigin(request.headers.origin)) {
        closeSocket(client, 1008, 'VNC proxy origin is not allowed.');
        return;
    }

    const url = new URL(request.url ?? '/vnc', `http://${request.headers.host ?? 'localhost'}`);

    createTcpSession(url)
        .then((upstream) => bridgeVnc(client, upstream))
        .catch((error) => {
            closeSocket(client, 1011, error instanceof Error ? error.message : 'Unable to create VNC session.');
        });
});

server.listen(port, host, () => {
    console.log(`[vnc-proxy] listening on ws://${host}:${port}/vnc`);
});

async function createTcpSession(url) {
    const resolved = await resolveVncTarget(url);

    return new Promise((resolve, reject) => {
        const socket = net.createConnection({
            host: resolved.host,
            port: Number(resolved.port || 5900),
        });

        socket.setNoDelay(true);
        socket.once('connect', () => resolve({ socket }));
        socket.once('error', reject);
    });
}

async function resolveVncTarget(url) {
    const resolveUrl = url.searchParams.get('resolve');

    if (!resolveUrl) {
        throw new Error('Missing VNC resolver.');
    }

    const parsedResolveUrl = new URL(resolveUrl);

    if (!isAllowedResolverUrl(parsedResolveUrl)) {
        throw new Error('VNC resolver is not allowed.');
    }

    const response = await fetch(parsedResolveUrl, {
        headers: { Accept: 'application/json' },
        redirect: 'error',
    });

    if (!response.ok) {
        throw new Error(`VNC resolver returned HTTP ${response.status}.`);
    }

    const payload = await response.json();

    if (!payload?.host || !payload?.port) {
        throw new Error('VNC resolver payload is incomplete.');
    }

    return payload;
}

function bridgeVnc(client, session) {
    const { socket } = session;

    socket.on('data', (chunk) => {
        if (client.readyState === WebSocket.OPEN) {
            client.send(chunk, { binary: true });
        }
    });

    socket.on('close', () => {
        if (client.readyState === WebSocket.OPEN || client.readyState === WebSocket.CONNECTING) {
            client.close(1000, 'VNC socket closed.');
        }
    });

    socket.on('error', (error) => {
        closeSocket(client, 1011, error instanceof Error ? error.message : 'VNC socket failed.');
    });

    client.on('message', (data, isBinary) => {
        if (!isBinary && typeof data === 'string') {
            socket.write(data);
            return;
        }

        socket.write(Buffer.isBuffer(data) ? data : Buffer.from(data));
    });

    client.on('close', () => {
        socket.end();
        socket.destroy();
    });

    client.on('error', () => {
        socket.destroy();
    });
}

function closeSocket(socket, code, reason) {
    if (socket.readyState === WebSocket.OPEN || socket.readyState === WebSocket.CONNECTING) {
        socket.close(code, normalizeReason(reason));
    }
}

function normalizeReason(reason) {
    const text = typeof reason === 'string'
        ? reason
        : Buffer.isBuffer(reason)
            ? reason.toString('utf8')
            : 'VNC relay closed.';

    return text.slice(0, 120) || 'VNC relay closed.';
}

function readEnvFile(filePath) {
    if (!fs.existsSync(filePath)) {
        return {};
    }

    return fs.readFileSync(filePath, 'utf8')
        .split(/\r?\n/)
        .reduce((accumulator, line) => {
            const trimmed = line.trim();

            if (trimmed === '' || trimmed.startsWith('#')) {
                return accumulator;
            }

            const separatorIndex = trimmed.indexOf('=');

            if (separatorIndex === -1) {
                return accumulator;
            }

            const key = trimmed.slice(0, separatorIndex).trim();
            let value = trimmed.slice(separatorIndex + 1).trim();

            if (
                (value.startsWith('"') && value.endsWith('"'))
                || (value.startsWith("'") && value.endsWith("'"))
            ) {
                value = value.slice(1, -1);
            }

            accumulator[key] = value;

            return accumulator;
        }, {});
}

function buildAllowedAppOrigins(baseUrl) {
    try {
        const parsed = new URL(baseUrl);
        const origins = new Set([parsed.origin]);
        const localPorts = parsed.port ? `:${parsed.port}` : '';

        if (['127.0.0.1', 'localhost', '0.0.0.0'].includes(parsed.hostname)) {
            origins.add(`${parsed.protocol}//127.0.0.1${localPorts}`);
            origins.add(`${parsed.protocol}//localhost${localPorts}`);
        }

        return origins;
    } catch {
        return new Set(['http://127.0.0.1:8000', 'http://localhost:8000']);
    }
}

function isAllowedBrowserOrigin(origin) {
    return typeof origin === 'string' && allowedAppOrigins.has(origin);
}

function isAllowedResolverUrl(url) {
    const isHttp = url.protocol === 'http:' || url.protocol === 'https:';

    return isHttp
        && allowedAppOrigins.has(url.origin)
        && url.pathname.startsWith('/proxy/headscale-vnc/');
}
