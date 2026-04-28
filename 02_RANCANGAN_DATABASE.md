# 🗄️ RANCANGAN DATABASE PRAKCHECK
> Platform: Supabase (PostgreSQL)  
> Semua tabel menggunakan UUID sebagai primary key  
> Timestamps menggunakan TIMESTAMPTZ (timezone-aware)  
> **INI ADALAH SUMBER KEBENARAN TUNGGAL — AI agent wajib merujuk file ini, tidak boleh mendefinisikan ulang skema**

---

## RINGKASAN TABEL

| No | Nama Tabel | Fungsi |
|---|---|---|
| 1 | `users` | Semua pengguna: mahasiswa dan asprak |
| 2 | `kelas` | Data kelas / mata kuliah praktikum |
| 3 | `kelas_mahasiswa` | Relasi mahasiswa ↔ kelas (many-to-many) |
| 4 | `tugas` | Slot pengumpulan tugas per kelas |
| 5 | `laporan` | File yang disubmit mahasiswa |
| 6 | `kemiripan` | Hasil perhitungan Cosine Similarity antar pasang laporan |
| 7 | `nilai` | Nilai akhir yang diberikan asprak |
| 8 | `materi` | Materi dan pengumuman dari asprak |
| 9 | `notifikasi` | Notifikasi untuk tiap pengguna |

**Tidak ada tabel atau role "dosen". Asprak memiliki akses penuh termasuk fungsi yang biasanya dilakukan dosen (monitoring, export Excel).**

---

## DIAGRAM RELASI ANTAR TABEL

```
users
  │
  ├──────────────────────────────────────────┐
  │ (role = mahasiswa)                       │ (role = asprak)
  ▼                                          ▼
kelas_mahasiswa ◄────────────────────────► kelas
                                             │
                                             ├──────────────┐
                                             ▼              ▼
                                           tugas          materi
                                             │
                                    ┌────────┴──────────┐
                                    ▼                   ▼
                                 laporan             (notifikasi)
                                    │
                           ┌────────┴────────┐
                           ▼                 ▼
                        kemiripan          nilai
```

---

## TABEL 1: `users`

Menyimpan semua pengguna sistem. Hanya ada dua role: **mahasiswa** dan **asprak**.  
Asprak memiliki hak akses tertinggi termasuk kelola kelas, nilai, dan export.

```sql
CREATE TABLE IF NOT EXISTS users (
  id            UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  nama          VARCHAR(100)  NOT NULL,
  email         VARCHAR(100)  NOT NULL UNIQUE,
  password_hash VARCHAR(255)  NOT NULL,
  nrp_nip       VARCHAR(20)   NOT NULL UNIQUE,
  role          VARCHAR(20)   NOT NULL CHECK (role IN ('mahasiswa', 'asprak')),
  avatar_url    TEXT,
  is_active     BOOLEAN       DEFAULT true,
  created_at    TIMESTAMPTZ   DEFAULT NOW(),
  updated_at    TIMESTAMPTZ   DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);
CREATE INDEX IF NOT EXISTS idx_users_role  ON users(role);
```

**Penjelasan kolom penting:**
- `role` hanya boleh `'mahasiswa'` atau `'asprak'` — tidak ada nilai lain
- `nrp_nip` untuk mahasiswa diisi NRP, untuk asprak diisi NIP
- `password_hash` diisi hasil bcrypt dari PHP sebelum disimpan
- `is_active = false` digunakan untuk nonaktifkan akun tanpa hapus data

**Contoh data:**
```
id: uuid-mhs-1  | nama: Andi Firmansyah | role: mahasiswa | nrp_nip: 210411100001
id: uuid-mhs-2  | nama: Budi Santoso    | role: mahasiswa | nrp_nip: 210411100002
id: uuid-asp-1  | nama: Rahardian       | role: asprak    | nrp_nip: 198501012010
```

---

## TABEL 2: `kelas`

Menyimpan data kelas praktikum yang dibuat dan dikelola oleh asprak.

