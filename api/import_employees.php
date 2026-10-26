<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
require_method('POST');
check_same_origin();
$user = require_auth();
require_csrf();
require_editor($user);
$input = read_json_body(32_000_000);
$sourceFile = trim((string)($input['sourceFile'] ?? 'Data_Karyawan.xlsx'));
$sourceFile = short_text(basename(str_replace('\\', '/', $sourceFile)), 255);
$rows = $input['rows'] ?? null;
$stats = is_array($input['stats'] ?? null) ? $input['stats'] : [];
if (!is_array($rows) || !array_is_list($rows)) fail_request('Data karyawan tidak terbaca sebagai daftar baris.', 422);
if (count($rows) < 1) fail_request('Tidak ada baris karyawan yang valid untuk diimpor.', 422);
if (count($rows) > 100000) fail_request('Jumlah baris karyawan melampaui batas satu kali impor.', 413);

$hasStatusName = false; $hasEmpStatus = false;
foreach ($rows as $r) {
    if (!is_array($r)) continue;
    if (trim((string)row_value($r, ['STATUS NAME'], '')) !== '') $hasStatusName = true;
    if (trim((string)row_value($r, ['EMP STATUS'], '')) !== '') $hasEmpStatus = true;
}
$statusKey = $hasStatusName ? 'STATUS NAME' : ($hasEmpStatus ? 'EMP STATUS' : '');
$accepted = [];
$seen = [];
$duplicates = 0;
$rejected = 0;
$inactive = 0;
foreach ($rows as $row) {
    if (!is_array($row)) { $rejected++; continue; }
    $nik = trim((string)row_value($row, ['NIK PENDEK'], ''));
    $nama = trim((string)row_value($row, ['NAMA'], ''));
    if ($nik === '' || $nama === '') { $rejected++; continue; }
    if ($statusKey !== '') {
        $status = trim((string)row_value($row, [$statusKey], ''));
        if (!preg_match('/^(active|aktif)$/i', preg_replace('/\s+/', ' ', $status))) { $inactive++; continue; }
    }
    if (isset($seen[$nik])) { $duplicates++; continue; }
    $seen[$nik] = true;
    $accepted[] = $row;
}
if (count($accepted) < 1) fail_request('Semua baris karyawan tidak lolos validasi. Pastikan NIK PENDEK dan NAMA terisi, serta STATUS NAME = Active bila kolom status tersedia.', 422);

$clientTotal = (int)($stats['totalInput'] ?? count($rows));
$totalInput = max(count($rows), min(max($clientTotal, count($rows)), 1000000));
$clientDuplicates = max(0, (int)($stats['duplicates'] ?? 0));
$clientInvalid = max(0, (int)($stats['blankOrInvalid'] ?? 0));
$clientInactive = max(0, (int)($stats['inactive'] ?? 0) + (int)($stats['blankStatus'] ?? 0));
$recordedDuplicates = $clientDuplicates + $duplicates;
$estimatedRejected = max($clientInvalid + $clientInactive, $totalInput - count($accepted) - $recordedDuplicates);
$recordedRejected = max($rejected + $inactive, $estimatedRejected);

try {
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->exec("UPDATE employee_imports SET status = 'archived' WHERE status = 'active'");
    $insBatch = $pdo->prepare("INSERT INTO employee_imports (source_file, status, total_input, imported_count, rejected_count, duplicate_count, imported_by, created_at, activated_at) VALUES (?, 'archived', ?, ?, ?, ?, ?, NOW(), NULL)");
    $insBatch->execute([$sourceFile, $totalInput, count($accepted), $recordedRejected, $recordedDuplicates, (int)$user['id']]);
    $batchId = (int)$pdo->lastInsertId();
    $insEmployee = $pdo->prepare('INSERT INTO employees (employee_import_id, nik_short, nama, org_code, job_code, status_name, row_json) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($accepted as $row) {
        $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false) throw new RuntimeException('Gagal menyimpan baris karyawan ke format JSON.');
        $insEmployee->execute([
            $batchId,
            short_text(trim((string)row_value($row, ['NIK PENDEK'], '')), 100),
            short_text(trim((string)row_value($row, ['NAMA'], '')), 255),
            ($v = row_value($row, ['ORG CODE'])) === null ? null : short_text((string)$v, 100),
            ($v = row_value($row, ['JOB CODE'])) === null ? null : short_text((string)$v, 100),
            ($v = row_value($row, ['STATUS NAME', 'EMP STATUS'])) === null ? null : short_text((string)$v, 80),
            $json,
        ]);
    }
    $active = $pdo->prepare("UPDATE employee_imports SET status = 'active', activated_at = NOW() WHERE id = ?");
    $active->execute([$batchId]);
    audit($pdo, (int)$user['id'], 'employees_import_activated', [
        'batch_id' => $batchId, 'source_file' => $sourceFile,
        'total_input' => $totalInput, 'imported_count' => count($accepted),
        'rejected_count' => $recordedRejected,
        'duplicate_count' => $recordedDuplicates,
    ]);
    $pdo->commit();
    respond([
        'ok' => true,
        'message' => 'Data karyawan berhasil disimpan dan diaktifkan.',
        'batch_id' => $batchId,
        'imported_count' => count($accepted),
        'rejected_count' => $recordedRejected,
        'duplicate_count' => $recordedDuplicates,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('[Monitoring SDM] Employee import failed: ' . $e->getMessage());
    fail_request('Impor karyawan gagal. Data aktif sebelumnya dipertahankan. Periksa kolom wajib dan log PHP.', 500);
}
