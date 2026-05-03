<?php

namespace App\Controllers;

use App\Config\SupabaseClient;
use App\Middleware\AuthMiddleware;

class LaporanController {

    /**
     * Upload laporan dengan validasi ketat
     */
    public function upload(): void {
        try {
            // STEP 1 — Auth dan ambil user
            $user = AuthMiddleware::requireRole('mahasiswa');
            $mahasiswaId = $user['user_id'];
            
            $tugasId = $_POST['tugas_id'] ?? null;
            if (!$tugasId) {
                http_response_code(400);
                echo json_encode(["error" => "tugas_id wajib diisi"]);
                return;
            }

// STEP 2 — Cek apakah ada file ATAU submit tanpa file (tanpa lampiran)
            $hasFile = isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK;
            $submitType = $_POST['submit_type'] ?? 'upload'; // 'upload' atau 'selesai'
            
            //Kalau kosong semua berarti invalid
            if (!$hasFile && $submitType !== 'selesai') {
                // User submit tanpa file dan bukan mode "selesai"
                http_response_code(400);
                echo json_encode(["error" => "Pilih file untuk diupload atau klik 'Selesai tanpa file'"]);
                return;
            }
            
            $supabase = SupabaseClient::getInstance();

            // STEP 3 — Ambil data tugas
            $tugasList = $supabase->select('tugas', ['id' => 'eq.' . $tugasId]);
            $tugas = $tugasList[0] ?? null;
            
            if (!$tugas) {
                http_response_code(404);
                echo json_encode(["error" => "Tugas tidak ditemukan"]);
                return;
            }
            if ($tugas['status'] !== 'open') {
                http_response_code(422);
                echo json_encode(["error" => "Tugas sudah ditutup, tidak bisa submit"]);
                return;
            }

            // STEP 3b — CEK existing laporan (mencegah spam upload)
            $existingLaporan = $supabase->select('laporan', [
                'mahasiswa_id' => 'eq.' . $mahasiswaId,
                'tugas_id' => 'eq.' . $tugasId
            ]);
            $existing = $existingLaporan[0] ?? null;
            if ($existing && in_array($existing['status'], ['diterima', 'dicancel'])) {
                http_response_code(422);
                echo json_encode(["error" => "Anda sudah upload untuk tugas ini. Status: " . $existing['status']]);
                return;
            }

            // STEP 4 — Validasi file (berurutan)
            
            // Jika ada file, siapkan metadata yang diperlukan
            if ($hasFile) {
                $originalName = $_FILES['file']['name'];
                $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                $mime = mime_content_type($_FILES['file']['tmp_name']);
                $fileType = $ext ?: 'pdf';
            }

            // 4a. DEADLINE
            if (time() > strtotime($tugas['deadline'])) {
                // Jika ada file, catat sebagai terlambat; jika tidak ada file tetap bisa submit tanpa file
                if ($hasFile) {
                    $supabase->insert('laporan', [
                        'mahasiswa_id' => $mahasiswaId,
                        'tugas_id'     => $tugasId,
                        'file_name'    => $_FILES['file']['name'],
                        'file_path'    => '',
                        'file_size_kb' => 0,
                        'file_type'    => 'pdf',
                        'status'       => 'ditolak',
                        'alasan_tolak' => 'terlambat'
                    ]);
                    
                    $supabase->insert('notifikasi', [
                        'user_id' => $mahasiswaId,
                        'judul'   => 'Laporan Ditolak',
                        'pesan'   => 'Laporan kamu untuk "' . $tugas['nama_tugas'] . '" ditolak: pengumpulan sudah terlambat',
                        'tipe'    => 'laporan_ditolak'
                    ]);
                    
                    http_response_code(422);
                    echo json_encode(["error" => "Batas waktu sudah lewat", "alasan" => "terlambat"]);
                    return;
                } else {
                    // Submit tanpa file tapi deadline sudah lewat - bisa dianggap selesai tanpa nilai
                    $laporanResult = $supabase->insert('laporan', [
                        'mahasiswa_id' => $mahasiswaId,
                        'tugas_id'     => $tugasId,
                        'file_name'    => '[Tanpa File]',
                        'file_path'    => '',
                        'file_size_kb' => 0,
                        'file_type'    => 'tanpa',
                        'status'       => 'diterima'
                    ]);
                    
                    $supabase->insert('notifikasi', [
                        'user_id' => $mahasiswaId,
                        'judul'   => 'Laporan Diterima',
                        'pesan'   => 'Laporanmu untuk "' . $tugas['nama_tugas'] . '" diterima (tanpa file)',
                        'tipe'    => 'laporan_diterima'
                    ]);
                    
                    http_response_code(201);
                    echo json_encode(["message" => "Tugas ditandai selesai tanpa upload file", "data" => isset($laporanResult[0]) ? $laporanResult[0] : $laporanResult]);
return;
                }
            }
            
            // 4c. NAMA FILE (hanya jika ada file)
            if ($hasFile && !empty($tugas['konvensi_regex'])) {
                if (!preg_match('/' . $tugas['konvensi_regex'] . '/', $originalName)) {
                    $supabase->insert('laporan', [
                        'mahasiswa_id' => $mahasiswaId,
                        'tugas_id'     => $tugasId,
                        'file_name'    => $originalName,
                        'file_path'    => '',
                        'file_size_kb' => 0,
                        'file_type'    => $fileType,
                        'status'       => 'ditolak',
                        'alasan_tolak' => 'nama_salah'
                    ]);
                    
                    $supabase->insert('notifikasi', [
                        'user_id' => $mahasiswaId,
                        'judul'   => 'Laporan Ditolak',
                        'pesan'   => 'Laporan kamu untuk "' . $tugas['nama_tugas'] . '" ditolak: nama file tidak sesuai format. Contoh: ' . $tugas['konvensi_nama'],
                        'tipe'    => 'laporan_ditolak'
                    ]);
                    
                    http_response_code(422);
                    echo json_encode([
                        "error" => "Nama file tidak sesuai format",
                        "alasan" => "nama_salah",
                        "format_yang_benar" => $tugas['konvensi_nama']
                    ]);
                    return;
                }
            }

// 4d. DUPLIKAT (hanya jika ada file)
            if ($hasFile) {
                $existing = $supabase->select('laporan', [
                    'mahasiswa_id' => 'eq.' . $mahasiswaId,
                    'tugas_id'     => 'eq.' . $tugasId,
                    'status'       => 'neq.ditolak'
                ]);
                if (count($existing) > 0) {
                    http_response_code(422);
                    echo json_encode([
                        "error" => "Kamu sudah mengumpulkan laporan untuk tugas ini",
                        "alasan" => "duplikat"
                    ]);
                    return;
                }
            }
            
            // 4e. UKURAN FILE (hanya jika ada file)
            if ($hasFile) {
                $maxBytes = $tugas['max_ukuran_mb'] * 1024 * 1024;
                if ($_FILES['file']['size'] > $maxBytes) {
                http_response_code(422);
                echo json_encode([
                    "error" => "Ukuran file melebihi batas " . $tugas['max_ukuran_mb'] . "MB"
                ]);
                return;
            }
            }

            // STEP 5 — Jika ada file: Upload dan simpan
            if ($hasFile) {
                $kelasId = $tugas['kelas_id'];
                $storagePath = $kelasId . '/' . $tugasId . '/' . $mahasiswaId . '/' . $originalName;
                
                $uploadResult = $supabase->uploadFile('laporan-files', $storagePath, $_FILES['file']['tmp_name'], $mime);
                if ($uploadResult === false) {
                    $errorMsg = "Gagal upload ke Supabase Storage. ";
                    $errorMsg .= "Cek: 1) Bucket 'laporan-files' sudah dibuat di Supabase? ";
                    $errorMsg .= "2) SUPABASE_SERVICE_KEY benar? ";
                    $errorMsg .= "3) Path: {$storagePath}";
                    error_log("LaporanController::upload - Upload failed: " . $errorMsg);
                    http_response_code(500);
                    echo json_encode(["error" => $errorMsg]);
                    return;
                }
                
                $fileSizeKb = (int) ceil($_FILES['file']['size'] / 1024);
                
                $laporanResult = $supabase->insert('laporan', [
                    'mahasiswa_id' => $mahasiswaId,
                    'tugas_id'     => $tugasId,
                    'file_name'    => $originalName,
                    'file_path'    => $storagePath,
                    'file_size_kb' => $fileSizeKb,
                    'file_type'    => $fileType,
                    'status'       => 'diterima'
                ]);
            }
            
            $laporan = isset($laporanResult[0]) ? $laporanResult[0] : $laporanResult;
            
            $supabase->insert('notifikasi', [
                'user_id' => $mahasiswaId,
                'judul'   => 'Laporan Diterima',
                'pesan'   => 'Laporan kamu untuk "' . $tugas['nama_tugas'] . '" berhasil diterima',
                'tipe'    => 'laporan_diterima'
            ]);
            
            http_response_code(201);
            echo json_encode([
                "message" => "Laporan berhasil dikumpulkan",
                "laporan" => $laporan
            ]);
            
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Terjadi kesalahan internal server"]);
            error_log("LaporanController::upload Exception: " . $e->getMessage());
        }
    }

