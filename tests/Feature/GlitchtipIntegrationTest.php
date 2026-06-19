<?php

namespace Tests\Feature;

use Tests\TestCase;

class GlitchtipIntegrationTest extends TestCase
{
    public function test_csp_reporting_header_is_added_when_security_endpoint_is_configured(): void
    {
        config()->set('glitchtip.security_endpoint', 'https://glitchtip.example.invalid/api/2/security/?glitchtip_key=test');
        config()->set('glitchtip.csp_report_only', true);
        config()->set('glitchtip.csp_policy', "default-src 'self'; script-src 'self' 'unsafe-inline';");

        $response = $this->get('/login');

        $response->assertOk();
        $response->assertHeader(
            'Content-Security-Policy-Report-Only',
            "default-src 'self'; script-src 'self' 'unsafe-inline'; report-uri https://glitchtip.example.invalid/api/2/security/?glitchtip_key=test;",
        );
    }
}