```sql
CREATE TABLE IF NOT EXISTS kelas (
  id            UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  asprak_id     UUID          NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  nama_kelas    VARCHAR(100)  NOT NULL,
  kode_kelas    VARCHAR(20)   NOT NULL UNIQUE,
  mata_kuliah   VARCHAR(100)  NOT NULL,
  semester      VARCHAR(20)   NOT NULL,
  tahun_ajaran  VARCHAR(20)   NOT NULL,
  is_active     BOOLEAN       DEFAULT true,
  created_at    TIMESTAMPTZ   DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_kelas_asprak ON kelas(asprak_id);
```

**Penjelasan kolom penting:**
- `asprak_id` → FK ke `users.id`, PHP wajib validasi role = asprak sebelum INSERT
- `kode_kelas` → unik, contoh: `ALG-2025-A`, `SARPRAS-2025-B`
- `ON DELETE RESTRICT` → asprak tidak bisa dihapus selama masih punya kelas

**Contoh data:**
```
id: uuid-kls-1 | asprak_id: uuid-asp-1 | nama_kelas: Algoritma Kelas A
               | kode_kelas: ALG-2025-A | mata_kuliah: Algoritma Pemrograman
               | semester: Genap 2024/2025
```

---

## TABEL 3: `kelas_mahasiswa`

Tabel pivot relasi many-to-many antara mahasiswa dan kelas.

```sql
CREATE TABLE IF NOT EXISTS kelas_mahasiswa (
  id            UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  kelas_id      UUID          NOT NULL REFERENCES kelas(id) ON DELETE CASCADE,
  mahasiswa_id  UUID          NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  joined_at     TIMESTAMPTZ   DEFAULT NOW(),
  UNIQUE(kelas_id, mahasiswa_id)
);

CREATE INDEX IF NOT EXISTS idx_kelas_mhs_kelas ON kelas_mahasiswa(kelas_id);
CREATE INDEX IF NOT EXISTS idx_kelas_mhs_mhs   ON kelas_mahasiswa(mahasiswa_id);
```

**Penjelasan:**
- `UNIQUE(kelas_id, mahasiswa_id)` → satu mahasiswa tidak bisa masuk kelas yang sama dua kali
- `ON DELETE CASCADE` → jika kelas dihapus, semua relasi ini ikut terhapus otomatis

---

## TABEL 4: `tugas`

Menyimpan setiap slot pengumpulan tugas yang dibuat asprak untuk kelas tertentu.

```sql
CREATE TABLE IF NOT EXISTS tugas (
  id                UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  kelas_id          UUID          NOT NULL REFERENCES kelas(id) ON DELETE RESTRICT,
  asprak_id         UUID          NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  nama_tugas        VARCHAR(200)  NOT NULL,
  deskripsi         TEXT,
  deadline          TIMESTAMPTZ   NOT NULL,
  format_diizinkan  VARCHAR(20)   NOT NULL DEFAULT 'both'
                    CHECK (format_diizinkan IN ('pdf', 'docx', 'both')),
  konvensi_nama     VARCHAR(200)  NOT NULL,
  konvensi_regex    VARCHAR(500),
  max_ukuran_mb     INTEGER       DEFAULT 10,
  status            VARCHAR(20)   NOT NULL DEFAULT 'open'
                    CHECK (status IN ('open', 'closed', 'analyzed')),
  is_analyzed       BOOLEAN       DEFAULT false,
  analyzed_at       TIMESTAMPTZ,
  created_at        TIMESTAMPTZ   DEFAULT NOW(),
  updated_at        TIMESTAMPTZ   DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_tugas_kelas    ON tugas(kelas_id);
CREATE INDEX IF NOT EXISTS idx_tugas_status   ON tugas(status);
CREATE INDEX IF NOT EXISTS idx_tugas_deadline ON tugas(deadline);
```

**Penjelasan kolom penting:**
- `format_diizinkan` → mengontrol validasi upload: `'pdf'`, `'docx'`, atau `'both'`
- `konvensi_nama` → teks penjelasan untuk mahasiswa, contoh: `NRP_NamaLengkap_Praktikum2.pdf`
- `konvensi_regex` → regex aktual untuk validasi PHP, contoh: `^[0-9]{12}_[A-Za-z]+_Praktikum[0-9]+\.(pdf|docx)$`
- `is_analyzed` → diset `true` oleh Python service setelah selesai hitung similarity

