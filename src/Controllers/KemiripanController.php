<?php

namespace App\Controllers;

use App\Config\SupabaseClient;
use App\Middleware\AuthMiddleware;

class KemiripanController {

    public function index(): void {
        try {
            $user = AuthMiddleware::requireRole('asprak');
            $supabase = SupabaseClient::getInstance();
            
            // Asprak hanya melihat kemiripan untuk laporan di kelasnya
            // Simplifikasi untuk frontend saat ini: ambil semua dari db (bisa di-filter by tugas di pengembangan selanjutnya)
            $kemiripan = $supabase->select('kemiripan_vektor', ['order' => 'skor_kemiripan.desc']);
            
            http_response_code(200);
            echo json_encode(["data" => $kemiripan]);
            
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Terjadi kesalahan internal"]);
        }
    }

    public function create(): void {
        http_response_code(501);
        echo json_encode(["error" => "Not Implemented"]);
    }

    public function show(string $id): void {
        http_response_code(501);
        echo json_encode(["error" => "Not Implemented"]);
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
