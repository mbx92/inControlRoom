<?php

namespace App\Services\Alerting;

use App\Models\Event;
use App\Models\NotificationChannel;
use Illuminate\Support\Facades\Http;

class TelegramAlertChannel
{
    public function send(NotificationChannel $channel, Event $event, string $transition): string
    {
        $config = $channel->config ?? [];
        $botToken = trim((string) ($config['bot_token'] ?? ''));
        $chatId = trim((string) ($config['chat_id'] ?? ''));

        if ($botToken === '' || $chatId === '') {
            throw new \RuntimeException('Telegram channel is missing bot_token or chat_id.');
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $this->buildMessage($event, $transition),
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true,
        ];

        $threadId = trim((string) ($config['message_thread_id'] ?? ''));
        if ($threadId !== '') {
            $payload['message_thread_id'] = $threadId;
        }

        $response = Http::asForm()
            ->timeout(10)
            ->post("https://api.telegram.org/bot{$botToken}/sendMessage", $payload);

        if (! $response->successful()) {
            throw new \RuntimeException("Telegram returned HTTP {$response->status()}");
        }

        return $response->body();
    }

    private function buildMessage(Event $event, string $transition): string
    {
        $title = str_replace(['*', '_', '`'], '', $event->title);
        $integration = str_replace(['*', '_', '`'], '', $event->integration?->name ?? 'Unknown integration');
        $site = str_replace(['*', '_', '`'], '', $event->site?->name ?? $event->integration?->site?->name ?? 'Global');
        $message = trim((string) $event->message);

        $headline = match ($transition) {
            'resolved' => 'RECOVERED',
            'severity_changed' => 'ALERT UPDATED',
            'reopened' => 'ALERT REOPENED',
            default => 'NEW ALERT',
        };

        $lines = [
            "*{$headline}*",
            "Severity: `{$event->severity}`",
            "Site: {$site}",
            "Integration: {$integration}",
            "Alert: {$title}",
        ];

        if ($message !== '') {
            $lines[] = '';
            $lines[] = $message;
        }

        return implode("\n", $lines);
    }
}
