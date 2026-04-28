# 📋 ALUR APLIKASI PRAKCHECK
> Sistem Manajemen & Deteksi Kemiripan Laporan Praktikum Berbasis Web  
> Universitas Trunojoyo Madura  
> Teknologi: PHP · Python · HTML/CSS/JS · Supabase · Railway

---

## GAMBARAN UMUM ARSITEKTUR

```
Browser (HTML/CSS/JS)
       │
       │ HTTP Request
       ▼
  PHP Backend (Railway)
  ├── Auth & Session
  ├── CRUD Tugas, Materi, Nilai
  ├── Validasi File
  └── Trigger ke Python Service
       │
       ├──────────────────────────┐
       ▼                          ▼
Supabase Storage           Python Service (Railway)
(Simpan file PDF/DOCX)     (Ekstraksi teks + Cosine Similarity)
       │                          │
       └──────────┬───────────────┘
                  ▼
           Supabase Database
           (PostgreSQL — semua metadata & hasil)
```

**Penjelasan singkat peran tiap teknologi:**

| Teknologi | Peran |
|---|---|
| HTML/CSS/JS | Antarmuka pengguna (frontend), semua halaman yang dilihat user |
| PHP | Backend utama: logika bisnis, routing, auth, CRUD ke Supabase |
| Python | Microservice khusus: ekstraksi teks PDF/DOCX + hitung Cosine Similarity |
| Supabase Storage | Menyimpan file fisik laporan (PDF/DOCX) yang diupload mahasiswa |
| Supabase Database | PostgreSQL — menyimpan semua data: user, tugas, nilai, hasil kemiripan |
| Railway | Platform hosting untuk PHP backend dan Python service sekaligus |

---

## ALUR 1 — REGISTRASI & LOGIN

### Registrasi (Mahasiswa / Asprak)

```
User buka halaman register
        │
        ▼
Isi form: Nama, NRP/NIP, Email, Password, Pilih Peran
        │
        ▼
Frontend kirim POST /api/register ke PHP
        │
        ▼
PHP validasi:
  - Email belum terdaftar? (cek tabel users di Supabase)
  - Password minimal 8 karakter?
  - Peran valid? (mahasiswa / asprak)
        │
   ┌────┴────┐
   ▼         ▼
GAGAL      SUKSES
   │         │
Balik form  PHP hash password (bcrypt)
+ pesan      │
error        ▼
          INSERT ke tabel `users` di Supabase
          (id, nama, email, password_hash, role, created_at)
             │
             ▼
          Redirect ke halaman Login
```

### Login

```
User isi Email + Password
        │
        ▼
POST /api/login ke PHP
        │
        ▼
PHP query tabel `users` WHERE email = ?
        │
        ▼
PHP verifikasi password_hash dengan bcrypt
        │
   ┌────┴────┐
   ▼         ▼
GAGAL      SUKSES
   │         │
Pesan       PHP buat SESSION:
"Email/     $_SESSION['user_id'] = id
password    $_SESSION['role'] = 'mahasiswa'/'asprak'
salah"           │
                 ▼
          Redirect berdasarkan role:
          - mahasiswa → /dashboard/mahasiswa
          - asprak    → /dashboard/asprak
```

---

## ALUR 2 — ASPRAK: BUAT TUGAS

```
Asprak login → Dashboard Asprak
        │
        ▼
Klik tombol "Buat Tugas Baru"
        │
        ▼
Isi form:
  - Nama Tugas        (contoh: "Laporan Praktikum 2 - Sorting")
  - Deskripsi Tugas
  - Deadline          (tanggal + jam)
  - Format File       (PDF / DOCX / keduanya)
  - Konvensi Nama     (contoh: "NRP_NamaLengkap_Praktikum2")
  - Kelas             (relasi ke tabel kelas)
        │
        ▼
POST /api/tugas/create ke PHP
        │
        ▼
PHP validasi:
  - Deadline harus di masa depan
  - Nama tugas tidak kosong
  - Kelas valid dan milik asprak ini
        │
        ▼
INSERT ke tabel `tugas`:
  (id, asprak_id, kelas_id, nama_tugas, deskripsi,
   deadline, format_diizinkan, konvensi_nama, status, created_at)
        │
        ▼
Notifikasi otomatis dikirim ke semua mahasiswa di kelas tersebut
(INSERT ke tabel `notifikasi` untuk tiap mahasiswa)
        │
        ▼
Dashboard Asprak tampilkan tugas baru di list
```

