# 🤖 PROMPT AI AGENT — SETUP PRAKCHECK (FULL PHP)
> Teknologi: PHP · HTML/CSS/JS · Supabase · Railway  
> **Tidak ada Python service. Semua logika termasuk ekstraksi teks dan Cosine Similarity dikerjakan PHP.**  
> **Skema database FINAL ada di file `02_RANCANGAN_DATABASE.md` — AI agent DILARANG mendefinisikan ulang skema apapun**

---

## ATURAN WAJIB UNTUK AI AGENT

Bacakan aturan ini ke AI agent sebelum memulai apapun:

```
ATURAN TIDAK BOLEH DILANGGAR:
1. Skema database FINAL ada di file 02_RANCANGAN_DATABASE.md.
   Kamu TIDAK BOLEH mengubah, menambah, atau menghapus tabel/kolom apapun.
2. Role pengguna hanya DUA: 'mahasiswa' dan 'asprak'. Tidak ada 'dosen'.
3. Tidak ada Python service. Semua logika — termasuk ekstraksi teks PDF/DOCX
   dan perhitungan Cosine Similarity — dikerjakan murni oleh PHP.
4. Urutan pengerjaan HARUS berurutan. Jangan loncat fase.
5. Setiap file yang kamu buat HARUS bisa langsung dijalankan tanpa error.
6. Jika ada yang ambigu, tanya dulu — jangan asumsi sendiri.
```

---

# ═══════════════════════════════════════════════
# FASE 1 — SETUP SUPABASE (LAKUKAN MANUAL, BUKAN AI AGENT)
# ═══════════════════════════════════════════════

## LANGKAH 1.1 — Buat Akun dan Project Supabase

1. Buka https://supabase.com → klik **Start your project**
2. Login dengan GitHub atau Google
3. Klik **New Project**, isi:
   - **Name**: `prakcheck`
   - **Database Password**: buat password kuat, **SIMPAN** karena tidak bisa dilihat lagi
   - **Region**: `Southeast Asia (Singapore)`
4. Klik **Create new project** → tunggu sekitar 2 menit sampai status `ACTIVE`

**Ambil credentials setelah aktif:**
1. Klik ⚙️ **Settings** → **API**
2. Catat tiga nilai ini:
```
Project URL      : https://[project-id].supabase.co
anon key         : eyJhbGci...(public)
service_role key : eyJhbGci...(RAHASIA — jangan expose ke frontend)
```

---

## LANGKAH 1.2 — Jalankan SQL Schema

1. Di dashboard Supabase → klik **SQL Editor** → **New query**
2. Copy seluruh SQL di bawah, paste, klik **Run**

```sql
-- ============================================================
-- PRAKCHECK DATABASE SCHEMA v1.0
-- Role: 'mahasiswa' dan 'asprak' saja. Tidak ada 'dosen'.
-- ============================================================

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

CREATE TABLE IF NOT EXISTS kelas_mahasiswa (
  id            UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  kelas_id      UUID          NOT NULL REFERENCES kelas(id) ON DELETE CASCADE,
  mahasiswa_id  UUID          NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  joined_at     TIMESTAMPTZ   DEFAULT NOW(),
  UNIQUE(kelas_id, mahasiswa_id)
);
CREATE INDEX IF NOT EXISTS idx_kelas_mhs_kelas ON kelas_mahasiswa(kelas_id);
CREATE INDEX IF NOT EXISTS idx_kelas_mhs_mhs   ON kelas_mahasiswa(mahasiswa_id);

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

CREATE TABLE IF NOT EXISTS laporan (
  id              UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  mahasiswa_id    UUID          NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  tugas_id        UUID          NOT NULL REFERENCES tugas(id) ON DELETE RESTRICT,
  file_name       VARCHAR(255)  NOT NULL,
  file_path       TEXT          NOT NULL,
  file_size_kb    INTEGER       NOT NULL,
  file_type       VARCHAR(10)   NOT NULL CHECK (file_type IN ('pdf', 'docx')),
  status          VARCHAR(20)   NOT NULL DEFAULT 'pending'
                  CHECK (status IN ('pending','diterima','ditolak','dianalisis','dinilai')),
  alasan_tolak    VARCHAR(100),
  extracted_text  TEXT,
  is_analyzed     BOOLEAN       DEFAULT false,
  uploaded_at     TIMESTAMPTZ   DEFAULT NOW(),
  UNIQUE(mahasiswa_id, tugas_id)
);
CREATE INDEX IF NOT EXISTS idx_laporan_tugas     ON laporan(tugas_id);
CREATE INDEX IF NOT EXISTS idx_laporan_mahasiswa ON laporan(mahasiswa_id);
CREATE INDEX IF NOT EXISTS idx_laporan_status    ON laporan(status);

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

CREATE TABLE IF NOT EXISTS notifikasi (
  id          UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id     UUID          NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  judul       VARCHAR(200)  NOT NULL,
  pesan       TEXT          NOT NULL,
  tipe        VARCHAR(30)   NOT NULL CHECK (tipe IN (
                'tugas_baru','laporan_ditolak','laporan_diterima',
                'nilai_keluar','materi_baru','pengumuman_baru'
              )),
  is_read     BOOLEAN       DEFAULT false,
  created_at  TIMESTAMPTZ   DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_notif_user   ON notifikasi(user_id);
CREATE INDEX IF NOT EXISTS idx_notif_unread ON notifikasi(user_id, is_read) WHERE is_read = false;
```

**Verifikasi:** Klik **Table Editor** → harus ada 9 tabel.

---

## LANGKAH 1.3 — Setup RLS

SQL Editor → New query → paste dan run:

