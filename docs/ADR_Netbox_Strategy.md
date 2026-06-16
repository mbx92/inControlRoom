# ADR: NetBox Strategy
## Integrate, Don't Rebuild

## Status

Proposed

## Context

InfraControl adalah control plane operasional yang menyatukan observability, alerting, dan quick actions. Di PRD awal, NetBox direncanakan sebagai integrasi untuk network inventory dan CMDB.

Pertanyaan produk yang muncul:

- Apakah integrasi ke NetBox terlalu overkill?
- Apakah lebih baik membuat ulang fitur ala NetBox langsung di InfraControl?

## Decision

Untuk project ini, arah yang direkomendasikan adalah:

- Jangan membangun ulang NetBox di dalam InfraControl.
- Gunakan integrasi NetBox jika memang sudah ada atau saat kebutuhan inventaris mulai nyata.
- Jika kebutuhan saat ini masih kecil, mulai dari master data `sites` dan mungkin `assets` minimum internal, bukan clone NetBox.

Singkatnya:

- `NetBox integration`: ya, bila butuh source of truth jaringan/aset.
- `NetBox replacement`: tidak direkomendasikan.

## Reasoning

### Kenapa integrasi NetBox bukan overkill

Integrasi NetBox tidak overkill jika kamu memang:

- mengelola banyak site,
- punya perangkat jaringan, rack, IP, VLAN, atau inventaris yang cepat berubah,
- butuh satu source of truth yang bisa dipakai lintas tool.

Dalam skenario itu, InfraControl sebaiknya menarik data dari NetBox, bukan menduplikasi model domainnya.

### Kenapa clone NetBox akan mahal

Membuat ulang NetBox terlihat sederhana di awal, tapi domain-nya cepat melebar:

- site, region, tenant
- device type, role, vendor
- rack, power, interface
- IPAM, prefix, VLAN, VRF
- cable/link topology
- lifecycle inventaris dan audit perubahan

Begitu masuk ke area itu, project akan terseret menjadi CMDB/IPAM penuh, padahal value utama InfraControl ada di control plane dan operational visibility.

### Tradeoff paling sehat

Pisahkan peran produk:

- `InfraControl` = control plane, alert center, action runner, site operations.
- `NetBox` = source of truth untuk inventaris jaringan dan aset.

Dengan pemisahan ini:

- model InfraControl tetap ramping,
- effort maintenance lebih kecil,
- upgrade domain inventaris bisa mengikuti best practice NetBox,
- integrasi lain seperti Proxmox dan monitoring tetap fokus ke operasi.

## Recommended Product Path

### Opsi A - Belum pakai NetBox sekarang

Pilih ini jika kebutuhanmu saat ini lebih dominan:

- monitoring status per site,
- Proxmox/backup/secrets visibility,
- daftar lokasi usaha,
- inventaris masih sederhana.

Implementasi internal yang cukup:

- `sites`
- mungkin `assets` ringan untuk catatan server/perangkat utama
- relasi integration ke site

Jangan langsung membangun:

- IPAM
- rack planning
- interface topology
- cable tracing

### Opsi B - Pakai NetBox sebagai integrasi nanti

Pilih ini jika nanti kamu mulai butuh:

- inventaris perangkat yang disiplin,
- IP/VLAN tracking,
- audit struktur jaringan,
- dependensi antar perangkat dan lokasi.

Pendekatan implementasi:

- simpan satu atau lebih integration NetBox,
- sinkronkan read-only data terpilih,
- tampilkan hanya view yang relevan untuk operasi harian,
- jangan menjadikan InfraControl sebagai tempat edit penuh untuk semua objek NetBox pada fase awal.

## Implementation Guidance

Untuk codebase ini, urutan yang saya sarankan:

1. Selesaikan multi-site foundation.
2. Deliver vertical value dengan integrations per site yang paling operasional.
3. Jika masih perlu inventaris internal, buat modul `assets` ringan dulu.
4. Tambahkan integrasi NetBox read-only saat kebutuhan inventaris mulai terasa sempit.

## Decision Summary

Rekomendasi saya:

- Tidak, integrasi NetBox bukan otomatis overkill.
- Ya, membangun ulang seperti NetBox cenderung overkill untuk project ini.
- Langkah paling enak adalah fokus ke `sites` + vertical integrations dulu, lalu integrasi NetBox secara read-only ketika domain inventaris benar-benar dibutuhkan.
