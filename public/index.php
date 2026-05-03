<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Load .env
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

session_start();

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serve Frontend SPA for non-API routes
if (strpos($uri, '/api') !== 0 && $uri !== '/health') {
    if (file_exists(__DIR__ . '/app.html')) {
        header('Content-Type: text/html');
        readfile(__DIR__ . '/app.html');
        exit;
    }
}

// Set default header for API
header('Content-Type: application/json');

// Format: [METHOD, PATH, ControllerClass, method, requireAuth, requireRole]
$routes = [
    ['GET',    '/health',                  null,                                              'health',      false, null],
    
    // Auth
    ['POST',   '/api/auth/register',       \App\Controllers\AuthController::class,            'register',    false, null],
    ['POST',   '/api/auth/login',          \App\Controllers\AuthController::class,            'login',       false, null],
    ['POST',   '/api/auth/logout',         \App\Controllers\AuthController::class,            'logout',      true,  null],
    ['GET',    '/api/auth/me',             \App\Controllers\AuthController::class,            'me',          true,  null],
    
    // Kelas
    ['GET',    '/api/kelas',               \App\Controllers\KelasController::class,           'index',       true,  null],
    ['POST',   '/api/kelas',               \App\Controllers\KelasController::class,           'create',      true,  'asprak'],
    ['GET',    '/api/kelas/{id}',          \App\Controllers\KelasController::class,           'show',        true,  null],
    ['POST',   '/api/kelas/join',          \App\Controllers\KelasController::class,           'join',        true,  'mahasiswa'],

    // Tugas
    ['GET',    '/api/tugas',               \App\Controllers\TugasController::class,           'index',       true,  null],
    ['POST',   '/api/tugas',               \App\Controllers\TugasController::class,           'create',      true,  'asprak'],
    ['GET',    '/api/tugas/{id}',          \App\Controllers\TugasController::class,           'show',        true,  null],
    ['PUT',    '/api/tugas/{id}',          \App\Controllers\TugasController::class,           'update',      true,  'asprak'],
    ['DELETE', '/api/tugas/{id}',          \App\Controllers\TugasController::class,           'destroy',     true,  'asprak'],
    ['GET',    '/api/tugas/{id}/lampiran',  \App\Controllers\TugasController::class,           'downloadLampiran', true,  null],

    // Laporan
    ['POST',   '/api/laporan/upload',      \App\Controllers\LaporanController::class,         'upload',      true,  'mahasiswa'],
    ['GET',    '/api/laporan',             \App\Controllers\LaporanController::class,         'index',       true,  null],
    ['GET',    '/api/laporan/compare',     \App\Controllers\LaporanController::class,         'compare',     true,  'asprak'],
    ['DELETE', '/api/laporan/{id}/cancel', \App\Controllers\LaporanController::class,         'cancel',      true,  'mahasiswa'],
    ['GET',    '/api/laporan/{id}',        \App\Controllers\LaporanController::class,         'show',        true,  null],
    
    // Analisis
    ['POST',   '/api/analisis/{id}',       \App\Controllers\AnalisisController::class,        'runAnalysis', true,  'asprak'],
    
    // Kemiripan
    ['GET',    '/api/kemiripan',           \App\Controllers\KemiripanController::class,       'index',       true,  'asprak'],
    ['PUT',    '/api/kemiripan/{id}/flag', \App\Controllers\KemiripanController::class,       'flag',        true,  'asprak'],
    
    // Nilai
    ['POST',   '/api/nilai',               \App\Controllers\NilaiController::class,           'submit',      true,  'asprak'],
    ['GET',    '/api/nilai/export',        \App\Controllers\NilaiController::class,           'export',      true,  'asprak'], // Pastikan spesifik dulu
    ['PUT',    '/api/nilai/{id}',          \App\Controllers\NilaiController::class,           'update',      true,  'asprak'],
    
    // Materi
    ['GET',    '/api/materi',              \App\Controllers\MateriController::class,          'index',       true,  null],
    ['POST',   '/api/materi',              \App\Controllers\MateriController::class,          'create',      true,  'asprak'],
    ['PUT',    '/api/materi/{id}',         \App\Controllers\MateriController::class,          'update',      true,  'asprak'],
    ['DELETE', '/api/materi/{id}',         \App\Controllers\MateriController::class,          'destroy',     true,  'asprak'],
    ['GET',    '/api/materi/{id}/lampiran', \App\Controllers\MateriController::class,        'downloadLampiran', true,  null],
    
    // Notifikasi
    ['GET',    '/api/notifikasi',          \App\Controllers\NotifikasiController::class,      'index',       true,  null],
    ['PUT',    '/api/notifikasi/read-all', \App\Controllers\NotifikasiController::class,      'readAll',     true,  null],
];

$routeFound = false;
$methodMatch = false;

foreach ($routes as $route) {
    list($rMethod, $rPath, $rClass, $rFunc, $rAuth, $rRole) = $route;
    
    // Cek exact match
    if ($rPath === $uri) {
        $routeFound = true;
        if ($method === $rMethod) {
            $methodMatch = true;
            dispatch($route, null);
            exit;
        }
    }
    
    // Cek pattern match (untuk {id})
    if (strpos($rPath, '{id}') !== false) {
        $pattern = '#^' . str_replace('{id}', '([^/]+)', $rPath) . '$#';
        if (preg_match($pattern, $uri, $matches)) {
            $routeFound = true;
            if ($method === $rMethod) {
                $methodMatch = true;
                dispatch($route, $matches[1]);
                exit;
            }
        }
    }
}

// Handle errors
if (!$routeFound) {
    http_response_code(404);
    echo json_encode(['error' => 'Not Found']);
} elseif (!$methodMatch) {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
}

/**
 * Dispatch route ke controller yang sesuai
 */
function dispatch(array $route, ?string $param): void {
    list($rMethod, $rPath, $rClass, $rFunc, $rAuth, $rRole) = $route;
    
    // Handle endpoint /health spesial
    if ($rPath === '/health') {
        include __DIR__ . '/health.php';
        return;
    }
    
    // Handle auth & role middleware
    if ($rAuth) {
        if ($rRole) {
            \App\Middleware\AuthMiddleware::requireRole($rRole);
        } else {
            \App\Middleware\AuthMiddleware::check();
        }
    }
    
    // Instansiasi dan jalankan controller
    $controller = new $rClass();
    if ($param !== null) {
        $controller->$rFunc($param);
    } else {
        $controller->$rFunc();
    }
}