```sql
ALTER TABLE laporan    ENABLE ROW LEVEL SECURITY;
ALTER TABLE nilai      ENABLE ROW LEVEL SECURITY;
ALTER TABLE kemiripan  ENABLE ROW LEVEL SECURITY;
ALTER TABLE notifikasi ENABLE ROW LEVEL SECURITY;

CREATE POLICY "mahasiswa lihat laporan sendiri" ON laporan
FOR SELECT USING (mahasiswa_id = auth.uid());

CREATE POLICY "asprak lihat laporan kelasnya" ON laporan
FOR SELECT USING (
  EXISTS (
    SELECT 1 FROM tugas t JOIN kelas k ON t.kelas_id = k.id
    WHERE t.id = laporan.tugas_id AND k.asprak_id = auth.uid()
  )
);

CREATE POLICY "mahasiswa lihat nilai sendiri" ON nilai
FOR SELECT USING (mahasiswa_id = auth.uid());

CREATE POLICY "asprak lihat nilai kelasnya" ON nilai
FOR SELECT USING (
  EXISTS (
    SELECT 1 FROM tugas t JOIN kelas k ON t.kelas_id = k.id
    WHERE t.id = nilai.tugas_id AND k.asprak_id = auth.uid()
  )
);

CREATE POLICY "asprak lihat kemiripan kelasnya" ON kemiripan
FOR SELECT USING (
  EXISTS (
    SELECT 1 FROM tugas t JOIN kelas k ON t.kelas_id = k.id
    WHERE t.id = kemiripan.tugas_id AND k.asprak_id = auth.uid()
  )
);

CREATE POLICY "user kelola notifikasi sendiri" ON notifikasi
FOR ALL USING (user_id = auth.uid());
```

---

## LANGKAH 1.4 — Setup Storage Buckets

SQL Editor → New query → paste dan run:

```sql
INSERT INTO storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
VALUES (
  'laporan-files', 'laporan-files', false, 10485760,
  ARRAY['application/pdf','application/vnd.openxmlformats-officedocument.wordprocessingml.document']
) ON CONFLICT (id) DO UPDATE SET
  public = false, file_size_limit = 10485760,
  allowed_mime_types = ARRAY['application/pdf','application/vnd.openxmlformats-officedocument.wordprocessingml.document'];

INSERT INTO storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
VALUES (
  'materi-files', 'materi-files', false, 52428800,
  ARRAY['application/pdf','image/jpeg','image/png']
) ON CONFLICT (id) DO UPDATE SET
  public = false, file_size_limit = 52428800,
  allowed_mime_types = ARRAY['application/pdf','image/jpeg','image/png'];

INSERT INTO storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
VALUES (
  'avatars', 'avatars', true, 2097152,
  ARRAY['image/jpeg','image/png','image/webp']
) ON CONFLICT (id) DO UPDATE SET
  public = true, file_size_limit = 2097152,
  allowed_mime_types = ARRAY['image/jpeg','image/png','image/webp'];

CREATE POLICY "service_role full access laporan" ON storage.objects
FOR ALL TO service_role USING (bucket_id = 'laporan-files');

CREATE POLICY "service_role full access materi" ON storage.objects
FOR ALL TO service_role USING (bucket_id = 'materi-files');

CREATE POLICY "service_role full access avatars" ON storage.objects
FOR ALL TO service_role USING (bucket_id = 'avatars');
```

**Verifikasi:** Klik **Storage** → harus ada 3 bucket: laporan-files, materi-files, avatars.

---

## LANGKAH 1.5 — Catat Credentials

```
SUPABASE_URL         = https://[project-id].supabase.co
SUPABASE_ANON_KEY    = eyJhbGci...(anon key)
SUPABASE_SERVICE_KEY = eyJhbGci...(service_role key — RAHASIA)
```

---

# ═══════════════════════════════════════════════
# FASE 2 — SETUP PROJECT PHP (GUNAKAN AI AGENT)
# ═══════════════════════════════════════════════

## PROMPT 2.1 — Inisialisasi Struktur Project

```
Kamu membangun aplikasi PrakCheck. Baca aturan ini dulu:

ATURAN:
- Role pengguna hanya: 'mahasiswa' dan 'asprak'. TIDAK ADA 'dosen'.
- Skema database sudah final di file 02_RANCANGAN_DATABASE.md — jangan ubah apapun.
- TIDAK ADA Python service. Semua logika dikerjakan PHP murni.
- Operasi database via Supabase REST API menggunakan SERVICE_KEY.

TUGAS:
Buat struktur folder dan file-file berikut:

1. STRUKTUR FOLDER:
prakcheck/
├── public/
│   ├── index.php          ← router utama
│   ├── health.php
│   └── assets/
│       ├── css/
│       ├── js/
│       └── img/
├── src/
│   ├── config/
│   │   └── supabase.php
│   ├── controllers/
│   │   ├── AuthController.php
│   │   ├── KelasController.php
│   │   ├── TugasController.php
│   │   ├── LaporanController.php
│   │   ├── AnalisisController.php   ← BARU: menggantikan Python service
│   │   ├── KemiripanController.php
│   │   ├── NilaiController.php
│   │   ├── MateriController.php
│   │   └── NotifikasiController.php
│   ├── services/
│   │   ├── PdfExtractor.php         ← BARU: ekstraksi teks dari PDF
│   │   ├── DocxExtractor.php        ← BARU: ekstraksi teks dari DOCX
│   │   └── CosineSimilarity.php     ← BARU: perhitungan kemiripan
│   ├── middleware/
│   │   └── AuthMiddleware.php
│   └── helpers/
│       ├── FileHelper.php
│       └── ResponseHelper.php
├── .env.example
├── composer.json
└── Procfile

2. FILE .env.example — isi PERSIS ini:
# Supabase
SUPABASE_URL=https://[project-id].supabase.co
SUPABASE_ANON_KEY=
SUPABASE_SERVICE_KEY=

# App
APP_URL=https://[railway-domain].up.railway.app
APP_SECRET=
APP_ENV=production

3. FILE composer.json — isi PERSIS ini:
{
  "require": {
    "php": ">=8.1",
    "vlucas/phpdotenv": "^5.5",
    "phpoffice/phpspreadsheet": "^2.0",
    "phpoffice/phpword": "^1.2",
    "smalot/pdfparser": "^2.7",
    "guzzlehttp/guzzle": "^7.0"
  },
  "autoload": {
    "psr-4": {
      "App\\": "src/"
    }
  }
}

Catatan library baru vs lama:
- phpoffice/phpword  → ekstraksi teks dari file DOCX (menggantikan python-docx)
- smalot/pdfparser   → ekstraksi teks dari file PDF (menggantikan pdfplumber)
- phpoffice/phpspreadsheet → tetap ada untuk export Excel

4. FILE Procfile (root):
web: php -S 0.0.0.0:$PORT -t public/

5. FILE public/health.php:
<?php
header('Content-Type: application/json');
echo json_encode([
  'status' => 'ok',
  'service' => 'PrakCheck PHP Backend',
  'timestamp' => date('c')
]);

OUTPUT: Jalankan semua perintah bash untuk buat folder, konfirmasi setiap file berhasil dibuat.
```

