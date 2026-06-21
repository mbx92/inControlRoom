<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\MaintenanceMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceModeFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('up');
        \Illuminate\Support\Facades\Cache::flush();
    }

    public function test_admin_can_enable_and_disable_maintenance_mode(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('settings.maintenance.update'), [
                'enabled' => true,
                'message' => 'Database upgrade in progress.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertTrue(MaintenanceMode::enabled());
        $this->assertSame('Database upgrade in progress.', MaintenanceMode::message());

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'system.maintenance.enable',
        ]);

        $this->actingAs($admin)
            ->put(route('settings.maintenance.update'), [
                'enabled' => false,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertFalse(MaintenanceMode::enabled());

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'system.maintenance.disable',
        ]);
    }

    public function test_non_admin_is_redirected_to_maintenance_page_when_mode_is_enabled(): void
    {
        $operator = User::factory()->operator()->create();
        $admin = User::factory()->admin()->create();

        MaintenanceMode::enable($admin, 'Planned maintenance window.');

        $this->actingAs($operator)
            ->get(route('dashboard'))
            ->assertRedirect(route('maintenance'));

        $this->actingAs($operator)
            ->get(route('maintenance'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Maintenance/Index')
                ->where('maintenance.enabled', true)
                ->where('maintenance.message', 'Planned maintenance window.')
            );
    }

    public function test_admin_can_still_access_dashboard_during_maintenance(): void
    {
        $admin = User::factory()->admin()->create();

        MaintenanceMode::enable($admin, 'Admin-only maintenance.');

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_non_admin_cannot_login_during_maintenance(): void
    {
        $admin = User::factory()->admin()->create();
        $operator = User::factory()->operator()->create([
            'password' => 'password',
        ]);

        MaintenanceMode::enable($admin, 'Login blocked for operators.');

        $this->post(route('login'), [
            'email' => $operator->email,
            'password' => 'password',
        ])
            ->assertRedirect()
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_admin_can_login_during_maintenance(): void
    {
        $admin = User::factory()->admin()->create([
            'password' => 'password',
        ]);

        MaintenanceMode::enable($admin, 'Admin-only maintenance.');

        $this->post(route('login'), [
            'email' => $admin->email,
            'password' => 'password',
        ])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_operator_cannot_toggle_maintenance_mode(): void
    {
        $operator = User::factory()->operator()->create();

        $this->actingAs($operator)
            ->put(route('settings.maintenance.update'), [
                'enabled' => true,
                'message' => 'Should fail',
            ])
            ->assertForbidden();

        $this->assertFalse(MaintenanceMode::enabled());
        $this->assertSame(0, AuditLog::query()->count());
    }
}
