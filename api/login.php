<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
require_method('POST');
check_same_origin();
$input = read_json_body(100_000);
$username = trim((string)($input['username'] ?? ''));
$password = (string)($input['password'] ?? '');
if ($username === '' || $password === '') fail_request('Username dan password wajib diisi.', 422);
if (!empty($_SESSION['login_locked_until']) && time() < (int)$_SESSION['login_locked_until']) {
    fail_request('Terlalu banyak percobaan login. Coba kembali beberapa menit lagi.', 429);
}
try {
    $pdo = db();
    $st = $pdo->prepare('SELECT id, username, password_hash, display_name, role, is_active FROM users WHERE username = ? LIMIT 1');
    $st->execute([$username]);
    $user = $st->fetch();
    if (!$user || (int)$user['is_active'] !== 1 || !password_verify($password, $user['password_hash'])) {
        $tries = (int)($_SESSION['login_failures'] ?? 0) + 1;
        $_SESSION['login_failures'] = $tries;
        if ($tries >= 8) $_SESSION['login_locked_until'] = time() + 300;
        fail_request('Username atau password salah.', 401);
    }
    session_regenerate_id(true);
    unset($_SESSION['login_failures'], $_SESSION['login_locked_until']);
    $_SESSION['user'] = [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'display_name' => $user['display_name'],
        'role' => $user['role'],
    ];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $up = $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
    $up->execute([(int)$user['id']]);
    audit($pdo, (int)$user['id'], 'login', ['username' => $user['username']]);
    respond(['ok' => true, 'user' => $_SESSION['user'], 'csrf' => $_SESSION['csrf_token']]);
} catch (Throwable $e) {
    if ($e instanceof PDOException) {
        error_log('[Monitoring SDM] Login error: ' . $e->getMessage());
        fail_request('Database belum siap atau tabel belum dibuat. Ikuti langkah instalasi pada README_ID.md.', 503);
    }
    throw $e;
}
