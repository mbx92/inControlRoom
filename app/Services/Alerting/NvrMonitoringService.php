<?php

namespace App\Services\Alerting;

use App\Models\Integration;
use Illuminate\Support\Facades\Http;

class NvrMonitoringService
{
    public function __construct(
        private readonly IntegrationCredentialsResolver $credentialsResolver,
    ) {
    }

    /**
     * Health check via Hikvision ISAPI /System/status
     */
    public function check(Integration $integration): array
    {
        $config = $integration->config ?? [];
        $verifySsl = (bool) ($config['verify_ssl'] ?? true);
        $baseUrl = rtrim($integration->base_url, '/');

        $startedAt = microtime(true);

        try {
            $response = $this->isapiRequest($integration, 'GET', '/ISAPI/System/status');
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => "NVR connection failed: {$e->getMessage()}",
                'meta' => [
                    'kind' => 'nvr',
                    'product' => 'Hikvision NVR',
                    'verify_ssl' => $verifySsl,
                    'health_endpoint' => "{$baseUrl}/ISAPI/System/status",
                    'api_reachable' => false,
                    'auth_status' => 'failed',
                    'latency_ms' => null,
                    'health_method' => 'GET',
                    'expected_status' => 200,
                ],
            ];
        }

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        if (! $response->successful()) {
            return [
                'success' => false,
                'message' => "NVR returned HTTP {$response->status()}",
                'meta' => [
                    'kind' => 'nvr',
                    'product' => 'Hikvision NVR',
                    'verify_ssl' => $verifySsl,
                    'health_endpoint' => "{$baseUrl}/ISAPI/System/status",
                    'api_reachable' => true,
                    'auth_status' => in_array($response->status(), [401, 403], true) ? 'failed' : 'unknown',
                    'latency_ms' => $latencyMs,
                    'http_status' => $response->status(),
                    'health_method' => 'GET',
                    'expected_status' => 200,
                ],
            ];
        }

        $body = $this->xmlToArray($response->body());
        $device = $body['deviceDescription'] ?? $body['DeviceStatus'] ?? $body;
        $deviceInfo = $this->fetchDeviceInfo($integration);
        $deviceMeta = $deviceInfo !== [] ? $deviceInfo : $device;

