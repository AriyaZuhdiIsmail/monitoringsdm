<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, max-age=0');

$scriptDir = dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/api/endpoint.php'));
$cookiePath = rtrim($scriptDir, '/\\') . '/';
if ($cookiePath === '//') $cookiePath = '/';
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('MONSDMSESSID');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => $cookiePath,
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function respond(array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function fail_request(string $message, int $status = 400): never {
    respond(['ok' => false, 'message' => $message], $status);
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $cfg = require dirname(__DIR__, 2) . '/config/database.php';
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['database'], $cfg['charset']);
    try {
        $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (Throwable $e) {
        error_log('[Monitoring SDM] DB connection failed: ' . $e->getMessage());
        fail_request('Koneksi database gagal. Periksa config/database.php dan pastikan MySQL sudah berjalan.', 503);
    }
    return $pdo;
}

function read_json_body(int $maxBytes = 32_000_000): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > $maxBytes) fail_request('Ukuran permintaan terlalu besar. Periksa batas post_max_size PHP.', 413);
    $data = json_decode($raw, true);
    if (!is_array($data)) fail_request('Data permintaan tidak valid (JSON).', 400);
    return $data;
}

function require_method(string $method): void {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== strtoupper($method)) {
        header('Allow: ' . strtoupper($method));
        fail_request('Metode permintaan tidak diizinkan.', 405);
    }
}

function check_same_origin(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') return;
    $requestScheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) ? 'https' : 'http';
    $originScheme = strtolower((string)parse_url($origin, PHP_URL_SCHEME));
    $originHost = strtolower((string)parse_url($origin, PHP_URL_HOST));
    $originPort = (int)(parse_url($origin, PHP_URL_PORT) ?: ($originScheme === 'https' ? 443 : 80));
    $requestUrl = parse_url($requestScheme . '://' . (string)($_SERVER['HTTP_HOST'] ?? ''));
    $requestName = strtolower((string)($requestUrl['host'] ?? ''));
    $requestPort = (int)($requestUrl['port'] ?? ($requestScheme === 'https' ? 443 : 80));
    if ($originHost === '' || $originHost !== $requestName || $originPort !== $requestPort || $originScheme !== $requestScheme) {
        fail_request('Permintaan lintas domain ditolak.', 403);
    }
}
function require_auth(): array {
    if (empty($_SESSION['user']) || !is_array($_SESSION['user'])) fail_request('Sesi berakhir. Silakan masuk kembali.', 401);
    return $_SESSION['user'];
}

function require_csrf(): void {
    $actual = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!is_string($actual) || $expected === '' || !hash_equals($expected, $actual)) {
        fail_request('Token keamanan tidak cocok. Muat ulang halaman dan coba kembali.', 419);
    }
}

function require_editor(array $user): void {
    if (!in_array($user['role'] ?? '', ['admin', 'editor'], true)) fail_request('Akun ini hanya memiliki akses baca.', 403);
}

function require_admin(array $user): void {
    if (($user['role'] ?? '') !== 'admin') fail_request('Fitur ini hanya dapat digunakan oleh administrator.', 403);
}

function audit(PDO $pdo, ?int $userId, string $action, array $details = []): void {
    $st = $pdo->prepare('INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)');
    $st->execute([$userId, $action, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
}

function short_text(string $value, int $max): string {
    return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
}

function normalized_headers(array $row): array {
    $out = [];
    foreach ($row as $key => $value) {
        $norm = strtoupper(trim(preg_replace('/\s+/', ' ', (string)$key)));
        $out[$norm] = $value;
    }
    return $out;
}

function row_value(array $row, array $keys, mixed $default = null): mixed {
    $normalized = normalized_headers($row);
    foreach ($keys as $key) {
        $key = strtoupper(trim(preg_replace('/\s+/', ' ', (string)$key)));
        if (array_key_exists($key, $normalized)) return $normalized[$key];
    }
    return $default;
}
