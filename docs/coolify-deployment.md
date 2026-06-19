# Coolify Deployment Guide

Dokumen ini menjelaskan cara deploy InfraControl ke Coolify menggunakan `docker-compose.coolify.yml`.

## Struktur Service

Stack ini dibagi menjadi empat service:

- `app`: Laravel + Nginx + PHP-FPM
- `queue`: worker untuk `QUEUE_CONNECTION=database`
- `scheduler`: menjalankan Laravel scheduler
- `terminal-proxy`: WebSocket SSH proxy untuk halaman terminal Headscale

`terminal-proxy` sengaja dipisah supaya bisa diberi domain sendiri di Coolify dan tetap sehat saat `app` diredeploy.

## File yang Dipakai

- Compose file: `docker-compose.coolify.yml`
- Docker image: `Dockerfile`
- Runtime scripts/config: folder `docker/`

## Langkah di Coolify

1. Buat resource baru dan pilih build pack `Docker Compose`.
2. Set `Base Directory` ke `/`.
3. Set `Docker Compose Location` ke `/docker-compose.coolify.yml`.
4. Jangan tambahkan custom network di compose. Coolify sudah membuat network stack sendiri.
5. Assign domain untuk service:
   - `app` ke domain utama, misalnya `https://incontrol.example.com`
   - `terminal-proxy` ke subdomain websocket, misalnya `https://terminal.incontrol.example.com`

## Environment Minimum

Isi minimal variable berikut di Coolify:

```env
APP_NAME=InfraControl
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:replace-me
APP_URL=https://incontrol.example.com

DB_CONNECTION=pgsql
DB_HOST=postgres.internal
DB_PORT=5432
DB_DATABASE=incontrolroom
DB_USERNAME=postgres
DB_PASSWORD=replace-me
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database

SSH_TERMINAL_PROXY_URL=wss://terminal.incontrol.example.com/terminal

SENTRY_LARAVEL_DSN=https://1404b56dc0254d1fbad9deb41db9d30c@glitchtip.ocnetworks.web.id/2
SENTRY_ENVIRONMENT=production
SENTRY_RELEASE=2026.06.19
GLITCHTIP_SECURITY_ENDPOINT=https://glitchtip.ocnetworks.web.id/api/2/security/?glitchtip_key=1404b56dc0254d1fbad9deb41db9d30c
GLITCHTIP_CSP_REPORT_ONLY=true

VITE_SENTRY_DSN=${SENTRY_LARAVEL_DSN}
VITE_SENTRY_ENVIRONMENT=${APP_ENV}
VITE_SENTRY_RELEASE=${SENTRY_RELEASE}
VITE_SENTRY_ENABLED=true
```

## Domain dan Terminal Proxy

Gunakan domain terpisah untuk terminal proxy. Konfigurasi yang direkomendasikan:

- `APP_URL=https://incontrol.example.com`
- `SSH_TERMINAL_PROXY_URL=wss://terminal.incontrol.example.com/terminal`

Alasannya:

- Browser origin untuk terminal tetap `APP_URL`, dan itu memang yang diizinkan oleh proxy.
- Coolify/Traefik akan menangani WebSocket upgrade otomatis selama service `terminal-proxy` diberi domain sendiri.
- Aplikasi tetap bisa health-check proxy secara internal lewat `http://terminal-proxy:8078/healthz`.

## Database Mode

File `.env.example` sekarang mencontohkan PostgreSQL, jadi untuk production di Coolify yang paling disarankan adalah:

1. Buat resource PostgreSQL di Coolify.
2. Isi `DB_CONNECTION=pgsql`.
3. Isi `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, dan `DB_PASSWORD` dari resource tersebut.
4. Biarkan `AUTO_RUN_MIGRATIONS=true` kalau ingin migration jalan otomatis saat deploy.

Compose ini masih bisa dipakai untuk SQLite kalau memang dibutuhkan, tetapi contoh environment yang didokumentasikan sekarang mengikuti PostgreSQL.

## Post-Deploy Checks

1. Buka `https://incontrol.example.com/up` dan pastikan health check `200 OK`.
2. Login lalu buka `Settings`.
3. Pastikan panel `Proxy Control` menampilkan `managed externally`.
4. Buka halaman terminal Headscale dan verifikasi WebSocket tersambung.
5. Jalankan test GlitchTip backend, frontend, dan CSP dari panel `GlitchTip`.

## Optional Overrides

- `AUTO_RUN_MIGRATIONS=false` jika migration ingin dijalankan manual.
- `QUEUE_WORKER_QUEUE=high,default` jika ingin queue tertentu.
- `SCHEDULER_INTERVAL=60` untuk interval scheduler loop.
- `DB_CONNECTION=mysql` atau `pgsql` jika memakai database eksternal.
