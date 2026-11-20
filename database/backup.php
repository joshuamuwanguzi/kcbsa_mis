<?php
// Daily XAMPP backup helper. Run from a terminal after updating the credentials:
// php database/backup.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$host = 'localhost';
$user = 'root';
$password = '';
$database = 'kcbsa_mis';
$backupDir = __DIR__ . '/backups';
if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
    fwrite(STDERR, "Could not create backup directory.\n");
    exit(1);
}
$file = $backupDir . '/kcbsa-' . date('Ymd-His') . '.sql';
$command = sprintf(
    'mysqldump --host=%s --user=%s %s --single-transaction --routines --triggers %s > %s',
    escapeshellarg($host),
    escapeshellarg($user),
    $password === '' ? '' : '--password=' . escapeshellarg($password),
    escapeshellarg($database),
    escapeshellarg($file)
);
exec($command . ' 2>&1', $output, $status);
if ($status !== 0 || !is_file($file) || filesize($file) === 0) {
    @unlink($file);
    fwrite(STDERR, "Backup failed: " . implode("\n", $output) . "\n");
    exit(1);
}
fwrite(STDOUT, "Backup created: {$file}\n");
