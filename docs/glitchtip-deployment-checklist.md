# GlitchTip Deployment Checklist

Checklist ini dipakai saat memindahkan integrasi GlitchTip ke environment production InfraControl.

## 1. Environment Variables

Isi variable berikut di server production:

```env
SENTRY_LARAVEL_DSN=https://1404b56dc0254d1fbad9deb41db9d30c@glitchtip.ocnetworks.web.id/2
SENTRY_ENVIRONMENT=production
SENTRY_RELEASE=2026.06.19
SENTRY_SAMPLE_RATE=1.0
SENTRY_TRACES_SAMPLE_RATE=0.01
SENTRY_SEND_DEFAULT_PII=false
SENTRY_SEND_LOCAL=false

GLITCHTIP_SECURITY_ENDPOINT=https://glitchtip.ocnetworks.web.id/api/2/security/?glitchtip_key=1404b56dc0254d1fbad9deb41db9d30c
GLITCHTIP_CSP_REPORT_ONLY=true

VITE_SENTRY_DSN="${SENTRY_LARAVEL_DSN}"
VITE_SENTRY_ENVIRONMENT="${APP_ENV}"
VITE_SENTRY_RELEASE="${SENTRY_RELEASE}"
VITE_SENTRY_TRACES_SAMPLE_RATE=0
VITE_SENTRY_ENABLED=true
VITE_BUILD_SOURCEMAP=true
```

Jika source map akan di-upload, tambahkan juga:

```env
SENTRY_URL=https://glitchtip.ocnetworks.web.id
SENTRY_AUTH_TOKEN=fill-me
SENTRY_ORG=fill-me
SENTRY_PROJECT=fill-me
```

## 2. Build dan Deploy

Jalankan command berikut saat release:

```sh
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
npm ci
npm run build:sourcemaps
```

Jika memakai upload source map:

```sh
npm run glitchtip:sourcemaps:upload
```

Atau gabungkan build + upload:

```sh
npm run glitchtip:sourcemaps:build-upload
```

## 3. Post-Deploy Verification

Setelah deploy selesai, verifikasi ini:

1. Buka `Settings > GlitchTip` dan pastikan backend/frontend status `Enabled`.
2. Klik `Send Backend Test Event` lalu cek event masuk di GlitchTip.
3. Klik `Throw Frontend Test Error` lalu cek event JavaScript masuk di GlitchTip.
4. Klik `Open CSP Report Test` lalu cek item baru muncul di tab Security GlitchTip.
5. Buka `php artisan about --only=sentry` di server dan pastikan environment serta SDK aktif.

## 4. Recommended Production Notes

- Biarkan `GLITCHTIP_CSP_REPORT_ONLY=true` lebih dulu sampai policy stabil.
- Setelah policy CSP sudah aman, pertimbangkan ubah ke enforce mode dengan `GLITCHTIP_CSP_REPORT_ONLY=false`.
- Gunakan nilai `SENTRY_RELEASE` yang konsisten, misalnya hash commit atau nomor release CI.
- Simpan `SENTRY_AUTH_TOKEN` hanya di secret manager atau environment server, jangan di repo.
