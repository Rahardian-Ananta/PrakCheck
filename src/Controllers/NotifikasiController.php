<?php

namespace App\Controllers;

use App\Config\SupabaseClient;
use App\Middleware\AuthMiddleware;

class NotifikasiController {

    /**
     * Mendapatkan daftar notifikasi untuk user yang login
     */
    public function index(): void {
        try {
            $user = AuthMiddleware::check();
            $supabase = SupabaseClient::getInstance();

            // Ambil parameter query
            $limit = $_GET['limit'] ?? '50';
            $isRead = $_GET['is_read'] ?? null;

            $filters = [
                'user_id' => 'eq.' . $user['user_id'],
                'order' => 'created_at.desc',
                'limit' => $limit
            ];

            // Filter berdasarkan status baca jika diberikan
            if ($isRead !== null) {
                $filters['is_read'] = 'eq.' . ($isRead === 'true' ? 'true' : 'false');
            }

            $notifikasi = $supabase->select('notifikasi', $filters);

            // Hitung jumlah notifikasi yang belum dibaca
            $unreadCount = $supabase->select('notifikasi', [
                'user_id' => 'eq.' . $user['user_id'],
                'is_read' => 'eq.false'
            ]);

            http_response_code(200);
            echo json_encode([
                "data" => $notifikasi,
                "unread_count" => count($unreadCount)
            ]);

        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Terjadi kesalahan sistem"]);
            error_log("NotifikasiController::index Exception: " . $e->getMessage());
        }
    }

    /**
     * Menandai semua notifikasi sebagai sudah dibaca
     */
    public function readAll(): void {
        try {
            $user = AuthMiddleware::check();
            $supabase = SupabaseClient::getInstance();

            // Update semua notifikasi user menjadi is_read = true
            $result = $supabase->update('notifikasi',
                ['is_read' => true],
                [
                    'user_id' => 'eq.' . $user['user_id'],
                    'is_read' => 'eq.false'
                ]
            );

            if ($result === false) {
                http_response_code(500);
                echo json_encode(["error" => "Gagal menandai notifikasi"]);
                return;
            }

            http_response_code(200);
            echo json_encode(["message" => "Semua notifikasi telah ditandai sebagai dibaca"]);

        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Terjadi kesalahan sistem"]);
            error_log("NotifikasiController::readAll Exception: " . $e->getMessage());
        }
    }

    /**
     * Menandai satu notifikasi sebagai sudah dibaca
     */
    public function markAsRead(string $id): void {
        try {
            $user = AuthMiddleware::check();
            $supabase = SupabaseClient::getInstance();

            // Validasi notifikasi milik user
            $notifList = $supabase->select('notifikasi', [
                'id' => 'eq.' . $id,
                'user_id' => 'eq.' . $user['user_id']
            ]);

            if (empty($notifList)) {
                http_response_code(404);
                echo json_encode(["error" => "Notifikasi tidak ditemukan"]);
                return;
            }

            // Update is_read
            $result = $supabase->update('notifikasi',
                ['is_read' => true],
                ['id' => 'eq.' . $id]
            );

            if ($result === false) {
                http_response_code(500);
                echo json_encode(["error" => "Gagal menandai notifikasi"]);
                return;
            }

            http_response_code(200);
            echo json_encode(["message" => "Notifikasi ditandai sebagai dibaca"]);

        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Terjadi kesalahan sistem"]);
            error_log("NotifikasiController::markAsRead Exception: " . $e->getMessage());
        }
    }

    /**
     * Menghapus notifikasi
     */
    public function destroy(string $id): void {
        try {
            $user = AuthMiddleware::check();
            $supabase = SupabaseClient::getInstance();

            // Validasi notifikasi milik user
            $notifList = $supabase->select('notifikasi', [
                'id' => 'eq.' . $id,
                'user_id' => 'eq.' . $user['user_id']
            ]);

            if (empty($notifList)) {
                http_response_code(404);
                echo json_encode(["error" => "Notifikasi tidak ditemukan"]);
                return;
            }

            // Hapus notifikasi
            $result = $supabase->delete('notifikasi', ['id' => 'eq.' . $id]);

            if ($result === false) {
                http_response_code(500);
                echo json_encode(["error" => "Gagal menghapus notifikasi"]);
                return;
            }

            http_response_code(200);
            echo json_encode(["message" => "Notifikasi berhasil dihapus"]);

        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Terjadi kesalahan sistem"]);
            error_log("NotifikasiController::destroy Exception: " . $e->getMessage());
        }
    }
}
