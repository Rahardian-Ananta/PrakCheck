<?php

namespace App\Config;

/**
 * Supabase Client (Singleton)
 * Menangani semua request ke Supabase REST API menggunakan SERVICE_KEY.
 */
class SupabaseClient {
    private static ?SupabaseClient $instance = null;
    private string $baseUrl;
    private string $serviceKey;

    /**
     * Private constructor untuk Singleton
     */
    private function __construct() {
        $this->baseUrl = $_ENV['SUPABASE_URL'] ?? getenv('SUPABASE_URL');
        $this->serviceKey = $_ENV['SUPABASE_SERVICE_KEY'] ?? getenv('SUPABASE_SERVICE_KEY');
        
        if (!$this->baseUrl || !$this->serviceKey) {
            error_log("SupabaseClient: Missing SUPABASE_URL or SUPABASE_SERVICE_KEY in environment.");
        }
    }

    /**
     * Mendapatkan instance dari SupabaseClient
     * 
     * @return self
     */
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Melakukan GET ke /rest/v1/{table}
     * 
     * @param string $table
     * @param array $filters
     * @param string $select
     * @return array
     */
    public function select(string $table, array $filters = [], string $select = '*'): array {
        try {
            $filters['select'] = $select;
            $query = http_build_query($filters);
            $endpoint = "/rest/v1/{$table}?{$query}";
            
            $res = $this->request('GET', $endpoint);
            
            if ($res['error'] || !is_array($res['body'])) {
                return [];
            }
            
            return $res['body'];
        } catch (\Exception $e) {
            error_log("SupabaseClient::select Exception: " . $e->getMessage());
            return [];
        }
    }

    /**
     * POST ke /rest/v1/{table}
     * 
     * @param string $table
     * @param array $data
     * @return array|false
     */
    public function insert(string $table, array $data): array|false {
        try {
            $endpoint = "/rest/v1/{$table}";
            $headers = ["Prefer: return=representation"];
            
            $res = $this->request('POST', $endpoint, $data, $headers);
            
            if ($res['error'] || !is_array($res['body'])) {
                return false;
            }
            
            return $res['body'];
        } catch (\Exception $e) {
            error_log("SupabaseClient::insert Exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * PATCH ke /rest/v1/{table}
     * 
     * @param string $table
     * @param array $data
     * @param array $filters
     * @return array|false
     */
    public function update(string $table, array $data, array $filters): array|false {
        try {
            $query = http_build_query($filters);
            $endpoint = "/rest/v1/{$table}?{$query}";
            $headers = ["Prefer: return=representation"];
            
            $res = $this->request('PATCH', $endpoint, $data, $headers);
            
            if ($res['error'] || !is_array($res['body'])) {
                return false;
            }
            
            return $res['body'];
        } catch (\Exception $e) {
            error_log("SupabaseClient::update Exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * DELETE ke /rest/v1/{table}
     * 
     * @param string $table
     * @param array $filters
     * @return bool
     */
    public function delete(string $table, array $filters): bool {
        try {
            $query = http_build_query($filters);
            $endpoint = "/rest/v1/{$table}?{$query}";
            
            $res = $this->request('DELETE', $endpoint);
            
            return !$res['error'] && in_array($res['status'], [200, 204]);
        } catch (\Exception $e) {
            error_log("SupabaseClient::delete Exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * POST ke /storage/v1/object/{bucket}/{storagePath}
     * 
     * @param string $bucket
     * @param string $storagePath
     * @param string $localFilePath
     * @param string $mimeType
     * @return string|false
     */
    public function uploadFile(string $bucket, string $storagePath, string $localFilePath, string $mimeType): string|false {
        try {
            $content = file_get_contents($localFilePath);
            if ($content === false) {
                error_log("SupabaseClient::uploadFile error: Cannot read local file $localFilePath");
                return false;
            }

            $endpoint = "/storage/v1/object/{$bucket}/{$storagePath}";
            $headers = ["Content-Type: {$mimeType}"];
            
            $res = $this->request('POST', $endpoint, $content, $headers);
            
            if ($res['error']) {
                return false;
            }
            
            return $storagePath;
        } catch (\Exception $e) {
            error_log("SupabaseClient::uploadFile Exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * GET ke /storage/v1/object/{bucket}/{storagePath}
     * 
     * @param string $bucket
     * @param string $storagePath
     * @return string|false
     */
    public function downloadFile(string $bucket, string $storagePath): string|false {
        try {
            $endpoint = "/storage/v1/object/{$bucket}/{$storagePath}";
            
            $res = $this->request('GET', $endpoint);
            
            if ($res['error']) {
                return false;
            }
            
            // Jika konten berupa array (terjadi jika format JSON, misalnya error handling gagal ditangkap sebelumnya)
            // maka kita kembalikan false atau diencode, normalnya string binary.
            if (is_array($res['body'])) {
                return json_encode($res['body']);
            }
            
            return (string)$res['body'];
        } catch (\Exception $e) {
            error_log("SupabaseClient::downloadFile Exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * POST ke /storage/v1/object/sign/{bucket}/{storagePath}
     * 
     * @param string $bucket
     * @param string $storagePath
     * @param int $expiresIn
     * @return string|false
     */
    public function getSignedUrl(string $bucket, string $storagePath, int $expiresIn = 3600): string|false {
        try {
            $endpoint = "/storage/v1/object/sign/{$bucket}/{$storagePath}";
            $body = ["expiresIn" => $expiresIn];
            
            $res = $this->request('POST', $endpoint, $body);
            
            if ($res['error'] || !is_array($res['body']) || !isset($res['body']['signedURL'])) {
                return false;
            }
            
            return $this->baseUrl . $res['body']['signedURL'];
        } catch (\Exception $e) {
            error_log("SupabaseClient::getSignedUrl Exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * DELETE ke /storage/v1/object/{bucket}
     * 
     * @param string $bucket
     * @param string $storagePath
     * @return bool
     */
    public function deleteFile(string $bucket, string $storagePath): bool {
        try {
            $endpoint = "/storage/v1/object/{$bucket}";
            $body = ["prefixes" => [$storagePath]];
            
            $res = $this->request('DELETE', $endpoint, $body);
            
            return !$res['error'];
        } catch (\Exception $e) {
            error_log("SupabaseClient::deleteFile Exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mengelola semua request HTTP menggunakan cURL
     * 
     * @param string $method
     * @param string $endpoint
     * @param mixed $body
     * @param array $extraHeaders
     * @return array
     */
    private function request(string $method, string $endpoint, mixed $body = null, array $extraHeaders = []): array {
        $url = $this->baseUrl . $endpoint;
        $ch = curl_init($url);

        $headers = [
            "apikey: {$this->serviceKey}",
            "Authorization: Bearer {$this->serviceKey}"
        ];

        if ($body !== null) {
            if (is_array($body) || is_object($body)) {
                $body = json_encode($body);
                $headers[] = "Content-Type: application/json";
            }
            // Jika $body berupa string (seperti pada uploadFile), biarkan as is.
            // Content-Type harusnya sudah disediakan di $extraHeaders.
        }

        $headers = array_merge($headers, $extraHeaders);

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($error) {
            error_log("SupabaseClient Request Error: {$error}");
            return ['status' => 500, 'body' => null, 'error' => $error];
        }

        if ($status >= 400) {
            error_log("SupabaseClient HTTP Error {$status}: {$response}");
            return ['status' => $status, 'body' => $response, 'error' => "HTTP {$status}"];
        }

        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $responseBody = $decoded;
        } else {
            $responseBody = $response;
        }

        return ['status' => $status, 'body' => $responseBody, 'error' => null];
    }
}