    /**
     * Mendapatkan daftar laporan
     */
    public function index(): void {
        try {
            $user = AuthMiddleware::check();
            $tugasId = $_GET['tugas_id'] ?? null;
            
            $filters = [];
            if ($tugasId) {
                $filters['tugas_id'] = 'eq.' . $tugasId;
            }
            
            // Mahasiswa hanya bisa melihat laporannya sendiri
            if ($user['role'] === 'mahasiswa') {
                $filters['mahasiswa_id'] = 'eq.' . $user['user_id'];
            }
            
            $supabase = SupabaseClient::getInstance();
            $laporan = $supabase->select('laporan', $filters);
            
            http_response_code(200);
            echo json_encode(["data" => $laporan]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Terjadi kesalahan sistem"]);
        }
    }

    /**
     * Melihat detail laporan tunggal
     */
    public function show(string $id): void {
        try {
            $user = AuthMiddleware::check();
            $supabase = SupabaseClient::getInstance();
            
            $laporanList = $supabase->select('laporan', ['id' => 'eq.' . $id]);
            if (empty($laporanList)) {
                http_response_code(404);
                echo json_encode(["error" => "Laporan tidak ditemukan"]);
                return;
            }
            
            $laporan = $laporanList[0];
            
            if ($user['role'] === 'mahasiswa' && $laporan['mahasiswa_id'] !== $user['user_id']) {
                http_response_code(403);
                echo json_encode(["error" => "Akses ditolak"]);
                return;
            }
            
            http_response_code(200);
            echo json_encode(["data" => $laporan]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Terjadi kesalahan sistem"]);
        }
    }

    /**
     * Menyiapkan data komparasi antara dua laporan (khusus asprak)
     */
    public function compare(): void {
        try {
            AuthMiddleware::requireRole('asprak');

            $idA = $_GET['a'] ?? null;
            $idB = $_GET['b'] ?? null;

            if (!$idA || !$idB) {
                http_response_code(400);
                echo json_encode(["error" => "Parameter a dan b wajib diisi"]);
                return;
            }

            $supabase = SupabaseClient::getInstance();

            $lapA = $supabase->select('laporan', ['id' => 'eq.' . $idA])[0] ?? null;
            $lapB = $supabase->select('laporan', ['id' => 'eq.' . $idB])[0] ?? null;

            if (!$lapA || !$lapB) {
                http_response_code(404);
                echo json_encode(["error" => "Laporan tidak ditemukan"]);
                return;
            }

            // Buat signed URL
            $lapA['signed_url'] = $supabase->getSignedUrl('laporan-files', $lapA['file_path']);
            $lapB['signed_url'] = $supabase->getSignedUrl('laporan-files', $lapB['file_path']);

            // Ambil info mahasiswa
            $userA = $supabase->select('users', ['id' => 'eq.' . $lapA['mahasiswa_id']], 'nama, nrp_nip')[0] ?? null;
            $userB = $supabase->select('users', ['id' => 'eq.' . $lapB['mahasiswa_id']], 'nama, nrp_nip')[0] ?? null;

            if ($userA) $lapA['user'] = $userA;
            if ($userB) $lapB['user'] = $userB;

            http_response_code(200);
            echo json_encode([
                "laporan_a" => $lapA,
                "laporan_b" => $lapB
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Terjadi kesalahan sistem"]);
        }
    }

    /**
     * Membatalkan/menghapus laporan yang sudah disubmit (khusus mahasiswa)
     * Hanya bisa dilakukan jika deadline belum lewat
     */
    public function cancel(string $id): void {
        try {
            $user = AuthMiddleware::requireRole('mahasiswa');
            $supabase = SupabaseClient::getInstance();

            // Validasi laporan milik mahasiswa
            $laporanList = $supabase->select('laporan', [
                'id' => 'eq.' . $id,
                'mahasiswa_id' => 'eq.' . $user['user_id']
            ]);

            if (empty($laporanList)) {
                http_response_code(404);
                echo json_encode(["error" => "Laporan tidak ditemukan"]);
                return;
            }

            $laporan = $laporanList[0];

            // Cek status laporan
            if ($laporan['status'] === 'ditolak') {
                http_response_code(422);
                echo json_encode(["error" => "Laporan yang ditolak tidak perlu dibatalkan"]);
                return;
            }

            if ($laporan['status'] === 'dinilai') {
                http_response_code(422);
                echo json_encode(["error" => "Laporan yang sudah dinilai tidak bisa dibatalkan"]);
                return;
            }

            // Ambil data tugas untuk cek deadline
            $tugasList = $supabase->select('tugas', ['id' => 'eq.' . $laporan['tugas_id']]);
            if (empty($tugasList)) {
                http_response_code(404);
                echo json_encode(["error" => "Tugas tidak ditemukan"]);
                return;
            }

            $tugas = $tugasList[0];

            // Cek apakah deadline sudah lewat
            if (time() > strtotime($tugas['deadline'])) {
                http_response_code(422);
                echo json_encode(["error" => "Tidak bisa membatalkan laporan, deadline sudah lewat"]);
                return;
            }

            // Cek apakah tugas sudah dianalisis
            if ($tugas['is_analyzed'] === true || $tugas['is_analyzed'] === 't') {
                http_response_code(422);
                echo json_encode(["error" => "Tidak bisa membatalkan laporan, tugas sudah dianalisis"]);
                return;
            }

            // Jangan hapus file saat batalkan — hanya ubah status jadi 'dicancel'
            $result = $supabase->update('laporan', ['status' => 'dicancel'], ['id' => 'eq.' . $id]);

            if ($result === false) {
                http_response_code(500);
                echo json_encode(["error" => "Gagal membatalkan laporan"]);
                return;
            }

            // Kirim notifikasi
            $supabase->insert('notifikasi', [
                'user_id' => $user['user_id'],
                'judul' => 'Laporan Dibatalkan',
                'pesan' => 'Laporan kamu untuk "' . $tugas['nama_tugas'] . '" berhasil dibatalkan. Kamu bisa mengumpulkan ulang sebelum deadline.',
                'tipe' => 'laporan_ditolak'
            ]);

            http_response_code(200);
            echo json_encode(["message" => "Laporan berhasil dibatalkan. Kamu bisa mengumpulkan ulang."]);

        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Terjadi kesalahan sistem"]);
            error_log("LaporanController::cancel Exception: " . $e->getMessage());
        }
    }

    /**
     * Hapus laporan permanen (bukan cancel)
     */
    public function destroy(string $id): void {
        try {
            $user = AuthMiddleware::requireRole('mahasiswa');
            $supabase = SupabaseClient::getInstance();
            
            $laporanList = $supabase->select('laporan', [
                'id' => 'eq.' . $id,
                'mahasiswa_id' => 'eq.' . $user['user_id']
            ]);
            
            if (empty($laporanList)) {
                http_response_code(404);
                echo json_encode(["error" => "Laporan tidak ditemukan"]);
                return;
            }
            
            $laporan = $laporanList[0];
            
            // Cek apakah sudah dinilai
            if ($laporan['status'] === 'dinilai') {
                http_response_code(422);
                echo json_encode(["error" => "Laporan yang sudah dinilai tidak bisa dihapus"]);
                return;
            }
            
            // Hapus file dari storage
            if (!empty($laporan['file_path'])) {
                $supabase->deleteFile('laporan-files', $laporan['file_path']);
            }
            
            // Hapus permanen dari database
            $result = $supabase->delete('laporan', ['id' => 'eq.' . $id]);
            
            if ($result === false) {
                http_response_code(500);
                echo json_encode(["error" => "Gagal menghapus laporan"]);
                return;
            }
            
            http_response_code(200);
            echo json_encode(["message" => "Laporan dihapus"]);
            
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Terjadi kesalahan sistem"]);
            error_log("LaporanController::destroy Exception: " . $e->getMessage());
        }
    }
}
