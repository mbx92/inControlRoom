import { createApp, h } from 'vue';
import * as Sentry from '@sentry/vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from 'ziggy-js';
import { initializeTheme } from './composables/useTheme';

import '../css/app.css';

const appName = import.meta.env.VITE_APP_NAME || 'InfraControl';
const sentryDsn = import.meta.env.VITE_SENTRY_DSN;
const sentryEnabled = import.meta.env.VITE_SENTRY_ENABLED === 'true' && Boolean(sentryDsn);
const sentryTraceRate = Number(import.meta.env.VITE_SENTRY_TRACES_SAMPLE_RATE ?? 0);

initializeTheme();

createInertiaApp({
    title: (title) => title ? `${title} — ${appName}` : appName,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue);

        if (sentryEnabled) {
            const sentryConfig = {
                app,
                dsn: sentryDsn,
                environment: import.meta.env.VITE_SENTRY_ENVIRONMENT || import.meta.env.MODE,
                release: import.meta.env.VITE_SENTRY_RELEASE || undefined,
                autoSessionTracking: false,
            };

            if (! Number.isNaN(sentryTraceRate) && sentryTraceRate > 0) {
                sentryConfig.tracesSampleRate = sentryTraceRate;
            }

            Sentry.init(sentryConfig);

            const authUser = props?.initialPage?.props?.auth?.user;

            if (authUser) {
                Sentry.setUser({
                    id: String(authUser.id),
                    email: authUser.email,
                    username: authUser.name,
                });
            }

            Sentry.setTag('inertia_component', props?.initialPage?.component ?? 'unknown');
        }

        return app.mount(el);
    },
    progress: {
        color: '#FCD535',
        showSpinner: true,
    },
});
