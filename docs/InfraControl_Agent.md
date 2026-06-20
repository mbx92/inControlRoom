# InfraControl Agent

Dokumen ini mendefinisikan desain, arsitektur, distribusi, dan rencana implementasi **InfraControl Agent** — lightweight daemon yang berjalan di server Windows/Linux untuk mengirim metrics dan status ke InfraControl secara outbound.

> **Status:** Desain / belum diimplementasi  
> **Target:** Server Windows dan Linux di belakang firewall rumah sakit  
> **Stack agent:** Go (rekomendasi) · **Stack server:** Laravel 11 + PostgreSQL

---

## Table of Contents

1. [Goal](#1-goal)
2. [Problem Statement](#2-problem-statement)
3. [Arsitektur](#3-arsitektur)
4. [Pilihan Bahasa](#4-pilihan-bahasa)
5. [Struktur Agent](#5-struktur-agent)
6. [API Server (Laravel)](#6-api-server-laravel)
7. [Data Model](#7-data-model)
8. [Payload Metrics](#8-payload-metrics)
9. [Enrollment Flow](#9-enrollment-flow)
10. [Keamanan](#10-keamanan)
11. [Distribusi & Instalasi](#11-distribusi--instalasi)
12. [Integrasi dengan Modul InfraControl](#12-integrasi-dengan-modul-infracontrol)
13. [Roadmap Implementasi](#13-roadmap-implementasi)
14. [Out of Scope (MVP)](#14-out-of-scope-mvp)
15. [Alternatif Tanpa Agent](#15-alternatif-tanpa-agent)

---

## 1. Goal

Menyediakan visibility host-level untuk server yang tidak bisa dipantau langsung dari InfraControl (Windows, Linux tanpa exporter, host di NAT/firewall), dengan karakteristik:

- **Outbound-only** — agent hanya kirim HTTPS ke server; tidak perlu buka port inbound di host target
- **Single binary** — tidak perlu install runtime di setiap mesin
- **Windows Service / systemd** — jalan otomatis saat boot
- **Terhubung ke Inventory** — satu agent ↔ satu `inventory_asset` (Server/Hypervisor)
- **Feed metrics & alerting** — data masuk ke tabel `metrics` dan pipeline alert yang sudah ada

---

## 2. Problem Statement

InfraControl saat ini memantau integrasi eksternal dengan **polling dari server ke target** (Proxmox, Docker, NAS, Custom API, dll). Pola ini cocok untuk layanan yang expose HTTP API, tapi tidak ideal untuk:

| Situasi | Kenapa polling dari server gagal |
|---------|----------------------------------|
| Windows Server di LAN tanpa port terbuka | InfraControl tidak bisa reach host dari cloud/VPS |
| Host di NAT / firewall ketat | Inbound connection ditolak |
| Metrics OS-level (CPU, RAM, disk, services) | Butuh kode yang jalan **di dalam** host |
| Banyak server Windows | WinRM/WMI dari server pusat rumit dan tidak aman tanpa agent |

Asset Monitoring saat ini hanya **ping ICMP** ke `primary_ip` — cukup untuk up/down, tapi tidak memberikan metrics detail atau status service.

Agent mengisi celah antara **ping sederhana** dan **integrasi API penuh**.

---

## 3. Arsitektur

### 3.1 Model Push (Rekomendasi)

```
┌─────────────────────┐         HTTPS POST          ┌──────────────────────────┐
│  Windows / Linux    │  ─────────────────────────► │  InfraControl (Laravel)  │
│  infracontrol-agent │   /api/agents/enroll        │                          │
│                     │   /api/agents/heartbeat     │  → agents table          │
│  - collect metrics  │                             │  → metrics table         │
│  - Windows Service  │                             │  → Asset Monitoring      │
│  - systemd unit     │                             │  → Alert Evaluator       │
└─────────────────────┘                             └──────────────────────────┘
        ▲
        │ tidak ada inbound connection
        │ dari server ke agent
```

**Keuntungan push model:**

- Tidak perlu buka firewall inbound di setiap server
- Cocok untuk deploy InfraControl di cloud (Coolify/VPS) sementara target di LAN rumah sakit
- Agent bisa retry otomatis jika server sementara down

### 3.2 Model Pull (Alternatif — Fase Awal / Prototype)

```
InfraControl ──GET──► http://10.x.x.x:9100/health  (Custom API integration)
```

Sudah didukung hari ini via tipe integrasi **`custom_api`**. Cocok untuk pilot cepat jika port HTTP bisa dibuka di host. Tidak direkomendasikan untuk production Windows di hospital network.

### 3.3 Diagram Komponen Lengkap

```
┌─────────────────────────────────────────────────────────────────┐
│                        InfraControl Server                       │
├─────────────────────────────────────────────────────────────────┤
│  Settings → Agents          POST /api/agents/enroll             │
│  (generate token, revoke)   POST /api/agents/heartbeat          │
│                             GET  /api/agents/version            │
├─────────────────────────────────────────────────────────────────┤
│  Scheduler (every 30s–1m)   EvaluateAgentStaleJob               │
│                             → alert jika last_seen > threshold  │
├─────────────────────────────────────────────────────────────────┤
│  Inventory Asset  ◄──linked──►  Agent Record                    │
│  Asset Monitoring ◄──reads──────  metrics (host.*)              │
│  Alerts           ◄──evaluates──  agent_offline, disk_full, ... │
└─────────────────────────────────────────────────────────────────┘
```

---

## 4. Pilihan Bahasa

| Bahasa | Binary | Cross-platform | Windows Service | Rekomendasi |
|--------|--------|----------------|-----------------|-------------|
| **Go** | Single exe (~10–20 MB) | Linux + Windows + macOS | Via `kardianos/service` | **Ya — pilihan utama** |
| .NET 8 | Lebih besar (~60 MB+) | Windows native, Linux OK | Native Windows Service | Windows-only shop |
| Rust | Kecil, cepat | Ya | Via crate `windows-service` | Tim kuat Rust |
| Node.js | Butuh runtime Node | Ya | Via `node-windows` | Prototype saja |
| Python | Butuh Python di host | Ya | Via NSSM | Tidak untuk production |

### Rekomendasi: Go

Alasan teknis:

- Satu binary per OS/arch — tidak perlu install dependency di target
- Pola industri standar (Prometheus node_exporter, Telegraf, Grafana Alloy)
- Library metrics: [`gopsutil`](https://github.com/shirou/gopsutil) (CPU, RAM, disk, network, processes)
- Build cross-compile mudah: `GOOS=windows GOARCH=amd64 go build`

Repo agent disarankan **terpisah** dari monolith Laravel:

```
infracontrol-agent/          ← repo Go terpisah (atau subfolder /agent)
inControlRoom/               ← Laravel dashboard (repo ini)
```

---

## 5. Struktur Agent

```
infracontrol-agent/
├── cmd/
│   └── agent/
│       └── main.go                 # entry point
├── internal/
│   ├── config/
│   │   └── config.go               # baca config.yaml + env override
│   ├── collector/
│   │   ├── collector.go            # interface Collector
│   │   ├── host.go                 # CPU, RAM, uptime (gopsutil)
│   │   ├── disk.go                 # disk usage per mount
│   │   └── services.go             # Windows services / systemd units
│   ├── client/
│   │   └── infracontrol.go         # HTTP client ke Laravel API
│   ├── enroll/
│   │   └── enroll.go               # registrasi pertama kali
│   └── platform/
│       ├── service.go              # abstraksi Windows Service / systemd
│       ├── windows/
│       └── linux/
├── config.example.yaml
├── install/
│   ├── windows/
│   │   ├── install.ps1
│   │   └── uninstall.ps1
│   └── linux/
│       ├── install.sh
│       └── infracontrol-agent.service
├── go.mod
└── README.md
```

### Config File

Lokasi default:

| OS | Path |
|----|------|
| Windows | `C:\ProgramData\InfraControl\config.yaml` |
| Linux | `/etc/infracontrol/agent.yaml` |

```yaml
# config.example.yaml
server_url: https://infracontrol.example.com
enroll_token: ""          # diisi sekali saat install; kosong setelah enroll
agent_token: ""           # diisi otomatis setelah enroll berhasil
site_id: ""               # UUID site dari InfraControl (opsional, bisa dari enroll)
inventory_asset_id: ""    # UUID asset yang di-link (opsional)
interval_seconds: 30      # frekuensi heartbeat
log_level: info           # debug | info | warn | error
```

Environment variable override (prioritas lebih tinggi dari file):

```env
INFRACONTROL_SERVER_URL=https://infracontrol.example.com
INFRACONTROL_AGENT_TOKEN=agent_xxxxxxxx
INFRACONTROL_INTERVAL_SECONDS=30
```

### Siklus Kerja Agent

```
Startup
  │
  ├─ agent_token kosong? ──Ya──► POST /api/agents/enroll
  │                                    │
  │                                    └─► simpan agent_token ke config
  │
  └─ Loop setiap interval_seconds:
        1. Collect metrics (CPU, RAM, disk, services, uptime)
        2. POST /api/agents/heartbeat
        3. Jika 401 → log error, stop (token revoked)
        4. Jika 5xx → retry dengan exponential backoff
        5. Cek versi terbaru (opsional, fase 3)
```

---

## 6. API Server (Laravel)

Endpoint baru yang perlu ditambahkan ke InfraControl. Semua endpoint agent menggunakan prefix `/api/agents` dan **tidak** memakai session web — autentikasi via bearer token.

### 6.1 POST `/api/agents/enroll`

Registrasi agent pertama kali menggunakan enrollment token sekali pakai.

**Request:**

```http
POST /api/agents/enroll
Content-Type: application/json

{
  "enroll_token": "enroll_abc123",
  "hostname": "SERVER-DC01",
  "os": "windows",
  "os_version": "2022 Datacenter",
  "arch": "amd64",
  "agent_version": "1.0.0",
  "primary_ip": "10.10.1.244",
  "inventory_asset_id": "uuid-optional"
}
```

**Response `201 Created`:**

```json
{
  "agent_id": "uuid",
  "agent_token": "agent_secret_xxxxxxxx",
  "site_id": "uuid",
  "interval_seconds": 30
}
```

**Error codes:**

| Code | Kondisi |
|------|---------|
| `401` | Enrollment token invalid / expired / sudah dipakai |
| `422` | Payload tidak valid |
| `409` | Hostname + site sudah ter-enroll (duplicate) |

### 6.2 POST `/api/agents/heartbeat`

Kirim metrics berkala. Autentikasi: `Authorization: Bearer {agent_token}`.

**Request:**

```http
POST /api/agents/heartbeat
Authorization: Bearer agent_secret_xxxxxxxx
Content-Type: application/json

{
  "agent_version": "1.0.0",
  "timestamp": "2026-06-20T14:00:00Z",
  "metrics": { ... },
  "services": [ ... ]
}
```

**Response `200 OK`:**

```json
{
  "ok": true,
  "next_interval_seconds": 30,
  "commands": []
}
```

Field `commands` reserved untuk fase lanjutan (restart service, run script, dll).

**Error codes:**

| Code | Kondisi |
|------|---------|
| `401` | Agent token invalid / revoked |
| `422` | Payload tidak valid |

### 6.3 GET `/api/agents/version`

Cek versi agent terbaru (untuk auto-update, fase 3).

**Response:**

```json
{
  "latest": "1.2.0",
  "min_supported": "1.0.0",
  "download": {
    "windows_amd64": "https://infracontrol.example.com/downloads/agent/1.2.0/windows-amd64.exe",
    "linux_amd64": "https://infracontrol.example.com/downloads/agent/1.2.0/linux-amd64"
  },
  "release_notes_url": "https://..."
}
```

### 6.4 UI Admin (Settings → Agents)

Halaman admin untuk:

- Generate enrollment token (expire 24 jam, one-time use)
- Lihat daftar agent ter-enroll (hostname, site, version, last_seen, status)
- Link / unlink ke Inventory Asset
- Revoke agent token
- Lihat metrics history per agent

---

## 7. Data Model

### 7.1 `agent_enrollment_tokens`

| Kolom | Tipe | Keterangan |
|-------|------|------------|
| `id` | uuid | PK |
| `site_id` | uuid | FK ke sites |
| `token_hash` | string | Hash enrollment token (plaintext tidak disimpan) |
| `inventory_asset_id` | uuid nullable | Pre-link ke asset saat generate |
| `expires_at` | timestamp | Default: now + 24 jam |
| `used_at` | timestamp nullable | Diisi saat enroll sukses |
| `created_by` | uuid | FK ke users |
| `created_at` | timestamp | |

### 7.2 `agents`

| Kolom | Tipe | Keterangan |
|-------|------|------------|
| `id` | uuid | PK |
| `site_id` | uuid | FK ke sites |
| `inventory_asset_id` | uuid nullable | FK ke inventory_assets |
| `token_hash` | string | Hash agent token permanen |
| `hostname` | string | Dari agent saat enroll |
| `os` | string | `windows` \| `linux` |
| `os_version` | string | |
| `arch` | string | `amd64`, `arm64`, dll |
| `primary_ip` | string nullable | IP yang dilaporkan agent |
| `agent_version` | string | Versi binary |
| `last_seen_at` | timestamp nullable | Update setiap heartbeat |
| `status` | string | `online` \| `offline` \| `revoked` |
| `enrolled_at` | timestamp | |
| `revoked_at` | timestamp nullable | |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

Index: `(site_id, hostname)` unique, `last_seen_at`, `status`.

### 7.3 Metrics (reuse tabel `metrics` yang ada)

Gunakan key convention konsisten dengan alerting plan:

| Metric Key | Tipe | Label |
|------------|------|-------|
| `host.cpu_usage_percent` | float | `site_id`, `agent_id`, `hostname` |
| `host.memory_usage_percent` | float | idem |
| `host.disk_usage_percent` | float | `mount` (C:, /, dll) |
| `host.uptime_seconds` | int | idem |
| `host.service_status` | int | `service_name`, `status` (1=running, 0=stopped) |
| `agent.last_seen_age_seconds` | int | untuk stale detection |

---

## 8. Payload Metrics

### 8.1 Heartbeat Payload Lengkap

```json
{
  "agent_version": "1.0.0",
  "timestamp": "2026-06-20T14:00:00Z",
  "metrics": {
    "cpu_usage_percent": 42.1,
    "memory_total_bytes": 17179869184,
    "memory_used_bytes": 11744051200,
    "memory_usage_percent": 68.3,
    "uptime_seconds": 864000,
    "disks": [
      {
        "mount": "C:",
        "fstype": "NTFS",
        "total_bytes": 536870912000,
        "used_bytes": 382252089344,
        "used_percent": 71.2
      },
      {
        "mount": "D:",
        "fstype": "NTFS",
        "total_bytes": 1099511627776,
        "used_bytes": 549755813888,
        "used_percent": 50.0
      }
    ],
    "network": [
      {
        "interface": "Ethernet0",
        "bytes_sent": 1234567890,
        "bytes_recv": 9876543210
      }
    ]
  },
  "services": [
    { "name": "Spooler", "display_name": "Print Spooler", "status": "running" },
    { "name": "MSSQLSERVER", "display_name": "SQL Server", "status": "running" },
    { "name": "wuauserv", "display_name": "Windows Update", "status": "stopped" }
  ]
}
```

### 8.2 Status Agent (derived server-side)

| Status | Kondisi |
|--------|---------|
| `online` | `last_seen_at` < 2 × `interval_seconds` |
| `offline` | `last_seen_at` > 2 × `interval_seconds` |
| `revoked` | `revoked_at` tidak null |

Scheduler `EvaluateAgentStaleJob` berjalan setiap menit dan membuka alert `agent_offline` jika status offline.

---

## 9. Enrollment Flow

```
Admin                          InfraControl UI              Agent (Windows)
  │                                  │                           │
  │  1. Settings → Agents            │                           │
  │     → Generate Token               │                           │
  │     (pilih site, optional asset)   │                           │
  │◄─────────────────────────────────  │                           │
  │  enroll_abc123 (24 jam)            │                           │
  │                                  │                           │
  │  2. Install agent + token          │                           │
  │─────────────────────────────────────────────────────────────►│
  │                                  │                           │
  │                                  │  3. POST /api/agents/enroll
  │                                  │◄──────────────────────────│
  │                                  │  enroll_token + hostname    │
  │                                  │                           │
  │                                  │  4. Return agent_token    │
  │                                  │──────────────────────────►│
  │                                  │                           │
  │                                  │  5. Simpan token lokal     │
  │                                  │     Hapus enroll_token     │
  │                                  │                           │
  │                                  │  6. POST heartbeat (loop)  │
  │                                  │◄──────────────────────────│
  │  7. Agent muncul online          │                           │
  │     di Settings → Agents         │                           │
  │◄─────────────────────────────────│                           │
```

**Aturan enrollment token:**

- One-time use — invalid setelah enroll sukses
- Expire 24 jam (configurable)
- Hanya admin/super-admin yang bisa generate
- Bisa pre-link `inventory_asset_id` agar agent langsung terhubung ke asset yang benar

---

## 10. Keamanan

### 10.1 Prinsip

| Prinsip | Implementasi |
|---------|----------------|
| Outbound-only | Agent tidak listen port apapun |
| HTTPS wajib | TLS ke InfraControl; reject self-signed kecuali dev |
| Token hashing | Enrollment & agent token disimpan sebagai hash di DB |
| Revoke instant | Revoke token → agent dapat 401 → stop & log |
| No secrets in agent | Agent tidak simpan credential Vault; hanya agent_token sendiri |
| Least privilege | Agent token hanya bisa POST heartbeat, tidak akses UI/API lain |

### 10.2 Agent Token

- Format: `agent_` + 32 byte random base64url
- Disimpan di server sebagai `hash('sha256', $token)`
- Agent simpan token di config file dengan permission ketat:
  - Windows: ACL hanya SYSTEM + Administrators
  - Linux: `chmod 600`, owner root

### 10.3 Rate Limiting

| Endpoint | Limit |
|----------|-------|
| `/api/agents/enroll` | 5 req/min per IP |
| `/api/agents/heartbeat` | 120 req/min per agent_token |

### 10.4 mTLS (Opsional — Hardened Production)

Untuk fase lanjutan: agent present client certificate saat TLS handshake. CA internal di-sign oleh InfraControl. Lebih aman dari bearer token saja, tapi kompleksitas deploy lebih tinggi.

---

## 11. Distribusi & Instalasi

### 11.1 Fase 1 — Manual Binary + PowerShell (Pilot)

**Windows:**

1. Download `infracontrol-agent-windows-amd64.exe` dari halaman Settings → Agents
2. Generate enrollment token di UI
3. Jalankan installer:

```powershell
# install.ps1
param(
    [Parameter(Mandatory=$true)]
    [string]$EnrollToken,

    [Parameter(Mandatory=$true)]
    [string]$ServerUrl = "https://infracontrol.example.com"
)

$InstallDir = "C:\Program Files\InfraControl"
$DataDir    = "C:\ProgramData\InfraControl"
$ServiceName = "InfraControlAgent"

New-Item -ItemType Directory -Force -Path $InstallDir, $DataDir | Out-Null

@{
    server_url   = $ServerUrl
    enroll_token = $EnrollToken
    interval_seconds = 30
    log_level    = "info"
} | ConvertTo-Yaml | Set-Content "$DataDir\config.yaml"

Copy-Item "infracontrol-agent.exe" "$InstallDir\infracontrol-agent.exe"

# Windows Service
sc.exe create $ServiceName `
    binPath= "`"$InstallDir\infracontrol-agent.exe`" --config `"$DataDir\config.yaml`"" `
    start= auto `
    DisplayName= "InfraControl Agent"

sc.exe description $ServiceName "InfraControl host monitoring agent"
sc.exe start $ServiceName

Write-Host "Agent installed. Check status: sc.exe query $ServiceName"
```

Contoh silent install via GPO startup script:

```powershell
\\fileserver\deploy\infracontrol-agent-setup.exe /S /TOKEN=enroll_abc123 /SERVER=https://infracontrol.example.com
```

**Linux:**

```bash
# install.sh
sudo mkdir -p /etc/infracontrol /opt/infracontrol
sudo cp infracontrol-agent /opt/infracontrol/
sudo cp infracontrol-agent.service /etc/systemd/system/

cat <<EOF | sudo tee /etc/infracontrol/agent.yaml
server_url: https://infracontrol.example.com
enroll_token: enroll_abc123
interval_seconds: 30
EOF

sudo systemctl daemon-reload
sudo systemctl enable --now infracontrol-agent
sudo systemctl status infracontrol-agent
```

### 11.2 Fase 2 — Installer Package

| Platform | Format | Tool |
|----------|--------|------|
| Windows | `.msi` | WiX Toolset v4 |
| Windows | `.exe` (silent) | Inno Setup |
| Linux Debian/Ubuntu | `.deb` | `nfpm` |
| Linux RHEL/Rocky | `.rpm` | `nfpm` |

MSI/Inno Setup bisa embed enrollment token via command line untuk deploy massal di Active Directory.

### 11.3 Fase 3 — Auto-Update

Agent cek versi setiap 24 jam:

```
GET /api/agents/version
```

Jika versi lokal < `latest`:

1. Download binary baru ke temp path
2. Verify checksum (SHA256 dari response header atau manifest)
3. Replace binary
4. Restart Windows Service / systemd unit

Rollback: simpan binary lama sebagai `.bak` sebelum replace.

### 11.4 Build & Release Pipeline

```yaml
# .github/workflows/release.yml (contoh)
on:
  push:
    tags: ['v*']

jobs:
  build:
    strategy:
      matrix:
        goos: [linux, windows]
        goarch: [amd64, arm64]
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-go@v5
      - run: GOOS=${{ matrix.goos }} GOARCH=${{ matrix.goarch }} go build -o dist/ ./cmd/agent
      - uses: softprops/action-gh-release@v2
        with:
          files: dist/*
```

Artifact naming:

```
infracontrol-agent-{version}-windows-amd64.exe
infracontrol-agent-{version}-linux-amd64
infracontrol-agent-{version}-linux-arm64
```

---

## 12. Integrasi dengan Modul InfraControl

### 12.1 Inventory

- Saat generate enrollment token, admin bisa pilih `inventory_asset` (Server/Hypervisor)
- Setelah enroll, `agents.inventory_asset_id` ter-set
- Asset detail page menampilkan badge "Agent connected" + last seen

### 12.2 Asset Monitoring

Saat ini Asset Monitoring ping `primary_ip`. Dengan agent:

- Status online/offline dari agent heartbeat (lebih akurat dari ICMP jika firewall block ping)
- Kolom tambahan: CPU%, RAM%, disk usage terburuk
- Filter: "Has agent" / "Agent offline"

### 12.3 Alerting

Default alert rules baru (selaras dengan `docs/Alerting_V1_Plan.md`):

| Rule Key | Severity | Kondisi |
|----------|----------|---------|
| `agent_offline` | critical | `last_seen_at` > 2 × interval |
| `host_cpu_usage_percent` | warning ≥ 80, critical ≥ 90 | dari heartbeat metrics |
| `host_memory_usage_percent` | warning ≥ 80, critical ≥ 90 | idem |
| `host_disk_usage_percent` | warning ≥ 80, critical ≥ 90 | per mount |
| `host_service_stopped` | warning | service penting status = stopped |

Service penting dikonfigurasi per agent via UI (whitelist service names).

### 12.4 Topology (Opsional)

Agent-linked server bisa muncul sebagai node di topology view dengan metrics overlay — fase lanjutan, bukan MVP.

### 12.5 Perbandingan dengan Custom API

| Aspek | Custom API (existing) | InfraControl Agent |
|-------|---------------------|-------------------|
| Arah koneksi | Pull (server → host) | Push (host → server) |
| Firewall | Perlu buka port inbound | Tidak perlu |
| Metrics OS | Hanya yang di-expose manual | CPU/RAM/disk/services built-in |
| Setup | HTTP endpoint custom | Install binary + token |
| Windows | Perlu buat sendiri | Native Windows Service |

---

## 13. Roadmap Implementasi

| Fase | Scope | Estimasi | Deliverable |
|------|-------|----------|-------------|
| **0** | Custom API health endpoint di Windows (tanpa agent) | 1 hari | Pilot visibility via integrasi existing |
| **1** | Agent Go: enroll + heartbeat + CPU/RAM/disk | 1–2 minggu | Binary + Laravel API endpoints |
| **2** | Settings UI: token management, agent list, revoke | 1 minggu | Admin bisa deploy agent dari UI |
| **3** | Windows Service installer (PS1 + MSI) | 3–5 hari | Deploy ke 1 server Windows pilot |
| **4** | Service monitoring + alert rules | 1 minggu | Alert disk full, service down |
| **5** | Linux systemd + .deb package | 3–5 hari | Support Linux servers |
| **6** | Auto-update + command channel | 2 minggu | Remote restart service (opsional) |

### Urutan Build Backend (Laravel)

1. Migration: `agent_enrollment_tokens`, `agents`
2. Model + policy (admin-only)
3. `AgentEnrollmentController` — generate token, list agents, revoke
4. `AgentApiController` — enroll, heartbeat (API routes, rate limit)
5. `ProcessAgentHeartbeatJob` — persist metrics async via queue
6. `EvaluateAgentMetricsJob` — feed alert evaluator
7. Settings UI (Vue/Inertia)
8. Asset Monitoring: tampilkan agent metrics

### Urutan Build Agent (Go)

1. Config loader + HTTP client
2. Enroll flow
3. Heartbeat loop + gopsutil collector
4. Windows Service wrapper
5. Linux systemd unit
6. Install scripts
7. CI release pipeline

---

## 14. Out of Scope (MVP)

- Remote command execution (restart service, run script)
- Log shipping / file tail
- Container metrics (Docker inside host)
- SNMP polling
- Active Directory integration
- Browser / desktop agent
- mTLS (fase hardened, bukan MVP)
- Agent-to-agent mesh / relay

---

## 15. Alternatif Tanpa Agent

Sebelum invest ke agent, pertimbangkan opsi yang sudah ada:

### Opsi A — Asset Monitoring (ICMP ping)

Sudah tersedia. Cukup untuk deteksi host up/down. Tidak ada metrics OS.

### Opsi B — Custom API Integration

Buat HTTP endpoint sederhana di Windows (PowerShell + Pode, atau small .NET app) yang expose `/health`:

```json
{ "status": "ok", "cpu": 42, "memory": 68 }
```

Tambahkan sebagai integrasi **Custom API** di Settings → Integrations. InfraControl poll dari server setiap 30 detik.

**Keterbatasan:** butuh buka port inbound; server InfraControl harus bisa reach host.

### Opsi C — Prometheus Node Exporter + Prometheus Integration (Future)

Jika nanti InfraControl punya integrasi Prometheus, node_exporter di Linux/Windows bisa jadi sumber metrics tanpa agent custom. Agent InfraControl tetap relevan untuk push model dan enrollment terpusat.

---

## Referensi Internal

- [Alerting V1 Plan](./Alerting_V1_Plan.md) — pipeline metrics → alert rules
- [Coolify Deployment](./coolify-deployment.md) — deploy InfraControl server
- [Internal Vault MVP](./Internal_Vault_MVP.md) — credential management (agent tidak simpan secret Vault)
- [InfraControl PRD v1](../InfraControl_PRD_v1.md) — scope produk keseluruhan

## Referensi Eksternal

- [gopsutil](https://github.com/shirou/gopsutil) — cross-platform metrics library for Go
- [kardianos/service](https://github.com/kardianos/service) — Windows Service / systemd wrapper for Go
- [Prometheus node_exporter](https://github.com/prometheus/node_exporter) — referensi arsitektur agent metrics
