<?php

namespace App\Controllers;

use App\Config\SupabaseClient;
use App\Middleware\AuthMiddleware;

class MateriController {

    /**
     * Mendapatkan daftar materi dan pengumuman
     * Filter berdasarkan kelas yang diikuti mahasiswa atau dikelola asprak
     */
    public function index(): void {
        try {
            $user = AuthMiddleware::check();
            $supabase = SupabaseClient::getInstance();

            $kelasId = $_GET['kelas_id'] ?? null;

            if ($user['role'] === 'mahasiswa') {
                // Mahasiswa hanya bisa lihat materi dari kelas yang dia ikuti
                $kelasIkut = $supabase->select('kelas_mahasiswa', [
                    'mahasiswa_id' => 'eq.' . $user['user_id']
                ]);

                if (empty($kelasIkut)) {
                    http_response_code(200);
                    echo json_encode(["data" => []]);
                    return;
                }

                $kelasIds = array_column($kelasIkut, 'kelas_id');

                // Filter berdasarkan kelas_id jika diberikan
                if ($kelasId && in_array($kelasId, $kelasIds)) {
                    $filters = [
                        'kelas_id' => 'eq.' . $kelasId,
                        'is_published' => 'eq.true',
                        'order' => 'publish_at.desc'
                    ];
                } else {
                    $filters = [
                        'kelas_id' => 'in.(' . implode(',', $kelasIds) . ')',
                        'is_published' => 'eq.true',
                        'order' => 'publish_at.desc'
                    ];
                }

                $materi = $supabase->select('materi', $filters);

            } else {
                // Asprak bisa lihat semua materi yang dia buat
                $filters = [
                    'asprak_id' => 'eq.' . $user['user_id'],
                    'order' => 'created_at.desc'
                ];

                if ($kelasId) {
                    $filters['kelas_id'] = 'eq.' . $kelasId;
                }

                $materi = $supabase->select('materi', $filters);
            }

            http_response_code(200);
            echo json_encode(["data" => $materi]);

        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Terjadi kesalahan sistem"]);
            error_log("MateriController::index Exception: " . $e->getMessage());
        }
    }

