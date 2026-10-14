# Raptika JSON API Documentation

Dokumentasi ini menjelaskan seluruh endpoint yang tersedia pada aplikasi, cara autentikasi, dan struktur payload yang dibutuhkan.

---

## 1. Authentication (Sanctum)

Semua endpoint yang dilindungi (selain login dan register) membutuhkan Header HTTP berikut:
`Authorization: Bearer <access_token>`

### Register
Mendaftarkan pengguna baru dan menghasilkan access token.
- **Endpoint**: `POST /api/register`
- **Body (JSON)**:
  ```json
  {
      "name": "John Doe",
      "email": "john@example.com",
      "password": "password123",
      "password_confirmation": "password123"
  }
  ```
- **Response (201 Created)**: Mengembalikan object user dan `access_token`.

### Login
Autentikasi pengguna dan mendapatkan access token.
- **Endpoint**: `POST /api/login`
- **Body (JSON)**:
  ```json
  {
      "email": "john@example.com",
      "password": "password123"
  }
  ```
- **Response (200 OK)**: Mengembalikan object user dan `access_token`.

### Logout
Menghapus (revoke) token pengguna yang sedang aktif.
- **Endpoint**: `POST /api/logout`
- **Headers**: `Authorization: Bearer <token>`
- **Response (200 OK)**: Pesan berhasil logout.

---

## 2. User & Profile
**[Membutuhkan Bearer Token]**

### Get Current User
- **Endpoint**: `GET /api/user`
- **Response**: Menampilkan data user yang sedang login.

### Update Profile
- **Endpoint**: `PATCH /api/profile`
- **Body**: `name`, `email`

### Delete Profile
- **Endpoint**: `DELETE /api/profile`
- **Body**: 
  ```json
  { "password": "current_password" }
  ```

---

## 3. Smart Jabar
**[Membutuhkan Bearer Token]**

Grup endpoint untuk mengelola aplikasi Smart Jabar dan statistiknya. Prefix: `/api/smartjabar`

### Joined Apps (Aplikasi Tergabung)
Mengelola data aplikasi yang tergabung.
- `GET /api/smartjabar/joined-apps` : Mendapatkan list data.
- `POST /api/smartjabar/joined-apps` : Menambahkan data baru.
  - **Body**:
    ```json
    {
      "year": 2024,
      "month": 5,
      "total_apps": 10
    }
    ```
- `GET /api/smartjabar/joined-apps/{id}/edit` : Mengambil data tunggal untuk diedit.
- `PUT /api/smartjabar/joined-apps/{id}` : Memperbarui data. (Body sama dengan POST).
- `DELETE /api/smartjabar/joined-apps/{id}` : Menghapus data.

### Usage Stats (Statistik Penggunaan)
Mengelola statistik penggunaan OPD di Smart Jabar.
- `POST /api/smartjabar/stats` : Menambahkan statistik (Bulk input array OPD).
  - **Body**: Membutuhkan `month`, `year`, dan array `stats` yang berisi map OPD -> `total_asn`, `active_users`.
- `GET /api/smartjabar/stats/{id}/edit` : Data spesifik.
- `PUT /api/smartjabar/stats/{id}` : Memperbarui data spesifik.
  - **Body**: `opd_id`, `month`, `year`, `total_asn`, `active_users`.
- `DELETE /api/smartjabar/stats/{id}`

---

## 4. Sada Jabar
**[Membutuhkan Bearer Token]**

Prefix: `/api/sadajabar`

- `GET /api/sadajabar/` : Index utama Sada Jabar.

### App Integration (Integrasi Aplikasi)
- `POST /api/sadajabar/integrasi` : Membuat data integrasi.
- `PUT /api/sadajabar/integrasi/{id}` : Update data.
- `DELETE /api/sadajabar/integrasi/{id}` : Hapus data.

### Encryption Stats (Statistik Enkripsi)
- `POST /api/sadajabar/enkripsi`
- `PUT /api/sadajabar/enkripsi/{id}`
- `DELETE /api/sadajabar/enkripsi/{id}`

---

## 5. Rekayasa (Engineering)
**[Membutuhkan Bearer Token]**

Prefix: `/api/rekayasa`

### Application Replications (Replikasi Aplikasi)
- `GET /api/rekayasa/application-replications`
- `POST /api/rekayasa/application-replications`
- `PUT /api/rekayasa/application-replications/{id}`
- `DELETE /api/rekayasa/application-replications/{id}`

