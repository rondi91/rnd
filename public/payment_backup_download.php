<?php
declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

session_start();
$currentUser = $_SESSION['user'] ?? null;
if (!$currentUser || !is_array($currentUser)) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}
$currentUser = normalizeUser($currentUser);
if (!isAdmin($currentUser)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$file = isset($_GET['file']) ? basename((string) $_GET['file']) : '';
if ($file === '' || strpos($file, '..') !== false) {
    http_response_code(400);
    echo 'File tidak valid';
    exit;
}

$dir = __DIR__ . '/../storage/payment_backups';
$path = $dir . '/' . $file;
if (!file_exists($path)) {
    http_response_code(404);
    echo 'File tidak ditemukan';
    exit;
}

header('Content-Type: application/json');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;

function normalizeUser(array $user): array
{
    $perms = $user['permissions'] ?? [];
    if (is_string($perms)) {
        $perms = array_values(array_filter(array_map('trim', explode(',', $perms))));
    }
    if (!is_array($perms)) {
        $perms = [];
    }
    $user['permissions'] = $perms;
    $user['role'] = $user['role'] ?? '';
    return $user;
}

function isAdmin(array $user): bool
{
    $role = strtolower((string) ($user['role'] ?? ''));
    if ($role === 'admin') return true;
    $perms = $user['permissions'] ?? [];
    return in_array('*', $perms, true) || in_array('all', $perms, true);
}
