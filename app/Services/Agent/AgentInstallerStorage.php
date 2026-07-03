<?php

namespace App\Services\Agent;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgentInstallerStorage
{
    public function isConfigured(): bool
    {
        $disk = (string) config('agent.installer.disk', 'minio');
        $bucket = (string) config("filesystems.disks.{$disk}.bucket", '');
        $endpoint = (string) config("filesystems.disks.{$disk}.endpoint", '');

        return $bucket !== '' && $endpoint !== '';
    }

    public function exists(): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        return $this->runSafely(fn () => $this->disk()->exists($this->objectKey()), false);
    }

    /**
     * @return array{
     *     configured: bool,
     *     available: bool,
     *     version: string,
     *     filename: string,
     *     size_bytes: int|null,
     *     last_modified_at: string|null,
     *     bucket_download_url: string|null
     * }
     */
    public function present(): array
    {
        $available = $this->exists();

        return [
            'configured' => $this->isConfigured(),
            'available' => $available,
            'version' => (string) config('agent.installer.version', '1.0.0'),
            'filename' => $this->downloadFilename(),
            'size_bytes' => $available ? $this->size() : null,
            'last_modified_at' => $available ? $this->lastModified()?->toIso8601String() : null,
            'bucket_download_url' => $this->publicDownloadUrl(),
        ];
    }

    public function downloadResponse(): StreamedResponse
    {
        return $this->disk()->download($this->objectKey(), $this->downloadFilename());
    }

    /**
     * @return array{size_bytes: int|null}
     */
    public function publish(string $localPath): array
    {
        $stream = fopen($localPath, 'rb');

        if ($stream === false) {
            throw new \RuntimeException("Unable to read installer at [{$localPath}].");
        }

        try {
            $this->disk()->put($this->objectKey(), $stream);
        } finally {
            fclose($stream);
        }

        $localSize = filesize($localPath);

        return [
            'size_bytes' => $localSize === false ? null : $localSize,
        ];
    }

    public function size(): ?int
    {
        return $this->runSafely(function () {
            $size = $this->disk()->size($this->objectKey());

            return $size === false ? null : (int) $size;
        });
    }

    public function lastModified(): ?Carbon
    {
        return $this->runSafely(function () {
            $timestamp = $this->disk()->lastModified($this->objectKey());

            if ($timestamp === false) {
                return null;
            }

            return Carbon::createFromTimestamp($timestamp);
        });
    }

    public function endpointLooksLikeCdn(): bool
    {
        $endpoint = strtolower((string) config('filesystems.disks.'.config('agent.installer.disk', 'minio').'.endpoint', ''));

        return str_contains($endpoint, '://cdn.') || str_contains($endpoint, '/cdn/');
    }

    public function publicDownloadUrl(): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $explicitUrl = trim((string) config('agent.installer.public_url', ''));

        if ($explicitUrl !== '') {
            return $explicitUrl;
        }

        $baseUrl = rtrim(trim((string) config('agent.installer.public_base_url', '')), '/');

        if ($baseUrl === '') {
            return null;
        }

        $disk = (string) config('agent.installer.disk', 'minio');
        $bucket = (string) config("filesystems.disks.{$disk}.bucket", '');
        $usePathStyle = (bool) config("filesystems.disks.{$disk}.use_path_style_endpoint", true);
        $objectKey = ltrim($this->objectKey(), '/');

        if ($usePathStyle && $bucket !== '') {
            return "{$baseUrl}/{$bucket}/{$objectKey}";
        }

        return "{$baseUrl}/{$objectKey}";
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('agent.installer.disk', 'minio'));
    }

    private function objectKey(): string
    {
        return (string) config('agent.installer.object_key', 'agents/InfraControl.Agent.Setup.exe');
    }

    private function downloadFilename(): string
    {
        return (string) config('agent.installer.filename', 'InfraControl.Agent.Setup.exe');
    }

    private function runSafely(callable $callback, mixed $default = null): mixed
    {
        try {
            return $callback();
        } catch (\Throwable) {
            return $default;
        }
    }
}
