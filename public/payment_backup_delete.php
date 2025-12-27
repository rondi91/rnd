<?php
declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

header('Content-Type: application/json');

session_start();
$currentUser = $_SESSION['user'] ?? null;
if (!$currentUser || !is_array($currentUser)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
$currentUser = normalizeUser($currentUser);
if (!isAdmin($currentUser)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = [];
$requested = isset($input['file']) ? trim((string) $input['file']) : '';

$dir = __DIR__ . '/../storage/payment_backups';
if (!is_dir($dir)) {
    http_response_code(404);
    echo json_encode(['error' => 'Folder backup tidak ditemukan']);
    exit;
}

$target = '';
if ($requested === '' || strtolower($requested) === 'latest') {
    $latest = null;
    foreach (glob($dir . '/*.json') as $path) {
        $mtime = filemtime($path) ?: 0;
        if ($latest === null || $mtime > $latest['mtime']) {
            $latest = ['path' => $path, 'mtime' => $mtime];
        }
    }
    if ($latest === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Tidak ada file backup']);
        exit;
    }
    $target = $latest['path'];
} else {
    $name = basename($requested);
    if ($name === '' || strpos($name, '..') !== false) {
        http_response_code(400);
        echo json_encode(['error' => 'File tidak valid']);
        exit;
    }
    $target = $dir . '/' . $name;
    if (!file_exists($target)) {
        http_response_code(404);
        echo json_encode(['error' => 'File tidak ditemukan']);
        exit;
    }
}

$deletedName = basename($target);
if (!@unlink($target)) {
    http_response_code(500);
    echo json_encode(['error' => 'Gagal menghapus file']);
    exit;
}

echo json_encode([
    'message' => 'Backup dihapus',
    'deleted' => $deletedName,
]);
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