**Alur status tugas:**
```
open  →  closed  →  analyzed
(bisa    (deadline   (Python
submit)   habis)      selesai)
```

---

## TABEL 5: `laporan`

Tabel inti sistem. Menyimpan setiap file yang disubmit mahasiswa beserta hasil validasi dan ekstraksi teks.

```sql
CREATE TABLE IF NOT EXISTS laporan (
  id              UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  mahasiswa_id    UUID          NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  tugas_id        UUID          NOT NULL REFERENCES tugas(id) ON DELETE RESTRICT,
  file_name       VARCHAR(255)  NOT NULL,
  file_path       TEXT          NOT NULL,
  file_size_kb    INTEGER       NOT NULL,
  file_type       VARCHAR(10)   NOT NULL CHECK (file_type IN ('pdf', 'docx')),
  status          VARCHAR(20)   NOT NULL DEFAULT 'pending'
                  CHECK (status IN (
                    'pending',
                    'diterima',
                    'ditolak',
                    'dianalisis',
                    'dinilai'
                  )),
  alasan_tolak    VARCHAR(100),
  extracted_text  TEXT,
  is_analyzed     BOOLEAN       DEFAULT false,
  uploaded_at     TIMESTAMPTZ   DEFAULT NOW(),
  UNIQUE(mahasiswa_id, tugas_id)
);

CREATE INDEX IF NOT EXISTS idx_laporan_tugas      ON laporan(tugas_id);
CREATE INDEX IF NOT EXISTS idx_laporan_mahasiswa  ON laporan(mahasiswa_id);
CREATE INDEX IF NOT EXISTS idx_laporan_status     ON laporan(status);
```

**Penjelasan kolom penting:**
- `file_path` → path lengkap di Supabase Storage bucket `laporan-files`  
  Format: `/{kelas_id}/{tugas_id}/{mahasiswa_id}/{file_name}`  
  Contoh: `/uuid-kls-1/uuid-tgs-1/uuid-mhs-1/210411100001_Andi_Praktikum2.pdf`
- `alasan_tolak` → hanya diisi jika `status = 'ditolak'`:  
  `'terlambat'` | `'format_salah'` | `'nama_salah'` | `'duplikat'`
- `extracted_text` → diisi Python service setelah ekstrak teks dari file
- `UNIQUE(mahasiswa_id, tugas_id)` → satu mahasiswa hanya boleh punya satu laporan per tugas

**Alur status laporan:**
```
pending → diterima → dianalisis → dinilai
        ↘ ditolak
```

---

## TABEL 6: `kemiripan`

Menyimpan hasil Cosine Similarity untuk setiap pasang laporan dalam satu tugas.  
**Diisi sepenuhnya oleh Python service. PHP hanya membaca tabel ini.**

```sql
CREATE TABLE IF NOT EXISTS kemiripan (
  id                UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  tugas_id          UUID          NOT NULL REFERENCES tugas(id) ON DELETE CASCADE,
  laporan_id_a      UUID          NOT NULL REFERENCES laporan(id) ON DELETE CASCADE,
  laporan_id_b      UUID          NOT NULL REFERENCES laporan(id) ON DELETE CASCADE,
  mahasiswa_id_a    UUID          NOT NULL REFERENCES users(id),
  mahasiswa_id_b    UUID          NOT NULL REFERENCES users(id),
  skor_kemiripan    DECIMAL(5,4)  NOT NULL CHECK (skor_kemiripan >= 0 AND skor_kemiripan <= 1),
  zona              VARCHAR(10)   NOT NULL CHECK (zona IN ('merah', 'kuning', 'hijau')),
  is_flagged        BOOLEAN       DEFAULT false,
  asprak_catatan    TEXT,
  created_at        TIMESTAMPTZ   DEFAULT NOW(),
  UNIQUE(laporan_id_a, laporan_id_b)
);

CREATE INDEX IF NOT EXISTS idx_kemiripan_tugas ON kemiripan(tugas_id);
CREATE INDEX IF NOT EXISTS idx_kemiripan_skor  ON kemiripan(skor_kemiripan DESC);
CREATE INDEX IF NOT EXISTS idx_kemiripan_zona  ON kemiripan(zona);
```

