<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use RouterOS\Client;
use RouterOS\Query;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$routerId = isset($input['router_id']) ? (string)$input['router_id'] : '';
$target = trim((string)($input['target'] ?? ''));
$direction = trim((string)($input['direction'] ?? 'receive'));
$protocol = trim((string)($input['protocol'] ?? 'udp'));
$duration = (int)($input['duration'] ?? 10);
$duration = max(1, min($duration, 120));

if ($routerId === '' || $target === '') {
    http_response_code(400);
    echo json_encode(['error' => 'router_id dan target diperlukan']);
    exit;
}

// server credential (opsional, default sama dengan router)
$targetUser = isset($input['target_user']) ? (string)$input['target_user'] : $input['user'] ?? '';
$targetPass = isset($input['target_pass']) ? (string)$input['target_pass'] : $input['password'] ?? '';

$file = __DIR__ . '/../storage/mikrotik.json';
$routers = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
if (!is_array($routers)) $routers = [];

$router = null;
foreach ($routers as $r) {
    if ((string)($r['id'] ?? '') === $routerId) {
        $router = $r;
        break;
    }
}

if (!$router) {
    http_response_code(404);
    echo json_encode(['error' => 'Router tidak ditemukan']);
    exit;
}

$host = $router['host'] ?? '';
$user = $router['username'] ?? '';
$pass = $router['password'] ?? '';

// coba cari kredensial target berdasarkan mikrotik.json
$targetCred = null;
foreach ($routers as $r) {
    if (($r['host'] ?? '') === $target) {
        $targetCred = $r;
        break;
    }
}
if ($targetCred) {
    $targetUser = $targetUser !== '' ? $targetUser : ($targetCred['username'] ?? $user);
    $targetPass = $targetPass !== '' ? $targetPass : ($targetCred['password'] ?? $pass);
}

try {
    // tambahkan sedikit lebih lama dari durasi test
    $client = new Client([
        'host' => $host,
        'user' => $user,
        'pass' => $pass ?? '',
        'port' => 8728,
        'timeout' => max(15, $duration + 10),
        'attempts' => 1,
    ]);

    // jalankan bandwidth-test
    $query = (new Query('/tool/bandwidth-test'))
        ->equal('address', $target)
        ->equal('user', $targetUser !== '' ? $targetUser : $user)
        ->equal('password', $targetPass !== '' ? $targetPass : ($pass ?? ''))
        ->equal('direction', $direction)
        ->equal('protocol', $protocol)
        ->equal('duration', (string)$duration)
        ->equal('connection-count', '20');

    $resp = $client->query($query)->read();

    echo json_encode([
        'message' => 'Bandwidth test dijalankan',
        'router' => $router['name'] ?? $host,
        'target' => $target,
        'response' => $resp,
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