---

## PROMPT 2.2 — Buat Supabase Client

```
Buat file src/config/supabase.php untuk PrakCheck.

KONTEKS:
- Semua request ke Supabase menggunakan SUPABASE_SERVICE_KEY (server-side, bypass RLS)
- Gunakan PHP curl murni
- Credentials dari .env via vlucas/phpdotenv

Buat class SupabaseClient dengan Singleton pattern dan method berikut:

public static function getInstance(): self

public function select(string $table, array $filters = [], string $select = '*'): array
  Melakukan GET ke /rest/v1/{table}
  $filters bisa berisi key=value untuk WHERE clause, contoh:
    ['role' => 'eq.mahasiswa', 'is_active' => 'eq.true', 'order' => 'nama.asc', 'limit' => '20']
  Semua filter diubah menjadi query string: ?role=eq.mahasiswa&is_active=eq.true&...
  Tambahkan &select={$select} ke query string
  Return array of rows, atau [] jika kosong/gagal

public function insert(string $table, array $data): array|false
  POST ke /rest/v1/{table}
  Header tambahan: Prefer: return=representation
  Return array data yang baru diinsert, atau false jika gagal

public function update(string $table, array $data, array $filters): array|false
  PATCH ke /rest/v1/{table}?{filters diubah ke query string}
  Header tambahan: Prefer: return=representation
  Return array data yang diupdate, atau false jika gagal

public function delete(string $table, array $filters): bool
  DELETE ke /rest/v1/{table}?{filters diubah ke query string}
  Return true jika HTTP status 200/204, false jika gagal

public function uploadFile(string $bucket, string $storagePath, string $localFilePath, string $mimeType): string|false
  POST ke /storage/v1/object/{bucket}/{storagePath}
  Kirim file sebagai binary body: file_get_contents($localFilePath)
  Content-Type sesuai $mimeType
  Jika sukses return $storagePath, jika gagal return false

public function downloadFile(string $bucket, string $storagePath): string|false
  GET ke /storage/v1/object/{bucket}/{storagePath}
  Return konten file sebagai string binary, atau false jika gagal
  (Digunakan oleh AnalisisController untuk download file sebelum diproses)

public function getSignedUrl(string $bucket, string $storagePath, int $expiresIn = 3600): string|false
  POST ke /storage/v1/object/sign/{bucket}/{storagePath}
  Body: {"expiresIn": $expiresIn}
  Return signed URL string, atau false jika gagal

public function deleteFile(string $bucket, string $storagePath): bool
  DELETE ke /storage/v1/object/{bucket}
  Body: {"prefixes": [$storagePath]}
  Return true/false

private function request(string $method, string $endpoint, mixed $body = null, array $extraHeaders = []): array
  Semua HTTP request lewat sini
  Selalu sertakan header: apikey: SERVICE_KEY, Authorization: Bearer SERVICE_KEY
  Untuk body array/object: json_encode + Content-Type: application/json
  Untuk body string binary: kirim apa adanya
  Return: ['status' => int, 'body' => array|string, 'error' => string|null]
  Jika status >= 400: error_log pesan error, return dengan error terisi

REQUIREMENT TAMBAHAN:
- Setiap public method harus ada try-catch
- Jika terjadi exception, error_log dan return false/[]
- Tambahkan docblock singkat di setiap method

OUTPUT: Satu file PHP lengkap.
```

---

## PROMPT 2.3 — Buat Tiga Service Class (Pengganti Python)

```
Buat tiga file service class untuk PrakCheck. Ini menggantikan seluruh fungsi Python service.

=== FILE 1: src/services/PdfExtractor.php ===

Gunakan library smalot/pdfparser.

class PdfExtractor {
  
  public function extractFromPath(string $filePath): string
    // Ekstraksi teks dari file PDF yang ada di local path
    // Gunakan: $parser = new \Smalot\PdfParser\Parser();
    //          $pdf = $parser->parseFile($filePath);
    //          $text = $pdf->getText();
    // Lakukan preprocessing: lowercase, hapus karakter non-alfanumerik kecuali spasi
    // Hapus spasi berlebih
    // Return teks bersih, atau string kosong jika gagal
  
  public function extractFromString(string $fileContent): string
    // Sama seperti extractFromPath tapi dari string binary
    // Simpan sementara ke sys_get_temp_dir() dengan nama unik
    // Proses, hapus file temp, return teks
}

=== FILE 2: src/services/DocxExtractor.php ===

Gunakan library phpoffice/phpword.

class DocxExtractor {
  
  public function extractFromPath(string $filePath): string
    // Ekstraksi teks dari file DOCX
    // Gunakan: $phpWord = \PhpOffice\PhpWord\IOFactory::load($filePath);
    // Loop semua section dan element untuk ambil text run
    // Preprocessing: lowercase, hapus non-alfanumerik kecuali spasi, trim
    // Return teks bersih, atau string kosong jika gagal
  
  public function extractFromString(string $fileContent): string
    // Sama seperti extractFromPath tapi dari string binary
    // Simpan sementara ke temp, proses, hapus, return
}

=== FILE 3: src/services/CosineSimilarity.php ===

Implementasi murni PHP tanpa library eksternal.

class CosineSimilarity {

  public function calculate(array $documents): array
    // $documents adalah array asosiatif: ['laporan_id' => 'teks dokumen', ...]
    // Return array hasil: [
    //   ['laporan_id_a' => '...', 'laporan_id_b' => '...', 'skor' => 0.9400],
    //   ...
    // ]
    // laporan_id_a selalu UUID yang lebih kecil secara string dari laporan_id_b
    
    IMPLEMENTASI:
    
    STEP 1 — Tokenisasi:
      Untuk setiap dokumen, pecah teks menjadi array kata: explode(' ', trim($text))
      Hapus kata kosong
    
    STEP 2 — Bangun vocabulary (kumpulan semua kata unik dari semua dokumen):
      $vocabulary = array_unique(array_merge(...semua array kata))
      Sort dan index: $vocabIndex = array_flip($vocabulary)
    
    STEP 3 — Hitung TF (Term Frequency) untuk setiap dokumen:
      Untuk setiap dokumen, hitung berapa kali tiap kata muncul
      Normalisasi dengan total kata di dokumen tersebut
      Hasilnya adalah vektor angka sepanjang vocabulary
    
    STEP 4 — Hitung IDF (Inverse Document Frequency):
      Untuk tiap kata: IDF = log(jumlah_dokumen / jumlah_dokumen_yang_mengandung_kata + 1)
      Gunakan: log($n / ($df + 1)) + 1 (smooth IDF)
    
    STEP 5 — Hitung TF-IDF:
      Kalikan TF × IDF untuk setiap kata di setiap dokumen
    
    STEP 6 — Hitung Cosine Similarity untuk setiap pasang (i, j) dimana i < j:
      dot_product = sum(vector_a[k] * vector_b[k] untuk semua k)
      magnitude_a = sqrt(sum(x^2 untuk x di vector_a))
      magnitude_b = sqrt(sum(x^2 untuk x di vector_b))
      jika magnitude_a == 0 atau magnitude_b == 0: skor = 0.0
      jika tidak: skor = dot_product / (magnitude_a * magnitude_b)
      Round ke 4 desimal
    
    STEP 7 — Tentukan laporan_id_a dan laporan_id_b:
      laporan_id_a = id yang lebih kecil secara string (strcmp)
      laporan_id_b = id yang lebih besar

  private function tokenize(string $text): array
    // Pecah teks menjadi kata, hapus yang kosong
    // Return array kata

  private function computeTfIdf(array $tokenizedDocs): array
    // Terima array of arrays (kata per dokumen)
    // Return array of TF-IDF vectors (array float per dokumen)
}

REQUIREMENT:
- Setiap method harus ada try-catch, return nilai aman jika error
- Tambahkan komentar inline pada bagian matematika agar mudah dipahami

OUTPUT: Tiga file PHP lengkap dan siap digunakan.
```

