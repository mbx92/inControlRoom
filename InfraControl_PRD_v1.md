# InfraControl
## Unified IT Infrastructure Control Plane
### Product Requirements Document · v1.0

> **Type:** Internal tool + portofolio — self-hosted, open source stack
> **Target:** Solo IT admin rumah sakit, 10–50 VM, 100–500 user, zero budget
> **Stack:** Laravel 11 + Vue 3 + Inertia.js + Tailwind CSS v4 + DaisyUI v5 + PostgreSQL

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Problem Statement](#2-problem-statement)
3. [Goals & Success Metrics](#3-goals--success-metrics)
4. [Scope & Modules](#4-scope--modules)
5. [Feature Requirements](#5-feature-requirements)
6. [Technical Architecture](#6-technical-architecture)
7. [Proxmox Integration — Detail Spec](#7-proxmox-integration--detail-spec)
8. [Development Roadmap](#8-development-roadmap)
9. [Out of Scope](#9-out-of-scope)
10. [Risks & Mitigations](#10-risks--mitigations)

---

## 1. Executive Summary

InfraControl adalah **unified control plane** berbasis web yang dibangun di atas open source tools (Proxmox, Prometheus, Uptime Kuma, Ansible, dll) plus modul internal yang memang spesifik ke kebutuhan operasional sendiri — bukan menggantikannya. Dashboard ini mengekspos satu antarmuka terpusat yang relevan dengan konteks spesifik infrastruktur rumah sakit: server mana yang kritis, siapa yang punya akses ke apa, kapan backup terakhir berjalan, dan satu klik untuk trigger remediation actions.

### Arsitektur Tiga Layer

```
┌─────────────────────────────────────────────────────────────┐
│              Custom Dashboard (milik kamu)                   │
│        Laravel + Vue 3 + Inertia.js — portofolio +          │
│                   production tool                            │
├──────────────┬──────────────┬──────────────┬────────────────┤
│ Server       │ Alert Center │ Backup       │ Quick Actions  │
│ Overview     │              │ Status       │                │
├─────────────────────────────────────────────────────────────┤
│              Integration Layer (Laravel)                     │
│     Polling/webhook · Encrypted credentials · REST API      │
├──────────────┬──────────────┬──────────────┬────────────────┤
│  Proxmox     │  Prometheus  │  Uptime Kuma │  Ansible AWX   │
│  PBS         │  Inventory   │  Duplicati   │  Internal Vault│
│              Open Source Engine (jangan disentuh)           │
└─────────────────────────────────────────────────────────────┘
```

**Diferensiasi utama:** semua credential dan konfigurasi integrasi dikelola melalui UI — tidak ada hardcoded config, tidak ada edit `.env` untuk credential integrasi. Setiap integrasi ditambahkan, dikonfigurasi, dan diuji langsung dari halaman Settings.

---

## 2. Problem Statement

### 2.1 Konteks

IT admin rumah sakit mayoritas beroperasi solo — mengelola virtual environment, networking, email server, backup, dan user management tanpa tim pendukung. Setiap sistem berjalan di tool yang berbeda dengan UI yang berbeda pula.

### 2.2 Pain Points Utama

| ID | Pain Point | Impact |
|----|-----------|--------|
| P1 | Tidak ada visibility terpusat — harus buka 5–6 dashboard berbeda untuk tahu status infrastruktur | Tinggi |
| P2 | Alert tidak terintegrasi — downtime bisa terjadi tanpa notifikasi, atau notifikasi dari banyak sistem yang tidak terhubung | Kritis |
| P3 | Credential chaos — password tersebar di sticky note, file teks, atau kepala admin sendiri | Tinggi |
| P4 | Tidak ada audit trail — tidak diketahui siapa melakukan apa dan kapan di sistem mana | Sedang |
| P5 | Onboarding sulit — kalau admin sakit atau resign, tidak ada yang bisa langsung mengambil alih | Tinggi |

---

## 3. Goals & Success Metrics

### 3.1 Primary Goals

- Satu dashboard untuk status semua sistem infrastruktur
- Alert terpusat dengan notifikasi ke kanal yang relevan (email, Telegram, WhatsApp)
- Credential management via UI — tidak ada hardcoded config
- Audit log setiap aksi yang dilakukan melalui dashboard
- Quick actions: restart service, run playbook, trigger backup — tanpa SSH manual

### 3.2 Success Metrics

| Metrik | Baseline | Target |
|--------|----------|--------|
| MTTR (Mean Time to Respond) | Tidak terukur | < 10 menit |
| Waktu cek status harian | ~30–45 menit | < 5 menit |
| Jumlah tool yang dibuka per cek | 5–7 tool | 1 dashboard |
| Dokumentasi infrastruktur | Manual / tidak ada | Registry inventaris internal yang terstruktur |
| Audit trail coverage | 0% | 100% aksi via dashboard |

---

## 4. Scope & Modules

Produk dibagi dalam 3 phase rilis. Phase 1 adalah MVP yang sudah bisa dipakai produksi.

### Phase 1 — MVP (Bulan 1–2)

#### Integration Hub
> Integrasi: Internal DB (encrypted credentials)

Halaman Settings untuk menambah, mengonfigurasi, dan menguji koneksi ke semua integrasi (Proxmox, Prometheus, dll). Credential dienkripsi dengan AES-256 sebelum disimpan ke database. Tidak ada hardcoded `.env` untuk credential integrasi.

#### Proxmox Module
> Integrasi: Proxmox VE API v2

Overview semua node, VM, dan container dari Proxmox cluster. Status real-time (running/stopped/error), resource usage (CPU/RAM/disk), dan quick actions: start, stop, reboot VM. Data di-fetch via Proxmox API menggunakan token yang dikonfigurasi di Integration Hub.

#### Internal Vault Module
> Integrasi: Internal encrypted storage

Penyimpanan secret operasional langsung di InfraControl dengan encryption-at-rest, audit trail, dan scope per site. Fokus awalnya adalah shared secrets tim IT, metadata rotasi, dan akses administratif yang bisa dilacak.

#### Alert Center
> Integrasi: Prometheus Alertmanager, Uptime Kuma webhook, internal health checks

Agregasi alert dari semua integrasi aktif ke satu feed terpusat. Alert dikategorikan berdasarkan severity (critical/warning/info). Notifikasi dikirim ke kanal yang dikonfigurasi user (Telegram bot, email SMTP). Setiap alert bisa di-acknowledge dengan komentar.

#### Audit Log
> Integrasi: Internal (database)

Setiap aksi yang dilakukan melalui dashboard dicatat: siapa, apa, kapan, dari IP mana, dan hasilnya. Log tidak bisa dihapus melalui UI. Filter dan export ke CSV.

---

### Phase 2 — Core Expansion (Bulan 3–4)

#### Backup Monitor
> Integrasi: Proxmox Backup Server API, Duplicati API

Status semua backup job dari PBS dan/atau Duplicati. Terakhir sukses, ukuran, durasi, dan next scheduled run. Alert otomatis kalau backup gagal lebih dari N jam.

#### Inventory Registry & CMDB
> Integrasi: Internal DB + manual registry

Registry inventaris internal yang ringan untuk mencatat aset, ownership, lokasi, IP utama, dan metadata tambahan seperlunya. Dibangun dari awal agar field yang disimpan hanya yang benar-benar dipakai tim operasional, tanpa overhead NetBox.

#### Automation Runner
> Integrasi: Ansible AWX / Semaphore API

Trigger Ansible playbook atau AWX job templates langsung dari dashboard. Lihat output real-time, history eksekusi, dan jadwalkan run berulang. Dilindungi oleh role permission.

---

### Phase 3 — Advanced (Bulan 5–6)

#### Reporting & SLA
> Integrasi: Internal aggregation

Laporan bulanan otomatis: uptime per service, backup success rate, incident count. Export PDF. Berguna untuk laporan ke manajemen rumah sakit.

#### Multi-user & RBAC
> Integrasi: Internal (Spatie Permission atau custom)

User management dengan role: Super Admin, IT Staff, View Only. Setiap role dibatasi modul dan aksi yang bisa dilakukan.

---

## 5. Feature Requirements

| Fitur | Deskripsi | Prioritas |
|-------|-----------|-----------|
| Dynamic credential store | Tambah/edit/hapus credential integrasi via UI, enkripsi AES-256 at rest | P0 |
| Connection test | Test koneksi ke setiap integrasi sebelum disimpan, tampilkan error yang actionable | P0 |
| Proxmox VM overview | List semua VM + node dengan status dan resource usage real-time | P0 |
| VM quick actions | Start / stop / reboot VM dari dashboard tanpa SSH | P0 |
| Alert feed terpusat | Agregasi alert dari semua integrasi aktif, sortir by severity dan waktu | P0 |
| Telegram notifikasi | Kirim alert ke Telegram bot yang dikonfigurasi user | P0 |
| Audit log immutable | Catat semua aksi user, tidak bisa dihapus, bisa di-filter dan export CSV | P0 |
| Internal secrets vault | Simpan dan kelola secret operasional dengan encryption, audit trail, dan scope site/global | P0 |
| Dashboard overview | Widget ringkasan: total VM, alert aktif, uptime skor | P1 |
| Backup job monitor | Status terakhir semua backup job, alert kalau terlambat | P1 |
| Ansible job trigger | Jalankan playbook/job template dari UI dengan output real-time | P1 |
| Inventory registry | Simpan aset, ownership, lokasi, IP, dan atribut tambahan di CMDB internal yang ringan | P1 |
| Email notifikasi | Kirim alert ke email via SMTP yang dikonfigurasi user | P1 |
| Laporan PDF bulanan | Generate laporan uptime dan backup otomatis setiap bulan | P2 |
| Multi-user RBAC | Role-based access dengan batasan per modul dan per aksi | P2 |
| Dark mode | Toggle dark/light mode, preferensi disimpan per user | P2 |

---

## 6. Technical Architecture

### 6.1 Stack

| Layer | Technology |
|-------|-----------|
| Backend | Laravel 11, PHP 8.3 |
| Database | PostgreSQL |
| Frontend | Vue 3 + Inertia.js v2 |
| CSS | Tailwind CSS v4 + DaisyUI v5 |
| Queue | Laravel Queue + Redis |
| Scheduler | Laravel Task Scheduler (cron) |
| Auth | Laravel Sanctum + session-based |

### 6.2 Integration Architecture

Setiap integrasi direpresentasikan sebagai record di tabel `integrations`:

```
integrations
├── id (uuid)
├── type          → proxmox | prometheus | uptime_kuma | pbs | ansible | ...
├── name          → label bebas dari user
├── base_url      → https://proxmox.local:8006
├── credentials   → JSON dienkripsi AES-256 (Laravel Crypt)
├── config        → JSON tambahan non-sensitif (polling interval, dll)
├── is_active
└── last_synced_at
```

Polling dilakukan oleh dedicated Job class per integrasi yang dijadwalkan via Laravel Scheduler setiap 30 detik (configurable). Hasil polling disimpan ke tabel `metrics` dan `events` sebagai buffer — frontend tidak hit integrasi langsung.

```
Laravel Scheduler
    └── PollProxmoxJob (every 30s)
    └── PollPrometheusJob (every 30s)
    └── PollUptimeKumaJob (every 60s)
            │
            ▼
    metrics / events table (buffer)
            │
            ▼
    Vue components via Inertia
```

### 6.3 Database Schema (Utama)

```sql
-- Integrasi & credential
integrations          (id, type, name, base_url, credentials, config, is_active, last_synced_at)

-- Buffer data dari polling
metrics               (id, integration_id, key, value, labels jsonb, recorded_at)
events                (id, integration_id, severity, title, message, status, acknowledged_by, acknowledged_at)

-- Inventaris internal
inventory_assets      (id, site_id, name, category, status, asset_tag, serial_number, primary_ip, location_label, owner_name, custom_fields jsonb, notes)

-- Notifikasi
notification_channels (id, type, config jsonb encrypted, is_active)

-- Audit
audit_logs            (id, user_id, action, target_type, target_id, payload jsonb, ip_address, created_at)
```

### 6.4 Security Considerations

> Wajib diperhatikan untuk lingkungan rumah sakit.

- Semua credential dienkripsi dengan `Laravel Crypt` (AES-256-CBC) sebelum disimpan
- Dashboard hanya dapat diakses dari jaringan internal — tidak expose ke internet langsung
- Setiap API call ke integrasi menggunakan token dengan scope minimal (read + specific actions only)
- Session timeout dikonfigurasi ketat (default 8 jam, configurable per user)
- Rate limiting pada endpoint quick actions untuk mencegah aksi tidak disengaja
- Audit log tidak bisa dihapus melalui UI — hanya bisa via direct DB oleh Super Admin

---

## 7. Proxmox Integration — Detail Spec

Proxmox adalah integrasi pertama yang dibangun. Ini menjadi referensi pola untuk semua integrasi berikutnya.

### 7.1 Konfigurasi di Integration Hub

| Field | Value | Catatan |
|-------|-------|---------|
| Base URL | `https://<host>:8006` | Wajib HTTPS |
| Auth type | API Token | Direkomendasikan vs username/password |
| Token format | `USER@REALM!TOKENID=SECRET` | e.g. `root@pam!infracontrol=abc123` |
| Verify SSL | Toggle | Disable untuk self-signed cert di homelab |
| Polling interval | 30s (default) | Configurable per integrasi |

**Test Connection** → hit `GET /api2/json/version` → tampilkan versi Proxmox jika sukses, error message yang actionable jika gagal.

### 7.2 Data yang Ditarik

```
GET /api2/json/nodes
    → nama node, status, CPU usage %, RAM usage %, uptime

GET /api2/json/nodes/{node}/qemu
    → vmid, name, status, cpus, maxmem, mem, disk, uptime

GET /api2/json/nodes/{node}/lxc
    → sama dengan qemu (container)

GET /api2/json/nodes/{node}/storage
    → name, type, avail, total, used
```

### 7.3 Quick Actions

| Aksi | Endpoint | Auth |
|------|----------|------|
| Start VM | `POST /api2/json/nodes/{node}/qemu/{vmid}/status/start` | API Token |
| Stop VM | `POST /api2/json/nodes/{node}/qemu/{vmid}/status/stop` | API Token |
| Reboot VM | `POST /api2/json/nodes/{node}/qemu/{vmid}/status/reboot` | API Token |
| Start CT | `POST /api2/json/nodes/{node}/lxc/{vmid}/status/start` | API Token |
| Stop CT | `POST /api2/json/nodes/{node}/lxc/{vmid}/status/stop` | API Token |

> Setiap aksi dicatat ke `audit_logs` **sebelum** dieksekusi. Kalau gagal, alert otomatis muncul di Alert Center.

### 7.4 Default Alert Rules

| Kondisi | Threshold | Severity |
|---------|-----------|----------|
| Node tidak respond | > 2 polling interval | Critical |
| VM status error | > 5 menit | Critical |
| CPU node tinggi | > 90% selama > 10 menit | Warning |
| RAM node kritis | > 95% | Warning |
| Storage hampir penuh | > 85% used | Warning |

### 7.5 Proxmox API Token Setup (Minimal Permission)

```bash
# Di Proxmox UI → Datacenter → Permissions → API Tokens
# Buat token untuk user dengan role PVEAuditor + custom privileges:

pveum role add InfraControl -privs "VM.PowerMgmt VM.Audit Datastore.Audit Sys.Audit"
pveum user add infracontrol@pve
pveum aclmod / -user infracontrol@pve -role InfraControl
pveum user token add infracontrol@pve infracontrol-token --privsep 0
```

---

## 7.6 Internal Vault — Directional Spec

Vault internal akan menggantikan ketergantungan ke password manager eksternal untuk use case operasional dasar. Modul ini harus dibangun dengan prinsip:

- encryption-at-rest untuk semua nilai secret,
- scope `site` atau `global`,
- audit trail immutable untuk create, read, update, revoke,
- tidak menampilkan plaintext secara bebas di feed operasional umum.

### Konfigurasi Awal yang Disarankan

| Field | Value | Catatan |
|-------|-------|---------|
| Name | string | Nama secret |
| Site | nullable site_id | `null` berarti global |
| Kind | enum | mis. password, api_token, ssh_key |
| Ciphertext | encrypted | Nilai terenkripsi |
| Rotation interval | optional | Metadata kebijakan |
| Last rotated at | optional | Metadata operasional |

### Prinsip Akses

- Secret hanya dibuka di endpoint/backend yang memang perlu.
- Dashboard umum menampilkan metadata, bukan isi secret.
- Aksi baca atau reveal wajib masuk audit log.

---

## 8. Development Roadmap

| Phase | Milestone | Durasi | Deliverable | Status |
|-------|-----------|--------|-------------|--------|
| Phase 1 | M1 | Minggu 1–2 | Integration Hub + Proxmox Module | 🔵 In Progress |
| Phase 1 | M2 | Minggu 3–4 | Alert Center + Telegram + Audit Log | ⚪ Planned |
| Phase 1 | M3 | Minggu 5–6 | Dashboard Overview + Polish + Testing | ⚪ Planned |
| Phase 2 | M4 | Bulan 2 | Backup Monitor + Uptime Kuma integration | ⚪ Planned |
| Phase 2 | M5 | Bulan 3 | Internal inventory registry + Ansible Runner | ⚪ Planned |
| Phase 2 | M6 | Bulan 4 | Email notif + Prometheus metrics module | ⚪ Planned |
| Phase 3 | M7 | Bulan 5–6 | RBAC + PDF reports + Dark mode | 🗂 Backlog |

### Definition of Done — Phase 1

- [ ] Integration Hub dapat menyimpan, mengedit, dan menghapus credential integrasi Proxmox
- [ ] Test connection berfungsi dan menampilkan feedback yang jelas
- [ ] Proxmox Module menampilkan semua node dan VM dengan status real-time
- [ ] Quick actions (start/stop/reboot) berfungsi dan tercatat di audit log
- [ ] Alert Center menerima dan menampilkan alert dari Proxmox
- [ ] Notifikasi Telegram terkirim saat ada alert baru
- [ ] Audit log mencatat semua aksi dengan detail lengkap
- [ ] **Fondasi vault internal siap menyimpan secret terenkripsi dengan scope site/global**
- [ ] **Aksi akses secret tercatat penuh di audit log**
- [ ] Dashboard di-deploy di homelab dan berjalan stabil selama 7 hari

---

## 9. Out of Scope (v1)

- **Mobile app** — dioptimalkan untuk desktop browser
- **Multi-hospital / multi-tenant** — satu instalasi per rumah sakit
- **Billing / asset procurement** — bukan ERP
- **Public cloud integration** (AWS/GCP/Azure) — fokus on-premise
- **SaaS / berbayar** — internal tool dan portofolio, bukan produk komersial v1

---

## 10. Risks & Mitigations

| ID | Risk | Likelihood | Impact | Mitigasi |
|----|------|-----------|--------|----------|
| R1 | API breaking changes dari Proxmox atau tool lain | Low | High | Pin versi API, tulis integration tests yang run weekly |
| R2 | Credential exposure jika database bocor | Low | Critical | Enkripsi AES-256, backup DB dienkripsi, tidak expose ke internet |
| R3 | Dashboard down = kehilangan visibility | Medium | Medium | OSS tools tetap berjalan independen, dashboard hanya agregator |
| R4 | Scope creep — terlalu banyak fitur dibangun sekaligus | High | Medium | Strict phase discipline, Phase 1 production-ready sebelum Phase 2 |

---

*InfraControl PRD v1.0 · Prepared for internal use · 2025*
*CONFIDENTIAL*
