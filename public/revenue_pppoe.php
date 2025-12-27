<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$file = __DIR__ . '/../storage/pppoe_prices.json';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $data = [];
    if (file_exists($file)) {
        $decoded = json_decode(file_get_contents($file), true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }
    echo json_encode(['data' => $data]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$routerId = isset($input['router_id']) ? (string) $input['router_id'] : '';
$profile = trim((string) ($input['profile'] ?? ''));
$price = $input['price'] ?? null;

if ($routerId === '' || $profile === '' || $price === null) {
    http_response_code(400);
    echo json_encode(['error' => 'router_id, profile, price wajib diisi']);
    exit;
}

$numPrice = is_numeric($price) ? (float) $price : null;
if ($numPrice === null || $numPrice < 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Harga harus angka >= 0']);
    exit;
}

$data = [];
if (file_exists($file)) {
    $decoded = json_decode(file_get_contents($file), true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}
if (!isset($data[$routerId]) || !is_array($data[$routerId])) {
    $data[$routerId] = [];
}
$data[$routerId][$profile] = $numPrice;

if (false === file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT))) {
    http_response_code(500);
    echo json_encode(['error' => 'Gagal menyimpan harga']);
    exit;
}

echo json_encode(['message' => 'Harga tersimpan', 'data' => $data]);