---

## PROMPT 2.4 — Buat AnalisisController (Pengganti Python Service)

```
Buat file src/controllers/AnalisisController.php untuk PrakCheck.

File ini menggantikan seluruh fungsi Python service. Semua logika ekstraksi teks
dan perhitungan Cosine Similarity dikerjakan PHP di sini.

DEPENDENCY yang digunakan (sudah ada di composer.json):
- App\Config\SupabaseClient
- App\Services\PdfExtractor
- App\Services\DocxExtractor
- App\Services\CosineSimilarity
- App\Middleware\AuthMiddleware

Buat method runAnalysis(string $tugasId): void dengan alur PERSIS ini:

STEP 1 — Validasi akses:
  Panggil AuthMiddleware::requireRole('asprak')
  Verifikasi tugas milik kelas asprak yang login:
    Query tugas JOIN kelas WHERE tugas.id = $tugasId AND kelas.asprak_id = session_user_id
  Jika tidak ditemukan → return 403 JSON {"error": "Akses ditolak"}

STEP 2 — Cek status tugas:
  Jika tugas.is_analyzed = true → return 200 JSON {"message": "Tugas sudah pernah dianalisis"}
  Jika tugas.status = 'open' → update status menjadi 'closed' via SupabaseClient::update()

STEP 3 — Ambil semua laporan yang diterima:
  Query tabel laporan WHERE tugas_id = $tugasId AND status IN ('diterima', 'dianalisis')
  SELECT id, mahasiswa_id, file_path, file_type
  Jika jumlah laporan < 2 → return 200 JSON {
    "status": "skip",
    "message": "Kurang dari 2 laporan, analisis dilewati",
    "total_laporan": jumlah
  }

STEP 4 — Download dan ekstraksi teks semua laporan:
  Buat array $documents = [] (key = laporan_id, value = teks)
  Buat array $errors = [] untuk laporan yang gagal
  
  Untuk setiap laporan:
    a. Download file dari Supabase Storage:
       $fileContent = SupabaseClient::downloadFile('laporan-files', laporan.file_path)
       Jika gagal → tambahkan ke $errors, lanjut ke laporan berikutnya
    
    b. Simpan ke file temp:
       $tmpPath = sys_get_temp_dir() . '/' . uniqid('prakcheck_') . '.' . laporan.file_type
       file_put_contents($tmpPath, $fileContent)
    
    c. Ekstraksi teks sesuai tipe file:
       Jika file_type = 'pdf'  → $text = PdfExtractor::extractFromPath($tmpPath)
       Jika file_type = 'docx' → $text = DocxExtractor::extractFromPath($tmpPath)
    
    d. Hapus file temp: unlink($tmpPath)
    
    e. Jika $text kosong → tambahkan ke $errors, lanjut
    
    f. Simpan: $documents[laporan.id] = $text
       Update laporan di DB: extracted_text = $text
  
  Jika count($documents) < 2 → return 500 JSON {
    "error": "Tidak cukup laporan yang bisa diproses",
    "errors": $errors
  }

STEP 5 — Hitung Cosine Similarity:
  $hasil = CosineSimilarity::calculate($documents)
  $hasil berisi: [['laporan_id_a', 'laporan_id_b', 'skor'], ...]

STEP 6 — Simpan hasil ke tabel kemiripan:
  $zonaCount = ['merah' => 0, 'kuning' => 0, 'hijau' => 0]
  
  Untuk setiap pasang di $hasil:
    Tentukan zona: >= 0.80 = 'merah', >= 0.50 = 'kuning', < 0.50 = 'hijau'
    $zonaCount[zona]++
    
    Cari mahasiswa_id_a dari array laporan berdasarkan laporan_id_a
    Cari mahasiswa_id_b dari array laporan berdasarkan laporan_id_b
    
    SupabaseClient::insert('kemiripan', [
      'tugas_id'        => $tugasId,
      'laporan_id_a'    => laporan_id_a,
      'laporan_id_b'    => laporan_id_b,
      'mahasiswa_id_a'  => mahasiswa_id_a,
      'mahasiswa_id_b'  => mahasiswa_id_b,
      'skor_kemiripan'  => $pasang['skor'],
      'zona'            => $zona
    ])

STEP 7 — Update status semua laporan yang berhasil diproses:
  Untuk setiap laporan_id di $documents:
    SupabaseClient::update('laporan',
      ['status' => 'dianalisis', 'is_analyzed' => true],
      ['id' => 'eq.' . $laporan_id]
    )

STEP 8 — Update status tugas:
  SupabaseClient::update('tugas',
    ['is_analyzed' => true, 'analyzed_at' => date('c'), 'status' => 'analyzed'],
    ['id' => 'eq.' . $tugasId]
  )

STEP 9 — Return hasil:
  return 200 JSON {
    "status": "success",
    "tugas_id": $tugasId,
    "total_laporan": count($documents),
    "total_pasang": count($hasil),
    "zona_merah": $zonaCount['merah'],
    "zona_kuning": $zonaCount['kuning'],
    "zona_hijau": $zonaCount['hijau'],
    "errors": $errors
  }

CATATAN PERFORMA:
- Untuk 20 laporan, PHP perlu mengerjakan 190 perhitungan pasang — ini sangat ringan
- Download file dari Supabase berjalan sekuensial — wajar, tidak butuh async
- Estimasi waktu untuk 20 laporan: 10-30 detik tergantung ukuran file
- Railway default timeout 30s — tambahkan set_time_limit(300) di awal method

OUTPUT: Satu file PHP lengkap dengan error handling di setiap step.
```

