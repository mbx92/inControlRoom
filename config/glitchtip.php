<?php

return [
    'security_endpoint' => env('GLITCHTIP_SECURITY_ENDPOINT'),
    'csp_report_only' => env('GLITCHTIP_CSP_REPORT_ONLY', true),
    'csp_policy' => env(
        'GLITCHTIP_CSP_POLICY',
        "default-src 'self'; "
        ."base-uri 'self'; "
        ."form-action 'self'; "
        ."frame-ancestors 'self'; "
        ."object-src 'none'; "
        ."img-src 'self' data: blob: https:; "
        ."font-src 'self' data: https:; "
        ."style-src 'self' 'unsafe-inline' https:; "
        ."script-src 'self' 'unsafe-inline' 'unsafe-eval' https:; "
        ."connect-src 'self' https: http: ws: wss:; "
        ."media-src 'self' data: blob: https:;",
    ),
];
