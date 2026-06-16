# InfraControl Task Breakdown
## Multi-site Foundation Backlog

Dokumen ini memecah requirement pada [InfraControl_PR_MultiSite.md](/Users/mbx/Projects/inControlRoom/docs/InfraControl_PR_MultiSite.md) menjadi task implementasi yang relevan dengan codebase saat ini.

## Milestone 1 - Data Foundation

### TASK-001 Create sites table and model

Priority: P0

Scope:

- Buat migration `sites`.
- Buat model `Site`.
- Tambahkan constraint unique untuk `code`.
- Tambahkan status aktif/nonaktif.

Acceptance criteria:

- Tabel `sites` tersedia.
- Model dapat membuat dan mengambil data site.
- `code` site unik.

### TASK-002 Link integrations to sites

Priority: P0

Scope:

- Tambahkan `site_id` nullable ke tabel `integrations`.
- Tambahkan relasi `Integration belongsTo Site`.
- Pastikan data lama tetap valid sebagai global integration.

Acceptance criteria:

- Integration bisa disimpan dengan `site_id` atau `null`.
- Existing data tidak rusak setelah migration.

### TASK-003 Make audit logs site-aware

Priority: P0

Scope:

- Tambahkan `site_id` nullable ke `audit_logs`.
- Tambahkan relasi `AuditLog belongsTo Site`.
- Update helper `AuditLog::record()` agar bisa menerima `siteId`.

Acceptance criteria:

- Audit log baru bisa menyimpan konteks site.
- Audit log lama tetap terbaca.

### TASK-004 Make events site-aware

Priority: P1

Scope:

- Tambahkan `site_id` nullable ke `events`.
- Isi `site_id` dari integration jika event berasal dari integration site-specific.

Acceptance criteria:

- Event baru punya context site bila relevan.
- Alert global tetap didukung.

## Milestone 2 - Backend CRUD and Query Layer

### TASK-005 Build site CRUD routes and controller

Priority: P0

Scope:

- Tambahkan route index/create/store/edit/update untuk sites.
- Buat controller untuk validasi dan persistence.
- Terapkan guard agar site dengan integration aktif tidak bisa dihapus sembarang.

Acceptance criteria:

- User login bisa create dan edit site.
- Validasi nama/kode berjalan.

### TASK-006 Add site filters to dashboard queries

Priority: P0

Scope:

- Tambahkan query param `site`.
- Filter stats, integrations, alerts, dan recent activity berdasarkan site.
- Sediakan opsi `All Sites`.

Acceptance criteria:

- Dashboard berubah sesuai site terpilih.
- Nilai agregat semua site tetap tersedia.

### TASK-007 Add site filters to integration listing

Priority: P0

Scope:

- Tambahkan filter site/global pada `IntegrationController@index`.
- Sertakan data site pada payload Inertia.

Acceptance criteria:

- User bisa melihat integration per site.
- Integration global bisa difilter terpisah.

### TASK-008 Add site filters to audit log listing

Priority: P0

Scope:

- Tambahkan filter `site_id` pada `AuditLogController@index`.
- Jika belum ada `site_id` di log lama, tampilkan sebagai `Global/Unknown` sesuai konteks.

Acceptance criteria:

- Audit log bisa dicari per site.
- Pagination dan filter tetap bekerja.

## Milestone 3 - Frontend Pages

### TASK-009 Build site management pages

Priority: P0

Scope:

- Tambahkan `Sites/Index.vue`, `Sites/Create.vue`, `Sites/Edit.vue`.
- Tampilkan list site dengan status dan metadata utama.

Acceptance criteria:

- User bisa mengelola site dari UI.
- Form error dan flash message tampil konsisten.

### TASK-010 Add site selector to integration forms

Priority: P0

Scope:

- Update form create/edit integration.
- Tambahkan pilihan `Global` atau pilih salah satu site.
- Tampilkan penjelasan singkat tentang scope integration.

Acceptance criteria:

- Integration baru wajib punya scope yang jelas.
- Edit form menampilkan nilai site/global saat ini.

### TASK-011 Show site badges in integration cards

Priority: P0

Scope:

- Tambahkan badge `Global` atau nama site pada listing integration.
- Tambahkan filter UI untuk semua site/site tertentu/global only.

Acceptance criteria:

- User langsung bisa memahami lokasi tiap integration.

### TASK-012 Add site filter UI to dashboard

Priority: P0

Scope:

- Tambahkan selector site di dashboard header.
- Pertahankan state filter saat navigasi yang relevan bila memungkinkan.

Acceptance criteria:

- Site filter mudah diakses dari dashboard.
- Data widget dan list ikut berubah tanpa membingungkan user.

### TASK-013 Add site filter UI to audit logs

Priority: P0

Scope:

- Tambahkan dropdown site pada filter audit log.
- Tampilkan site column atau context text pada tiap row.

Acceptance criteria:

- User dapat melacak aktivitas berdasarkan lokasi.

## Milestone 4 - Cross-cutting Behavior

### TASK-014 Record site context in integration actions

Priority: P0

Scope:

- Saat create/update/delete/test integration, pastikan audit log menyimpan `site_id`.
- Pastikan perubahan site pada integration juga tercatat.

Acceptance criteria:

- Audit log integration actions konsisten membawa site context.

### TASK-015 Update dashboard summary for multi-site ops

Priority: P1

Scope:

- Tambahkan ringkasan problem sites atau site dengan alert terbanyak.
- Tambahkan counter integration per site bila data tersedia.

Acceptance criteria:

- Dashboard lebih berguna untuk admin yang memonitor beberapa lokasi.

### TASK-016 Define empty states and copy for global vs site data

Priority: P1

Scope:

- Rapikan label dan empty state di dashboard, integrations, dan audit log.
- Gunakan istilah yang konsisten: `Global`, `All Sites`, `No Site`.

Acceptance criteria:

- UI tidak ambigu saat data lintas site muncul.

## Milestone 5 - Testing and Hardening

### TASK-017 Add feature tests for site CRUD

Priority: P0

Scope:

- Test create/update validation.
- Test unique code.
- Test auth protection.

Acceptance criteria:

- Feature test untuk alur site dasar lulus.

### TASK-018 Add feature tests for site-scoped integrations

Priority: P0

Scope:

- Test create integration global.
- Test create integration with site.
- Test filtering integration by site.

Acceptance criteria:

- Alur utama multi-site integration ter-cover.

### TASK-019 Add feature tests for dashboard and audit log filters

Priority: P1

Scope:

- Test dashboard stats per site.
- Test audit log filtering per site.

Acceptance criteria:

- Query lintas site tidak bocor ke filter site lain.

### TASK-020 Seed sample multi-site data

Priority: P1

Scope:

- Tambahkan seed/dev fixtures untuk minimal 2-3 site.
- Sediakan integration global dan integration per site.

Acceptance criteria:

- Local development lebih cepat untuk verifikasi UI multi-site.

## Suggested Execution Order

1. TASK-001 sampai TASK-004
2. TASK-005 sampai TASK-008
3. TASK-009 sampai TASK-013
4. TASK-014 sampai TASK-016
5. TASK-017 sampai TASK-020

## Definition of Done

Satu task dianggap selesai jika:

- Perubahan code sudah masuk.
- Validation dan edge case utama sudah ditangani.
- UI state dasar sudah rapi.
- Test yang relevan sudah ditambahkan atau diupdate.
- Tidak merusak flow existing untuk integration global.
