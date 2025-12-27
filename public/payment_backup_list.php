<?php
declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, OPTIONS');

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

$dir = __DIR__ . '/../storage/payment_backups';
$list = [];
if (is_dir($dir)) {
    foreach (glob($dir . '/*.json') as $path) {
        $name = basename($path);
        if ($name === '' || strpos($name, '..') !== false) continue;
        $list[] = [
            'name' => $name,
            'size' => filesize($path) ?: 0,
            'mtime' => filemtime($path) ?: 0,
        ];
    }
}
usort($list, function($a, $b){
    return ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0);
});

echo json_encode([
    'count' => count($list),
    'files' => $list,
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
