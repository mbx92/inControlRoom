# Internal Vault MVP

Dokumen ini mendefinisikan scope awal vault internal InfraControl setelah integrasi Vaultwarden dihapus dari codebase.

## Goal

Menyediakan penyimpanan secret operasional yang:

- terenkripsi saat disimpan,
- punya scope `site` atau `global`,
- punya audit trail yang jelas,
- cukup untuk kebutuhan admin infrastruktur,
- belum mencoba menjadi password manager end-user penuh.

## In Scope

- Menyimpan secret generik seperti:
  - password admin layanan
  - API token
  - SSH private key
  - recovery code
- Metadata secret:
  - `name`
  - `kind`
  - `site_id`
  - `notes`
  - `rotation_interval_days`
  - `last_rotated_at`
- Audit log untuk:
  - create
  - update
  - reveal
  - revoke / archive
- Enkripsi backend menggunakan Laravel encryption primitives atau envelope encryption yang setara.

## Out of Scope

- Browser extension
- Autofill credential
- Shared vault ala Bitwarden
- Organization policy engine
- End-user password manager
- TOTP generator di fase awal

## Data Model Draft

### `vault_entries`

- `id`
- `site_id` nullable
- `name`
- `kind`
- `ciphertext`
- `notes`
- `rotation_interval_days` nullable
- `last_rotated_at` nullable
- `is_active`
- `created_at`
- `updated_at`

### `vault_entry_access_logs`

- `id`
- `vault_entry_id`
- `user_id`
- `action`
- `ip_address`
- `created_at`

## Security Rules

- Plaintext secret tidak boleh dikirim ke list page.
- Reveal harus dilakukan per-entry dan dicatat ke audit log.
- Secret tidak boleh dipakai sebagai filter/search plaintext.
- Export massal plaintext tidak masuk MVP.

## Suggested Build Order

1. Migration + model `vault_entries`
2. CRUD backend + encryption
3. List page + create/edit form
4. Reveal action dengan audit log
5. Site filter + dashboard counters

## Migration Command

Untuk memindahkan credential lama yang masih tersimpan inline di tabel `integrations` ke vault internal:

```bash
php artisan vault:migrate-inline-credentials --user=<id-atau-email>
```

Preview tanpa menulis perubahan:

```bash
php artisan vault:migrate-inline-credentials --dry-run --user=<id-atau-email>
```

Perilaku command:

- hanya memproses integrasi `proxmox` yang belum punya `vault_entry_id`,
- membuat `vault_entries` baru dari token lama,
- mengosongkan `integrations.credentials` menjadi `[]`,
- menulis audit log untuk vault dan integration migration.
