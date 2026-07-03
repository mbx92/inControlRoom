<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Agent\AgentInstallerStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AgentInstallerDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('minio');
        config([
            'agent.installer.disk' => 'minio',
            'agent.installer.object_key' => 'agents/InfraControl.Agent.Setup.exe',
            'agent.installer.filename' => 'InfraControl.Agent.Setup.exe',
            'agent.installer.version' => '1.0.0',
            'filesystems.disks.minio.bucket' => 'infracontrol',
            'filesystems.disks.minio.endpoint' => 'http://minio.test:9000',
        ]);
    }

    public function test_admin_sees_installer_metadata_on_settings_page(): void
    {
        Storage::disk('minio')->put('agents/InfraControl.Agent.Setup.exe', 'fake installer');

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('settings.agents.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Agents/Index')
                ->where('installer.configured', true)
                ->where('installer.available', true)
                ->where('installer.version', '1.0.0')
                ->where('installer.filename', 'InfraControl.Agent.Setup.exe')
            );
    }

    public function test_admin_can_download_installer_from_minio(): void
    {
        Storage::disk('minio')->put('agents/InfraControl.Agent.Setup.exe', 'fake installer bytes');

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('settings.agents.installer.download'))
            ->assertOk()
            ->assertDownload('InfraControl.Agent.Setup.exe');
    }

    public function test_download_returns_not_found_when_installer_missing(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('settings.agents.installer.download'))
            ->assertNotFound();
    }

    public function test_non_admin_cannot_download_installer(): void
    {
        Storage::disk('minio')->put('agents/InfraControl.Agent.Setup.exe', 'fake installer bytes');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('settings.agents.installer.download'))
            ->assertForbidden();
    }

    public function test_present_marks_installer_unavailable_when_bucket_not_configured(): void
    {
        config([
            'filesystems.disks.minio.bucket' => '',
            'filesystems.disks.minio.endpoint' => '',
        ]);

        $present = app(AgentInstallerStorage::class)->present();

        $this->assertFalse($present['configured']);
        $this->assertFalse($present['available']);
    }
}