---

## ALUR 3 — MAHASISWA: SUBMIT LAPORAN

```
Mahasiswa login → Dashboard Mahasiswa
        │
        ▼
Lihat daftar tugas aktif (status = open, deadline belum lewat)
        │
        ▼
Klik tugas → Halaman Detail Tugas
        │
        ▼
Upload file (PDF / DOCX)
        │
        ▼
POST /api/laporan/upload ke PHP
(multipart/form-data: file + tugas_id + mahasiswa_id)
        │
        ▼
═══════════════════════════════════
       VALIDASI OTOMATIS (PHP)
═══════════════════════════════════

TAHAP 1 — Cek Deadline:
  - Ambil deadline dari tabel `tugas`
  - Bandingkan dengan waktu sekarang (date('Y-m-d H:i:s'))
  - Jika NOW > deadline → TOLAK, status = 'terlambat'

TAHAP 2 — Cek Format File:
  - Cek ekstensi file: hanya .pdf atau .docx
  - Cek MIME type (bukan hanya ekstensi)
  - Jika tidak sesuai → TOLAK, status = 'format_salah'

TAHAP 3 — Cek Konvensi Nama:
  - Bandingkan nama file dengan pola konvensi di tabel `tugas`
  - Contoh pola: /^[0-9]{11}_[A-Za-z]+_Praktikum[0-9]+\.(pdf|docx)$/
  - Jika tidak cocok → TOLAK, status = 'nama_salah'

TAHAP 4 — Cek Duplikat:
  - Cek apakah mahasiswa ini sudah pernah submit untuk tugas ini
  - Jika sudah ada → TOLAK atau REPLACE (sesuai setting tugas)
        │
   ┌────┴─────────────────────────────┐
   ▼                                  ▼
GAGAL VALIDASI                    LOLOS VALIDASI
   │                                  │
INSERT ke tabel `laporan`:         Upload file ke Supabase Storage:
  status = 'ditolak'                  Bucket: laporan-files
  alasan = 'terlambat' /              Path: /{kelas_id}/{tugas_id}/{mahasiswa_id}/{filename}
           'format_salah' /              │
           'nama_salah'                  ▼
   │                              INSERT ke tabel `laporan`:
Notifikasi ke mahasiswa:            (id, mahasiswa_id, tugas_id,
  "File ditolak: [alasan]"           file_path, file_name, status='diterima',
                                     uploaded_at, extracted_text=NULL,
                                     is_analyzed=false)
                                         │
                                         ▼
                                  Notifikasi ke mahasiswa:
                                    "Laporan berhasil dikirim"
```

---

## ALUR 4 — TRIGGER ANALISIS KEMIRIPAN

Analisis kemiripan berjalan **otomatis** ketika deadline habis. Ada dua mekanisme:

### Mekanisme A: Cron Job di Railway (Otomatis)
```
Railway menjalankan cron setiap 5 menit:
  php artisan plagiarism:check
  (atau script PHP check_deadline.php)
        │
        ▼
PHP query: SELECT * FROM tugas
           WHERE deadline < NOW()
           AND status = 'open'
           AND is_analyzed = false
        │
        ▼
Untuk setiap tugas yang ditemukan:
  - Update status tugas → 'closed'
  - Kirim request ke Python Service:
    POST http://python-service:5000/analyze
    Body: { "tugas_id": "xxx" }
```

### Mekanisme B: Manual oleh Asprak (Tombol)
```
Asprak klik "Mulai Analisis" di dashboard
        │
        ▼
POST /api/analisis/trigger ke PHP
        │
        ▼
PHP kirim request ke Python Service:
  POST http://python-service:5000/analyze
  Body: { "tugas_id": "xxx" }
```

---

## ALUR 5 — PYTHON SERVICE: EKSTRAKSI & COSINE SIMILARITY

