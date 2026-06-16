# InfraControl Project Requirement
## Multi-site MVP Extension

Reference utama dokumen ini adalah [InfraControl_PRD_v1.md](/Users/mbx/Projects/inControlRoom/InfraControl_PRD_v1.md). Dokumen ini menurunkan PRD tersebut ke konteks codebase saat ini dan menambahkan kebutuhan `sites` karena sistem dipakai untuk mengelola beberapa tempat usaha/lokasi operasional.

## 1. Baseline Project Saat Ini

Fitur yang sudah ada di codebase:

- Autentikasi login berbasis session.
- Dashboard ringkas untuk total integration, open alerts, dan recent activity.
- Integration Hub dasar: create, edit, delete, test connection.
- Tipe integrasi aktif saat ini: Proxmox VE.
- Audit log list dengan filter dasar.
- Penyimpanan credential terenkripsi di database.

Gap utama terhadap PRD:

- Belum ada konsep `sites`.
- Belum ada ownership lokasi pada integrations, alerts, metrics, dan aktivitas.
- Belum ada dashboard/filter per lokasi usaha.
- Belum ada flow operasional multi-site untuk admin yang mengelola lebih dari satu tempat.

## 2. Problem yang Ingin Diselesaikan

Saat admin mengelola beberapa lokasi usaha, semua integration, alert, dan aktivitas akan tercampur dalam satu namespace. Ini menimbulkan beberapa masalah:

- Sulit membedakan issue antar lokasi.
- Dashboard tidak bisa menjawab "site mana yang bermasalah sekarang".
- Integrasi seperti Proxmox, PBS, dan monitoring tidak punya konteks lokasi.
- Audit trail kurang berguna untuk investigasi karena tidak ada penanda site.

## 3. Goal Rilis Berikutnya

Rilis berikutnya berfokus pada membangun pondasi multi-site tanpa mengubah arah utama PRD.

Target rilis:

- Menambahkan master data `sites`.
- Menjadikan integration bersifat site-aware.
- Menampilkan dashboard yang bisa di-filter per site atau dilihat secara agregat.
- Menjadikan alert dan audit log dapat ditelusuri berdasarkan site.
- Menjaga agar integrasi global tetap dimungkinkan untuk kebutuhan lintas lokasi.

## 4. Konsep Domain Baru

### 4.1 Site

`Site` merepresentasikan satu lokasi usaha/operasional yang dikelola dalam InfraControl.

Contoh:

- Klinik A
- Kantor Pusat
- Warehouse 01
- Cabang Makassar

### 4.2 Scope Resource

Setiap integration minimal harus memiliki salah satu scope berikut:

- `site`: integration milik satu site tertentu.
- `global`: integration berlaku lintas site atau level pusat.

Implementasi awal yang direkomendasikan:

- Gunakan `site_id` nullable pada `integrations`.
- `site_id = null` berarti integration bersifat global.

Pendekatan ini sederhana, cocok untuk MVP, dan tidak memaksa pivot many-to-many lebih awal.

## 5. Functional Requirements

### FR-01 Master Data Sites

Sistem harus menyediakan CRUD untuk `sites` dengan field minimal:

- `name`
- `code`
- `business_type`
- `address` (optional)
- `timezone` (default mengikuti app)
- `is_active`
- `notes` (optional)

Aturan:

- `code` harus unik.
- Site tidak boleh dihapus jika masih dipakai integration aktif, kecuali ada flow validasi yang jelas.
- Site nonaktif tetap bisa muncul di histori audit dan alert lama.

### FR-02 Site-aware Integrations

Saat membuat atau mengubah integration, user harus bisa memilih:

- integration ini milik site tertentu, atau
- integration ini global.

Aturan:

- Semua integration baru wajib memiliki scope yang jelas.
- Listing integrations harus menampilkan badge site/global.
- Filter integration berdasarkan site harus tersedia.

### FR-03 Dashboard Filter by Site

Dashboard harus mendukung:

- tampilan semua site secara agregat,
- filter satu site,
- ringkasan jumlah integration aktif per site,
- ringkasan alert open per site.

Widget minimum untuk fase ini:

- Active Integrations
- Open Alerts
- Critical Alerts
- Warning Alerts
- Top/Latest Problem Sites

### FR-04 Alert Context by Site

Alert/event harus bisa dilihat bersama konteks:

- site name,
- integration name,
- severity,
- waktu kejadian.

Aturan:

- Jika event berasal dari integration site-specific, event harus tampil dengan label site.
- Jika event berasal dari integration global, tampilkan label `Global`.

