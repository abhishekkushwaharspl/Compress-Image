<?php
// Download all compressed images as ZIP
define('COMPRESSED_DIR', __DIR__ . '/compressed/');

if (!isset($_GET['zip'])) {
    http_response_code(400);
    exit('Missing zip param');
}

$files = glob(COMPRESSED_DIR . '*.{jpg,jpeg,png,webp,gif}', GLOB_BRACE);
if (!$files) {
    http_response_code(404);
    exit('No compressed files found.');
}

// Optional: only files from last 30 minutes to avoid zipping everything
// Comment out to zip all files
$recent = array_filter($files, fn($f) => time() - filemtime($f) < 1800);
if ($recent) $files = $recent;

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('ZipArchive not enabled on this server.');
}

$zipName = 'compressed_' . date('Ymd_His') . '.zip';
$tmpZip = sys_get_temp_dir() . '/' . $zipName;

$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit('Cannot create zip.');
}
foreach ($files as $f) {
    $zip->addFile($f, basename($f));
}
$zip->close();

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . filesize($tmpZip));
header('Cache-Control: no-cache');
readfile($tmpZip);
unlink($tmpZip);
exit;