### Mentoring Performances (Performa Mentoring)
- `GET /api/rekayasa/mentoring-performances`
- `POST /api/rekayasa/mentoring-performances`
- `PUT /api/rekayasa/mentoring-performances/{id}`
- `DELETE /api/rekayasa/mentoring-performances/{id}`

---

## 6. Intop (Interoperability)
**[Membutuhkan Bearer Token]**

Prefix: `/api/intop`

### Integration Summaries
- `GET /api/intop/integration-summaries`
- `POST /api/intop/integration-summaries`
- `PUT /api/intop/integration-summaries/{id}`
- `DELETE /api/intop/integration-summaries/{id}`

### Service Catalogs
- `GET /api/intop/service-catalogs`
- `POST /api/intop/service-catalogs`
- `PUT /api/intop/service-catalogs/{id}`
- `DELETE /api/intop/service-catalogs/{id}`

---

## 7. Sidebar
**[Membutuhkan Bearer Token]**

Prefix: `/api/sidebar`

### Document Stats
- `GET /api/sidebar/document-stats`
- `POST /api/sidebar/document-stats`
- `PUT /api/sidebar/document-stats/{id}`
- `DELETE /api/sidebar/document-stats/{id}`

### Metrics
- `GET /api/sidebar/metrics`
- `POST /api/sidebar/metrics`
- `PUT /api/sidebar/metrics/{id}`
- `DELETE /api/sidebar/metrics/{id}`

### OPD Usages
- `GET /api/sidebar/opd-usages`
- `POST /api/sidebar/opd-usages`
- `PUT /api/sidebar/opd-usages/{id}`
- `DELETE /api/sidebar/opd-usages/{id}`

---

## 8. Task Management Notifications
**[Membutuhkan Bearer Token]**

Prefix: `/api/task-management/notifications`

- `GET /api/task-management/notifications` — ambil daftar notifikasi terbaru untuk user login.
- `GET /api/task-management/notifications/unread-count` — ambil jumlah notifikasi belum dibaca.
- `PATCH /api/task-management/notifications/{id}/read` — tandai satu notifikasi sebagai dibaca.
- `PATCH /api/task-management/notifications/read-all` — tandai semua notifikasi sebagai dibaca.
- `DELETE /api/task-management/notifications/{id}` — hapus notifikasi.

Contoh response:
```json
{
  "success": true,
  "data": [
    {
      "id": 10,
      "type": "TASK_ASSIGNED",
      "title": "Tugas baru ditugaskan",
      "message": "John menugaskan Anda untuk tugas API Integration.",
      "is_read": false,
      "created_at": "2026-07-14T00:00:00.000000Z",
      "board": { "id": 2, "name": "Board" },
      "task": { "id": 15, "title": "API Integration", "status": "todo" },
      "created_by": { "id": 4, "name": "John" }
    }
  ]
}
```

