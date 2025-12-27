<?php
declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

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

$storageDir = __DIR__ . '/../storage';
$allowedFiles = [];
foreach (glob($storageDir . '/*.json') as $path) {
    $name = basename($path);
    if ($name === '' || strpos($name, '..') !== false) {
        continue;
    }
    $allowedFiles[$name] = $path;
}

$action = strtolower((string) ($_GET['action'] ?? $_POST['action'] ?? ''));
if ($action === 'export') {
    $payload = [
        'version' => 1,
        'generated_at' => date('c'),
        'files' => [],
    ];
    foreach ($allowedFiles as $name => $path) {
        $data = file_exists($path) ? json_decode(file_get_contents($path), true) : [];
        if (!is_array($data)) {
            $data = [];
        }
        $payload['files'][$name] = $data;
    }
    $filename = 'system_backup_' . date('Ymd_His') . '.json';
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode($payload, JSON_PRETTY_PRINT);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['backup_file'])) {
        http_response_code(400);
        echo json_encode(['error' => 'File backup wajib diupload']);
        exit;
    }
    $file = $_FILES['backup_file'];
    if (!is_uploaded_file($file['tmp_name'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Upload tidak valid']);
        exit;
    }
    $raw = file_get_contents($file['tmp_name']);
    $json = json_decode($raw, true);
    if (!is_array($json) || !isset($json['files']) || !is_array($json['files'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Format backup tidak valid']);
        exit;
    }
    $files = $json['files'];
    $written = [];
    foreach ($files as $name => $data) {
        if (!is_string($name) || $name === '') {
            continue;
        }
        if (!isset($allowedFiles[$name])) {
            http_response_code(400);
            echo json_encode(['error' => 'File tidak diizinkan: ' . $name]);
            exit;
        }
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['error' => 'Data ' . $name . ' tidak valid']);
            exit;
        }
        $path = $allowedFiles[$name];
        $ok = file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ($ok === false) {
            http_response_code(500);
            echo json_encode(['error' => 'Gagal menyimpan ' . $name]);
            exit;
        }
        $written[] = $name;
    }
    echo json_encode([
        'message' => 'Import selesai',
        'written' => $written,
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Aksi tidak dikenali']);
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