        return [
            'success' => true,
            'message' => 'Connected to Hikvision NVR',
            'meta' => [
                'kind' => 'nvr',
                'product' => 'Hikvision NVR',
                'model' => $deviceMeta['model'] ?? $deviceMeta['deviceModel'] ?? null,
                'firmware' => $deviceMeta['firmwareVersion'] ?? ($deviceMeta['firmwareReleasedDate'] ?? null),
                'serial_number' => $deviceMeta['serialNumber'] ?? null,
                'channel_count' => $this->coerceInt(
                    $deviceMeta['channelNumber'] ?? $device['channelNumber'] ?? $deviceMeta['videoInputChannelNum'] ?? $device['videoInputChannelNum'] ?? $deviceMeta['videoInputChannelNumber'] ?? $device['videoInputChannelNumber'] ?? null
                ),
                'recording_count' => null,
                'verify_ssl' => $verifySsl,
                'health_endpoint' => "{$baseUrl}/ISAPI/System/status",
                'api_reachable' => true,
                'auth_status' => 'valid',
                'latency_ms' => $latencyMs,
                'health_method' => 'GET',
                'expected_status' => 200,
            ],
        ];
    }

    /**
     * Capture channel list and recording status from the NVR.
     */
    public function capture(Integration $integration): array
    {
        $channels = [];
        $channelsById = [];
        $channelNames = $this->fetchCameraNamesByStreamingChannel($integration);

        try {
            $response = $this->isapiRequest($integration, 'GET', '/ISAPI/Streaming/channels');
            if ($response->successful()) {
                $body = $this->xmlToArray($response->body());
                $streamingChannels = $body['StreamingChannel'] ?? $body['StreamingChannelList']['StreamingChannel'] ?? [];

                if (isset($streamingChannels['id'])) {
                    $streamingChannels = [$streamingChannels];
                }

                foreach ($streamingChannels as $channel) {
                    $channelId = (int) ($channel['id'] ?? 0);
                    if ($channelId > 0) {
                        $channelsById[$channelId] = $channel;
                    }
                }
            }
        } catch (\Throwable) {
            $channelsById = [];
        }

        $recordingsById = [];
        try {
            $response = $this->isapiRequest($integration, 'GET', '/ISAPI/ContentMgmt/record/status');
            if ($response->successful()) {
                $body = $this->xmlToArray($response->body());
                $recordItems = $body['recordStatus'] ?? [];

                if (isset($recordItems['id'])) {
                    $recordItems = [$recordItems];
                }

                foreach ($recordItems as $record) {
                    $recordId = (int) ($record['id'] ?? 0);
                    if ($recordId > 0) {
                        $recordingsById[$recordId] = $record;
                    }
                }
            }
        } catch (\Throwable) {
            // Recording status is optional on some Hikvision/Hilook builds.
        }

        foreach ($channelsById as $id => $channel) {
            $record = $recordingsById[$id] ?? [];
            $video = is_array($channel['Video'] ?? null) ? $channel['Video'] : [];
            $width = $this->coerceInt($video['videoResolutionWidth'] ?? null);
            $height = $this->coerceInt($video['videoResolutionHeight'] ?? null);

            $channels[] = [
                'id' => $id,
                'name' => $channelNames[$id] ?? (string) ($channel['channelName'] ?? "Channel {$id}"),
                'enabled' => ($channel['enabled'] ?? 'true') === 'true',
                'video_codec' => $video['videoCodecType'] ?? ($channel['videoCodecType'] ?? null),
                'video_resolution' => $width > 0 && $height > 0 ? "{$width}x{$height}" : null,
                'video_quality' => $video['videoQualityControlType'] ?? ($channel['videoQualityControlType'] ?? null),
                'is_recording' => (string) ($record['recording'] ?? 'false') === 'true',
                'recording_type' => $record['type'] ?? null,
                'rtsp_url' => $channel['rtspURL'] ?? null,
            ];
        }

        foreach ($recordingsById as $id => $record) {
            if (isset($channelsById[$id])) {
                continue;
            }

            $channels[] = [
                'id' => $id,
                'name' => $channelNames[$id] ?? "Channel {$id}",
                'enabled' => false,
                'video_codec' => null,
                'video_resolution' => null,
                'video_quality' => null,
                'is_recording' => (string) ($record['recording'] ?? 'false') === 'true',
                'recording_type' => $record['type'] ?? null,
                'rtsp_url' => null,
            ];
        }

        usort($channels, fn ($a, $b) => $a['id'] <=> $b['id']);

        return $channels;
    }

    public function topologyCameras(Integration $integration): array
    {
        return collect($this->capture($integration))
            ->filter(fn (array $channel) => $this->isPrimaryCameraChannel((int) ($channel['id'] ?? 0)))
            ->map(function (array $channel) {
                $channelId = (int) ($channel['id'] ?? 0);
                $cameraNumber = $this->cameraNumberFromChannelId($channelId);

                return [
                    ...$channel,
                    'camera_number' => $cameraNumber,
                    'camera_label' => $this->resolveCameraLabel($channel, $cameraNumber),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Summarize channel data.
     */
    public function summarize(array $channels): array
    {
        $collection = collect($channels);

        return [
            'channel_total' => $collection->count(),
            'enabled_total' => $collection->where('enabled', true)->count(),
            'recording_total' => $collection->where('is_recording', true)->count(),
        ];
    }

    private function isapiRequest(Integration $integration, string $method, string $path)
    {
        $credentials = $this->credentialsResolver->resolve($integration);
        $config = $integration->config ?? [];
        $verifySsl = (bool) ($config['verify_ssl'] ?? true);
        $username = trim((string) ($config['username'] ?? $credentials['username'] ?? ''));
        $password = (string) ($credentials['password'] ?? $credentials['token'] ?? '');

        if ($username === '') {
            throw new \RuntimeException('NVR username is missing. Set the Hikvision username on this integration.');
        }

        if ($password === '') {
            throw new \RuntimeException('NVR password is missing. Attach a vault entry with the Hikvision password.');
        }

        $baseUrl = rtrim($integration->base_url, '/');
        $uri = "{$baseUrl}{$path}";

        $first = Http::withOptions(['verify' => $verifySsl])
            ->timeout(15)
            ->send($method, $uri);

        if ($first->status() !== 401) {
            return $first;
        }

        $authHeader = $first->header('WWW-Authenticate');
        if (! $authHeader) {
            return $first;
        }

        $digestParams = $this->parseDigestChallenge($authHeader);
        if (! $digestParams) {
            return $first;
        }

        $authValue = $this->buildDigestResponse(
            $username,
            $password,
            $method,
            $path,
            $digestParams,
        );

        return Http::withOptions(['verify' => $verifySsl])
            ->withHeaders(['Authorization' => $authValue])
            ->timeout(15)
            ->send($method, $uri);
    }

    private function parseDigestChallenge(string $header): ?array
    {
        if (! preg_match('/^Digest\s+(.+)$/i', $header, $matches)) {
            return null;
        }

        $params = [];
        preg_match_all('/(\w+)=("[^"]*"|[^,]+)/', $matches[1], $pairs, PREG_SET_ORDER);

        foreach ($pairs as $pair) {
            $params[$pair[1]] = trim($pair[2], '"');
        }

        if (empty($params['realm']) || empty($params['nonce'])) {
            return null;
        }

        return $params;
    }

    private function buildDigestResponse(string $username, string $password, string $method, string $uri, array $params): string
    {
        $realm = $params['realm'];
        $nonce = $params['nonce'];
        $qop = $params['qop'] ?? null;
        $opaque = $params['opaque'] ?? null;
        $algorithm = $params['algorithm'] ?? 'MD5';

        $nc = '00000001';
        $cnonce = bin2hex(random_bytes(8));

        $ha1 = $algorithm === 'MD5-sess'
            ? md5(md5("{$username}:{$realm}:{$password}").":{$nonce}:{$cnonce}")
            : md5("{$username}:{$realm}:{$password}");

        $ha2 = $qop === 'auth-int'
            ? md5("{$method}:{$uri}:".md5(''))
            : md5("{$method}:{$uri}");

        $response = $qop
            ? md5("{$ha1}:{$nonce}:{$nc}:{$cnonce}:{$qop}:{$ha2}")
            : md5("{$ha1}:{$nonce}:{$ha2}");

        $parts = [
            "Digest username=\"{$username}\"",
            "realm=\"{$realm}\"",
            "nonce=\"{$nonce}\"",
            "uri=\"{$uri}\"",
            "response=\"{$response}\"",
        ];

        if ($qop) {
            $parts[] = "qop={$qop}";
            $parts[] = "nc={$nc}";
            $parts[] = "cnonce=\"{$cnonce}\"";
        }

        if ($algorithm && $algorithm !== 'MD5') {
            $parts[] = "algorithm={$algorithm}";
        }

        if ($opaque) {
            $parts[] = "opaque=\"{$opaque}\"";
        }

        return implode(', ', $parts);
    }

    private function xmlToArray(string $xml): array
    {
        if ($xml === '' || $xml === '0') {
            return [];
        }

        libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);

        if ($document === false) {
            return [];
        }

        $json = json_decode(json_encode($document), true);

        return is_array($json) ? $json : [];
    }

    private function coerceInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function fetchDeviceInfo(Integration $integration): array
    {
        foreach (['/ISAPI/System/deviceInfo', '/ISAPI/System/deviceinfo'] as $path) {
            try {
                $response = $this->isapiRequest($integration, 'GET', $path);
                if (! $response->successful()) {
                    continue;
                }

                $body = $this->xmlToArray($response->body());
                $deviceInfo = $body['DeviceInfo'] ?? $body;

                if (is_array($deviceInfo) && $deviceInfo !== []) {
                    return $deviceInfo;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return [];
    }

    private function fetchCameraNamesByStreamingChannel(Integration $integration): array
    {
        try {
            $channelResponse = $this->isapiRequest($integration, 'GET', '/ISAPI/ContentMgmt/InputProxy/channels');
            $statusResponse = $this->isapiRequest($integration, 'GET', '/ISAPI/ContentMgmt/InputProxy/channels/status');

            if (! $channelResponse->successful() || ! $statusResponse->successful()) {
                return [];
            }

            $channelsBody = $this->xmlToArray($channelResponse->body());
            $statusBody = $this->xmlToArray($statusResponse->body());

            $inputChannels = $channelsBody['InputProxyChannel'] ?? $channelsBody['InputProxyChannelList']['InputProxyChannel'] ?? [];
            $statusChannels = $statusBody['InputProxyChannelStatus'] ?? $statusBody['InputProxyChannelStatusList']['InputProxyChannelStatus'] ?? [];

            if (isset($inputChannels['id'])) {
                $inputChannels = [$inputChannels];
            }

            if (isset($statusChannels['id'])) {
                $statusChannels = [$statusChannels];
            }

            $namesByInputId = [];
            foreach ($inputChannels as $inputChannel) {
                $inputId = (int) ($inputChannel['id'] ?? 0);
                $rawName = $inputChannel['name'] ?? null;
                $name = is_scalar($rawName) ? trim((string) $rawName) : '';

                if ($inputId > 0 && $name !== '') {
                    $namesByInputId[$inputId] = $name;
                }
            }

            $namesByStreamingId = [];
            foreach ($statusChannels as $statusChannel) {
                $inputId = (int) ($statusChannel['id'] ?? 0);
                $name = $namesByInputId[$inputId] ?? null;

                if (! $name) {
                    continue;
                }

                $streamingIds = $statusChannel['streamingProxyChannelIdList']['streamingProxyChannelId'] ?? [];
                if (! is_array($streamingIds)) {
                    $streamingIds = [$streamingIds];
                }

                foreach ($streamingIds as $streamingId) {
                    $resolvedId = $this->coerceInt($streamingId);
                    if ($resolvedId > 0) {
                        $namesByStreamingId[$resolvedId] = $name;
                    }
                }
            }

            return $namesByStreamingId;
        } catch (\Throwable) {
            return [];
        }
    }

    private function isPrimaryCameraChannel(int $channelId): bool
    {
        if ($channelId <= 0) {
            return false;
        }

        if ($channelId >= 100) {
            return $channelId % 100 === 1;
        }

        return true;
    }

    private function cameraNumberFromChannelId(int $channelId): ?int
    {
        if ($channelId <= 0) {
            return null;
        }

        if ($channelId >= 100) {
            return (int) floor($channelId / 100);
        }

        return $channelId;
    }

    private function resolveCameraLabel(array $channel, ?int $cameraNumber): string
    {
        $name = trim((string) ($channel['name'] ?? ''));

        if ($name !== '' && ! preg_match('/^\d+$/', $name)) {
            return $name;
        }

        return $cameraNumber !== null ? "Camera {$cameraNumber}" : ($name !== '' ? $name : 'Camera');
    }
}