---

## PROMPT 2.5 — Buat Router dan Auth

```
Buat dua file untuk PrakCheck:

=== FILE 1: public/index.php ===

Router utama PHP. Spesifikasi:
1. Load autoload composer dan .env via Dotenv
2. session_start()
3. Set header: Content-Type: application/json untuk semua response
4. Parse REQUEST_METHOD dan REQUEST_URI (strip query string)
5. Definisikan semua routes dalam array:
   [METHOD, '/path/{param}', ControllerClass, method, requireAuth(bool), requireRole(string|null)]

DAFTAR ROUTE LENGKAP (tidak boleh kurang):
GET    /health                        → include health.php                  auth=false
POST   /api/auth/register             → AuthController::register()          auth=false
POST   /api/auth/login                → AuthController::login()             auth=false
POST   /api/auth/logout               → AuthController::logout()            auth=true
GET    /api/auth/me                   → AuthController::me()                auth=true

GET    /api/kelas                     → KelasController::index()            auth=true
POST   /api/kelas                     → KelasController::create()           auth=true  role=asprak
POST   /api/kelas/{id}/join           → KelasController::join()             auth=true  role=mahasiswa

GET    /api/tugas                     → TugasController::index()            auth=true
POST   /api/tugas                     → TugasController::create()           auth=true  role=asprak
GET    /api/tugas/{id}                → TugasController::show()             auth=true
PUT    /api/tugas/{id}                → TugasController::update()           auth=true  role=asprak
DELETE /api/tugas/{id}                → TugasController::destroy()          auth=true  role=asprak

POST   /api/laporan/upload            → LaporanController::upload()         auth=true  role=mahasiswa
GET    /api/laporan                   → LaporanController::index()          auth=true
GET    /api/laporan/{id}              → LaporanController::show()           auth=true
GET    /api/laporan/compare           → LaporanController::compare()        auth=true  role=asprak

POST   /api/analisis/{id}             → AnalisisController::runAnalysis()   auth=true  role=asprak

GET    /api/kemiripan                 → KemiripanController::index()        auth=true  role=asprak
PUT    /api/kemiripan/{id}/flag       → KemiripanController::flag()         auth=true  role=asprak

POST   /api/nilai                     → NilaiController::submit()           auth=true  role=asprak
PUT    /api/nilai/{id}                → NilaiController::update()           auth=true  role=asprak
GET    /api/nilai/export              → NilaiController::export()           auth=true  role=asprak

GET    /api/materi                    → MateriController::index()           auth=true
POST   /api/materi                    → MateriController::create()          auth=true  role=asprak
DELETE /api/materi/{id}               → MateriController::destroy()         auth=true  role=asprak

GET    /api/notifikasi                → NotifikasiController::index()       auth=true
PUT    /api/notifikasi/read-all       → NotifikasiController::readAll()     auth=true

6. Matching logic:
   - Exact match lebih dulu
   - Pattern match untuk {id} menggunakan regex: #^/path/([^/]+)$#
   - Path param yang ditemukan dioper ke method controller sebagai argumen pertama
   - 404 jika tidak ada route cocok
   - 405 jika route ada tapi method HTTP tidak sesuai

=== FILE 2: src/middleware/AuthMiddleware.php ===

class AuthMiddleware {

  public static function check(): array
    Cek $_SESSION['user_id'] dan $_SESSION['role']
    Jika tidak ada → header HTTP 401, echo JSON {"error":"Unauthorized"}, die()
    Return: ['user_id' => string, 'role' => string, 'nama' => string]

  public static function requireRole(string $role): array
    Panggil check() — jika gagal sudah die() di sana
    Cek role sesuai
    Jika tidak sesuai → header HTTP 403, echo JSON {"error":"Forbidden"}, die()
    Return hasil check()

  public static function getCurrentUser(): array
    Jika session ada return ['user_id', 'role', 'nama']
    Jika tidak ada return []
}

=== FILE 3: src/controllers/AuthController.php ===

Implementasi LENGKAP:

register(): void
  Validasi: nama, email, password (min 8 char), nrp_nip, role wajib ada
  Validasi role hanya 'mahasiswa' atau 'asprak'
  Validasi format email dengan filter_var()
  Cek duplikat email: SupabaseClient::select('users', ['email' => 'eq.'.$email])
  Cek duplikat nrp_nip: SupabaseClient::select('users', ['nrp_nip' => 'eq.'.$nrp_nip])
  Hash password: password_hash($password, PASSWORD_BCRYPT)
  Insert ke tabel users
  Return 201 JSON {"message":"Registrasi berhasil","user":{"id","nama","email","role"}}

login(): void
  Validasi: email dan password ada
  Query: SupabaseClient::select('users', ['email' => 'eq.'.$email])
  Jika tidak ditemukan → 401 JSON {"error":"Email atau password salah"}
  Verifikasi: password_verify($password, $user['password_hash'])
  Jika salah → 401 JSON {"error":"Email atau password salah"}
  Jika is_active = false → 403 JSON {"error":"Akun dinonaktifkan"}
  Set session: user_id, role, nama
  Return 200 JSON {"message":"Login berhasil","user":{"id","nama","email","role"}}

logout(): void
  session_destroy()
  Return 200 JSON {"message":"Logout berhasil"}

me(): void
  AuthMiddleware::check()
  Query users WHERE id = session user_id
  Return 200 JSON data user (hapus kolom password_hash dari response)

OUTPUT: Tiga file PHP lengkap.
```

