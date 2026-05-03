# PrakCheck API Documentation

## Overview
PrakCheck adalah sistem manajemen praktikum dengan fitur deteksi plagiarisme. API menggunakan PHP session-based authentication.

**Base URL:** `http://localhost:8080/api` (atau sesuai deployment)

---

## Authentication

API menggunakan PHP session. Login akan mengatur session cookie. Untuk request yang membutuhkan auth, pastikan session cookie terkirim.

### POST /api/auth/register
Register user baru (mahasiswa atau asprak).

**Request Body:**
```json
{
  "nama": "John Doe",
  "email": "john@example.com",
  "password": "password123",
  "nrp_nip": "123456789",
  "role": "mahasiswa"
}
```

**Response Success (201):**
```json
{
  "message": "Registrasi berhasil",
  "user": {
    "id": "uuid",
    "nama": "John Doe",
    "email": "john@example.com",
    "role": "mahasiswa"
  }
}
```

### POST /api/auth/login
Login user.

**Request Body:**
```json
{
  "email": "john@example.com",
  "password": "password123"
}
```

**Response Success (200):**
```json
{
  "message": "Login berhasil",
  "user": {
    "id": "uuid",
    "nama": "John Doe",
    "email": "john@example.com",
    "role": "mahasiswa"
  }
}
```

### POST /api/auth/logout
Logout (menghancurkan session).

**Response Success (200):**
```json
{
  "message": "Logout berhasil"
}
```

### GET /api/auth/me
Cek session user saat ini.

**Response Success (200):**
```json
{
  "id": "uuid",
  "nama": "John Doe",
  "email": "john@example.com",
  "role": "mahasiswa",
  "nrp_nip": "123456789"
}
```

---

## Kelas (Classes)

### GET /api/kelas
Mendapatkan daftar kelas.
- Mahasiswa: kelas yang diikuti
- Asprak: kelas yang dikelola

**Response Success (200):**
```json
{
  "data": [
    {
      "id": "uuid",
      "nama_kelas": "Algoritma & Struktur Data A",
      "kode_kelas": "ALG-2025-X7K2",
      "mata_kuliah": "Algoritma & Struktur Data",
      "semester": "Ganjil",
      "tahun_ajaran": "2025/2026",
      "asprak_id": "uuid",
      "is_active": true
    }
  ]
}
```

### POST /api/kelas
Buat kelas baru (hanya asprak). Kode kelas digenerate otomatis.

**Request Body:**
```json
{
  "nama_kelas": "Algoritma & Struktur Data A",
  "mata_kuliah": "Algoritma & Struktur Data",
  "semester": "Ganjil",
  "tahun_ajaran": "2025/2026"
}
```

**Response Success (201):**
```json
{
  "message": "Kelas berhasil dibuat",
  "data": {
    "id": "uuid",
    "nama_kelas": "...",
    "kode_kelas": "ALG-2025-X7K2",
    ...
  }
}
```

### GET /api/kelas/{id}
Mendapatkan detail kelas berdasarkan ID.

**Response Success (200):**
```json
{
  "data": {
    "id": "uuid",
    "nama_kelas": "...",
    "kode_kelas": "ALG-2025-X7K2",
    ...
  }
}
```

### POST /api/kelas/join
Mahasiswa join kelas menggunakan kode kelas.

**Request Body:**
```json
{
  "kode_kelas": "ALG-2025-X7K2"
}
```

**Response Success (201):**
```json
{
  "message": "Berhasil bergabung ke kelas",
  "data": {
    "id": "uuid",
    "nama_kelas": "...",
    "kode_kelas": "..."
  }
}
```

---

## Tugas (Assignments)

### GET /api/tugas
Mendapatkan daftar tugas.
- Mahasiswa: tugas dari kelas yang diikuti
- Asprak: tugas yang dibuat

**Query Params (optional):** `kelas_id=uuid`

**Response Success (200):**
```json
{
  "data": [
    {
      "id": "uuid",
      "nama_tugas": "Tugas 1: Sorting",
      "deskripsi": "Buat program sorting",
      "deadline": "2025-06-30T23:59:00",
      "format_diizinkan": "both",
      "status": "open",
      "kelas_id": "uuid",
      "asprak_id": "uuid",
      "is_analyzed": false
    }
  ]
}
```

### POST /api/tugas
Buat tugas baru (hanya asprak).

**Request Body:**
```json
{
  "kelas_id": "uuid",
  "nama_tugas": "Tugas 1: Sorting",
  "deskripsi": "Buat program sorting",
  "deadline": "2025-06-30T23:59:00",
  "format_diizinkan": "both",
  "konvensi_regex": "^[0-9]{2}[A-Z]{2}\\.pdf$",
  "konvensi_nama": "NRP_Nama.pdf"
}
```

### GET /api/tugas/{id}
Detail tugas.

### PUT /api/tugas/{id}
Update tugas (hanya asprak, tugas belum dianalisis).

**Request Body:** (field yang ingin diupdate)
```json
{
  "nama_tugas": "New Name",
  "deadline": "2025-07-01T23:59:00"
}
```

### DELETE /api/tugas/{id}
Hapus tugas (hanya asprak, tidak ada laporan yang masuk).

---

## Laporan (Submissions)

### GET /api/laporan
Mendapatkan daftar laporan (mahasiswa: laporan sendiri; asprak: semua laporan).

**Query Params (optional):** `tugas_id=uuid`

### POST /api/laporan/upload
Upload laporan (mahasiswa). Menggunakan multipart/form-data.

**Form Fields:**
- `tugas_id`: uuid
- `file`: file PDF/DOCX

