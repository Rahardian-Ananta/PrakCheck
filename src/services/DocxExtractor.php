<?php

namespace App\Services;

class DocxExtractor {
    
    /**
     * Ekstraksi teks dari file DOCX yang ada di local path
     * 
     * @param string $filePath
     * @return string
     */
    public function extractFromPath(string $filePath): string {
        try {
            $phpWord = \PhpOffice\PhpWord\IOFactory::load($filePath);
            $text = '';
            
            // Loop semua section dan element untuk ambil text
            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    if ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
                        foreach ($element->getElements() as $textElement) {
                            if ($textElement instanceof \PhpOffice\PhpWord\Element\Text) {
                                $text .= $textElement->getText() . ' ';
                            }
                        }
                    } elseif ($element instanceof \PhpOffice\PhpWord\Element\Text) {
                        $text .= $element->getText() . ' ';
                    } elseif ($element instanceof \PhpOffice\PhpWord\Element\Title) {
                        $text .= $element->getText() . ' ';
                    }
                }
            }
            
            // Preprocessing: lowercase
            $text = strtolower($text);
            
            // Hapus karakter non-alfanumerik kecuali spasi
            $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);
            
            // Hapus spasi berlebih
            $text = preg_replace('/\s+/', ' ', $text);
            
            return trim($text);
        } catch (\Exception $e) {
            error_log("DocxExtractor::extractFromPath Exception: " . $e->getMessage());
            return "";
        }
    }
    
    /**
     * Ekstraksi teks dari file DOCX binary string
     * 
     * @param string $fileContent
     * @return string
     */
    public function extractFromString(string $fileContent): string {
        try {
            // Simpan sementara ke temp directory dengan nama unik
            $tmpPath = sys_get_temp_dir() . '/' . uniqid('prakcheck_docx_', true) . '.docx';
            file_put_contents($tmpPath, $fileContent);
            
            // Proses ekstraksi dari file temp
            $text = $this->extractFromPath($tmpPath);
            
            // Hapus file temp
            @unlink($tmpPath);
            
            return $text;
        } catch (\Exception $e) {
            error_log("DocxExtractor::extractFromString Exception: " . $e->getMessage());
            return "";
        }
    }
}
