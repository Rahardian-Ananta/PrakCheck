<?php

namespace App\Controllers;

use App\Config\SupabaseClient;
use App\Services\PdfExtractor;
use App\Services\DocxExtractor;
use App\Services\CosineSimilarity;
use App\Middleware\AuthMiddleware;

class AnalisisController {

    /**
     * Menjalankan proses ekstraksi teks dan analisis Cosine Similarity
     * 
     * @param string $tugasId
     * @return void
     */
    public function runAnalysis(string $tugasId): void {
        // CATATAN PERFORMA: Mencegah timeout saat memproses banyak laporan
        set_time_limit(300);
        
        try {
            $supabase = SupabaseClient::getInstance();
            
            // STEP 1 — Validasi akses:
            $user = AuthMiddleware::requireRole('asprak');
            $asprakId = $user['user_id'];
            
            // Verifikasi tugas milik kelas asprak yang login
            $tugasList = $supabase->select('tugas', ['id' => 'eq.' . $tugasId]);
            if (empty($tugasList)) {
                http_response_code(404);
                echo json_encode(["error" => "Tugas tidak ditemukan"]);
                return;
            }
            $tugas = $tugasList[0];
            
            $kelasList = $supabase->select('kelas', [
                'id' => 'eq.' . $tugas['kelas_id'],
                'asprak_id' => 'eq.' . $asprakId
            ]);
            
            if (empty($kelasList)) {
                http_response_code(403);
                echo json_encode(["error" => "Akses ditolak"]);
                return;
            }
            
            // STEP 2 — Cek status tugas:
            if ($tugas['is_analyzed'] === true || $tugas['is_analyzed'] === 't') {
                http_response_code(200);
                echo json_encode(["message" => "Tugas sudah pernah dianalisis"]);
                return;
            }
            
            if ($tugas['status'] === 'open') {
                $supabase->update('tugas', ['status' => 'closed'], ['id' => 'eq.' . $tugasId]);
            }
            
            // STEP 3 — Ambil semua laporan yang diterima:
            $laporanList = $supabase->select('laporan', [
                'tugas_id' => 'eq.' . $tugasId,
                'status' => 'in.(diterima,dianalisis)'
            ], 'id, mahasiswa_id, file_path, file_type');
            
            if (count($laporanList) < 2) {
                http_response_code(200);
                echo json_encode([
                    "status" => "skip",
                    "message" => "Kurang dari 2 laporan, analisis dilewati",
                    "total_laporan" => count($laporanList)
                ]);
                return;
            }
            
            // Peta mahasiswa_id untuk digunakan di STEP 6
            $mahasiswaMap = [];
            foreach ($laporanList as $lap) {
                $mahasiswaMap[$lap['id']] = $lap['mahasiswa_id'];
            }
            
            // STEP 4 — Download dan ekstraksi teks semua laporan:
            $documents = [];
            $errors = [];
            
            $pdfExtractor = new PdfExtractor();
            $docxExtractor = new DocxExtractor();
            
            foreach ($laporanList as $laporan) {
                $lapId = $laporan['id'];
                
                // a. Download file dari Supabase Storage:
                $fileContent = $supabase->downloadFile('laporan-files', $laporan['file_path']);
                if ($fileContent === false) {
                    $errors[] = "Gagal download laporan ID: {$lapId}";
                    continue;
                }
                
                // b. Simpan ke file temp:
                $tmpPath = sys_get_temp_dir() . '/' . uniqid('prakcheck_') . '.' . $laporan['file_type'];
                file_put_contents($tmpPath, $fileContent);
                
                // c. Ekstraksi teks sesuai tipe file:
                $text = '';
                if ($laporan['file_type'] === 'pdf') {
                    $text = $pdfExtractor->extractFromPath($tmpPath);
                } elseif ($laporan['file_type'] === 'docx') {
                    $text = $docxExtractor->extractFromPath($tmpPath);
                }
                
                // d. Hapus file temp:
                @unlink($tmpPath);
                
                // e. Jika text kosong:
                if (trim($text) === '') {
                    $errors[] = "Teks kosong atau gagal diekstrak pada laporan ID: {$lapId}";
                    continue;
                }
                
                // f. Simpan:
                $documents[$lapId] = $text;
                $supabase->update('laporan', ['extracted_text' => $text], ['id' => 'eq.' . $lapId]);
            }
            
            if (count($documents) < 2) {
                http_response_code(500);
                echo json_encode([
                    "error" => "Tidak cukup laporan yang bisa diproses",
                    "errors" => $errors
                ]);
                return;
            }
            
            // STEP 5 — Hitung Cosine Similarity:
            $cosineSim = new CosineSimilarity();
            $hasil = $cosineSim->calculate($documents);
            
            // STEP 6 — Simpan hasil ke tabel kemiripan:
            $zonaCount = ['merah' => 0, 'kuning' => 0, 'hijau' => 0];
            
            foreach ($hasil as $pasang) {
                $skor = $pasang['skor'];
                
                if ($skor >= 0.80) {
                    $zona = 'merah';
                } elseif ($skor >= 0.50) {
                    $zona = 'kuning';
                } else {
                    $zona = 'hijau';
                }
                
                $zonaCount[$zona]++;
                
                $laporanIdA = $pasang['laporan_id_a'];
                $laporanIdB = $pasang['laporan_id_b'];
                
                $mahasiswaIdA = $mahasiswaMap[$laporanIdA] ?? null;
                $mahasiswaIdB = $mahasiswaMap[$laporanIdB] ?? null;
                
                $supabase->insert('kemiripan', [
                    'tugas_id'       => $tugasId,
                    'laporan_id_a'   => $laporanIdA,
                    'laporan_id_b'   => $laporanIdB,
                    'mahasiswa_id_a' => $mahasiswaIdA,
                    'mahasiswa_id_b' => $mahasiswaIdB,
                    'skor_kemiripan' => $skor,
                    'zona'           => $zona
                ]);
            }
            
            // STEP 7 — Update status semua laporan yang berhasil diproses:
            foreach ($documents as $laporan_id => $text) {
                $supabase->update('laporan', [
                    'status' => 'dianalisis',
                    'is_analyzed' => true
                ], ['id' => 'eq.' . $laporan_id]);
            }
            
            // STEP 8 — Update status tugas:
            $supabase->update('tugas', [
                'is_analyzed' => true,
                'analyzed_at' => date('c'),
                'status' => 'analyzed'
            ], ['id' => 'eq.' . $tugasId]);
            
            // STEP 9 — Return hasil:
            http_response_code(200);
            echo json_encode([
                "status" => "success",
                "tugas_id" => $tugasId,
                "total_laporan" => count($documents),
                "total_pasang" => count($hasil),
                "zona_merah" => $zonaCount['merah'],
                "zona_kuning" => $zonaCount['kuning'],
                "zona_hijau" => $zonaCount['hijau'],
                "errors" => $errors
            ]);
            
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                "error" => "Terjadi kesalahan pada server saat menjalankan analisis",
                "message" => $e->getMessage()
            ]);
        }
    }
}
