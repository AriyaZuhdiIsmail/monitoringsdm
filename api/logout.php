<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
require_method('POST');
check_same_origin();
$user = require_auth();
require_csrf();
try {
    audit(db(), (int)$user['id'], 'logout', []);
} catch (Throwable $e) {
    error_log('[Monitoring SDM] Logout audit failed: ' . $e->getMessage());
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
}
session_destroy();
respond(['ok' => true, 'message' => 'Anda telah keluar.']);