    /**
     * Membuat materi atau pengumuman baru (khusus asprak)
     */
    public function create(): void {
        try {
            $user = AuthMiddleware::requireRole('asprak');
            $supabase = SupabaseClient::getInstance();

            // Ambil data dari form (multipart untuk upload file)
            $kelasId = $_POST['kelas_id'] ?? null;
            $judul = $_POST['judul'] ?? null;
            $isi = $_POST['isi'] ?? null;
            $tipe = $_POST['tipe'] ?? 'materi'; // 'materi' atau 'pengumuman'
            $publishAt = $_POST['publish_at'] ?? date('c');

            // Validasi field wajib
            if (!$kelasId || !$judul || !$isi) {
                http_response_code(400);
                echo json_encode(["error" => "kelas_id, judul, dan isi wajib diisi"]);
                return;
            }

            // Validasi tipe
            if (!in_array($tipe, ['materi', 'pengumuman'])) {
                http_response_code(400);
                echo json_encode(["error" => "Tipe harus 'materi' atau 'pengumuman'"]);
                return;
            }

            // Validasi kelas milik asprak
            $kelasList = $supabase->select('kelas', [
                'id' => 'eq.' . $kelasId,
                'asprak_id' => 'eq.' . $user['user_id']
            ]);

            if (empty($kelasList)) {
                http_response_code(403);
                echo json_encode(["error" => "Akses ditolak, kelas tidak valid"]);
                return;
            }

            $kelas = $kelasList[0];

            // Handle upload lampiran (opsional)
            $lampiranPath = null;
            $lampiranName = null;

            if (isset($_FILES['lampiran']) && $_FILES['lampiran']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['lampiran'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $mime = mime_content_type($file['tmp_name']);

// Validasi tipe file
                $allowedTypes = [
                    'pdf' => 'application/pdf',
                    'jpg' => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'png' => 'image/png',
                    'doc' => 'application/msword',
                    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                ];
                
                if (!isset($allowedTypes[$ext])) {
                    http_response_code(422);
                    echo json_encode(["error" => "Format file tidak diizinkan. Hanya PDF, JPG, PNG, DOC, DOCX"]);
                    return;
                }

                // Validasi ukuran (max 50MB)
                if ($file['size'] > 50 * 1024 * 1024) {
                    http_response_code(422);
                    echo json_encode(["error" => "Ukuran file maksimal 50MB"]);
                    return;
                }

                // Upload ke Supabase Storage
                $timestamp = time();
                $storagePath = $kelasId . '/' . $timestamp . '_' . $file['name'];

                $uploadResult = $supabase->uploadFile('materi-files', $storagePath, $file['tmp_name'], $mime);

                if ($uploadResult === false) {
                    http_response_code(500);
                    echo json_encode(["error" => "Gagal mengupload lampiran"]);
                    return;
                }

                $lampiranPath = $storagePath;
                $lampiranName = $file['name'];
            }

            // Insert materi
            $insertData = [
                'asprak_id' => $user['user_id'],
                'kelas_id' => $kelasId,
                'judul' => $judul,
                'isi' => $isi,
                'tipe' => $tipe,
                'lampiran_path' => $lampiranPath,
                'lampiran_name' => $lampiranName,
                'publish_at' => $publishAt,
                'is_published' => true
            ];

            $result = $supabase->insert('materi', $insertData);

            if ($result === false) {
                http_response_code(500);
                echo json_encode(["error" => "Gagal menyimpan materi"]);
                return;
            }

            // Kirim notifikasi ke semua mahasiswa di kelas
            $mahasiswaList = $supabase->select('kelas_mahasiswa', ['kelas_id' => 'eq.' . $kelasId]);

            $notifTipe = $tipe === 'pengumuman' ? 'pengumuman_baru' : 'materi_baru';
            $notifJudul = $tipe === 'pengumuman' ? 'Pengumuman Baru' : 'Materi Baru';

            foreach ($mahasiswaList as $km) {
                $supabase->insert('notifikasi', [
                    'user_id' => $km['mahasiswa_id'],
                    'judul' => $notifJudul,
                    'pesan' => $notifJudul . ' di kelas "' . $kelas['nama_kelas'] . '": ' . $judul,
                    'tipe' => $notifTipe
                ]);
            }

            http_response_code(201);
            echo json_encode([
                "message" => ucfirst($tipe) . " berhasil dibuat",
                "data" => isset($result[0]) ? $result[0] : $result
            ]);

        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Terjadi kesalahan sistem"]);
            error_log("MateriController::create Exception: " . $e->getMessage());
        }
    }

    /**
     * Mengedit materi/pengumuman (khusus asprak)
     */
    public function update(string $id): void {
        try {
            $user = AuthMiddleware::requireRole('asprak');
            $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $supabase = SupabaseClient::getInstance();
            
            // Validasi materi milik asprak
            $materiList = $supabase->select('materi', [
                'id' => 'eq.' . $id,
                'asprak_id' => 'eq.' . $user['user_id']
            ]);
            
            if (empty($materiList)) {
                http_response_code(403);
                echo json_encode(["error" => "Materi tidak ditemukan atau akses ditolak"]);
                return;
            }
            
            // Siapkan data update
            $updateData = [];
            if (isset($data['judul'])) $updateData['judul'] = $data['judul'];
            if (isset($data['isi'])) $updateData['isi'] = $data['isi'];
            if (isset($data['tipe'])) $updateData['tipe'] = $data['tipe'];
            if (isset($data['publish_at'])) $updateData['publish_at'] = $data['publish_at'];
            
            if (empty($updateData)) {
                http_response_code(400);
                echo json_encode(["error" => "Tidak ada data yang diupdate"]);
                return;
            }
            
            // Update dengan filter id DAN asprak_id (keamanan)
            $filters = [
                'id' => 'eq.' . $id,
                'asprak_id' => 'eq.' . $user['user_id']
            ];
            
            $result = $supabase->update('materi', $updateData, $filters);
            
            if ($result === false) {
                http_response_code(500);
                echo json_encode(["error" => "Gagal mengupdate materi"]);
                return;
            }
            
            http_response_code(200);
            echo json_encode([
                "message" => "Materi berhasil diupdate",
                "data" => isset($result[0]) ? $result[0] : $result
            ]);
            
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Terjadi kesalahan sistem"]);
            error_log("MateriController::update Exception: " . $e->getMessage());
        }
    }

    /**
     * Menghapus materi (khusus asprak)
     */
    public function destroy(string $id): void {
        try {
            $user = AuthMiddleware::requireRole('asprak');
            $supabase = SupabaseClient::getInstance();

            // Validasi materi milik asprak
            $materiList = $supabase->select('materi', [
                'id' => 'eq.' . $id,
                'asprak_id' => 'eq.' . $user['user_id']
            ]);

            if (empty($materiList)) {
                http_response_code(403);
                echo json_encode(["error" => "Materi tidak ditemukan atau akses ditolak"]);
                return;
            }

            $materi = $materiList[0];

            // Hapus file lampiran jika ada
            if (!empty($materi['lampiran_path'])) {
                $supabase->deleteFile('materi-files', $materi['lampiran_path']);
            }

            // Hapus materi dari database
            $result = $supabase->delete('materi', ['id' => 'eq.' . $id]);

            if ($result === false) {
                http_response_code(500);
                echo json_encode(["error" => "Gagal menghapus materi"]);
                return;
            }

            http_response_code(200);
            echo json_encode(["message" => "Materi berhasil dihapus"]);

        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Terjadi kesalahan sistem"]);
            error_log("MateriController::destroy Exception: " . $e->getMessage());
        }
    }

    public function downloadLampiran(string $id): void {
        try {
            AuthMiddleware::check();
            $supabase = SupabaseClient::getInstance();
            
            $materiList = $supabase->select('materi', ['id' => 'eq.' . $id]);
            if (empty($materiList)) {
                http_response_code(404);
                echo json_encode(["error" => "Materi tidak ditemukan"]);
                return;
            }
            
            $materi = $materiList[0];
            
            if (empty($materi['lampiran_path'])) {
                http_response_code(404);
                echo json_encode(["error" => "Tidak ada lampiran"]);
                return;
            }
            
            $fileContent = $supabase->downloadFile('materi-files', $materi['lampiran_path']);
            if ($fileContent === false) {
                http_response_code(500);
                echo json_encode(["error" => "Gagal download lampiran"]);
                return;
            }
            
            $ext = strtolower(pathinfo($materi['lampiran_name'], PATHINFO_EXTENSION));
            $mimeTypes = [
                'pdf' => 'application/pdf',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ];
            $mime = $mimeTypes[$ext] ?? 'application/octet-stream';
            
            header('Content-Type: ' . $mime);
            header('Content-Disposition: attachment; filename="' . $materi['lampiran_name'] . '"');
            header('Content-Length: ' . strlen($fileContent));
            echo $fileContent;
            
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Terjadi kesalahan sistem"]);
            error_log("MateriController::downloadLampiran Exception: " . $e->getMessage());
        }
    }
}
