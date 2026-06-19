<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Sentry\EventId;
use Sentry\State\HubInterface;
use Tests\TestCase;

class SettingsGlitchtipFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_page_exposes_glitchtip_status(): void
    {
        config()->set('sentry.dsn', 'https://public@example.invalid/1');
        config()->set('sentry.environment', 'testing');
        config()->set('sentry.release', 'test-release');
        config()->set('glitchtip.security_endpoint', 'https://example.invalid/security');
        config()->set('glitchtip.csp_report_only', true);
        config()->set('app.ssh_terminal_proxy_health_url', 'http://terminal-proxy:8078/healthz');
        config()->set('app.ssh_terminal_proxy_managed_externally', true);
        Http::fake([
            'http://terminal-proxy:8078/healthz' => Http::response(['ok' => true], 200),
        ]);

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Index')
                ->where('glitchtip.enabled', true)
                ->where('glitchtip.backend_environment', 'testing')
                ->where('glitchtip.release', 'test-release')
                ->where('glitchtip.security_endpoint', 'https://example.invalid/security')
                ->where('glitchtip.csp_report_only', true)
                ->where('runtimeServices.ssh_terminal_proxy.health_url', 'http://terminal-proxy:8078/healthz')
                ->where('runtimeServices.ssh_terminal_proxy.managed_externally', true)
                ->where('runtimeServices.ssh_terminal_proxy.supports_process_control', false)
            );
    }

    public function test_admin_can_send_backend_glitchtip_test_event(): void
    {
        config()->set('sentry.dsn', 'https://public@example.invalid/1');

        $admin = User::factory()->admin()->create();

        $hub = Mockery::mock(HubInterface::class)->shouldIgnoreMissing();
        $hub->shouldReceive('captureMessage')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'InfraControl backend test event triggered at'))
            ->andReturn(new EventId('0123456789abcdef0123456789abcdef'));

        $this->app->instance(HubInterface::class, $hub);

        $this->actingAs($admin)
            ->post(route('settings.glitchtip.test'))
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_admin_can_open_csp_report_test_page(): void
    {
        config()->set('glitchtip.security_endpoint', 'https://glitchtip.example.invalid/api/2/security/?glitchtip_key=test');

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('settings.glitchtip.csp-test'))
            ->assertOk()
            ->assertSee('CSP report sedang dipicu dari halaman ini.')
            ->assertHeader(
                'Content-Security-Policy-Report-Only',
                "default-src 'self'; img-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; report-uri https://glitchtip.example.invalid/api/2/security/?glitchtip_key=test;",
            );
    }

    public function test_admin_cannot_control_runtime_service_when_managed_externally(): void
    {
        config()->set('app.ssh_terminal_proxy_managed_externally', true);
        Http::fake([
            'http://127.0.0.1:8078/healthz' => Http::response(['ok' => true], 200),
        ]);

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('settings.runtime-services.restart', 'ssh-terminal-proxy'))
            ->assertStatus(409)
            ->assertJsonPath('message', 'SSH terminal proxy is managed by your deployment platform. Use Coolify service controls instead.');
    }
}
