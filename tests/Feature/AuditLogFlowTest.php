<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_filter_audit_logs_by_site_scope(): void
    {
        $admin = User::factory()->admin()->create();
        $siteA = Site::factory()->create(['name' => 'Alpha Site']);
        $siteB = Site::factory()->create(['name' => 'Beta Site']);

        AuditLog::record($admin->id, 'integration.create', 'integration', 'a', [], '127.0.0.1', 'success', null, $siteA->id);
        AuditLog::record($admin->id, 'integration.create', 'integration', 'b', [], '127.0.0.1', 'success', null, $siteB->id);
        AuditLog::record($admin->id, 'integration.create', 'integration', 'global', [], '127.0.0.1', 'success', null, null);

        $this->actingAs($admin)
            ->get(route('audit-logs.index', ['site' => $siteA->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('AuditLog/Index')
                ->where('filters.site', $siteA->id)
                ->where('logs.total', 1)
                ->where('logs.data.0.site_name', 'Alpha Site')
            );

        $this->actingAs($admin)
            ->get(route('audit-logs.index', ['site' => 'global']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.site', 'global')
                ->where('logs.total', 1)
                ->where('logs.data.0.site_name', 'Global')
            );
    }

    public function test_audit_log_to_date_filter_includes_the_full_day(): void
    {
        $admin = User::factory()->admin()->create();

        AuditLog::create([
            'user_id' => $admin->id,
            'site_id' => null,
            'action' => 'audit.same_day',
            'target_type' => 'demo',
            'target_id' => '123',
            'payload' => [],
            'ip_address' => '127.0.0.1',
            'result' => 'success',
            'error_message' => null,
            'created_at' => '2026-06-15 15:30:00',
        ]);

        $this->actingAs($admin)
            ->get(route('audit-logs.index', ['to' => '2026-06-15']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.to', '2026-06-15')
                ->where('logs.total', 1)
                ->where('logs.data.0.action', 'audit.same_day')
            );
    }
}