---

## PROMPT 2.6 — Buat LaporanController

```
Buat file src/controllers/LaporanController.php untuk PrakCheck secara LENGKAP.

REFERENSI SKEMA (dari file 02_RANCANGAN_DATABASE.md, tidak boleh diubah):
- Tabel laporan: id, mahasiswa_id, tugas_id, file_name, file_path, file_size_kb,
  file_type, status, alasan_tolak, extracted_text, is_analyzed, uploaded_at
- file_path format: {kelas_id}/{tugas_id}/{mahasiswa_id}/{file_name}
- status: 'pending','diterima','ditolak','dianalisis','dinilai'
- alasan_tolak: 'terlambat','format_salah','nama_salah','duplikat'

Method upload(): void — implementasi LENGKAP dengan 5 validasi berurutan:

STEP 1 — Auth dan ambil user:
  $user = AuthMiddleware::requireRole('mahasiswa')
  $mahasiswaId = $user['user_id']
  $body = json_decode(file_get_contents('php://input'), true) — untuk POST form data ambil dari $_POST
  $tugasId = $_POST['tugas_id'] ?? null
  Jika tidak ada → return 400 JSON {"error":"tugas_id wajib diisi"}

STEP 2 — Cek file ada:
  Cek $_FILES['file'] ada dan $_FILES['file']['error'] === UPLOAD_ERR_OK
  Jika tidak → return 400 JSON {"error":"File tidak ditemukan atau gagal diupload"}

STEP 3 — Ambil data tugas:
  $tugas = SupabaseClient::select('tugas', ['id' => 'eq.'.$tugasId])[0] ?? null
  Jika null → return 404 JSON {"error":"Tugas tidak ditemukan"}
  Jika status bukan 'open' → return 422 JSON {"error":"Tugas sudah ditutup, tidak bisa submit"}

STEP 4 — Validasi file (berurutan, berhenti di validasi pertama yang gagal):

  4a. DEADLINE:
    Jika time() > strtotime($tugas['deadline']):
      INSERT laporan: status='ditolak', alasan_tolak='terlambat'
        (mahasiswa_id, tugas_id, file_name=$_FILES['file']['name'],
         file_path='', file_size_kb=0, file_type='pdf')
      Notifikasi: tipe='laporan_ditolak', judul='Laporan Ditolak',
        pesan='Laporan kamu untuk "'.$tugas['nama_tugas'].'" ditolak: pengumpulan sudah terlambat'
      Return 422 JSON {"error":"Batas waktu sudah lewat","alasan":"terlambat"}

  4b. FORMAT FILE:
    $originalName = $_FILES['file']['name']
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION))
    $mime = mime_content_type($_FILES['file']['tmp_name'])
    
    $formatValid = false
    Jika $ext === 'pdf' && $mime === 'application/pdf':
      $fileType = 'pdf'
      $formatValid = ($tugas['format_diizinkan'] === 'pdf' || $tugas['format_diizinkan'] === 'both')
    Jika $ext === 'docx' && strpos($mime, 'wordprocessingml') !== false:
      $fileType = 'docx'
      $formatValid = ($tugas['format_diizinkan'] === 'docx' || $tugas['format_diizinkan'] === 'both')
    
    Jika !$formatValid:
      INSERT laporan: status='ditolak', alasan_tolak='format_salah', file_type=($ext ?: 'pdf')
      Notifikasi: pesan='...ditolak: format file tidak diizinkan. Format yang diterima: '.$tugas['format_diizinkan']
      Return 422 JSON {"error":"Format file tidak diizinkan","alasan":"format_salah","format_diterima":$tugas['format_diizinkan']}

  4c. NAMA FILE (hanya cek jika konvensi_regex tidak kosong):
    Jika $tugas['konvensi_regex'] tidak kosong:
      Jika !preg_match('/' . $tugas['konvensi_regex'] . '/', $originalName):
        INSERT laporan: status='ditolak', alasan_tolak='nama_salah'
        Notifikasi: pesan='...ditolak: nama file tidak sesuai format. Contoh: '.$tugas['konvensi_nama']
        Return 422 JSON {
          "error":"Nama file tidak sesuai format",
          "alasan":"nama_salah",
          "format_yang_benar": $tugas['konvensi_nama']
        }

  4d. DUPLIKAT:
    $existing = SupabaseClient::select('laporan', [
      'mahasiswa_id' => 'eq.'.$mahasiswaId,
      'tugas_id' => 'eq.'.$tugasId,
      'status' => 'neq.ditolak'
    ])
    Jika count($existing) > 0:
      Return 422 JSON {"error":"Kamu sudah mengumpulkan laporan untuk tugas ini","alasan":"duplikat"}

  4e. UKURAN FILE:
    $maxBytes = $tugas['max_ukuran_mb'] * 1024 * 1024
    Jika $_FILES['file']['size'] > $maxBytes:
      Return 422 JSON {"error":"Ukuran file melebihi batas " . $tugas['max_ukuran_mb'] . "MB"}

STEP 5 — Upload dan simpan:
  $kelas = SupabaseClient::select('tugas', ['id' => 'eq.'.$tugasId], 'kelas_id')[0]
  $kelasId = $kelas['kelas_id']
  $storagePath = $kelasId . '/' . $tugasId . '/' . $mahasiswaId . '/' . $originalName
  
  $uploadResult = SupabaseClient::uploadFile(
    'laporan-files', $storagePath,
    $_FILES['file']['tmp_name'], $mime
  )
  Jika gagal → return 500 JSON {"error":"Gagal menyimpan file, coba lagi"}
  
  $fileSizeKb = (int) ceil($_FILES['file']['size'] / 1024)
  
  $laporan = SupabaseClient::insert('laporan', [
    'mahasiswa_id' => $mahasiswaId,
    'tugas_id'     => $tugasId,
    'file_name'    => $originalName,
    'file_path'    => $storagePath,
    'file_size_kb' => $fileSizeKb,
    'file_type'    => $fileType,
    'status'       => 'diterima'
  ])
  
  SupabaseClient::insert('notifikasi', [
    'user_id' => $mahasiswaId,
    'judul'   => 'Laporan Diterima',
    'pesan'   => 'Laporan kamu untuk "' . $tugas['nama_tugas'] . '" berhasil diterima',
    'tipe'    => 'laporan_diterima'
  ])
  
  Return 201 JSON {"message":"Laporan berhasil dikumpulkan","laporan":$laporan}

Buat juga method berikut (implementasi dasar, tidak perlu selengkap upload):

index(): void
  Ambil tugas_id dari query string
  Jika role=mahasiswa: filter tambahan mahasiswa_id = user_id
  Return 200 JSON {"data": array laporan}

show(string $id): void
  Query laporan WHERE id = $id
  Jika role=mahasiswa dan mahasiswa_id != user_id → return 403
  Return 200 JSON {"data": laporan}

compare(): void
  AuthMiddleware::requireRole('asprak')
  Ambil ?a= dan ?b= dari query string
  Query kedua laporan JOIN users
  Buat signed URL untuk masing-masing file
  Return 200 JSON {"laporan_a": {..., "signed_url":"..."}, "laporan_b": {..., "signed_url":"..."}}

OUTPUT: Satu file PHP lengkap.
```

