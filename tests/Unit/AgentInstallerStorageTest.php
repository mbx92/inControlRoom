<?php

namespace Tests\Unit;

use App\Services\Agent\AgentInstallerStorage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AgentInstallerStorageTest extends TestCase
{
    public function test_present_reports_availability_from_minio_disk(): void
    {
        Storage::fake('minio');
        config([
            'agent.installer.disk' => 'minio',
            'agent.installer.object_key' => 'agents/InfraControl.Agent.Setup.exe',
            'agent.installer.filename' => 'InfraControl.Agent.Setup.exe',
            'agent.installer.version' => '1.2.3',
            'filesystems.disks.minio.bucket' => 'infracontrol',
            'filesystems.disks.minio.endpoint' => 'http://minio.test:9000',
        ]);

        Storage::disk('minio')->put('agents/InfraControl.Agent.Setup.exe', 'installer');

        $present = app(AgentInstallerStorage::class)->present();

        $this->assertTrue($present['configured']);
        $this->assertTrue($present['available']);
        $this->assertSame('1.2.3', $present['version']);
        $this->assertSame('InfraControl.Agent.Setup.exe', $present['filename']);
        $this->assertSame(9, $present['size_bytes']);
    }

    public function test_present_marks_installer_unconfigured_without_bucket(): void
    {
        config([
            'agent.installer.disk' => 'minio',
            'filesystems.disks.minio.bucket' => '',
            'filesystems.disks.minio.endpoint' => '',
        ]);

        $present = app(AgentInstallerStorage::class)->present();

        $this->assertFalse($present['configured']);
        $this->assertFalse($present['available']);
    }

    public function test_endpoint_looks_like_cdn(): void
    {
        config(['filesystems.disks.minio.endpoint' => 'https://cdn.example.com']);

        $this->assertTrue(app(AgentInstallerStorage::class)->endpointLooksLikeCdn());
    }

    public function test_public_download_url_uses_path_style_bucket_prefix(): void
    {
        config([
            'agent.installer.disk' => 'minio',
            'agent.installer.object_key' => 'agents/InfraControl.Agent.Setup.exe',
            'agent.installer.public_base_url' => 'https://cdn.example.com',
            'filesystems.disks.minio.bucket' => 'infracontrol-agent',
            'filesystems.disks.minio.endpoint' => 'http://minio.test:9000',
            'filesystems.disks.minio.use_path_style_endpoint' => true,
        ]);

        $url = app(AgentInstallerStorage::class)->publicDownloadUrl();

        $this->assertSame(
            'https://cdn.example.com/infracontrol-agent/agents/InfraControl.Agent.Setup.exe',
            $url,
        );
    }

    public function test_public_download_url_honors_explicit_override(): void
    {
        config([
            'agent.installer.disk' => 'minio',
            'agent.installer.public_url' => 'https://downloads.example.com/agent.exe',
            'filesystems.disks.minio.bucket' => 'infracontrol-agent',
            'filesystems.disks.minio.endpoint' => 'http://minio.test:9000',
        ]);

        $this->assertSame(
            'https://downloads.example.com/agent.exe',
            app(AgentInstallerStorage::class)->publicDownloadUrl(),
        );
    }
}
