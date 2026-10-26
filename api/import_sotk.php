<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
require_method('POST');
check_same_origin();
$user = require_auth();
require_csrf();
require_admin($user);
$input = read_json_body(32_000_000);
$sourceFile = trim((string)($input['sourceFile'] ?? 'SOTK_MASTER.xlsx'));
$sourceFile = short_text(basename(str_replace('\\', '/', $sourceFile)), 255);
$sheets = $input['sheets'] ?? null;
$requiredSheets = [
    'SOTK_WILAYAH', 'SOTK_AREA', 'SOTK_CABANG', 'UNIT_MASTER_CABANG',
    'CORPORATE_TITLE', 'WEB_FILTER_MASTER', 'POSITION_GRADE_RULES', 'POSITION_GRADE_TIER_MAP',
];
if (!is_array($sheets)) fail_request('Sheet SOTK tidak ditemukan dalam file.', 422);
foreach ($requiredSheets as $name) {
    if (!isset($sheets[$name]) || !is_array($sheets[$name]) || count($sheets[$name]) < 1) {
        fail_request('Sheet wajib tidak ada atau kosong: ' . $name . '. Gunakan workbook master dengan delapan sheet lengkap.', 422);
    }
}
$totalRows = 0;
$totalCabangFormasi = 0.0;
foreach ($requiredSheets as $name) {
    if (count($sheets[$name]) > 50000) fail_request('Jumlah baris terlalu besar pada sheet ' . $name . '.', 413);
    $totalRows += count($sheets[$name]);
    foreach ($sheets[$name] as $row) {
        if (!is_array($row)) fail_request('Format baris tidak valid pada sheet ' . $name . '.', 422);
        if ($name === 'SOTK_CABANG') {
            $v = row_value($row, ['FORMASI'], 0);
            if (is_numeric($v)) $totalCabangFormasi += (float)$v;
        }
    }
}
if ($totalCabangFormasi <= 0) fail_request('Total formasi pada sheet SOTK_CABANG harus lebih dari nol. Master tidak diaktifkan.', 422);
if (count($sheets['UNIT_MASTER_CABANG']) < 1) fail_request('UNIT_MASTER_CABANG harus berisi daftar unit.', 422);

try {
    $pdo = db();
    $pdo->beginTransaction();
    $versionName = 'SOTK-' . date('Ymd-His');
    $insVersion = $pdo->prepare("INSERT INTO sotk_versions (version_name, source_file, status, total_rows, total_formasi_cabang, imported_by, created_at, activated_at) VALUES (?, ?, 'archived', ?, ?, ?, NOW(), NULL)");
    $insVersion->execute([$versionName, $sourceFile, $totalRows, $totalCabangFormasi, (int)$user['id']]);
    $versionId = (int)$pdo->lastInsertId();
    $insRow = $pdo->prepare('INSERT INTO sotk_rows (version_id, sheet_name, row_number, org_code, job_code, formation_key, formasi, row_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($requiredSheets as $sheetName) {
        $rowNum = 0;
        foreach ($sheets[$sheetName] as $row) {
            if (!is_array($row)) continue;
            $rowNum++;
            $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($json === false) throw new RuntimeException('Gagal mengubah baris menjadi JSON pada sheet ' . $sheetName . '.');
            $org = row_value($row, ['ORG CODE', 'UNIT_KEY', 'BRANCH_KEY']);
            $job = row_value($row, ['JOB CODE', 'SOTK_JOB_KEY']);
            $formationKey = row_value($row, ['FORMASI_KEY', 'MATCH_KEY', 'UNIT_KEY']);
            $formasi = row_value($row, ['FORMASI']);
            $insRow->execute([
                $versionId, $sheetName, $rowNum,
                $org === null ? null : short_text((string)$org, 100),
                $job === null ? null : short_text((string)$job, 100),
                $formationKey === null ? null : short_text((string)$formationKey, 255),
                is_numeric($formasi) ? (float)$formasi : null,
                $json,
            ]);
        }
    }
    $pdo->exec("UPDATE sotk_versions SET status = 'archived' WHERE status = 'active'");
    $active = $pdo->prepare("UPDATE sotk_versions SET status = 'active', activated_at = NOW() WHERE id = ?");
    $active->execute([$versionId]);
    audit($pdo, (int)$user['id'], 'sotk_import_activated', [
        'version_id' => $versionId, 'source_file' => $sourceFile,
        'rows_by_sheet' => array_map('count', array_intersect_key($sheets, array_flip($requiredSheets))),
        'total_rows' => $totalRows, 'total_formasi_cabang' => $totalCabangFormasi,
    ]);
    $pdo->commit();
    respond([
        'ok' => true,
        'message' => 'Master SOTK berhasil divalidasi dan diaktifkan.',
        'version' => $versionName,
        'version_id' => $versionId,
        'total_rows' => $totalRows,
        'rows_by_sheet' => array_map('count', array_intersect_key($sheets, array_flip($requiredSheets))),
        'total_formasi_cabang' => $totalCabangFormasi,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('[Monitoring SDM] SOTK import failed: ' . $e->getMessage());
    fail_request('Impor SOTK gagal. Master aktif sebelumnya dipertahankan. Periksa struktur sheet dan log PHP.', 500);
}
