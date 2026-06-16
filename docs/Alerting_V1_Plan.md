# Alerting V1 Plan for inControlRoom

## Summary
Build alerting v1 on top of the existing `events`, `metrics`, and `notification_channels` foundation. `events` becomes the alert source of truth, Proxmox guest and resource metrics feed rule evaluation, notifications are sent to Telegram per-site, evaluation runs on a 1-minute schedule, alerts are deduped by fingerprint, operators can acknowledge, and alerts auto-resolve when conditions recover.

## Key Changes
- Fix the `events` schema to match current app usage.
  - Add `site_id` and backfill it from `integration.site_id`.
  - Add lifecycle fields: `rule_key`, `fingerprint`, `context` JSON, `first_seen_at`, `last_seen_at`, `resolved_at`.
  - Add indexing and uniqueness so one active alert exists per fingerprint.
- Add alert configuration.
  - Create `alert_rules` table and model, admin-only.
  - Provide default v1 rules:
    - `integration_health_failure` -> `critical` when an integration health test fails.
    - `proxmox_guest_stopped` -> `critical` when guest status is not `running`.
    - `proxmox_guest_cpu_usage_percent` -> `warning >= 80`, `critical >= 90`.
    - `proxmox_guest_memory_usage_percent` -> `warning >= 80`, `critical >= 90`.
    - `proxmox_guest_disk_usage_percent` -> `warning >= 80`, `critical >= 90`.
- Add polling and evaluation pipeline.
  - Extract health-test logic from `IntegrationController` into a reusable service.
  - Add a scheduled command in `bootstrap/app.php` to poll active integrations every minute.
  - For Proxmox, persist metrics with keys:
    - `guest.status`
    - `guest.cpu_usage_percent`
    - `guest.memory_usage_percent`
    - `guest.disk_usage_percent`
  - Store labels at minimum: `site_id`, `node`, `vmid`, `guest_type`, `guest_name`.
  - Add an `AlertEvaluator` service to open, update, escalate, preserve acknowledge state where valid, and resolve alerts by fingerprint.
- Add Telegram delivery per-site.
  - Extend `notification_channels` with nullable `site_id`.
  - `site_id = <uuid>` means a site-specific channel.
  - `site_id = null` is the fallback for global integrations.
  - Focus v1 delivery on `telegram`; encrypted config stores `bot_token`, `chat_id`, and optional `message_thread_id`.
  - Add `alert_notifications` table for send history and idempotency.
  - Send messages only on open, severity change, and resolve.
- Add operator and admin UI.
  - New `/alerts` page for all roles with filters for site, status, severity, and integration.
  - New alert detail page.
  - Add `acknowledge` action with optional comment for admin and operator.
  - Add admin-only settings pages for notification channels and alert rules.
  - Keep the dashboard as a summary surface that links into full alert management.

## Public Interfaces
- New routes:
  - `GET /alerts`
  - `GET /alerts/{event}`
  - `PUT /alerts/{event}/acknowledge`
  - `GET|POST|PUT /settings/notification-channels`
  - `GET|POST|PUT /settings/alert-rules`
- New models and services:
  - `AlertRule`
  - `AlertNotification`
  - Health polling service
  - Metric snapshot writer
  - `AlertEvaluator`
  - Telegram notifier
- Alert fingerprint format:
  - Health: `integration:{integration_id}:health_failure`
  - Guest stopped: `integration:{integration_id}:guest:{guest_type}:{vmid}:stopped`
  - Threshold: `integration:{integration_id}:guest:{guest_type}:{vmid}:{metric_key}`

## Test Plan
- Failed health check opens one `critical` alert, stores `site_id`, and sends one Telegram message to the correct site channel.
- Repeated failures for the same fingerprint do not create duplicate events or resend Telegram on every poll.
- Recovery resolves the same alert and sends one recovery message.
- A Proxmox guest in a non-running state opens a `critical` alert and resolves when status returns to `running`.
- CPU, memory, and disk thresholds open `warning`, escalate to `critical`, and resolve on recovery.
- `acknowledge` stores user and comment; if the condition worsens again, the alert reopens and notifies again.
- `viewer` cannot acknowledge; only `admin` can manage channels and rules.
- Site-scoped users only see alerts for allowed sites.

## Assumptions and Defaults
- Polling interval default is 1 minute.
- Telegram delivery is per-site, with global fallback for integrations not tied to a site.
- V1 excludes manual event input and external webhook ingestion.
- V1 excludes email and generic webhook delivery even though the model stays extensible.
- The current `Event` to `Site` relation is intended, and schema will be aligned to it.