**Penjelasan kolom penting:**
- `skor_kemiripan` → disimpan `0.0000–1.0000`, tampilkan ke user dikali 100 jadi persentase
- Aturan zona (Python yang menentukan saat INSERT):
  - `'merah'`  → skor >= 0.80
  - `'kuning'` → skor >= 0.50 dan < 0.80
  - `'hijau'`  → skor < 0.50
- `laporan_id_a` selalu UUID lebih kecil dari `laporan_id_b` — Python yang atur ini
- `is_flagged` → diset asprak saat terbukti plagiat
- Untuk 20 mahasiswa → **190 baris** per tugas (20×19÷2)

---

## TABEL 7: `nilai`

Menyimpan nilai akhir yang diberikan asprak setelah verifikasi laporan.

```sql
CREATE TABLE IF NOT EXISTS nilai (
  id            UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  laporan_id    UUID          NOT NULL UNIQUE REFERENCES laporan(id) ON DELETE CASCADE,
  mahasiswa_id  UUID          NOT NULL REFERENCES users(id),
  tugas_id      UUID          NOT NULL REFERENCES tugas(id),
  asprak_id     UUID          NOT NULL REFERENCES users(id),
  nilai         DECIMAL(5,2)  CHECK (nilai >= 0 AND nilai <= 100),
  catatan       TEXT,
  is_plagiat    BOOLEAN       DEFAULT false,
  dinilai_at    TIMESTAMPTZ   DEFAULT NOW(),
  updated_at    TIMESTAMPTZ   DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_nilai_tugas     ON nilai(tugas_id);
CREATE INDEX IF NOT EXISTS idx_nilai_mahasiswa ON nilai(mahasiswa_id);
```

**Penjelasan kolom penting:**
- `laporan_id UNIQUE` → satu laporan hanya boleh punya satu nilai
- `nilai` bisa NULL jika asprak belum mengisi
- `is_plagiat = true` → PHP otomatis set `nilai = 0`

---

## TABEL 8: `materi`

Menyimpan materi atau pengumuman dari asprak untuk kelas tertentu.

```sql
CREATE TABLE IF NOT EXISTS materi (
  id            UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  asprak_id     UUID          NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  kelas_id      UUID          NOT NULL REFERENCES kelas(id) ON DELETE CASCADE,
  judul         VARCHAR(200)  NOT NULL,
  isi           TEXT          NOT NULL,
  tipe          VARCHAR(20)   NOT NULL CHECK (tipe IN ('materi', 'pengumuman')),
  lampiran_path TEXT,
  lampiran_name VARCHAR(255),
  publish_at    TIMESTAMPTZ   DEFAULT NOW(),
  is_published  BOOLEAN       DEFAULT true,
  created_at    TIMESTAMPTZ   DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_materi_kelas      ON materi(kelas_id);
CREATE INDEX IF NOT EXISTS idx_materi_publish_at ON materi(publish_at DESC);
```

---

## TABEL 9: `notifikasi`

```sql
CREATE TABLE IF NOT EXISTS notifikasi (
  id          UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id     UUID          NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  judul       VARCHAR(200)  NOT NULL,
  pesan       TEXT          NOT NULL,
  tipe        VARCHAR(30)   NOT NULL CHECK (tipe IN (
                'tugas_baru', 'laporan_ditolak', 'laporan_diterima',
                'nilai_keluar', 'materi_baru', 'pengumuman_baru'
              )),
  is_read     BOOLEAN       DEFAULT false,
  created_at  TIMESTAMPTZ   DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_notif_user   ON notifikasi(user_id);
CREATE INDEX IF NOT EXISTS idx_notif_unread ON notifikasi(user_id, is_read)
  WHERE is_read = false;
```

---

## SUPABASE STORAGE — BUCKET

| Bucket | Akses | Batas Ukuran | Tipe File |
|---|---|---|---|
| `laporan-files` | Private | 10 MB | PDF, DOCX |
| `materi-files` | Private | 50 MB | PDF, JPG, PNG |
| `avatars` | Public | 2 MB | JPG, PNG, WebP |

**Path file laporan:** `/{kelas_id}/{tugas_id}/{mahasiswa_id}/{file_name}`  
**Path file materi:** `/{kelas_id}/{timestamp}_{file_name}`  
**Path avatar:** `/{user_id}/avatar.jpg`

