<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="infracontrol" data-app-theme="industrial-ops">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="InfraControl — Service unavailable">
    <title>@yield('title', '503 — Service Unavailable')</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='6' fill='%23FCD535'/><text x='16' y='22' text-anchor='middle' font-family='system-ui' font-weight='700' font-size='14' fill='%23181A20'>IC</text></svg>">

    @vite(['resources/css/app.css'])
</head>
<body class="antialiased">
    <div class="status-page">
        <div class="status-shell">
            @yield('content')
        </div>
    </div>
</body>
</html>
