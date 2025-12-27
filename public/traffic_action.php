<?php
declare(strict_types=1);

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
$routerId = isset($input['router_id']) ? (string) $input['router_id'] : '';
$iface = trim($input['interface'] ?? '');

if ($routerId === '' || $iface === '') {
    http_response_code(400);
    echo json_encode(['error' => 'router_id dan interface diperlukan']);
    exit;
}

$file = __DIR__ . '/../storage/mikrotik.json';
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
if (!is_array($data)) $data = [];

$updated = false;
foreach ($data as &$row) {
    if ((string)($row['id'] ?? '') === $routerId) {
        $row['traffic_interface'] = $iface;
        $updated = true;
        break;
    }
}
unset($row);

if (!$updated) {
    http_response_code(404);
    echo json_encode(['error' => 'Router tidak ditemukan']);
    exit;
}

file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
echo json_encode(['message' => 'Interface disimpan', 'router_id' => $routerId, 'interface' => $iface]);
