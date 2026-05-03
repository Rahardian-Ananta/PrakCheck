<?php

namespace App\Controllers;

use App\Config\SupabaseClient;
use App\Middleware\AuthMiddleware;

class KelasController {

    /**
     * Mendapatkan daftar kelas
     * - Asprak: kelas yang dia kelola
     * - Mahasiswa: kelas yang dia ikuti
     */
    public function index(): void {
        try {
            $user = AuthMiddleware::check();
            $supabase = SupabaseClient::getInstance();

            if ($user['role'] === 'asprak') {
                // Asprak melihat kelas yang dia kelola
                $kelas = $supabase->select('kelas', [
                    'asprak_id' => 'eq.' . $user['user_id'],
                    'order' => 'created_at.desc'
                ]);
            } else {
                // Mahasiswa melihat kelas yang dia ikuti
                $kelasIkut = $supabase->select('kelas_mahasiswa', [
                    'mahasiswa_id' => 'eq.' . $user['user_id']
                ]);

                if (empty($kelasIkut)) {
                    $kelas = [];
                } else {
                    $kelasIds = array_column($kelasIkut, 'kelas_id');
                    $kelas = $supabase->select('kelas', [
                        'id' => 'in.(' . implode(',', $kelasIds) . ')',
                        'order' => 'created_at.desc'
                    ]);
                }
            }

            http_response_code(200);
            echo json_encode(["data" => $kelas]);

        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Terjadi kesalahan sistem"]);
            error_log("KelasController::index Exception: " . $e->getMessage());
        }
    }

    /**
     * Membuat kelas baru (khusus asprak)
     */
    public function create(): void {
        try {
            $user = AuthMiddleware::requireRole('asprak');
            $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

            // Validasi field wajib
            $required = ['nama_kelas', 'kode_kelas', 'mata_kuliah', 'semester', 'tahun_ajaran'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    http_response_code(400);
                    echo json_encode(["error" => "{$field} wajib diisi"]);
                    return;
                }
            }

            $supabase = SupabaseClient::getInstance();

            // Cek duplikat kode_kelas
            $existing = $supabase->select('kelas', ['kode_kelas' => 'eq.' . $data['kode_kelas']]);
            if (!empty($existing)) {
                http_response_code(409);
                echo json_encode(["error" => "Kode kelas sudah digunakan"]);
                return;
            }

            // Insert kelas baru
            $insertData = [
                'asprak_id' => $user['user_id'],
                'nama_kelas' => $data['nama_kelas'],
                'kode_kelas' => $data['kode_kelas'],
                'mata_kuliah' => $data['mata_kuliah'],
                'semester' => $data['semester'],
                'tahun_ajaran' => $data['tahun_ajaran'],
                'is_active' => true
            ];

            $result = $supabase->insert('kelas', $insertData);

            if ($result === false) {
                http_response_code(500);
                echo json_encode(["error" => "Gagal membuat kelas"]);
                return;
            }

            http_response_code(201);
            echo json_encode([
                "message" => "Kelas berhasil dibuat",
                "data" => isset($result[0]) ? $result[0] : $result
            ]);

        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Terjadi kesalahan sistem"]);
            error_log("KelasController::create Exception: " . $e->getMessage());
        }
    }

    /**
     * Mahasiswa bergabung ke kelas dengan kode kelas (bukan ID)
     * POST /api/kelas/join dengan body: {"kode_kelas": "ALG-2025-A"}
     */
    public function join(string $id = null): void {
        try {
            $user = AuthMiddleware::requireRole('mahasiswa');
            $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $supabase = SupabaseClient::getInstance();

            // Ambil kode_kelas dari body request
            $kodeKelas = $data['kode_kelas'] ?? null;

            if (!$kodeKelas) {
                http_response_code(400);
                echo json_encode(["error" => "kode_kelas wajib diisi"]);
                return;
            }

            // Cari kelas berdasarkan kode_kelas
            $kelasList = $supabase->select('kelas', [
                'kode_kelas' => 'eq.' . $kodeKelas,
                'is_active' => 'eq.true'
            ]);

            if (empty($kelasList)) {
                http_response_code(404);
                echo json_encode(["error" => "Kode kelas tidak ditemukan atau kelas tidak aktif"]);
                return;
            }

            $kelas = $kelasList[0];
            $kelasId = $kelas['id'];

            // Cek apakah sudah terdaftar
            $existing = $supabase->select('kelas_mahasiswa', [
                'kelas_id' => 'eq.' . $kelasId,
                'mahasiswa_id' => 'eq.' . $user['user_id']
            ]);

            if (!empty($existing)) {
                http_response_code(409);
                echo json_encode(["error" => "Kamu sudah terdaftar di kelas ini"]);
                return;
            }

            // Insert ke kelas_mahasiswa
            $result = $supabase->insert('kelas_mahasiswa', [
                'kelas_id' => $kelasId,
                'mahasiswa_id' => $user['user_id']
            ]);

            if ($result === false) {
                http_response_code(500);
                echo json_encode(["error" => "Gagal bergabung ke kelas"]);
                return;
            }

            // Kirim notifikasi
            $supabase->insert('notifikasi', [
                'user_id' => $user['user_id'],
                'judul' => 'Berhasil Bergabung',
                'pesan' => 'Kamu berhasil bergabung ke kelas "' . $kelas['nama_kelas'] . '" (' . $kelas['kode_kelas'] . ')',
                'tipe' => 'pengumuman_baru'
            ]);

            http_response_code(201);
            echo json_encode([
                "message" => "Berhasil bergabung ke kelas",
                "data" => $kelas
            ]);

        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Terjadi kesalahan sistem"]);
            error_log("KelasController::join Exception: " . $e->getMessage());
        }
    }
}