---

## SQL QUERY SIAP PAKAI

### Query 1 — Ranking Kemiripan (Dashboard Asprak)
```sql
SELECT
  k.id                                AS kemiripan_id,
  ROUND(k.skor_kemiripan * 100, 2)   AS persentase,
  k.zona,
  k.is_flagged,
  k.asprak_catatan,
  ma.nama     AS nama_a,  ma.nrp_nip AS nrp_a,
  mb.nama     AS nama_b,  mb.nrp_nip AS nrp_b,
  la.id       AS laporan_id_a,  la.file_path AS file_a,
  lb.id       AS laporan_id_b,  lb.file_path AS file_b
FROM kemiripan k
JOIN laporan la ON k.laporan_id_a = la.id
JOIN laporan lb ON k.laporan_id_b = lb.id
JOIN users   ma ON k.mahasiswa_id_a = ma.id
JOIN users   mb ON k.mahasiswa_id_b = mb.id
WHERE k.tugas_id = $1
ORDER BY k.skor_kemiripan DESC;
```

### Query 2 — Rekap Nilai untuk Export Excel
```sql
SELECT
  u.nrp_nip, u.nama,
  t.nama_tugas, t.deadline,
  l.status                                        AS status_laporan,
  l.alasan_tolak,
  COALESCE(n.nilai::text, 'Belum dinilai')        AS nilai,
  CASE WHEN n.is_plagiat THEN 'Ya' ELSE 'Tidak' END AS plagiat,
  n.catatan
FROM kelas_mahasiswa km
JOIN users u  ON km.mahasiswa_id = u.id
JOIN tugas t  ON t.kelas_id = km.kelas_id
LEFT JOIN laporan l ON (l.mahasiswa_id = u.id AND l.tugas_id = t.id)
LEFT JOIN nilai n   ON n.laporan_id = l.id
WHERE km.kelas_id = $1
ORDER BY u.nama ASC, t.created_at ASC;
```

### Query 3 — Dashboard Mahasiswa
```sql
SELECT
  t.id AS tugas_id, t.nama_tugas, t.deadline,
  t.format_diizinkan, t.konvensi_nama, t.status AS status_tugas,
  EXTRACT(EPOCH FROM (t.deadline - NOW()))::INT  AS sisa_detik,
  l.id AS laporan_id, l.status AS status_laporan,
  l.alasan_tolak, l.uploaded_at,
  n.nilai, n.catatan, n.is_plagiat
FROM kelas_mahasiswa km
JOIN tugas t       ON t.kelas_id = km.kelas_id
LEFT JOIN laporan l ON (l.tugas_id = t.id AND l.mahasiswa_id = $1)
LEFT JOIN nilai n   ON n.laporan_id = l.id
WHERE km.mahasiswa_id = $1
ORDER BY t.deadline ASC;
```

### Query 4 — Statistik Kelas (Dashboard Asprak)
```sql
SELECT
  t.id AS tugas_id, t.nama_tugas, t.deadline, t.status,
  COUNT(DISTINCT km.mahasiswa_id)                                AS total_mahasiswa,
  COUNT(l.id) FILTER (WHERE l.status IN ('diterima','dianalisis','dinilai')) AS total_submit,
  COUNT(l.id) FILTER (WHERE l.status = 'ditolak')               AS total_ditolak,
  COUNT(n.id)                                                    AS total_dinilai,
  COUNT(k.id) FILTER (WHERE k.zona = 'merah')                   AS zona_merah,
  COUNT(k.id) FILTER (WHERE k.zona = 'kuning')                  AS zona_kuning
FROM tugas t
JOIN kelas_mahasiswa km ON km.kelas_id = t.kelas_id
LEFT JOIN laporan l   ON (l.tugas_id = t.id AND l.mahasiswa_id = km.mahasiswa_id)
LEFT JOIN nilai n     ON n.laporan_id = l.id
LEFT JOIN kemiripan k ON k.tugas_id = t.id
WHERE t.kelas_id = $1
GROUP BY t.id, t.nama_tugas, t.deadline, t.status
ORDER BY t.created_at DESC;
```
