<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="infracontrol" data-app-theme="industrial-ops">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta name="description" content="InfraControl — Unified IT Infrastructure Control Plane">

    <title inertia>InfraControl</title>

    <!-- Preconnect to Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='6' fill='%23FCD535'/><text x='16' y='22' text-anchor='middle' font-family='system-ui' font-weight='700' font-size='14' fill='%23181A20'>IC</text></svg>">

    <script>
        (() => {
            const storageKey = 'infracontrol-theme';
            const fallbackTheme = 'industrial-ops';
            const validThemes = new Set(['industrial-ops', 'premium-terminal', 'tactical-monitoring']);
            const storedTheme = window.localStorage.getItem(storageKey);
            const nextTheme = validThemes.has(storedTheme) ? storedTheme : fallbackTheme;
            document.documentElement.dataset.appTheme = nextTheme;
        })();
    </script>

    @routes
    @vite(['resources/js/app.js'])
    @inertiaHead
</head>
<body class="antialiased">
    @if (app()->environment('local'))
        <script>
            // Clear stale service workers that may cache broken [::1]:5173 asset URLs.
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.getRegistrations().then((registrations) => {
                    registrations.forEach((registration) => registration.unregister());
                });
            }
            if ('caches' in window) {
                caches.keys().then((keys) => keys.forEach((key) => caches.delete(key)));
            }
        </script>
    @endif
    @inertia
</body>
</html>
