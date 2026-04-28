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
        http_response_code(501);
        echo json_encode(["error" => "Not Implemented"]);
    }

    public function destroy(string $id): void {
        http_response_code(501);
        echo json_encode(["error" => "Not Implemented"]);
    }
}
