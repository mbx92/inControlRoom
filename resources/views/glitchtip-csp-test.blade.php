<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>GlitchTip CSP Test</title>
    <style>
        :root {
            color-scheme: light;
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #f4efe6;
            color: #1f2937;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background:
                radial-gradient(circle at top, rgba(217, 119, 6, 0.22), transparent 40%),
                linear-gradient(180deg, #f8f1e4 0%, #f4efe6 100%);
        }

        main {
            width: min(760px, calc(100% - 32px));
            border: 1px solid rgba(146, 64, 14, 0.18);
            border-radius: 24px;
            background: rgba(255, 251, 235, 0.96);
            box-shadow: 0 24px 64px rgba(146, 64, 14, 0.12);
            padding: 32px;
        }

        h1 {
            margin: 0 0 12px;
            font-size: clamp(2rem, 4vw, 3rem);
        }

        p, li {
            line-height: 1.65;
        }

        code {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 0.95em;
            background: rgba(146, 64, 14, 0.08);
            border-radius: 8px;
            padding: 0.2rem 0.4rem;
        }

        .panel {
            margin-top: 24px;
            padding: 20px;
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.84);
            border: 1px solid rgba(146, 64, 14, 0.12);
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 24px;
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: 0.85rem 1.1rem;
            font-weight: 600;
            text-decoration: none;
            border: 1px solid rgba(146, 64, 14, 0.18);
            color: #1f2937;
            background: #ffffff;
        }

        .button.primary {
            background: #b45309;
            color: #fff7ed;
            border-color: #92400e;
        }
    </style>
</head>
<body>
    <main>
        <p><strong>GlitchTip Security Test</strong></p>
        <h1>CSP report sedang dipicu dari halaman ini.</h1>
        <p>
            Halaman ini sengaja mengirim pelanggaran CSP ke endpoint
            <code>{{ $securityEndpoint }}</code>
            menggunakan mode <code>Content-Security-Policy-Report-Only</code>.
        </p>

        <div class="panel">
            <p><strong>Yang diuji dari halaman ini:</strong></p>
            <ul>
                <li>Inline script di bawah ini akan melanggar <code>script-src 'self'</code>.</li>
                <li>Gambar eksternal akan melanggar <code>img-src 'self'</code>.</li>
                <li>Karena ini report-only, halaman tetap terbuka dan tidak mengganggu operasional.</li>
            </ul>
        </div>

        <div class="panel">
            <p><strong>Policy uji:</strong></p>
            <code>{{ $policy }}</code>
        </div>

        <div class="actions">
            <a href="{{ route('settings.index') }}" class="button">Kembali ke Settings</a>
            <a href="{{ $securityEndpoint }}" class="button primary" target="_blank" rel="noreferrer">Buka Security Endpoint</a>
        </div>

        <img
            src="https://example.com/glitchtip-csp-test.png"
            alt="Intentional CSP report test"
            width="1"
            height="1"
            style="position:absolute;left:-9999px;top:-9999px"
        >

        <script>
            console.log('InfraControl CSP report test at {{ now()->toIso8601String() }}');
        </script>
    </main>
</body>
</html>
