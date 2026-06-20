import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import os from 'os';

const vitePort = 5173;

function getLanIp() {
    const nets = os.networkInterfaces();
    for (const name of Object.keys(nets)) {
        for (const net of nets[name] ?? []) {
            if (net.family === 'IPv4' && !net.internal) {
                return net.address;
            }
        }
    }
    return '127.0.0.1';
}

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const lanMode = env.VITE_DEV_LAN === '1' || env.VITE_DEV_LAN === 'true';
    const buildSourcemap = env.VITE_BUILD_SOURCEMAP === '1' || env.VITE_BUILD_SOURCEMAP === 'true';
    const lanIp = env.VITE_DEV_LAN_IP || getLanIp();
    const devHost = lanMode ? '0.0.0.0' : '127.0.0.1';
    const devOrigin = lanMode ? `http://${lanIp}:${vitePort}` : undefined;
    const appUrl = env.APP_URL || 'http://127.0.0.1:8088';

    const corsOrigins = lanMode
        ? [appUrl, `http://${lanIp}:8088`, 'http://127.0.0.1:8088', 'http://localhost:8088']
        : ['http://127.0.0.1:8088', 'http://localhost:8088'];

    return {
        plugins: [
            laravel({
                input: ['resources/js/app.js'],
                refresh: [
                    'resources/views/**',
                    'routes/**',
                    'app/**',
                    // Fallback: full reload on remote clients when Inertia page HMR is flaky over LAN
                    'resources/js/Pages/**',
                    'resources/js/Components/**',
                ],
            }),
            vue({
                template: {
                    transformAssetUrls: {
                        base: null,
                        includeAbsolute: false,
                    },
                },
            }),
            tailwindcss(),
            {
                name: 'lan-dev-hints',
                configureServer(server) {
                    server.httpServer?.once('listening', () => {
                        if (!lanMode) return;
                        console.log('');
                        console.log('  LAN dev mode');
                        console.log(`  App (all devices):  http://${lanIp}:8088`);
                        console.log(`  Vite origin:        ${devOrigin}`);
                        console.log(`  HMR WebSocket:      ws://${lanIp}:${vitePort}`);
                        console.log('  Restart Vite after IP/Wi-Fi change.');
                        console.log('');
                    });
                },
            },
        ],
        resolve: {
            alias: {
                '@': '/resources/js',
            },
        },
        server: {
            host: devHost,
            port: vitePort,
            strictPort: true,
            // Required so @vite/client and module URLs resolve correctly on remote browsers
            origin: devOrigin,
            cors: {
                origin: corsOrigins,
            },
            hmr: lanMode
                ? {
                    host: lanIp,
                    port: vitePort,
                    clientPort: vitePort,
                    protocol: 'ws',
                }
                : {
                    host: '127.0.0.1',
                    port: vitePort,
                    protocol: 'ws',
                },
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
        build: {
            sourcemap: buildSourcemap,
        },
    };
});