---

## PROMPT 2.7 — Buat NilaiController dengan Export Excel

```
Buat file src/controllers/NilaiController.php untuk PrakCheck secara LENGKAP.

DEPENDENCY: phpoffice/phpspreadsheet sudah ada di composer.json.

Method submit(): void
  AuthMiddleware::requireRole('asprak')
  Input: laporan_id, nilai (0-100), catatan (opsional), is_plagiat (bool, opsional)
  Validasi laporan ada dan milik kelas asprak yang login
  Jika is_plagiat = true → set nilai = 0 otomatis
  INSERT ke tabel nilai (atau UPDATE jika sudah ada menggunakan laporan_id UNIQUE)
  UPDATE tabel laporan SET status = 'dinilai' WHERE id = laporan_id
  INSERT notifikasi ke mahasiswa:
    user_id = laporan.mahasiswa_id
    tipe = 'nilai_keluar'
    judul = 'Nilai Keluar'
    pesan = 'Nilai kamu untuk "' . nama_tugas . '" sudah keluar: ' . $nilai
  Return 201 JSON {"message":"Nilai berhasil disimpan","nilai":{data nilai}}

Method update(string $id): void
  AuthMiddleware::requireRole('asprak')
  Validasi nilai milik asprak yang login
  UPDATE tabel nilai WHERE id = $id
  Return 200 JSON {"message":"Nilai diupdate","nilai":{data nilai}}

Method export(): void — implementasi LENGKAP
  AuthMiddleware::requireRole('asprak')
  $kelasId = $_GET['kelas_id'] ?? null
  Jika null → return 400 JSON {"error":"kelas_id wajib diisi"}
  
  Validasi kelas milik asprak:
    SELECT * FROM kelas WHERE id = $kelasId AND asprak_id = session_user_id
    Jika tidak ditemukan → return 403 JSON {"error":"Akses ditolak"}
  
  Ambil semua tugas di kelas ini:
    $tugasList = SupabaseClient::select('tugas', ['kelas_id' => 'eq.'.$kelasId, 'order' => 'created_at.asc'])
  
  Ambil semua mahasiswa di kelas:
    $mahasiswaList = SupabaseClient::select('kelas_mahasiswa',
      ['kelas_id' => 'eq.'.$kelasId], 'mahasiswa_id')
    Kemudian query users untuk tiap mahasiswa_id
  
  Untuk setiap mahasiswa, ambil nilai per tugas:
    Loop $tugasList, query laporan JOIN nilai untuk tiap pasang mahasiswa-tugas
  
  Buat file Excel menggunakan PhpSpreadsheet:
  
  SHEET 1 "Rekap Nilai":
    Row 1 (header, bold, background abu-abu):
      Kolom A: "NRP"
      Kolom B: "Nama Mahasiswa"
      Kolom C dst: nama setiap tugas
      Kolom terakhir: "Rata-rata"
    Row 2 dst: data per mahasiswa
      - Sel yang is_plagiat=true: background merah muda (#FFB3B3), nilai = 0
      - Sel yang belum dinilai (NULL): background kuning (#FFFFAA), tulis "Belum dinilai"
      - Sel nilai normal: background putih
    Baris terakhir: rata-rata kelas per kolom tugas
  
  SHEET 2 "Terindikasi Plagiat":
    Row 1 (header, bold):
      Nama Mahasiswa A | NRP A | Nama Mahasiswa B | NRP B | Nama Tugas | Kemiripan (%)
    Row 2 dst: data dari tabel kemiripan zona='merah' atau is_flagged=true di kelas ini
      JOIN dengan laporan dan users untuk ambil nama
      Kolom kemiripan: format XX.XX%
  
  Set column width auto-fit untuk semua kolom
  Freeze row pertama (header) agar tetap terlihat saat scroll
  
  Output sebagai download:
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
    $namaFile = 'Rekap_Nilai_' . str_replace(' ', '_', $kelas['nama_kelas']) . '_' . date('Ymd') . '.xlsx'
    header('Content-Disposition: attachment; filename="' . $namaFile . '"')
    header('Cache-Control: max-age=0')
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet)
    $writer->save('php://output')
    exit

OUTPUT: Satu file PHP lengkap.
```

---

# ═══════════════════════════════════════════════
# FASE 3 — DEPLOY KE RAILWAY (MANUAL)
# ═══════════════════════════════════════════════

