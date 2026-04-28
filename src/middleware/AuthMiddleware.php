<?php

namespace App\Middleware;

class AuthMiddleware {
    
    /**
     * Memeriksa keberadaan session login
     * 
     * @return array
     */
    public static function check(): array {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
            http_response_code(401);
            echo json_encode(["error" => "Unauthorized"]);
            die();
        }
        
        return [
            'user_id' => $_SESSION['user_id'],
            'role'    => $_SESSION['role'],
            'nama'    => $_SESSION['nama'] ?? ''
        ];
    }
    
    /**
     * Memeriksa session sekaligus role spesifik (contoh: 'asprak' atau 'mahasiswa')
     * 
     * @param string $role
     * @return array
     */
    public static function requireRole(string $role): array {
        $user = self::check();
        
        if ($user['role'] !== $role) {
            http_response_code(403);
            echo json_encode(["error" => "Forbidden"]);
            die();
        }
        
        return $user;
    }
    
    /**
     * Mendapatkan data user saat ini jika login, atau array kosong jika belum
     * 
     * @return array
     */
    public static function getCurrentUser(): array {
        if (isset($_SESSION['user_id'])) {
            return [
                'user_id' => $_SESSION['user_id'],
                'role'    => $_SESSION['role'] ?? '',
                'nama'    => $_SESSION['nama'] ?? ''
            ];
        }
        return [];
    }
}
