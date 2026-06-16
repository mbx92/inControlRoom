<?php

namespace App\Http\Controllers;

use App\Models\NotificationChannel;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class NotificationChannelController extends Controller
{
    public function index(): Response
    {
        $channels = NotificationChannel::query()
            ->with('site')
            ->orderBy('name')
            ->get()
            ->map(fn (NotificationChannel $channel) => $this->presentChannel($channel));

        return Inertia::render('Settings/NotificationChannels/Index', [
            'channels' => $channels,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Settings/NotificationChannels/Create', [
            'sites' => $this->siteOptions(),
            'types' => [
                NotificationChannel::TYPE_TELEGRAM => 'Telegram',
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateChannel($request);

        NotificationChannel::create([
            'type' => $validated['type'],
            'name' => $validated['name'],
            'site_id' => $validated['site_id'] ?: null,
            'config' => [
                'bot_token' => $validated['config']['bot_token'],
                'chat_id' => $validated['config']['chat_id'],
                'message_thread_id' => $validated['config']['message_thread_id'] ?? null,
            ],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return redirect()->route('notification-channels.index')
            ->with('success', 'Notification channel created successfully.');
    }

    public function edit(NotificationChannel $notificationChannel): Response
    {
        $notificationChannel->load('site');

        return Inertia::render('Settings/NotificationChannels/Edit', [
            'channel' => $this->presentChannel($notificationChannel, includeConfig: true),
            'sites' => $this->siteOptions(),
            'types' => [
                NotificationChannel::TYPE_TELEGRAM => 'Telegram',
            ],
        ]);
    }

    public function update(Request $request, NotificationChannel $notificationChannel)
    {
        $validated = $this->validateChannel($request);

        $notificationChannel->update([
            'type' => $validated['type'],
            'name' => $validated['name'],
            'site_id' => $validated['site_id'] ?: null,
            'config' => [
                'bot_token' => $validated['config']['bot_token'],
                'chat_id' => $validated['config']['chat_id'],
                'message_thread_id' => $validated['config']['message_thread_id'] ?? null,
            ],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return redirect()->route('notification-channels.index')
            ->with('success', 'Notification channel updated successfully.');
    }

    private function validateChannel(Request $request): array
    {
        return $request->validate([
            'type' => ['required', 'string', Rule::in([NotificationChannel::TYPE_TELEGRAM])],
            'name' => ['required', 'string', 'max:255'],
            'site_id' => ['nullable', 'string', 'exists:sites,id'],
            'is_active' => ['nullable', 'boolean'],
            'config.bot_token' => ['required', 'string'],
            'config.chat_id' => ['required', 'string'],
            'config.message_thread_id' => ['nullable', 'string'],
        ]);
    }

    private function presentChannel(NotificationChannel $channel, bool $includeConfig = false): array
    {
        $config = $channel->config ?? [];

        return [
            'id' => $channel->id,
            'type' => $channel->type,
            'name' => $channel->name,
            'site_id' => $channel->site_id,
            'scope_label' => $channel->site?->name ?? 'Global fallback',
            'is_active' => $channel->is_active,
            'chat_id' => $config['chat_id'] ?? null,
            'message_thread_id' => $config['message_thread_id'] ?? null,
            'config' => $includeConfig ? [
                'bot_token' => $config['bot_token'] ?? '',
                'chat_id' => $config['chat_id'] ?? '',
                'message_thread_id' => $config['message_thread_id'] ?? '',
            ] : null,
        ];
    }

    private function siteOptions(): array
    {
        return Site::query()
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn (Site $site) => [
                'id' => $site->id,
                'name' => $site->name,
                'code' => $site->code,
            ])
            ->all();
    }
}