```
Python Service menerima: { "tugas_id": "abc123" }
        │
        ▼
STEP 1 — Ambil semua laporan untuk tugas ini:
  Query Supabase DB:
  SELECT id, mahasiswa_id, file_path, file_name
  FROM laporan
  WHERE tugas_id = 'abc123' AND status = 'diterima'
        │
        ▼
STEP 2 — Download semua file dari Supabase Storage:
  Untuk setiap laporan:
    GET https://[project].supabase.co/storage/v1/object/laporan-files/{file_path}
    Simpan sementara di /tmp/
        │
        ▼
STEP 3 — Ekstraksi Teks:
  Untuk file .PDF:
    import pdfplumber
    with pdfplumber.open(file_path) as pdf:
        text = " ".join([page.extract_text() for page in pdf.pages])

  Untuk file .DOCX:
    from docx import Document
    doc = Document(file_path)
    text = " ".join([para.text for para in doc.paragraphs])
        │
        ▼
STEP 4 — Preprocessing Teks:
  - Lowercase semua teks
  - Hapus tanda baca & angka
  - Hapus stop words (Bahasa Indonesia + Inggris)
  - Stemming (opsional, pakai PySastrawi untuk Bahasa Indonesia)
        │
        ▼
STEP 5 — Vektorisasi TF-IDF:
  from sklearn.feature_extraction.text import TfidfVectorizer
  
  documents = [text_laporan_1, text_laporan_2, ..., text_laporan_N]
  vectorizer = TfidfVectorizer()
  tfidf_matrix = vectorizer.fit_transform(documents)

  Setiap laporan menjadi vektor angka
  Contoh (disederhanakan):
    Laporan A → [0.3, 0.0, 0.8, 0.2, ...]
    Laporan B → [0.3, 0.1, 0.7, 0.2, ...]
        │
        ▼
STEP 6 — Hitung Cosine Similarity (Matrix):
  from sklearn.metrics.pairwise import cosine_similarity
  
  similarity_matrix = cosine_similarity(tfidf_matrix)
  
  Hasilnya adalah matrix N×N:
  
         Laporan A  Laporan B  Laporan C
  Lap A [  1.00,     0.94,      0.45  ]
  Lap B [  0.94,     1.00,      0.32  ]
  Lap C [  0.45,     0.32,      1.00  ]
  
  Diagonal selalu 1.00 (laporan sama diri sendiri)
  Yang diambil: segitiga atas (upper triangle) untuk hindari duplikat
        │
        ▼
STEP 7 — Kirim Hasil ke Supabase DB:
  Untuk setiap pasang (i, j) dengan i < j:
    INSERT INTO kemiripan
    (id, tugas_id, laporan_id_a, laporan_id_b,
     mahasiswa_id_a, mahasiswa_id_b,
     skor_kemiripan, zona, created_at)
    
    Zona ditentukan:
    - skor >= 0.80 → 'merah'
    - skor >= 0.50 → 'kuning'
    - skor <  0.50 → 'hijau'
        │
        ▼
STEP 8 — Update status di DB:
  UPDATE laporan SET extracted_text = [text], is_analyzed = true
  WHERE tugas_id = 'abc123'
  
  UPDATE tugas SET is_analyzed = true
  WHERE id = 'abc123'
        │
        ▼
STEP 9 — Hapus file sementara di /tmp/
        │
        ▼
Return response ke PHP: { "status": "success", "pairs_analyzed": 190 }
```

---

## ALUR 6 — ASPRAK: LIHAT RANKING & VERIFIKASI