## LANGKAH 3.1 — Push Project ke GitHub

Sebelum deploy, pastikan project sudah ada di GitHub:
```
git init
git add .
git commit -m "Initial commit PrakCheck"
git remote add origin https://github.com/[username]/prakcheck.git
git push -u origin main
```

---

## LANGKAH 3.2 — Buat Project di Railway

1. Buka https://railway.app → login dengan GitHub
2. Klik **New Project** → **Deploy from GitHub repo**
3. Pilih repository `prakcheck`
4. Railway otomatis buat satu service dari root project
5. Tunggu build pertama selesai (mungkin gagal dulu sebelum environment variable diisi — itu wajar)

---

## LANGKAH 3.3 — Konfigurasi PHP Service

1. Klik service yang baru dibuat → tab **Settings**
2. Bagian **Build**:
   - Build Command: `composer install --no-dev --optimize-autoloader`
3. Bagian **Deploy**:
   - Start Command: `php -S 0.0.0.0:$PORT -t public/`
4. Tab **Networking**:
   - Klik **Generate Domain**
   - Catat domain, contoh: `prakcheck.up.railway.app`
5. Tab **Variables** → klik **Raw Editor** → paste semua sekaligus:

```
SUPABASE_URL=https://[project-id].supabase.co
SUPABASE_ANON_KEY=eyJhbGci...(anon key)
SUPABASE_SERVICE_KEY=eyJhbGci...(service_role key)
APP_SECRET=buat_string_acak_32_karakter_disini
APP_ENV=production
APP_URL=https://prakcheck.up.railway.app
```

6. Klik **Deploy** → tunggu selesai
7. Buka `https://prakcheck.up.railway.app/health` — harus muncul:
   ```json
   {"status":"ok","service":"PrakCheck PHP Backend","timestamp":"..."}
   ```

---

## LANGKAH 3.4 — Setup Cron Job untuk Auto-Trigger Analisis

**Kirim prompt ini ke AI agent:**

```
Buat file src/cron/check_deadline.php untuk PrakCheck.

File ini dijalankan Railway Cron setiap 5 menit.
Tugasnya: cek tugas yang deadlinenya sudah lewat dan trigger analisis otomatis.

PENTING: Analisis dijalankan langsung di sini (bukan kirim ke service lain)
karena tidak ada Python service. Gunakan AnalisisController.

Implementasi:
1. Load autoload dan .env
2. Buat instance SupabaseClient
3. Query tugas: WHERE deadline < NOW() AND status = 'open' AND is_analyzed = false
4. Untuk setiap tugas yang ditemukan:
   a. echo "[".date('c')."] Memproses: ".$tugas['nama_tugas']."\n"
   b. Update tugas status = 'closed'
   c. Buat instance AnalisisController
   d. Simulasikan session asprak yang punya kelas ini:
      Query kelas WHERE id = tugas.kelas_id, ambil asprak_id
      Set $_SESSION['user_id'] = asprak_id
      Set $_SESSION['role'] = 'asprak'
   e. Panggil langsung method analisis internal (bukan via HTTP):
      Refactor AnalisisController agar logic utama ada di method
      public function processAnalysis(string $tugasId): array
      yang bisa dipanggil langsung tanpa HTTP context
   f. echo hasil sukses atau error
5. echo "Selesai. Diproses: X tugas\n"

Catatan: set_time_limit(0) di awal agar tidak timeout untuk banyak tugas.

OUTPUT: File PHP lengkap. Jika perlu refactor AnalisisController, lakukan sekalian.
```

**Setup di Railway:**
1. Di Railway project → klik **+ New** → **Cron Job**
2. Isi:
   - **Schedule**: `*/5 * * * *`
   - **Command**: `php src/cron/check_deadline.php`
   - **Service**: pilih PHP service yang sudah ada
3. Klik **Add**

---

## CHECKLIST VERIFIKASI FINAL

Kirim prompt ini ke AI agent setelah semua selesai:

```
Lakukan verifikasi akhir project PrakCheck. Untuk setiap item jawab:
✅ ADA & BENAR / ❌ TIDAK ADA / ⚠️ PERLU DIPERBAIKI

SUPABASE (sudah dijalankan manual):
[ ] 9 tabel ada di Supabase Table Editor
[ ] Tidak ada tabel/kolom/role 'dosen' dimanapun
[ ] 3 bucket Storage: laporan-files (private), materi-files (private), avatars (public)
[ ] RLS aktif di laporan, nilai, kemiripan, notifikasi

PHP PROJECT:
[ ] composer.json punya 5 dependency: phpdotenv, phpspreadsheet, phpword, pdfparser, guzzle
[ ] TIDAK ADA Procfile python-service atau file apapun terkait Python
[ ] src/config/supabase.php — method: select, insert, update, delete, uploadFile, downloadFile, getSignedUrl, deleteFile
[ ] src/services/PdfExtractor.php — method extractFromPath dan extractFromString
[ ] src/services/DocxExtractor.php — method extractFromPath dan extractFromString
[ ] src/services/CosineSimilarity.php — method calculate dengan TF-IDF
[ ] src/controllers/AnalisisController.php — runAnalysis() dan processAnalysis() (9 step)
[ ] src/controllers/LaporanController.php — upload() dengan 5 validasi berurutan LENGKAP
[ ] src/controllers/NilaiController.php — export() dengan 2 sheet Excel LENGKAP
[ ] src/controllers/AuthController.php — register, login, logout, me LENGKAP
[ ] 5 controller lain ada (Kelas, Tugas, Kemiripan, Materi, Notifikasi)
[ ] src/middleware/AuthMiddleware.php — check, requireRole, getCurrentUser
[ ] public/index.php — router dengan semua 23 route terdefinisi
[ ] src/cron/check_deadline.php ada
[ ] Procfile di root: web: php -S 0.0.0.0:$PORT -t public/

RAILWAY:
[ ] Satu service PHP deployed
[ ] Endpoint /health merespons 200 OK
[ ] Semua 6 environment variable terisi
[ ] Cron job setiap */5 * * * * sudah dibuat

Untuk setiap ❌ dan ⚠️: sebutkan apa yang harus dikerjakan.
Berikan estimasi persentase kesiapan project.
```
