<?php

namespace App\Controllers;

use App\Config\SupabaseClient;
use App\Middleware\AuthMiddleware;

class KemiripanController {

    /**
     * Mendapatkan daftar kemiripan untuk tugas tertentu
     * Diurutkan berdasarkan skor tertinggi
     */
    public function index(): void {
        try {
            $user = AuthMiddleware::requireRole('asprak');
            $supabase = SupabaseClient::getInstance();

            $tugasId = $_GET['tugas_id'] ?? null;

            if (!$tugasId) {
                http_response_code(400);
                echo json_encode(["error" => "tugas_id wajib diisi"]);
                return;
            }

            // Validasi tugas milik kelas asprak
            $tugasList = $supabase->select('tugas', ['id' => 'eq.' . $tugasId]);
            if (empty($tugasList)) {
                http_response_code(404);
                echo json_encode(["error" => "Tugas tidak ditemukan"]);
                return;
            }

            $tugas = $tugasList[0];
            $kelasList = $supabase->select('kelas', [
                'id' => 'eq.' . $tugas['kelas_id'],
                'asprak_id' => 'eq.' . $user['user_id']
            ]);

            if (empty($kelasList)) {
                http_response_code(403);
                echo json_encode(["error" => "Akses ditolak"]);
                return;
            }

            // Ambil data kemiripan
            $kemiripan = $supabase->select('kemiripan', [
                'tugas_id' => 'eq.' . $tugasId,
                'order' => 'skor_kemiripan.desc'
            ]);

            // Enrich dengan data mahasiswa
            foreach ($kemiripan as &$k) {
                $userA = $supabase->select('users', ['id' => 'eq.' . $k['mahasiswa_id_a']], 'nama, nrp_nip')[0] ?? null;
                $userB = $supabase->select('users', ['id' => 'eq.' . $k['mahasiswa_id_b']], 'nama, nrp_nip')[0] ?? null;

                $k['mahasiswa_a'] = $userA;
                $k['mahasiswa_b'] = $userB;
                $k['persentase'] = round($k['skor_kemiripan'] * 100, 2);
            }

            http_response_code(200);
            echo json_encode(["data" => $kemiripan]);

        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Terjadi kesalahan internal"]);
            error_log("KemiripanController::index Exception: " . $e->getMessage());
        }
    }

    /**
     * Menandai pasangan laporan sebagai plagiat (flag)
     */
    public function flag(string $id): void {
        try {
            $user = AuthMiddleware::requireRole('asprak');
            $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

            $supabase = SupabaseClient::getInstance();

            // Validasi kemiripan ada
            $kemiripanList = $supabase->select('kemiripan', ['id' => 'eq.' . $id]);
            if (empty($kemiripanList)) {
                http_response_code(404);
                echo json_encode(["error" => "Data kemiripan tidak ditemukan"]);
                return;
            }

            $kemiripan = $kemiripanList[0];

            // Validasi tugas milik kelas asprak
            $tugasList = $supabase->select('tugas', ['id' => 'eq.' . $kemiripan['tugas_id']]);
            $tugas = $tugasList[0];

            $kelasList = $supabase->select('kelas', [
                'id' => 'eq.' . $tugas['kelas_id'],
                'asprak_id' => 'eq.' . $user['user_id']
            ]);

            if (empty($kelasList)) {
                http_response_code(403);
                echo json_encode(["error" => "Akses ditolak"]);
                return;
            }

            // Update flag dan catatan
            $isFlagged = filter_var($data['is_flagged'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $catatan = $data['asprak_catatan'] ?? '';

            $updateData = [
                'is_flagged' => $isFlagged ? 'true' : 'false',
                'asprak_catatan' => $catatan
            ];

            $result = $supabase->update('kemiripan', $updateData, ['id' => 'eq.' . $id]);

            if ($result === false) {
                http_response_code(500);
                echo json_encode(["error" => "Gagal mengupdate flag"]);
                return;
            }

            http_response_code(200);
            echo json_encode([
                "message" => $isFlagged ? "Ditandai sebagai plagiat" : "Flag plagiat dihapus",
                "data" => isset($result[0]) ? $result[0] : $result
            ]);

        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Terjadi kesalahan sistem"]);
            error_log("KemiripanController::flag Exception: " . $e->getMessage());
        }
    }
}