```
Asprak buka Dashboard → Pilih Tugas → Klik "Lihat Hasil Kemiripan"
        │
        ▼
GET /api/kemiripan?tugas_id=abc123 ke PHP
        │
        ▼
PHP query tabel `kemiripan`:
  SELECT k.*, 
         m_a.nama AS nama_a, m_b.nama AS nama_b,
         l_a.file_path AS file_a, l_b.file_path AS file_b
  FROM kemiripan k
  JOIN laporan l_a ON k.laporan_id_a = l_a.id
  JOIN laporan l_b ON k.laporan_id_b = l_b.id
  JOIN users m_a ON k.mahasiswa_id_a = m_a.id
  JOIN users m_b ON k.mahasiswa_id_b = m_b.id
  WHERE k.tugas_id = 'abc123'
  ORDER BY k.skor_kemiripan DESC
        │
        ▼
Frontend tampilkan tabel ranking:

┌─────────────────────────────────────────────────────────┐
│  #  │  Mahasiswa A    │  Mahasiswa B    │  Skor │  Zona  │
├─────────────────────────────────────────────────────────┤
│  1  │  Andi (2021001) │  Budi (2021002) │  94%  │  🔴   │
│  2  │  Cici (2021003) │  Dodi (2021004) │  87%  │  🔴   │
│  3  │  Eko  (2021005) │  Fani (2021006) │  72%  │  🟡   │
│  4  │  Gita (2021007) │  Hana (2021008) │  41%  │  🟢   │
└─────────────────────────────────────────────────────────┘
        │
        ▼
Asprak klik "Compare" pada pasangan yang dicurigai
        │
        ▼
GET /api/laporan/compare?a=laporan_id_a&b=laporan_id_b
        │
        ▼
PHP ambil extracted_text kedua laporan dari DB
PHP ambil signed URL file dari Supabase Storage
        │
        ▼
Frontend tampilkan Side-by-Side:

┌───────────────────┬───────────────────┐
│  Laporan Andi     │  Laporan Budi     │
│                   │                   │
│  ...teks yang     │  ...teks yang     │
│  mirip di-        │  mirip di-        │
│  highlight merah  │  highlight merah  │
│                   │                   │
└───────────────────┴───────────────────┘
  Skor kemiripan: 94% 🔴

[Beri Nilai]  [Tandai Plagiat → 0]  [Abaikan]
```

---

## ALUR 7 — ASPRAK: BERI NILAI

```
Asprak isi nilai di form (0-100)
Opsional: tambah catatan/komentar
Opsional: centang "Plagiat → Nilai otomatis 0"
        │
        ▼
POST /api/nilai/submit ke PHP
Body: { laporan_id, nilai, catatan, is_plagiat }
        │
        ▼
PHP validasi:
  - nilai antara 0-100
  - laporan_id valid dan milik kelas asprak ini
        │
        ▼
INSERT / UPDATE tabel `nilai`:
  (id, laporan_id, mahasiswa_id, tugas_id,
   asprak_id, nilai, catatan, is_plagiat,
   dinilai_at)
        │
        ▼
UPDATE tabel `laporan` SET status = 'dinilai'
        │
        ▼
INSERT ke tabel `notifikasi` untuk mahasiswa:
  "Laporan kamu untuk [nama_tugas] telah dinilai: [nilai]"
        │
        ▼
Dashboard asprak update: baris mahasiswa tersebut tampil nilai
```

---

## ALUR 8 — ASPRAK: MATERI & PENGUMUMAN

```
Asprak klik "Tambah Materi" atau "Buat Pengumuman"
        │
        ▼
Isi form:
  - Judul
  - Isi teks (rich text / plain)
  - Lampiran (opsional, PDF/file)
  - Jadwal publish (sekarang / nanti)
  - Kelas tujuan
        │
        ▼
POST /api/materi/create ke PHP
        │
        ▼
Jika ada lampiran:
  Upload ke Supabase Storage
  Bucket: materi-files
  Path: /{kelas_id}/{timestamp}_{filename}
        │
        ▼
INSERT ke tabel `materi`:
  (id, asprak_id, kelas_id, judul, isi,
   lampiran_path, tipe (materi/pengumuman),
   publish_at, created_at)
        │
        ▼
Jika publish_at = sekarang:
  INSERT notifikasi ke semua mahasiswa kelas
        │
        ▼
Tampil di Dashboard Mahasiswa section "Materi & Pengumuman"
diurutkan berdasarkan publish_at terbaru
```


---

## RINGKASAN PERAN SETIAP TEKNOLOGI DALAM ALUR

| Alur | PHP | Python | Supabase DB | Supabase Storage | Railway |
|---|---|---|---|---|---|
| Register/Login | Auth, hash, session | ❌ | Simpan user | ❌ | Host PHP |
| Buat Tugas | CRUD | ❌ | Simpan tugas | ❌ | Host PHP |
| Submit Laporan | Validasi, routing | ❌ | Metadata laporan | Simpan file | Host PHP |
| Analisis Kemiripan | Trigger | Ekstraksi + CS | Simpan hasil | Download file | Host Python |
| Ranking & Compare | Serve data | ❌ | Query hasil | Signed URL | Host PHP |
| Beri Nilai | CRUD | ❌ | Simpan nilai | ❌ | Host PHP |
| Export Excel | Generate xlsx | ❌ | Query semua nilai | ❌ | Host PHP |
