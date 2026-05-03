<?php

/**
 * CRON JOB: Check Deadline dan Trigger Analisis Otomatis
 *
 * File ini dijalankan setiap 5 menit oleh Railway Cron Job.
 * Tugasnya: cek tugas yang deadlinenya sudah lewat dan trigger analisis otomatis.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

// Load .env
if (file_exists(__DIR__ . '/../../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
    $dotenv->load();
}

// Set time limit unlimited untuk proses panjang
set_time_limit(0);

use App\Config\SupabaseClient;
use App\Controllers\AnalisisController;

echo "[" . date('Y-m-d H:i:s') . "] === CRON JOB: Check Deadline Started ===\n";

try {
    $supabase = SupabaseClient::getInstance();

    // STEP 1: Query tugas yang deadline sudah lewat dan belum dianalisis
    $now = date('c'); // ISO 8601 format

    echo "[" . date('Y-m-d H:i:s') . "] Mencari tugas dengan deadline < $now\n";

    $tugasList = $supabase->select('tugas', [
        'status' => 'eq.open',
        'is_analyzed' => 'eq.false',
        'deadline' => 'lt.' . $now
    ]);

    if (empty($tugasList)) {
        echo "[" . date('Y-m-d H:i:s') . "] Tidak ada tugas yang perlu diproses.\n";
        echo "[" . date('Y-m-d H:i:s') . "] === CRON JOB: Selesai ===\n";
        exit(0);
    }

    echo "[" . date('Y-m-d H:i:s') . "] Ditemukan " . count($tugasList) . " tugas yang perlu dianalisis.\n\n";

    $successCount = 0;
    $errorCount = 0;

    // STEP 2: Proses setiap tugas
    foreach ($tugasList as $tugas) {
        $tugasId = $tugas['id'];
        $namaTugas = $tugas['nama_tugas'];

        echo "[" . date('Y-m-d H:i:s') . "] Memproses: $namaTugas (ID: $tugasId)\n";

        try {
            // Update status tugas menjadi 'closed'
            $supabase->update('tugas', ['status' => 'closed'], ['id' => 'eq.' . $tugasId]);
            echo "[" . date('Y-m-d H:i:s') . "]   - Status tugas diubah menjadi 'closed'\n";

            // Ambil asprak_id dari kelas
            $kelasList = $supabase->select('kelas', ['id' => 'eq.' . $tugas['kelas_id']], 'asprak_id');
            if (empty($kelasList)) {
                echo "[" . date('Y-m-d H:i:s') . "]   - ERROR: Kelas tidak ditemukan\n";
                $errorCount++;
                continue;
            }

            $asprakId = $kelasList[0]['asprak_id'];

            // Simulasikan session asprak untuk bypass auth middleware
            session_start();
            $_SESSION['user_id'] = $asprakId;
            $_SESSION['role'] = 'asprak';

            // Panggil AnalisisController untuk proses analisis
            echo "[" . date('Y-m-d H:i:s') . "]   - Memulai analisis kemiripan...\n";

            // Capture output dari controller
            ob_start();
            $controller = new AnalisisController();
            $controller->runAnalysis($tugasId);
            $output = ob_get_clean();

            // Parse JSON response
            $result = json_decode($output, true);

            if (isset($result['status']) && $result['status'] === 'success') {
                echo "[" . date('Y-m-d H:i:s') . "]   - ✓ Analisis berhasil!\n";
                echo "[" . date('Y-m-d H:i:s') . "]   - Total laporan: " . $result['total_laporan'] . "\n";
                echo "[" . date('Y-m-d H:i:s') . "]   - Total pasang: " . $result['total_pasang'] . "\n";
                echo "[" . date('Y-m-d H:i:s') . "]   - Zona merah: " . $result['zona_merah'] . "\n";
                echo "[" . date('Y-m-d H:i:s') . "]   - Zona kuning: " . $result['zona_kuning'] . "\n";
                echo "[" . date('Y-m-d H:i:s') . "]   - Zona hijau: " . $result['zona_hijau'] . "\n";
                $successCount++;
            } elseif (isset($result['status']) && $result['status'] === 'skip') {
                echo "[" . date('Y-m-d H:i:s') . "]   - ⊘ Dilewati: " . $result['message'] . "\n";
                $successCount++;
            } else {
                echo "[" . date('Y-m-d H:i:s') . "]   - ✗ Analisis gagal: " . ($result['error'] ?? 'Unknown error') . "\n";
                if (isset($result['errors']) && !empty($result['errors'])) {
                    foreach ($result['errors'] as $err) {
                        echo "[" . date('Y-m-d H:i:s') . "]     - $err\n";
                    }
                }
                $errorCount++;
            }

            // Clear session
            session_destroy();

        } catch (\Exception $e) {
            echo "[" . date('Y-m-d H:i:s') . "]   - ✗ Exception: " . $e->getMessage() . "\n";
            $errorCount++;
        }

        echo "\n";
    }

    // STEP 3: Summary
    echo "[" . date('Y-m-d H:i:s') . "] === RINGKASAN ===\n";
    echo "[" . date('Y-m-d H:i:s') . "] Total tugas diproses: " . count($tugasList) . "\n";
    echo "[" . date('Y-m-d H:i:s') . "] Berhasil: $successCount\n";
    echo "[" . date('Y-m-d H:i:s') . "] Gagal: $errorCount\n";
    echo "[" . date('Y-m-d H:i:s') . "] === CRON JOB: Selesai ===\n";

} catch (\Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] FATAL ERROR: " . $e->getMessage() . "\n";
    echo "[" . date('Y-m-d H:i:s') . "] Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

exit(0);
