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
            
            $result = $supabase->insert('tugas', $insertData);
            
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
}