### FR-05 Audit Log Context by Site

Audit log harus mendukung pelacakan aktivitas berdasarkan site.

Minimal behavior:

- Aksi pada integration site-specific harus bisa ditelusuri ke site terkait.
- Filter audit log berdasarkan site harus tersedia.
- Entry audit log lama tanpa site tetap valid.

### FR-06 Site Overview Page

Sistem sebaiknya menyediakan halaman daftar site yang menampilkan:

- nama site,
- kode,
- jenis usaha,
- status aktif/nonaktif,
- jumlah integration,
- jumlah alert open.

Halaman detail site untuk fase awal bersifat optional, tetapi struktur backend harus siap untuk ditambah.

## 6. Non-functional Requirements

- Perubahan harus backward-compatible terhadap data integration yang sudah ada.
- Existing integrations tanpa `site_id` harus otomatis dianggap `global`.
- UI tetap sederhana dan cepat dipakai admin solo.
- Query dashboard dan audit log harus tetap efisien untuk skala 10-50 integration per site.
- Audit trail tidak boleh kehilangan data historis saat site dinonaktifkan.

## 7. Data Model Direction

Struktur minimum yang direkomendasikan:

### sites

- `id`
- `name`
- `code`
- `business_type`
- `address`
- `timezone`
- `notes`
- `is_active`
- `created_at`
- `updated_at`

### integrations

Tambahan:

- `site_id` nullable

Makna:

- `site_id != null` -> integration milik satu site
- `site_id == null` -> integration global

### audit_logs

Direkomendasikan salah satu dari dua pendekatan:

- MVP cepat: filter site melalui relasi target integration
- MVP yang lebih siap scale: tambahkan `site_id` nullable ke audit_logs

Rekomendasi untuk project ini:

- Tambahkan `site_id` nullable di `audit_logs` agar filtering lebih langsung dan lebih siap untuk aksi non-integration di masa depan.

### events

Direkomendasikan:

- Tambahkan `site_id` nullable untuk mempermudah alert feed dan dashboard per site.

## 8. UX Scope

Halaman baru:

- `Sites/Index`
- `Sites/Create`
- `Sites/Edit`

Halaman yang diubah:

- Dashboard
- Settings/Integrations/Index
- Settings/Integrations/Create
- Settings/Integrations/Edit
- AuditLog/Index

Perubahan UX minimum:

- Filter site pada dashboard, integrations, dan audit log.
- Badge `Global` atau nama site di kartu/list integration.
- Site selector yang jelas saat create/edit integration.

## 9. Release Scope

### P0

- Sites migration + model + CRUD
- Integration association ke site
- Site filter pada dashboard
- Site filter pada integrations
- Site filter pada audit log
- Badge context `Global` / `Site`

### P1

- Dashboard widget ringkasan per site
- Halaman site overview dengan counters
- Event site context yang lebih kaya

### P2

- Site detail page
- Site-specific notification routing
- RBAC per site

### Phase 2 Focus - Internal Secrets per Site

Setelah pondasi `sites` selesai, prioritas vertical feature berikutnya adalah membangun vault internal yang tetap site-aware sejak awal.

Target fase ini:

- Secret entry dapat dimiliki oleh site tertentu atau global.
- Audit trail dan akses operasional dapat difilter per site.
- Struktur backend siap untuk encryption, rotation metadata, dan alert dasar tanpa bergantung ke produk eksternal.

## 10. Acceptance Criteria

Rilis dianggap selesai jika:

- User dapat membuat, mengubah, dan menonaktifkan site.
- User dapat membuat integration dan menetapkannya ke site atau global.
- Dashboard dapat difilter berdasarkan site.
- Audit log dapat difilter berdasarkan site.
- Integration listing menampilkan context site/global dengan jelas.
- Data lama tetap terbaca tanpa migrasi manual di luar aplikasi.

## 11. Out of Scope Untuk Iterasi Ini

- Multi-tenant database terpisah per site.
- Many-to-many integration ke banyak site.
- Permission matrix per site.
- SLA/reporting per site yang kompleks.
- Auto-discovery topology antar site.

## 12. Rekomendasi Implementasi

Urutan implementasi yang paling aman untuk codebase saat ini:

1. Tambahkan tabel `sites`.
2. Tambahkan `site_id` pada `integrations`.
3. Tambahkan `site_id` pada `audit_logs` dan `events`.
4. Update controller, validation, dan query list/filter.
5. Update UI create/edit/list dashboard.
6. Tambahkan test coverage untuk alur multi-site dasar.
