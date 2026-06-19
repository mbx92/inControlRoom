<?php

use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$sentryDsn = env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN'));
$appEnv = env('APP_ENV', 'production');

if ($appEnv === 'local' && ! env('SENTRY_SEND_LOCAL', false)) {
    $sentryDsn = null;
}

return [
    'dsn' => $sentryDsn,
    'release' => env('SENTRY_RELEASE', env('APP_VERSION')),
    'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV')),
    'sample_rate' => env('SENTRY_SAMPLE_RATE') === null ? 1.0 : (float) env('SENTRY_SAMPLE_RATE'),
    'traces_sample_rate' => env('SENTRY_TRACES_SAMPLE_RATE') === null ? null : (float) env('SENTRY_TRACES_SAMPLE_RATE'),
    'send_default_pii' => env('SENTRY_SEND_DEFAULT_PII', false),
    'ignore_transactions' => [
        '/up',
    ],
    'before_send' => static function (Event $event, ?EventHint $hint): ?Event {
        $throwable = $hint?->exception;

        if ($throwable instanceof HttpExceptionInterface && in_array($throwable->getStatusCode(), [401, 403, 404, 419], true)) {
            return null;
        }

        return $event;
    },
];
