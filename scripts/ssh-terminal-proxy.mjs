import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { Client as SshClient } from 'ssh2';
import { WebSocket, WebSocketServer } from 'ws';

const rootDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const envFile = path.join(rootDir, '.env');
const fileEnv = readEnvFile(envFile);

const host = process.env.SSH_TERMINAL_PROXY_HOST || fileEnv.SSH_TERMINAL_PROXY_HOST || '127.0.0.1';
const port = Number(process.env.SSH_TERMINAL_PROXY_PORT || fileEnv.SSH_TERMINAL_PROXY_PORT || 8078);
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
    path: '/terminal',
    perMessageDeflate: false,
});

wss.on('connection', (client, request) => {
    if (!isAllowedBrowserOrigin(request.headers.origin)) {
        closeSocket(client, 1008, 'Terminal proxy origin is not allowed.');
        return;
    }

    const url = new URL(request.url ?? '/terminal', `http://${request.headers.host ?? 'localhost'}`);

    createSshSession(url)
        .then((session) => bridgeTerminal(client, session))
        .catch((error) => {
            closeSocket(client, 1011, error instanceof Error ? error.message : 'Unable to create SSH session.');
        });
});

server.listen(port, host, () => {
    console.log(`[ssh-terminal-proxy] listening on ws://${host}:${port}/terminal`);
});

async function createSshSession(url) {
    const resolved = await resolveTerminalTarget(url);
    const ssh = new SshClient();

    return new Promise((resolve, reject) => {
        ssh.on('ready', () => {
            ssh.shell(
                {
                    term: 'xterm-256color',
                    cols: 120,
                    rows: 36,
                },
                (error, stream) => {
                    if (error) {
                        ssh.end();
                        reject(error);
                        return;
                    }

                    resolve({ ssh, stream, target: resolved });
                },
            );
        });

        ssh.on('error', reject);

        ssh.connect({
            host: resolved.host,
            port: Number(resolved.port || 22),
            username: resolved.username,
            password: resolved.auth_type === 'password' ? resolved.password : undefined,
            privateKey: resolved.auth_type === 'private_key' ? resolved.private_key : undefined,
            passphrase: resolved.auth_type === 'private_key' ? (resolved.passphrase || undefined) : undefined,
            keepaliveInterval: 10000,
            readyTimeout: 15000,
            hostVerifier: () => true,
        });
    });
}

async function resolveTerminalTarget(url) {
    const resolveUrl = url.searchParams.get('resolve');

    if (!resolveUrl) {
        throw new Error('Missing terminal resolver.');
    }

    const parsedResolveUrl = new URL(resolveUrl);

    if (!isAllowedResolverUrl(parsedResolveUrl)) {
        throw new Error('Terminal resolver is not allowed.');
    }

    const response = await fetch(parsedResolveUrl, {
        headers: { Accept: 'application/json' },
        redirect: 'error',
    });

    if (!response.ok) {
        throw new Error(`Terminal resolver returned HTTP ${response.status}.`);
    }

    const payload = await response.json();

    if (!payload?.host || !payload?.username || !payload?.auth_type) {
        throw new Error('Terminal resolver payload is incomplete.');
    }

    return payload;
}

function bridgeTerminal(client, session) {
    const { ssh, stream } = session;

    stream.on('data', (chunk) => {
        if (client.readyState === WebSocket.OPEN) {
            client.send(JSON.stringify({
                type: 'data',
                data: chunk.toString('utf8'),
            }));
        }
    });

    stream.stderr?.on('data', (chunk) => {
        if (client.readyState === WebSocket.OPEN) {
            client.send(JSON.stringify({
                type: 'data',
                data: chunk.toString('utf8'),
            }));
        }
    });

    stream.on('close', () => {
        if (client.readyState === WebSocket.OPEN || client.readyState === WebSocket.CONNECTING) {
            client.close(1000, 'SSH session closed.');
        }
        ssh.end();
    });

    ssh.on('close', () => {
        if (client.readyState === WebSocket.OPEN || client.readyState === WebSocket.CONNECTING) {
            client.close(1000, 'SSH connection closed.');
        }
    });

    ssh.on('error', (error) => {
        closeSocket(client, 1011, error instanceof Error ? error.message : 'SSH connection failed.');
    });

    client.on('message', (raw) => {
        const message = parseClientMessage(raw);

        if (message.type === 'input') {
            stream.write(message.data ?? '');
            return;
        }

        if (message.type === 'resize') {
            const cols = Number(message.cols || 120);
            const rows = Number(message.rows || 36);
            stream.setWindow(rows, cols, rows * 16, cols * 9);
        }
    });

    client.on('close', () => {
        stream.end('exit\n');
        ssh.end();
    });

    client.on('error', () => {
        stream.end('exit\n');
        ssh.end();
    });
}

function parseClientMessage(raw) {
    const text = Buffer.isBuffer(raw) ? raw.toString('utf8') : String(raw);

    try {
        return JSON.parse(text);
    } catch {
        return { type: 'input', data: text };
    }
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
            : 'Terminal relay closed.';

    return text.slice(0, 120) || 'Terminal relay closed.';
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
        && url.pathname.startsWith('/proxy/headscale-terminal/');
}
