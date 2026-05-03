<?php

namespace App\Controllers;

use App\Config\SupabaseClient;
use App\Middleware\AuthMiddleware;

class TugasController {

    public function index(): void {
        try {
            $user = AuthMiddleware::check();
            $supabase = SupabaseClient::getInstance();
            
            $filters = [];
            
            if ($user['role'] === 'asprak') {
                $filters['asprak_id'] = 'eq.' . $user['user_id'];
                $filters['order'] = 'created_at.desc';
                $tugas = $supabase->select('tugas', $filters);
            } else {
                // Mahasiswa: cari kelas yang diikuti
                $kelasIkut = $supabase->select('kelas_mahasiswa', ['mahasiswa_id' => 'eq.' . $user['user_id']]);
                if (empty($kelasIkut)) {
                    $tugas = [];
                } else {
                    $kelasIds = array_column($kelasIkut, 'kelas_id');
                    $tugas = $supabase->select('tugas', [
                        'kelas_id' => 'in.(' . implode(',', $kelasIds) . ')',
                        'order' => 'created_at.desc'
                    ]);
                }
            }
            
            http_response_code(200);
            echo json_encode(["data" => $tugas]);
            
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Terjadi kesalahan internal"]);
        }
    }

    public function create(): void {
        try {
            $user = AuthMiddleware::requireRole('asprak');
            $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            
            $required = ['nama_tugas', 'deadline', 'kelas_id', 'format_diizinkan'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    http_response_code(400);
                    echo json_encode(["error" => "{$field} wajib diisi"]);
                    return;
                }
            }
            
            $supabase = SupabaseClient::getInstance();
            
            // Verifikasi kelas milik asprak
            $kelas = $supabase->select('kelas', ['id' => 'eq.' . $data['kelas_id'], 'asprak_id' => 'eq.' . $user['user_id']]);
            if (empty($kelas)) {
                http_response_code(403);
                echo json_encode(["error" => "Kelas tidak valid atau akses ditolak"]);
                return;
            }
            
            $lampiranPath = null;
            $lampiranName = null;
            
            // Handle lampiran upload (opsional)
            if (isset($_FILES['lampiran']) && $_FILES['lampiran']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['lampiran'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $mime = mime_content_type($file['tmp_name']);
                
                // Validasi: hanya PDF, JPG, PNG
                if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'])) {
                    http_response_code(400);
                    echo json_encode(["error" => "Format lampiran tidak diizinkan. Hanya PDF, JPG, PNG"]);
                    return;
                }
                
                if ($file['size'] > 50 * 1024 * 1024) {
                    http_response_code(400);
                    echo json_encode(["error" => "Ukuran lampiran maksimal 50MB"]);
                    return;
                }
                
                $timestamp = time();
                $storagePath = $data['kelas_id'] . '/tugas/' . $timestamp . '_' . $file['name'];
                $uploadResult = $supabase->uploadFile('tugas-files', $storagePath, $file['tmp_name'], $mime);
                
                if ($uploadResult === false) {
                    http_response_code(500);
                    echo json_encode(["error" => "Gagal upload lampiran"]);
                    return;
                }
                
                $lampiranPath = $storagePath;
                $lampiranName = $file['name'];
            }
            
            $insertData = [
                'asprak_id' => $user['user_id'],
                'kelas_id' => $data['kelas_id'],
                'nama_tugas' => $data['nama_tugas'],
                'deskripsi' => $data['deskripsi'] ?? '',
                'deadline' => $data['deadline'],
                'format_diizinkan' => $data['format_diizinkan'],
                'konvensi_regex' => $data['konvensi_regex'] ?? '',
                'konvensi_nama' => $data['konvensi_nama'] ?? '',
                'status' => 'open'
            ];
            
            if ($lampiranPath) {
                $insertData['lampiran_path'] = $lampiranPath;
                $insertData['lampiran_name'] = $lampiranName;
            }
            
            $result = $supabase->insert('tugas', $insertData);
            error_log('TugasController::create - uploadResult: ' . var_export($uploadResult ?? null, true));
            error_log('TugasController::create - insert result: ' . var_export($result, true));
            
            http_response_code(201);
            echo json_encode(["message" => "Tugas berhasil dibuat", "data" => isset($result[0]) ? $result[0] : $result]);
            
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Terjadi kesalahan sistem"]);
        }
    }

    public function show(string $id): void {
        try {
            $user = AuthMiddleware::check();
            $supabase = SupabaseClient::getInstance();
            
            $tugasList = $supabase->select('tugas', ['id' => 'eq.' . $id]);
            if (empty($tugasList)) {
                http_response_code(404);
                echo json_encode(["error" => "Tugas tidak ditemukan"]);
                return;
            }
            
            http_response_code(200);
            echo json_encode(["data" => $tugasList[0]]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Terjadi kesalahan sistem"]);
        }
    }

    public function update(string $id): void {
        try {
            $user = AuthMiddleware::requireRole('asprak');
            $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

            $supabase = SupabaseClient::getInstance();

            // Validasi tugas milik asprak
            $tugasList = $supabase->select('tugas', ['id' => 'eq.' . $id]);
            if (empty($tugasList)) {
                http_response_code(404);
                echo json_encode(["error" => "Tugas tidak ditemukan"]);
                return;
            }

            $tugas = $tugasList[0];

            // Cek kelas milik asprak
            $kelasList = $supabase->select('kelas', [
                'id' => 'eq.' . $tugas['kelas_id'],
                'asprak_id' => 'eq.' . $user['user_id']
            ]);

            if (empty($kelasList)) {
                http_response_code(403);
                echo json_encode(["error" => "Akses ditolak, tugas ini bukan milik Anda"]);
                return;
            }

            // Cek apakah tugas sudah dianalisis
            if ($tugas['is_analyzed'] === true || $tugas['is_analyzed'] === 't') {
                http_response_code(422);
                echo json_encode(["error" => "Tugas yang sudah dianalisis tidak bisa diubah"]);
                return;
            }

            // Siapkan data update
            $updateData = [];

            if (isset($data['nama_tugas'])) $updateData['nama_tugas'] = $data['nama_tugas'];
            if (isset($data['deskripsi'])) $updateData['deskripsi'] = $data['deskripsi'];
            if (isset($data['deadline'])) $updateData['deadline'] = $data['deadline'];
            if (isset($data['format_diizinkan'])) $updateData['format_diizinkan'] = $data['format_diizinkan'];
            if (isset($data['konvensi_nama'])) $updateData['konvensi_nama'] = $data['konvensi_nama'];
            if (isset($data['konvensi_regex'])) $updateData['konvensi_regex'] = $data['konvensi_regex'];
            if (isset($data['max_ukuran_mb'])) $updateData['max_ukuran_mb'] = $data['max_ukuran_mb'];

            if (empty($updateData)) {
                http_response_code(400);
                echo json_encode(["error" => "Tidak ada data yang diupdate"]);
                return;
            }

            $updateData['updated_at'] = date('c');

            // Update tugas
            $result = $supabase->update('tugas', $updateData, ['id' => 'eq.' . $id]);

            if ($result === false) {
                http_response_code(500);
                echo json_encode(["error" => "Gagal mengupdate tugas"]);
                return;
            }

            http_response_code(200);
            echo json_encode([
                "message" => "Tugas berhasil diupdate",
                "data" => isset($result[0]) ? $result[0] : $result
            ]);

        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Terjadi kesalahan sistem"]);
            error_log("TugasController::update Exception: " . $e->getMessage());
        }
    }

    public function destroy(string $id): void {
        try {
            $user = AuthMiddleware::requireRole('asprak');
            $supabase = SupabaseClient::getInstance();

            // Validasi tugas milik asprak
            $tugasList = $supabase->select('tugas', ['id' => 'eq.' . $id]);
            if (empty($tugasList)) {
                http_response_code(404);
                echo json_encode(["error" => "Tugas tidak ditemukan"]);
                return;
            }

            $tugas = $tugasList[0];

            // Cek kelas milik asprak
            $kelasList = $supabase->select('kelas', [
                'id' => 'eq.' . $tugas['kelas_id'],
                'asprak_id' => 'eq.' . $user['user_id']
            ]);

            if (empty($kelasList)) {
                http_response_code(403);
                echo json_encode(["error" => "Akses ditolak, tugas ini bukan milik Anda"]);
                return;
            }

            // Cek apakah ada laporan yang sudah disubmit
            $laporanList = $supabase->select('laporan', ['tugas_id' => 'eq.' . $id]);

            if (!empty($laporanList)) {
                http_response_code(422);
                echo json_encode([
                    "error" => "Tugas tidak bisa dihapus karena sudah ada " . count($laporanList) . " laporan yang disubmit",
                    "total_laporan" => count($laporanList)
                ]);
                return;
            }

            // Hapus tugas
            $result = $supabase->delete('tugas', ['id' => 'eq.' . $id]);

            if ($result === false) {
                http_response_code(500);
                echo json_encode(["error" => "Gagal menghapus tugas"]);
                return;
            }

            http_response_code(200);
            echo json_encode(["message" => "Tugas berhasil dihapus"]);

        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Terjadi kesalahan sistem"]);
            error_log("TugasController::destroy Exception: " . $e->getMessage());
        }
    }

    public function downloadLampiran(string $id): void {
        try {
            AuthMiddleware::check();
            $supabase = SupabaseClient::getInstance();
            
            $tugasList = $supabase->select('tugas', ['id' => 'eq.' . $id]);
            if (empty($tugasList)) {
                http_response_code(404);
                echo json_encode(["error" => "Tugas tidak ditemukan"]);
                return;
            }
            
            $tugas = $tugasList[0];
            
            if (empty($tugas['lampiran_path'])) {
                http_response_code(404);
                echo json_encode(["error" => "Tidak ada lampiran"]);
                return;
            }
            
            $fileContent = $supabase->downloadFile('tugas-files', $tugas['lampiran_path']);
            if ($fileContent === false) {
                http_response_code(500);
                echo json_encode(["error" => "Gagal download lampiran"]);
                return;
            }
            
            // Detect MIME type dari extension
            $ext = strtolower(pathinfo($tugas['lampiran_name'], PATHINFO_EXTENSION));
            $mimeTypes = [
                'pdf' => 'application/pdf',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png'
            ];
            $mime = $mimeTypes[$ext] ?? 'application/octet-stream';
            
            header('Content-Type: ' . $mime);
            // Untuk image/pdf kita sajikan inline supaya <img> atau <iframe> dapat menampilkannya
            $inlineTypes = ['image/jpeg','image/png','application/pdf'];
            if (in_array($mime, $inlineTypes)) {
                header('Content-Disposition: inline; filename="' . $tugas['lampiran_name'] . '"');
            } else {
                header('Content-Disposition: attachment; filename="' . $tugas['lampiran_name'] . '"');
            }
            header('Content-Length: ' . strlen($fileContent));
            echo $fileContent;
            
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Terjadi kesalahan sistem"]);
            error_log("TugasController::downloadLampiran Exception: " . $e->getMessage());
        }
    }
}
