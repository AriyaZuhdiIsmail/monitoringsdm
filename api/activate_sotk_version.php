<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
require_method('POST');
check_same_origin();
$user = require_auth();
require_csrf();
require_admin($user);
$input = read_json_body(100_000);
$id = filter_var($input['versionId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) fail_request('Versi SOTK yang dipilih tidak valid.', 422);
try {
    $pdo = db();
    $check = $pdo->prepare('SELECT id, version_name, status, total_rows FROM sotk_versions WHERE id = ? LIMIT 1');
    $check->execute([(int)$id]);
    $version = $check->fetch();
    if (!$version) fail_request('Versi SOTK tidak ditemukan.', 404);
    $rowCheck = $pdo->prepare('SELECT COUNT(*) FROM sotk_rows WHERE version_id = ?');
    $rowCheck->execute([(int)$id]);
    if ((int)$rowCheck->fetchColumn() < 1) fail_request('Versi ini tidak memiliki baris master dan tidak dapat diaktifkan.', 422);
    $pdo->beginTransaction();
    $pdo->exec("UPDATE sotk_versions SET status = 'archived' WHERE status = 'active'");
    $activate = $pdo->prepare("UPDATE sotk_versions SET status = 'active', activated_at = NOW() WHERE id = ?");
    $activate->execute([(int)$id]);
    audit($pdo, (int)$user['id'], 'sotk_version_rollback', ['version_id' => (int)$id, 'version_name' => $version['version_name']]);
    $pdo->commit();
    respond(['ok' => true, 'message' => 'Versi SOTK berhasil diaktifkan kembali.', 'version_id' => (int)$id, 'version_name' => $version['version_name']]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('[Monitoring SDM] SOTK version activate failed: ' . $e->getMessage());
    fail_request('Versi SOTK gagal diaktifkan. Versi aktif sebelumnya dipertahankan.', 500);
}
