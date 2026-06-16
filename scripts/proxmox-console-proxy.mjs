import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { WebSocket, WebSocketServer } from 'ws';

const rootDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const envFile = path.join(rootDir, '.env');
const fileEnv = readEnvFile(envFile);

const host = process.env.PROXMOX_CONSOLE_PROXY_HOST || fileEnv.PROXMOX_CONSOLE_PROXY_HOST || '127.0.0.1';
const port = Number(process.env.PROXMOX_CONSOLE_PROXY_PORT || fileEnv.PROXMOX_CONSOLE_PROXY_PORT || 8077);
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
    path: '/console',
    perMessageDeflate: false,
});

wss.on('connection', (client, request) => {
    if (!isAllowedBrowserOrigin(request.headers.origin)) {
        closeSocket(client, 1008, 'Console proxy origin is not allowed.');
        return;
    }

    const url = new URL(request.url ?? '/console', `http://${request.headers.host ?? 'localhost'}`);
    createUpstreamConnection(url)
        .then((upstream) => bridgeSockets(client, upstream))
        .catch((error) => {
            closeSocket(client, 1011, error instanceof Error ? error.message : 'Unable to resolve console session.');
        });
});

server.listen(port, host, () => {
    console.log(`[proxmox-console-proxy] listening on ws://${host}:${port}/console`);
});

function closeSocket(socket, code, reason) {
    if (socket.readyState === WebSocket.OPEN || socket.readyState === WebSocket.CONNECTING) {
        socket.close(code, normalizeReason(reason));
    }
}

async function createUpstreamConnection(url) {
    const resolved = await resolveConsoleTarget(url);
    const upstreamUrl = new URL(resolved.websocket_url);

    if (!['ws:', 'wss:'].includes(upstreamUrl.protocol) || !upstreamUrl.pathname.includes('/vncwebsocket')) {
        throw new Error('Unsupported console target.');
    }

    const headers = {
        Cookie: `PVEAuthCookie=${resolved.auth_cookie || resolved.ticket}`,
        Origin: `${upstreamUrl.protocol === 'wss:' ? 'https:' : 'http:'}//${upstreamUrl.host}`,
    };

    return new WebSocket(upstreamUrl, {
        headers,
        perMessageDeflate: false,
        rejectUnauthorized: resolved.verify_ssl !== false,
    });
}

async function resolveConsoleTarget(url) {
    const resolveUrl = url.searchParams.get('resolve');

    if (!resolveUrl) {
        throw new Error('Missing console resolver.');
    }

    const parsedResolveUrl = new URL(resolveUrl);

    if (!isAllowedResolverUrl(parsedResolveUrl)) {
        throw new Error('Console resolver is not allowed.');
    }

    const response = await fetch(parsedResolveUrl, {
        headers: {
            Accept: 'application/json',
        },
        redirect: 'error',
    });

    if (!response.ok) {
        throw new Error(`Console resolver returned HTTP ${response.status}.`);
    }

    const payload = await response.json();

    if (!payload?.websocket_url || !payload?.ticket) {
        throw new Error('Console resolver payload is incomplete.');
    }

    return payload;
}

function bridgeSockets(client, upstream) {
    let opened = false;

    upstream.on('open', () => {
        opened = true;
    });

    upstream.on('message', (data, isBinary) => {
        if (client.readyState === WebSocket.OPEN) {
            client.send(data, { binary: isBinary });
        }
    });

    upstream.on('close', (code, reason) => {
        if (client.readyState === WebSocket.OPEN || client.readyState === WebSocket.CONNECTING) {
            client.close(code || 1011, normalizeReason(reason));
        }
    });

    upstream.on('error', (error) => {
        const message = error instanceof Error ? error.message : 'Upstream console connection failed.';

        if (!opened && client.readyState === WebSocket.OPEN) {
            closeSocket(client, 1011, message);
            return;
        }

        if (client.readyState === WebSocket.OPEN) {
            client.close(1011, 'Upstream console connection failed.');
        }
    });

    client.on('message', (data, isBinary) => {
        if (upstream.readyState === WebSocket.OPEN) {
            upstream.send(data, { binary: isBinary });
        }
    });

    client.on('close', () => {
        if (upstream.readyState === WebSocket.OPEN || upstream.readyState === WebSocket.CONNECTING) {
            upstream.close();
        }
    });

    client.on('error', () => {
        if (upstream.readyState === WebSocket.OPEN || upstream.readyState === WebSocket.CONNECTING) {
            upstream.close();
        }
    });
}

function normalizeReason(reason) {
    const text = typeof reason === 'string'
        ? reason
        : Buffer.isBuffer(reason)
            ? reason.toString('utf8')
            : 'Console relay closed.';

    return text.slice(0, 120) || 'Console relay closed.';
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
        && url.pathname.startsWith('/proxy/proxmox-console/');
}
