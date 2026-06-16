<?php

namespace App\Services\Alerting;

use App\Models\AlertNotification;
use App\Models\Event;
use App\Models\NotificationChannel;

class AlertNotifier
{
    public function __construct(
        private readonly TelegramAlertChannel $telegramChannel,
    ) {
    }

    public function notify(Event $event, string $transition): void
    {
        $channels = NotificationChannel::query()
            ->where('type', NotificationChannel::TYPE_TELEGRAM)
            ->where('is_active', true)
            ->when(
                $event->site_id,
                fn ($query, $siteId) => $query->where('site_id', $siteId),
                fn ($query) => $query->whereNull('site_id'),
            )
            ->get();

        foreach ($channels as $channel) {
            $deliveryKey = sha1(implode('|', [
                $event->id,
                $channel->id,
                $transition,
                $event->status,
                $event->severity,
                optional($event->last_seen_at)->timestamp,
                optional($event->resolved_at)->timestamp,
            ]));

            if (AlertNotification::query()->where('delivery_key', $deliveryKey)->exists()) {
                continue;
            }

            $notification = AlertNotification::create([
                'event_id' => $event->id,
                'notification_channel_id' => $channel->id,
                'transition' => $transition,
                'delivery_key' => $deliveryKey,
                'status' => 'pending',
            ]);

            try {
                $response = $this->telegramChannel->send($channel, $event, $transition);

                $notification->update([
                    'status' => 'sent',
                    'response' => $response,
                    'sent_at' => now(),
                ]);
            } catch (\Throwable $e) {
                $notification->update([
                    'status' => 'failed',
                    'response' => $e->getMessage(),
                ]);
            }
        }
    }
}
