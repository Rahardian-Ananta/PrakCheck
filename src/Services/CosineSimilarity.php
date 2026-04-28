<?php

namespace App\Services;

class CosineSimilarity {

    /**
     * Hitung kemiripan kosinus antar dokumen.
     * 
     * @param array $documents Array asosiatif ['laporan_id' => 'teks dokumen']
     * @return array Array hasil: [['laporan_id_a', 'laporan_id_b', 'skor'], ...]
     */
    public function calculate(array $documents): array {
        try {
            $tokenizedDocs = [];
            $docIds = array_keys($documents);
            $n = count($documents);
            
            if ($n < 2) {
                return [];
            }
            
            // STEP 1 — Tokenisasi
            foreach ($documents as $id => $text) {
                $tokenizedDocs[$id] = $this->tokenize($text);
            }
            
            // Lakukan pre-komputasi TF-IDF (mencakup Step 2, 3, 4, 5)
            $tfIdfVectors = $this->computeTfIdf($tokenizedDocs);
            
            if (empty($tfIdfVectors)) {
                return [];
            }
            
            $hasil = [];
            
            // STEP 6 — Hitung Cosine Similarity untuk setiap pasang (i, j) dimana i < j
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $id1 = $docIds[$i];
                    $id2 = $docIds[$j];
                    
                    $vecA = $tfIdfVectors[$id1];
                    $vecB = $tfIdfVectors[$id2];
                    
                    $dotProduct = 0.0;
                    $magA2 = 0.0;
                    $magB2 = 0.0;
                    
                    // Karena panjang vektor sama (sepanjang vocabulary)
                    $vocabLen = count($vecA);
                    for ($k = 0; $k < $vocabLen; $k++) {
                        // dot_product = sum(vector_a[k] * vector_b[k] untuk semua k)
                        $dotProduct += $vecA[$k] * $vecB[$k];
                        // magnitude_a = sum(x^2 untuk x di vector_a)
                        $magA2 += $vecA[$k] * $vecA[$k];
                        // magnitude_b = sum(x^2 untuk x di vector_b)
                        $magB2 += $vecB[$k] * $vecB[$k];
                    }
                    
                    $magA = sqrt($magA2);
                    $magB = sqrt($magB2);
                    
                    if ($magA == 0 || $magB == 0) {
                        $skor = 0.0;
                    } else {
                        $skor = $dotProduct / ($magA * $magB);
                    }
                    
                    // Round ke 4 desimal
                    $skor = round($skor, 4);
                    
                    // STEP 7 — Tentukan laporan_id_a dan laporan_id_b (A < B secara string)
                    if (strcmp($id1, $id2) < 0) {
                        $laporan_id_a = $id1;
                        $laporan_id_b = $id2;
                    } else {
                        $laporan_id_a = $id2;
                        $laporan_id_b = $id1;
                    }
                    
                    $hasil[] = [
                        'laporan_id_a' => $laporan_id_a,
                        'laporan_id_b' => $laporan_id_b,
                        'skor' => $skor
                    ];
                }
            }
            
            return $hasil;
        } catch (\Exception $e) {
            error_log("CosineSimilarity::calculate Exception: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Pecah teks menjadi array kata
     * 
     * @param string $text
     * @return array
     */
    private function tokenize(string $text): array {
        try {
            $words = explode(' ', trim($text));
            
            // Hapus kata yang kosong
            $words = array_filter($words, function($word) {
                return $word !== '';
            });
            
            return array_values($words);
        } catch (\Exception $e) {
            error_log("CosineSimilarity::tokenize Exception: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Hitung matriks TF-IDF untuk sekumpulan dokumen
     * 
     * @param array $tokenizedDocs
     * @return array
     */
    private function computeTfIdf(array $tokenizedDocs): array {
        try {
            // STEP 2 — Bangun vocabulary
            $vocabularyMap = [];
            foreach ($tokenizedDocs as $words) {
                foreach ($words as $word) {
                    $vocabularyMap[$word] = true;
                }
            }
            $vocabulary = array_keys($vocabularyMap);
            sort($vocabulary); // Opsional, untuk keteraturan
            $vocabIndex = array_flip($vocabulary);
            $vocabSize = count($vocabulary);
            
            $n = count($tokenizedDocs);
            
            if ($vocabSize === 0) {
                return [];
            }
            
            // STEP 3 — Hitung TF & catat DF
            $tfVectors = [];
            $docFreq = array_fill(0, $vocabSize, 0); 
            
            foreach ($tokenizedDocs as $id => $words) {
                $vector = array_fill(0, $vocabSize, 0.0);
                $totalWords = count($words);
                
                if ($totalWords > 0) {
                    $wordCounts = array_count_values($words);
                    foreach ($wordCounts as $word => $count) {
                        $idx = $vocabIndex[$word];
                        // Normalisasi TF: kemunculan kata / total kata dalam dokumen
                        $vector[$idx] = $count / $totalWords;
                        // Catat untuk DF
                        $docFreq[$idx]++;
                    }
                }
                $tfVectors[$id] = $vector;
            }
            
            // STEP 4 — Hitung IDF
            $idfVector = array_fill(0, $vocabSize, 0.0);
            for ($i = 0; $i < $vocabSize; $i++) {
                $df = $docFreq[$i];
                // Smooth IDF: log(N / (DF + 1)) + 1
                $idfVector[$i] = log($n / ($df + 1)) + 1;
            }
            
            // STEP 5 — Hitung TF-IDF
            $tfIdfVectors = [];
            foreach ($tfVectors as $id => $tfVector) {
                $vector = array_fill(0, $vocabSize, 0.0);
                for ($i = 0; $i < $vocabSize; $i++) {
                    // Kalikan TF dengan IDF
                    $vector[$i] = $tfVector[$i] * $idfVector[$i];
                }
                $tfIdfVectors[$id] = $vector;
            }
            
            return $tfIdfVectors;
        } catch (\Exception $e) {
            error_log("CosineSimilarity::computeTfIdf Exception: " . $e->getMessage());
            return [];
        }
    }
}
