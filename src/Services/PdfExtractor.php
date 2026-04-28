<?php

namespace App\Services;

class PdfExtractor {
    
    /**
     * Ekstraksi teks dari file PDF yang ada di local path
     * 
     * @param string $filePath
     * @return string
     */
    public function extractFromPath(string $filePath): string {
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($filePath);
            $text = $pdf->getText();
            
            // Preprocessing: lowercase
            $text = strtolower($text);
            
            // Hapus karakter non-alfanumerik kecuali spasi
            $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);
            
            // Hapus spasi berlebih
            $text = preg_replace('/\s+/', ' ', $text);
            
            return trim($text);
        } catch (\Exception $e) {
            error_log("PdfExtractor::extractFromPath Exception: " . $e->getMessage());
            return "";
        }
    }
    
    /**
     * Ekstraksi teks dari file PDF binary string
     * 
     * @param string $fileContent
     * @return string
     */
    public function extractFromString(string $fileContent): string {
        try {
            // Simpan sementara ke temp directory dengan nama unik
            $tmpPath = sys_get_temp_dir() . '/' . uniqid('prakcheck_pdf_', true) . '.pdf';
            file_put_contents($tmpPath, $fileContent);
            
            // Proses ekstraksi dari file temp
            $text = $this->extractFromPath($tmpPath);
            
            // Hapus file temp
            @unlink($tmpPath);
            
            return $text;
        } catch (\Exception $e) {
            error_log("PdfExtractor::extractFromString Exception: " . $e->getMessage());
            return "";
        }
    }
}
