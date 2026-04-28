<?php

namespace App\Controllers;

use App\Config\SupabaseClient;
use App\Middleware\AuthMiddleware;

class AuthController {
    
    /**
     * Pendaftaran user baru (mahasiswa atau asprak)
     */
    public function register(): void {
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        
        // 1. Validasi field wajib
        $required = ['nama', 'email', 'password', 'nrp_nip', 'role'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                http_response_code(400);
                echo json_encode(['error' => "{$field} wajib diisi"]);
                return;
            }
        }
        
        // 2. Validasi role
        if (!in_array($data['role'], ['mahasiswa', 'asprak'])) {
            http_response_code(400);
            echo json_encode(['error' => "Role harus mahasiswa atau asprak"]);
            return;
        }
        
        // 3. Validasi email
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['error' => "Format email tidak valid"]);
            return;
        }
        
        // 4. Validasi panjang password
        if (strlen($data['password']) < 8) {
            http_response_code(400);
            echo json_encode(['error' => "Password minimal 8 karakter"]);
            return;
        }
        
        $supabase = SupabaseClient::getInstance();
        
        // 5. Cek duplikat email
        $checkEmail = $supabase->select('users', ['email' => 'eq.' . $data['email']]);
        if (!empty($checkEmail)) {
            http_response_code(409);
            echo json_encode(['error' => "Email sudah terdaftar"]);
            return;
        }
        
        // 6. Cek duplikat nrp_nip
        $checkNrp = $supabase->select('users', ['nrp_nip' => 'eq.' . $data['nrp_nip']]);
        if (!empty($checkNrp)) {
            http_response_code(409);
            echo json_encode(['error' => "NRP/NIP sudah terdaftar"]);
            return;
        }
        
        // 7. Hash password
        $passwordHash = password_hash($data['password'], PASSWORD_BCRYPT);
        
        // 8. Insert
        $insertData = [
            'nama' => $data['nama'],
            'email' => $data['email'],
            'password_hash' => $passwordHash,
            'nrp_nip' => $data['nrp_nip'],
            'role' => $data['role']
        ];
        
        $result = $supabase->insert('users', $insertData);
        if ($result === false || empty($result)) {
            http_response_code(500);
            echo json_encode(['error' => "Gagal mendaftar, terjadi kesalahan sistem"]);
            return;
        }
        
        // Ambil array record pertama yang dikembalikan Supabase
        $user = isset($result[0]) ? $result[0] : $result;
        
        http_response_code(201);
        echo json_encode([
            'message' => 'Registrasi berhasil',
            'user' => [
                'id' => $user['id'],
                'nama' => $user['nama'],
                'email' => $user['email'],
                'role' => $user['role']
            ]
        ]);
    }
    
    /**
     * Proses login user
     */
    public function login(): void {
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        
        if (empty($data['email']) || empty($data['password'])) {
            http_response_code(400);
            echo json_encode(['error' => "Email dan password wajib diisi"]);
            return;
        }
        
        $supabase = SupabaseClient::getInstance();
        $users = $supabase->select('users', ['email' => 'eq.' . $data['email']]);
        
        // Jika email tidak ditemukan
        if (empty($users)) {
            http_response_code(401);
            echo json_encode(['error' => "Email atau password salah"]);
            return;
        }
        
        $user = $users[0];
        
        // Verifikasi password
        if (!password_verify($data['password'], $user['password_hash'])) {
            http_response_code(401);
            echo json_encode(['error' => "Email atau password salah"]);
            return;
        }
        
        // Verifikasi akun aktif (mengantisipasi representasi bool di Supabase t/f vs boolean native)
        if ($user['is_active'] === false || $user['is_active'] === 'f') {
            http_response_code(403);
            echo json_encode(['error' => "Akun dinonaktifkan"]);
            return;
        }
        
        // Set session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['nama'] = $user['nama'];
        
        http_response_code(200);
        echo json_encode([
            'message' => 'Login berhasil',
            'user' => [
                'id' => $user['id'],
                'nama' => $user['nama'],
                'email' => $user['email'],
                'role' => $user['role']
            ]
        ]);
    }
    
    /**
     * Proses logout user
     */
    public function logout(): void {
        session_destroy();
        http_response_code(200);
        echo json_encode(['message' => 'Logout berhasil']);
    }
    
    /**
     * Mengambil informasi profile (user saat ini)
     */
    public function me(): void {
        $currentUser = AuthMiddleware::check();
        
        $supabase = SupabaseClient::getInstance();
        $users = $supabase->select('users', ['id' => 'eq.' . $currentUser['user_id']]);
        
        if (empty($users)) {
            http_response_code(404);
            echo json_encode(['error' => "User tidak ditemukan"]);
            return;
        }
        
        $user = $users[0];
        unset($user['password_hash']); // Jangan expose hash
        
        http_response_code(200);
        echo json_encode($user);
    }
}