```

---

## 9. SMKI - Berita Acara Penghancuran Media (FR014-SMKI)
**[Membutuhkan Bearer Token]**

Prefix: `/api/smki/berita-acara`

Standar formulir FR014-SMKI untuk pencatatan resmi pemusnahan media penyimpanan, harddisk, flashdisk, server, dan aset perangkat keras lainnya.

### Endpoints
- `GET /api/smki/berita-acara` : Mendapatkan daftar berita acara dengan paginasi, live search, dan ringkasan KPI (Total Data, Data Hari Ini, Data Dihapus).
  - Query parameters:
    - `search`: string (cari nomor dokumen, pelaksana, pihak diketahui, alasan, atau perangkat media)
    - `date_from`: string (filter tanggal mulai `YYYY-MM-DD`)
    - `date_to`: string (filter tanggal selesai `YYYY-MM-DD`)
    - `page`: int (halaman saat ini)
    - `per_page`: int (jumlah per halaman, default: 10)
- `GET /api/smki/berita-acara/lookup` : Master data untuk dropdown pengguna/pegawai dan rekomendasi nomor dokumen otomatis (misal: `BA-001/SMKI/2026`).
- `POST /api/smki/berita-acara` : Menyimpan dokumen berita acara baru beserta array rincian media perangkat (`detail_media`).
  - **Body (JSON)**:
    ```json
    {
      "nomor_dokumen": "BA-001/SMKI/2025",
      "tanggal_pelaksanaan": "2025-09-12",
      "alasan_penghancuran": "Media/perangkat mengalami kerusakan dan data tidak diperlukan.",
      "id_pelaksana": 1,
      "id_diketahui": 2,
      "nama_pelaksana": "Ahmad Fauzi",
      "nama_diketahui": "Budi Santoso",
      "detail_media": [
        {
          "nama_perangkat": "Hard Disk Internal",
          "spesifikasi": "Seagate Barracuda 1 TB",
          "jenis_media": "Storage",
          "serial_number": "SG-2021-001",
          "jumlah": 2,
          "satuan": "Unit",
          "keterangan": "Rusak dan tidak dapat digunakan"
        }
      ]
    }
    ```
- `GET /api/smki/berita-acara/{id}` : Mengambil data rincian berita acara spesifik beserta list media.
- `PUT /api/smki/berita-acara/{id}` : Memperbarui data berita acara dan menyinkronkan array rincian media.
- `DELETE /api/smki/berita-acara/{id}` : Menghapus berita acara (soft-delete dengan cascading child media).
- `GET /api/smki/berita-acara/export-docx` atau `GET /api/smki/berita-acara/{id}/export-docx` : Menghasilkan file dokumen Word (.docx) resmi yang telah terformat sesuai template standar DISKOMINFO PROVINSI JAWA BARAT "FR014-SMKI".
  - Query parameters opsional:
    - `no_dokumen`: string
    - `no_revisi`: string (default: 1.0)
    - `tanggal_berlaku`: string

---

## 10. SMKI - Pelaporan Insiden Keamanan Informasi (FR-016)
**[Membutuhkan Bearer Token]**

Prefix: `/api/smki/pelaporan-insiden`

Hak akses: user biasa hanya melihat/mengubah **laporannya sendiri** (`user_id`); **hapus khusus admin** (403 untuk non-admin); `status_laporan` yang dikirim non-admin **diabaikan**. Buat (`POST`) dan ubah (`PUT` via `POST + _method=PUT` bila ada file) memakai `multipart/form-data` karena ada upload bukti (`bukti_pelapor`, `bukti_pemilik`: `png,jpg,jpeg,pdf,docx`, maks 5MB).

### Endpoints
- `GET /api/smki/pelaporan-insiden` : Daftar laporan (milik sendiri, kecuali admin) + paginasi + KPI.
  - Query parameters:
    - `search`: string (nama pelapor / sistem / kategori / instansi)
    - `kategori`: string (salah satu dari 9 kategori)
    - `status`: string (`Menunggu Pemilik Sistem`, `Dalam Penanganan`, `Selesai`)
    - `page`, `per_page` (`-1` = semua)
- `GET /api/smki/pelaporan-insiden/lookup` : Master statis dropdown (kategori, jenis sistem, jenis layanan, status) + default kop dokumen (`F16-SMKI`, `1.0`, `14 Februari 2022`).
- `POST /api/smki/pelaporan-insiden` : Buat laporan Bagian I (required: `kategori_insiden`, `jenis_layanan`, `tanggal_laporan`, `nama_pelapor`, `instansi_pelapor`, `email_pelapor`, `waktu_kejadian`, `nama_sistem`).
- `GET /api/smki/pelaporan-insiden/{id}` : Detail satu laporan (pemilik atau admin, selain itu 404).
- `PUT /api/smki/pelaporan-insiden/{id}` : Lengkapi Bagian II (required: `nama_instansi_pemilik`, `nama_pic`, `deskripsi_kejadian`). Flag `hapus_bukti_pelapor` / `hapus_bukti_pemilik` (`1`) untuk menghapus file lama.
- `DELETE /api/smki/pelaporan-insiden/{id}` : **[Admin saja]** Hapus laporan (soft delete + hapus file bukti).
- `GET /api/smki/pelaporan-insiden/{id}/unduh?tipe=pelapor|pemilik` : Unduh file bukti (attachment + nama asli, CORS-friendly).
- `GET /api/smki/pelaporan-insiden/{id}/export-docx` : Dokumen Word FR-016 terisi (checkbox, kop, screenshot ter-embed di lampiran).
  - Query parameters opsional: `no_dokumen`, `no_revisi`, `tanggal_berlaku`.

---

> [!NOTE]
> Semua route yang tidak menggunakan `GET` (seperti `POST`, `PUT`, `DELETE`) harus mengirimkan data dalam format `application/json` (Gunakan Header `Content-Type: application/json` dan `Accept: application/json`).

