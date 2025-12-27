<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

session_start();
$user = $_SESSION['user'] ?? null;
if (!$user || !is_array($user) || !isAdmin($user)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$locationsFile = __DIR__ . '/../storage/alamat.json';
$existing = file_exists($locationsFile) ? json_decode(file_get_contents($locationsFile), true) : [];
if (!is_array($existing)) $existing = [];
$set = [];
foreach ($existing as $loc) {
    $loc = strtoupper(trim((string) $loc));
    if ($loc !== '') $set[$loc] = true;
}

// ambil data PPPoE dari endpoint
$pppoeData = null;
$url = null;
if (!empty($_SERVER['HTTP_HOST'])) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base = rtrim(dirname($_SERVER['REQUEST_URI'] ?? ''), '/\\');
    $url = $scheme . '://' . $_SERVER['HTTP_HOST'] . $base . '/pppoe_data.php';
    $pppoeJson = @file_get_contents($url);
    if ($pppoeJson !== false) {
        $pppoeData = json_decode($pppoeJson, true);
    }
}
if ($pppoeData === null || !is_array($pppoeData)) {
    $pppoeJson = @shell_exec('php ' . escapeshellarg(__DIR__ . '/pppoe_data.php'));
    if ($pppoeJson !== null) {
        $pppoeData = json_decode($pppoeJson, true);
    }
}
if (!is_array($pppoeData)) {
    http_response_code(500);
    echo json_encode(['error' => 'Gagal mengambil data PPPoE']);
    exit;
}

$sources = array_merge($pppoeData['active'] ?? [], $pppoeData['inactive_users'] ?? []);
foreach ($sources as $row) {
    $uname = $row['username'] ?? '';
    if ($uname === '') continue;
    $loc = deriveLocation($uname);
    if ($loc !== '') $set[$loc] = true;
}

$locations = array_values(array_keys($set));
sort($locations, SORT_NATURAL | SORT_FLAG_CASE);

if (false === file_put_contents($locationsFile, json_encode($locations, JSON_PRETTY_PRINT))) {
    http_response_code(500);
    echo json_encode(['error' => 'Gagal menyimpan alamat.json']);
    exit;
}

echo json_encode(['locations' => $locations]);

function deriveLocation(string $username): string
{
    $pos = strrpos($username, '@');
    if ($pos === false) return '';
    $loc = substr($username, $pos + 1);
    return strtoupper(trim($loc));
}

function isAdmin(array $user): bool
{
    $role = strtolower((string) ($user['role'] ?? ''));
    if ($role === 'admin') return true;
    $perms = $user['permissions'] ?? [];
    if (is_string($perms)) {
        $perms = array_values(array_filter(array_map('trim', explode(',', $perms))));
    }
    if (!is_array($perms)) $perms = [];
    return in_array('*', $perms, true) || in_array('all', $perms, true);
}
