<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
require_method('GET');
$user = require_auth();
try {
    $pdo = db();
    $sotkVersion = null;
    $s = $pdo->query("SELECT id, version_name, source_file, total_rows, total_formasi_cabang, created_at, activated_at FROM sotk_versions WHERE status = 'active' ORDER BY id DESC LIMIT 1")->fetch();
    if ($s) {
        $sheetStmt = $pdo->prepare('SELECT sheet_name, row_json FROM sotk_rows WHERE version_id = ? ORDER BY sheet_name, row_number');
        $sheetStmt->execute([(int)$s['id']]);
        $sheets = [];
        while ($row = $sheetStmt->fetch()) {
            $decoded = json_decode($row['row_json'], true);
            if (is_array($decoded)) $sheets[$row['sheet_name']][] = $decoded;
        }
        $sotkVersion = [
            'id' => (int)$s['id'],
            'version_name' => $s['version_name'],
            'source_file' => $s['source_file'],
            'total_rows' => (int)$s['total_rows'],
            'total_formasi_cabang' => (float)$s['total_formasi_cabang'],
            'created_at' => $s['created_at'],
            'activated_at' => $s['activated_at'],
            'sheets' => $sheets,
        ];
    }

    $versionRows = $pdo->query('SELECT id, version_name, source_file, status, total_rows, total_formasi_cabang, created_at, activated_at FROM sotk_versions ORDER BY id DESC LIMIT 20')->fetchAll();
    $sotkVersions = array_map(static function (array $v): array {
        return [
            'id' => (int)$v['id'], 'version_name' => $v['version_name'], 'source_file' => $v['source_file'],
            'status' => $v['status'], 'total_rows' => (int)$v['total_rows'],
            'total_formasi_cabang' => (float)$v['total_formasi_cabang'],
            'created_at' => $v['created_at'], 'activated_at' => $v['activated_at'],
        ];
    }, $versionRows);

    $batchStmt = $pdo->query("SELECT id, source_file, total_input, imported_count, rejected_count, duplicate_count, created_at, activated_at FROM employee_imports WHERE status = 'active' ORDER BY id DESC LIMIT 1");
    $b = $batchStmt->fetch();
    $employees = null;
    if ($b) {
        $empStmt = $pdo->prepare('SELECT row_json FROM employees WHERE employee_import_id = ? ORDER BY id');
        $empStmt->execute([(int)$b['id']]);
        $rows = [];
        while ($row = $empStmt->fetch()) {
            $decoded = json_decode($row['row_json'], true);
            if (is_array($decoded)) $rows[] = $decoded;
        }
        $employees = [
            'id' => (int)$b['id'],
            'source_file' => $b['source_file'],
            'total_input' => (int)$b['total_input'],
            'imported_count' => (int)$b['imported_count'],
            'rejected_count' => (int)$b['rejected_count'],
            'duplicate_count' => (int)$b['duplicate_count'],
            'created_at' => $b['created_at'],
            'activated_at' => $b['activated_at'],
            'rows' => $rows,
        ];
    }
    respond([
        'ok' => true,
        'user' => $user,
        'csrf' => $_SESSION['csrf_token'] ?? '',
        'sotk' => $sotkVersion,
        'sotkVersions' => $sotkVersions,
        'employees' => $employees,
    ]);
} catch (Throwable $e) {
    error_log('[Monitoring SDM] Bootstrap error: ' . $e->getMessage());
    fail_request('Gagal membaca data database. Periksa struktur database dan log PHP.', 500);
}
