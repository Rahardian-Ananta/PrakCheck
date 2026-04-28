<?php

namespace App\Controllers;

use App\Config\SupabaseClient;
use App\Middleware\AuthMiddleware;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class NilaiController {

    /**
     * Menyimpan atau memperbarui nilai dari asprak
     */
    public function submit(): void {
        try {
            $user = AuthMiddleware::requireRole('asprak');
            $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            
            $laporanId = $data['laporan_id'] ?? null;
            if (!$laporanId) {
                http_response_code(400);
                echo json_encode(["error" => "laporan_id wajib diisi"]);
                return;
            }
            
            $supabase = SupabaseClient::getInstance();
            
            // Validasi laporan
            $laporanList = $supabase->select('laporan', ['id' => 'eq.' . $laporanId]);
            if (empty($laporanList)) {
                http_response_code(404);
                echo json_encode(["error" => "Laporan tidak ditemukan"]);
                return;
            }
            $laporan = $laporanList[0];
            
            $tugasId = $laporan['tugas_id'];
            $mahasiswaId = $laporan['mahasiswa_id'];
            
            // Validasi tugas dan kelas milik asprak
            $tugasList = $supabase->select('tugas', ['id' => 'eq.' . $tugasId]);
            $tugas = $tugasList[0];
            
            $kelasList = $supabase->select('kelas', [
                'id' => 'eq.' . $tugas['kelas_id'],
                'asprak_id' => 'eq.' . $user['user_id']
            ]);
            
            if (empty($kelasList)) {
                http_response_code(403);
                echo json_encode(["error" => "Akses ditolak, laporan ini bukan dari kelas Anda"]);
                return;
            }
            
            // Siapkan data nilai
            $nilai = floatval($data['nilai'] ?? 0);
            $catatan = $data['catatan'] ?? '';
            $isPlagiat = filter_var($data['is_plagiat'] ?? false, FILTER_VALIDATE_BOOLEAN);
            
            if ($isPlagiat) {
                $nilai = 0; // Jika plagiat otomatis 0
            }
            
            $nilaiData = [
                'laporan_id' => $laporanId,
                'mahasiswa_id' => $mahasiswaId,
                'tugas_id' => $tugasId,
                'asprak_id' => $user['user_id'],
                'nilai' => $nilai,
                'catatan' => $catatan,
                'is_plagiat' => $isPlagiat ? 'true' : 'false'
            ];
            
            // Upsert (Cek apakah sudah ada)
            $existingNilai = $supabase->select('nilai', ['laporan_id' => 'eq.' . $laporanId]);
            
            if (!empty($existingNilai)) {
                $nilaiResult = $supabase->update('nilai', $nilaiData, ['id' => 'eq.' . $existingNilai[0]['id']]);
            } else {
                $nilaiResult = $supabase->insert('nilai', $nilaiData);
            }
            
            // Update status laporan
            $supabase->update('laporan', ['status' => 'dinilai'], ['id' => 'eq.' . $laporanId]);
            
            // Kirim notifikasi
            $supabase->insert('notifikasi', [
                'user_id' => $mahasiswaId,
                'judul' => 'Nilai Keluar',
                'pesan' => 'Nilai kamu untuk "' . $tugas['nama_tugas'] . '" sudah keluar: ' . $nilai,
                'tipe' => 'nilai_keluar'
            ]);
            
            http_response_code(201);
            echo json_encode([
                "message" => "Nilai berhasil disimpan",
                "nilai" => isset($nilaiResult[0]) ? $nilaiResult[0] : $nilaiResult
            ]);
            
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Terjadi kesalahan internal", "message" => $e->getMessage()]);
        }
    }

    /**
     * Update nilai existing
     */
    public function update(string $id): void {
        try {
            $user = AuthMiddleware::requireRole('asprak');
            $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            
            $supabase = SupabaseClient::getInstance();
            
            // Validasi nilai milik asprak ini
            $existing = $supabase->select('nilai', ['id' => 'eq.' . $id, 'asprak_id' => 'eq.' . $user['user_id']]);
            if (empty($existing)) {
                http_response_code(403);
                echo json_encode(["error" => "Data nilai tidak ditemukan atau akses ditolak"]);
                return;
            }
            
            $updateData = [];
            if (isset($data['nilai'])) $updateData['nilai'] = floatval($data['nilai']);
            if (isset($data['catatan'])) $updateData['catatan'] = $data['catatan'];
            if (isset($data['is_plagiat'])) {
                $isPlag = filter_var($data['is_plagiat'], FILTER_VALIDATE_BOOLEAN);
                $updateData['is_plagiat'] = $isPlag ? 'true' : 'false';
                if ($isPlag) $updateData['nilai'] = 0;
            }
            
            $result = $supabase->update('nilai', $updateData, ['id' => 'eq.' . $id]);
            
            http_response_code(200);
            echo json_encode([
                "message" => "Nilai diupdate",
                "nilai" => isset($result[0]) ? $result[0] : $result
            ]);
            
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Terjadi kesalahan sistem"]);
        }
    }

    /**
     * Export rekap nilai dan data indikasi plagiat ke file Excel
     */
    public function export(): void {
        try {
            $user = AuthMiddleware::requireRole('asprak');
            $kelasId = $_GET['kelas_id'] ?? null;
            
            if (!$kelasId) {
                http_response_code(400);
                echo json_encode(["error" => "kelas_id wajib diisi"]);
                return;
            }
            
            $supabase = SupabaseClient::getInstance();
            
            // Validasi kelas milik asprak
            $kelasList = $supabase->select('kelas', [
                'id' => 'eq.' . $kelasId,
                'asprak_id' => 'eq.' . $user['user_id']
            ]);
            
            if (empty($kelasList)) {
                http_response_code(403);
                echo json_encode(["error" => "Akses ditolak"]);
                return;
            }
            $kelas = $kelasList[0];
            
            // Ambil semua tugas
            $tugasList = $supabase->select('tugas', [
                'kelas_id' => 'eq.' . $kelasId,
                'order' => 'created_at.asc'
            ]);
            
            // Ambil semua mahasiswa
            $mahasiswaList = $supabase->select('kelas_mahasiswa', ['kelas_id' => 'eq.' . $kelasId]);
            $mhsIds = array_column($mahasiswaList, 'mahasiswa_id');
            
            $usersMap = [];
            if (!empty($mhsIds)) {
                $users = $supabase->select('users', ['id' => 'in.(' . implode(',', $mhsIds) . ')']);
                foreach ($users as $u) {
                    $usersMap[$u['id']] = $u;
                }
            }
            
            // Init PhpSpreadsheet
            $spreadsheet = new Spreadsheet();
            $sheet1 = $spreadsheet->getActiveSheet();
            $sheet1->setTitle("Rekap Nilai");
            
            // ==========================================
            // SHEET 1: REKAP NILAI
            // ==========================================
            $sheet1->setCellValue('A1', 'NRP');
            $sheet1->setCellValue('B1', 'Nama Mahasiswa');
            
            $colIndex = 3;
            $tugasIds = [];
            foreach ($tugasList as $tugas) {
                $colLetter = Coordinate::stringFromColumnIndex($colIndex);
                $sheet1->setCellValue($colLetter . '1', $tugas['nama_tugas']);
                $tugasIds[] = $tugas['id'];
                $colIndex++;
            }
            
            $lastColLetter = Coordinate::stringFromColumnIndex($colIndex);
            $sheet1->setCellValue($lastColLetter . '1', 'Rata-rata');
            
            // Styling Header
            $headerStyle = [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FFDDDDDD'] // Abu-abu
                ]
            ];
            $sheet1->getStyle("A1:{$lastColLetter}1")->applyFromArray($headerStyle);
            $sheet1->freezePane('A2');
            
            // Isi Data
            $row = 2;
            foreach ($usersMap as $mhsId => $u) {
                $sheet1->setCellValue('A' . $row, $u['nrp_nip']);
                $sheet1->setCellValue('B' . $row, $u['nama']);
                
                $cIdx = 3;
                $sumNilai = 0;
                $countNilai = 0;
                
                foreach ($tugasList as $tugas) {
                    $cLetter = Coordinate::stringFromColumnIndex($cIdx);
                    
                    // Ambil laporan
                    $laporan = $supabase->select('laporan', [
                        'mahasiswa_id' => 'eq.' . $mhsId,
                        'tugas_id' => 'eq.' . $tugas['id']
                    ]);
                    
                    if (empty($laporan)) {
                        $sheet1->setCellValue($cLetter . $row, 'Belum dinilai');
                        $sheet1->getStyle($cLetter . $row)->getFill()
                               ->setFillType(Fill::FILL_SOLID)
                               ->getStartColor()->setARGB('FFFFFFAA'); // Kuning
                    } else {
                        $laporanId = $laporan[0]['id'];
                        $nilaiData = $supabase->select('nilai', ['laporan_id' => 'eq.' . $laporanId]);
                        
                        if (empty($nilaiData)) {
                            $sheet1->setCellValue($cLetter . $row, 'Belum dinilai');
                            $sheet1->getStyle($cLetter . $row)->getFill()
                                   ->setFillType(Fill::FILL_SOLID)
                                   ->getStartColor()->setARGB('FFFFFFAA');
                        } else {
                            $nData = $nilaiData[0];
                            $isPlag = ($nData['is_plagiat'] === true || $nData['is_plagiat'] === 't');
                            
                            $sheet1->setCellValue($cLetter . $row, $nData['nilai']);
                            
                            if ($isPlag) {
                                $sheet1->getStyle($cLetter . $row)->getFill()
                                       ->setFillType(Fill::FILL_SOLID)
                                       ->getStartColor()->setARGB('FFFFB3B3'); // Merah muda
                            }
                            
                            $sumNilai += $nData['nilai'];
                            $countNilai++;
                        }
                    }
                    $cIdx++;
                }
                
                $avg = $countNilai > 0 ? round($sumNilai / $countNilai, 2) : 0;
                $avgColLetter = Coordinate::stringFromColumnIndex($cIdx);
                $sheet1->setCellValue($avgColLetter . $row, $avg);
                
                $row++;
            }
            
            // Baris Rata-rata Kelas
            $sheet1->setCellValue('B' . $row, 'RATA-RATA KELAS');
            $sheet1->getStyle('B' . $row)->getFont()->setBold(true);
            
            for ($c = 3; $c <= $colIndex; $c++) {
                $cLetter = Coordinate::stringFromColumnIndex($c);
                $colSum = 0;
                $colCount = 0;
                
                for ($r = 2; $r < $row; $r++) {
                    $val = $sheet1->getCell($cLetter . $r)->getValue();
                    if (is_numeric($val)) {
                        $colSum += $val;
                        $colCount++;
                    }
                }
                $colAvg = $colCount > 0 ? round($colSum / $colCount, 2) : 0;
                $sheet1->setCellValue($cLetter . $row, $colAvg);
                $sheet1->getStyle($cLetter . $row)->getFont()->setBold(true);
            }
            
            // ==========================================
            // SHEET 2: TERINDIKASI PLAGIAT
            // ==========================================
            $sheet2 = $spreadsheet->createSheet();
            $sheet2->setTitle("Terindikasi Plagiat");
            
            $sheet2->setCellValue('A1', 'Nama Mahasiswa A');
            $sheet2->setCellValue('B1', 'NRP A');
            $sheet2->setCellValue('C1', 'Nama Mahasiswa B');
            $sheet2->setCellValue('D1', 'NRP B');
            $sheet2->setCellValue('E1', 'Nama Tugas');
            $sheet2->setCellValue('F1', 'Kemiripan (%)');
            
            $sheet2->getStyle("A1:F1")->applyFromArray(['font' => ['bold' => true]]);
            $sheet2->freezePane('A2');
            
            if (!empty($tugasIds)) {
                $kemiripanList = $supabase->select('kemiripan', [
                    'tugas_id' => 'in.(' . implode(',', $tugasIds) . ')',
                    'or' => '(zona.eq.merah,is_flagged.eq.true)'
                ]);
                
                $r2 = 2;
                foreach ($kemiripanList as $kem) {
                    $uA = $usersMap[$kem['mahasiswa_id_a']] ?? null;
                    $uB = $usersMap[$kem['mahasiswa_id_b']] ?? null;
                    
                    // Fallback jika tidak ada di map
                    if (!$uA) {
                        $qA = $supabase->select('users', ['id' => 'eq.' . $kem['mahasiswa_id_a']]);
                        $uA = $qA[0] ?? ['nama' => 'Unknown', 'nrp_nip' => '-'];
                    }
                    if (!$uB) {
                        $qB = $supabase->select('users', ['id' => 'eq.' . $kem['mahasiswa_id_b']]);
                        $uB = $qB[0] ?? ['nama' => 'Unknown', 'nrp_nip' => '-'];
                    }
                    
                    $tName = 'Unknown';
                    foreach ($tugasList as $t) {
                        if ($t['id'] === $kem['tugas_id']) {
                            $tName = $t['nama_tugas'];
                            break;
                        }
                    }
                    
                    $sheet2->setCellValue('A' . $r2, $uA['nama']);
                    $sheet2->setCellValue('B' . $r2, $uA['nrp_nip']);
                    $sheet2->setCellValue('C' . $r2, $uB['nama']);
                    $sheet2->setCellValue('D' . $r2, $uB['nrp_nip']);
                    $sheet2->setCellValue('E' . $r2, $tName);
                    
                    $percent = round($kem['skor_kemiripan'] * 100, 2) . '%';
                    $sheet2->setCellValue('F' . $r2, $percent);
                    $r2++;
                }
            }
            
            // Auto Fit Columns
            foreach (range('A', $lastColLetter) as $col) {
                $sheet1->getColumnDimension($col)->setAutoSize(true);
            }
            foreach (range('A', 'F') as $col) {
                $sheet2->getColumnDimension($col)->setAutoSize(true);
            }
            
            // Download file Output
            $spreadsheet->setActiveSheetIndex(0);
            $namaFile = 'Rekap_Nilai_' . str_replace(' ', '_', $kelas['nama_kelas']) . '_' . date('Ymd') . '.xlsx';
            
            // Bersihkan output buffer jika ada space tersembunyi sebelumnya
            if (ob_get_length()) { ob_end_clean(); }
            
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $namaFile . '"');
            header('Cache-Control: max-age=0');
            
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            exit;
            
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Gagal export excel: " . $e->getMessage()]);
        }
    }
}
