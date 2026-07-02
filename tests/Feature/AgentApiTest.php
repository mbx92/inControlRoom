<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentEnrollmentToken;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_enroll_returns_agent_token_and_persists_agent(): void
    {
        $site = Site::factory()->create();
        $plainToken = 'enroll_valid_token_123';
        $token = AgentEnrollmentToken::create([
            'site_id' => $site->id,
            'name' => 'Pilot Windows Agent',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
            'max_uses' => 1,
            'used_count' => 0,
        ]);

        $response = $this->postJson(route('api.agents.enroll'), [
            'enroll_token' => $plainToken,
            'device_id' => 'machine-guid-001',
            'hostname' => 'CLIENT-01',
            'os' => 'Windows',
            'os_version' => '11 Pro',
            'arch' => 'x64',
            'agent_version' => '1.0.0',
            'primary_ip' => '10.20.30.40',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'agent_id',
                'agent_token',
                'site_id',
                'interval_seconds',
            ]);

        $agent = Agent::query()->firstOrFail();

        $this->assertSame($site->id, $agent->site_id);
        $this->assertSame('CLIENT-01', $agent->hostname);
        $this->assertSame('machine-guid-001', $agent->device_id);
        $this->assertSame('10.20.30.40', $agent->primary_ip);
        $this->assertSame('1.0.0', $agent->agent_version);
        $this->assertNotEmpty($agent->agent_token_hash);

        $token->refresh();
        $this->assertSame(1, $token->used_count);
        $this->assertNotNull($token->last_used_at);
    }

    public function test_heartbeat_updates_host_metadata_and_payload_fields(): void
    {
        $site = Site::factory()->create();
        $plainAgentToken = 'agent_valid_token_123';
        $agent = Agent::create([
            'site_id' => $site->id,
            'hostname' => 'OLD-HOST',
            'device_id' => 'machine-guid-001',
            'agent_token_hash' => hash('sha256', $plainAgentToken),
            'os' => 'Windows',
            'os_version' => '10',
            'arch' => 'x64',
            'primary_ip' => '10.10.10.10',
            'agent_version' => '0.9.0',
            'is_active' => true,
        ]);

        $timestamp = now()->subSeconds(15)->toIso8601String();

        $this->withHeader('Authorization', 'Bearer '.$plainAgentToken)
            ->postJson(route('api.agents.heartbeat'), [
                'agent_version' => '1.0.0',
                'device_id' => 'machine-guid-001',
                'hostname' => 'CLIENT-02',
                'os' => 'Windows',
                'os_version' => '11 Pro',
                'arch' => 'arm64',
                'primary_ip' => '10.20.20.20',
                'timestamp' => $timestamp,
                'labels' => ['branch-a', 'nurse-station'],
                'metrics' => [
                    'cpu' => [
                        'usage_percent' => 31.5,
                    ],
                ],
                'services' => [
                    [
                        'name' => 'Spooler',
                        'display_name' => 'Print Spooler',
                        'status' => 'Running',
                        'start_mode' => 'Automatic',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'next_interval_seconds' => 30,
                'commands' => [],
            ]);

        $agent->refresh();

        $this->assertSame('CLIENT-02', $agent->hostname);
        $this->assertSame('Windows', $agent->os);
        $this->assertSame('11 Pro', $agent->os_version);
        $this->assertSame('arm64', $agent->arch);
        $this->assertSame('10.20.20.20', $agent->primary_ip);
        $this->assertSame('1.0.0', $agent->agent_version);
        $this->assertSame(['branch-a', 'nurse-station'], $agent->labels);
        $this->assertSame(['cpu' => ['usage_percent' => 31.5]], $agent->last_metrics);
        $this->assertSame([
            [
                'name' => 'Spooler',
                'display_name' => 'Print Spooler',
                'status' => 'Running',
                'start_mode' => 'Automatic',
            ],
        ], $agent->last_services);
        $this->assertTrue($agent->is_active);
        $this->assertNotNull($agent->last_heartbeat_at);
        $this->assertSame($timestamp, optional($agent->last_seen_at)?->toIso8601String());
    }

    public function test_heartbeat_requires_bearer_token(): void
    {
        $this->postJson(route('api.agents.heartbeat'), [
            'agent_version' => '1.0.0',
        ])->assertStatus(401)
            ->assertJson([
                'message' => 'Missing agent bearer token.',
            ]);
    }

    public function test_enroll_rejects_invalid_or_expired_token(): void
    {
        $site = Site::factory()->create();

        AgentEnrollmentToken::create([
            'site_id' => $site->id,
            'name' => 'Expired token',
            'token_hash' => hash('sha256', 'enroll_expired_token'),
            'expires_at' => now()->subHour(),
            'max_uses' => 1,
            'used_count' => 0,
        ]);

        $this->postJson(route('api.agents.enroll'), [
            'enroll_token' => 'enroll_expired_token',
            'device_id' => 'machine-guid-001',
            'hostname' => 'CLIENT-03',
        ])->assertStatus(422)
            ->assertJson([
                'message' => 'Enrollment token is invalid, expired, exhausted, or revoked.',
            ]);
    }
}
