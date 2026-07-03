<?php

namespace App\Console\Commands;

use App\Services\Agent\AgentInstallerStorage;
use Illuminate\Console\Command;

class PublishAgentInstallerCommand extends Command
{
    protected $signature = 'agent:publish-installer
                            {path? : Local path to InfraControl.Agent.Setup.exe}
                            {--disk= : Override filesystem disk (defaults to agent.installer.disk)}';

    protected $description = 'Upload the Windows agent installer to MinIO / S3';

    public function handle(AgentInstallerStorage $storage): int
    {
        $path = (string) ($this->argument('path') ?: base_path('agent/dist/installer/InfraControl.Agent.Setup.exe'));

        if (! is_file($path)) {
            $this->error("Installer not found at [{$path}]. Build it first with: cd agent && npm run build && npm run build:installer");

            return self::FAILURE;
        }

        if ($this->option('disk')) {
            config(['agent.installer.disk' => (string) $this->option('disk')]);
        }

        if (! $storage->isConfigured()) {
            $this->error('MinIO is not configured. Set MINIO_ENDPOINT (S3 API URL), MINIO_ACCESS_KEY, MINIO_SECRET_KEY, and MINIO_BUCKET.');

            return self::FAILURE;
        }

        if ($storage->endpointLooksLikeCdn()) {
            $this->warn('MINIO_ENDPOINT looks like a CDN URL. Use the MinIO S3 API endpoint instead (for example https://minio.example.com:9000).');
        }

        $localSize = filesize($path);
        $this->info('Uploading installer to object storage...');

        $published = $storage->publish($path);

        $this->info('Published successfully.');
        $this->line('Object key: '.config('agent.installer.object_key'));

        $sizeBytes = $published['size_bytes'] ?? $localSize;
        if (is_int($sizeBytes)) {
            $this->line('Size: '.$this->formatBytes($sizeBytes));
        }

        if ($storage->lastModified()) {
            $this->line('Last modified: '.$storage->lastModified()->toIso8601String());
        } elseif ($storage->endpointLooksLikeCdn()) {
            $this->warn('Could not read object metadata from storage. Fix MINIO_ENDPOINT if Settings download also fails.');
        }

        if ($publicUrl = $storage->publicDownloadUrl()) {
            $this->line('Bucket URL: '.$publicUrl);
        }

        return self::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / (1024 * 1024), 1).' MB';
    }
}