**Response Success (201):**
```json
{
  "message": "Laporan berhasil dikumpulkan",
  "laporan": {
    "id": "uuid",
    "mahasiswa_id": "uuid",
    "tugas_id": "uuid",
    "file_name": "12345678_John.pdf",
    "status": "diterima"
  }
}
```

### GET /api/laporan/{id}
Detail laporan.

### GET /api/laporan/compare?a=uuid&b=uuid
Bandingkan dua laporan (asprak only).

### DELETE /api/laporan/{id}/cancel
Batalkan laporan (mahasiswa, sebelum deadline dan belum dianalisis).

---

## Analisis & Kemiripan

### POST /api/analisis/{tugas_id}
Jalankan analisis kemiripan untuk tugas tertentu (asprak only).

**Response Success (200):**
```json
{
  "message": "Analisis selesai",
  "total_pasang": 10
}
```

### GET /api/kemiripan
Mendapatkan hasil kemiripan (asprak only).

**Response Success (200):**
```json
{
  "data": [
    {
      "id": "uuid",
      "tugas_id": "uuid",
      "laporan_id_a": "uuid",
      "laporan_id_b": "uuid",
      "skor_kemiripan": 0.85,
      "zona": "merah",
      "mahasiswa_id_a": "uuid",
      "mahasiswa_id_b": "uuid"
    }
  ]
}
```

### PUT /api/kemiripan/{id}/flag
Tandai kemiripan (asprak only).

---

## Nilai (Grades)

### POST /api/nilai
Beri nilai laporan (asprak only).

**Request Body:**
```json
{
  "laporan_id": "uuid",
  "nilai": 85,
  "is_plagiat": false,
  "catatan": "Kerja bagus"
}
```

### GET /api/nilai/export?kelas_id=uuid
Export nilai ke Excel/CSV (asprak only).

### PUT /api/nilai/{id}
Update nilai (asprak only).

---

## Materi & Pengumuman

### GET /api/materi
Mendapatkan daftar materi/pengumuman.
- Query Params: `kelas_id=uuid`, `tipe=pengumuman`

**Response Success (200):**
```json
{
  "data": [
    {
      "id": "uuid",
      "judul": "Pengumuman Tugas 1",
      "isi": "Tugas 1 sudah bisa dikerjakan",
      "tipe": "pengumuman",
      "kelas_id": "uuid",
      "asprak_id": "uuid",
      "publish_at": "2025-06-01T08:00:00",
      "is_published": true
    }
  ]
}
```

### POST /api/materi
Buat materi/pengumuman (asprak only). Menggunakan multipart/form-data.

**Form Fields:**
- `kelas_id`: uuid
- `judul`: string
- `isi`: string
- `tipe`: "materi" atau "pengumuman"
- `lampiran`: file (opsional, PDF/JPG/PNG max 50MB)

### PUT /api/materi/{id}
Update materi/pengumuman (asprak only).

**Request Body:**
```json
{
  "judul": "Judul baru",
  "isi": "Isi baru"
}
```

### DELETE /api/materi/{id}
Hapus materi (asprak only).

---

## Notifikasi

### GET /api/notifikasi
Mendapatkan notifikasi user.

**Query Params:**
- `limit`: number (default 50)
- `is_read`: "true"/"false"

**Response Success (200):**
```json
{
  "data": [
    {
      "id": "uuid",
      "user_id": "uuid",
      "judul": "Laporan Diterima",
      "pesan": "Laporan kamu untuk Tugas 1 berhasil diterima",
      "tipe": "laporan_diterima",
      "is_read": false,
      "created_at": "2025-06-01T10:00:00"
    }
  ],
  "unread_count": 5
}
```

### PUT /api/notifikasi/read-all
Tandai semua notifikasi sebagai sudah dibaca.

### PUT /api/notifikasi/{id}
Tandai notifikasi tertentu sebagai sudah dibaca.

### DELETE /api/notifikasi/{id}
Hapus notifikasi.

---

## Contoh Penggunaan dengan cURL

### Login
```bash
curl -X POST http://localhost:8080/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"john@example.com","password":"password123"}' \
  -c cookies.txt
```

### Buat Kelas (Asprak)
```bash
curl -X POST http://localhost:8080/api/kelas \
  -H "Content-Type: application/json" \
  -b cookies.txt \
  -d '{"nama_kelas":"Algoritma A","mata_kuliah":"Algoritma","semester":"Ganjil","tahun_ajaran":"2025/2026"}'
```

### Join Kelas (Mahasiswa)
```bash
curl -X POST http://localhost:8080/api/kelas/join \
  -H "Content-Type: application/json" \
  -b cookies.txt \
  -d '{"kode_kelas":"ALG-2025-X7K2"}'
```

### Upload Laporan
```bash
curl -X POST http://localhost:8080/api/laporan/upload \
  -b cookies.txt \
  -F "tugas_id=uuid" \
  -F "file=@/path/to/file.pdf"
```

---

## Response Codes

- `200`: Success
- `201`: Created
- `400`: Bad Request (field missing/invalid)
- `401`: Unauthorized (login required)
- `403`: Forbidden (role not allowed)
- `404`: Not Found
- `409`: Conflict (duplicate data)
- `422`: Unprocessable Entity (validation error)
- `500`: Internal Server Error

---

## Notes

1. Semua waktu menggunakan format ISO 8601 (UTC).
2. Session-based auth: pastikan cookie session terkirim dengan `-b cookies.txt` (cURL) atau `credentials: 'include'` (fetch).
3. Untuk upload file (laporan, lampiran), gunakan `multipart/form-data`.
4. Kode kelas digenerate otomatis dengan format: `{3-huruf matkul}-{tahun}-{4-random}`.
