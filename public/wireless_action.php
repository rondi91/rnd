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
$name = trim($input['name'] ?? '');
$host = trim($input['host'] ?? '');
$location = trim($input['location'] ?? '');
$username = trim($input['username'] ?? '');
$password = trim($input['password'] ?? '');
$notes = trim($input['notes'] ?? '');
$category = trim($input['category'] ?? 'ap');

if ($name === '' || $host === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Nama dan Host/IP wajib diisi']);
    exit;
}

$file = __DIR__ . '/../storage/mikrotik.json';
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
if (!is_array($data)) $data = [];

$maxId = 0;
foreach ($data as $row) {
    $maxId = max($maxId, (int)($row['id'] ?? 0));
}
$new = [
    'id' => $maxId + 1,
    'name' => $name,
    'host' => $host,
    'location' => $location,
    'username' => $username,
    'password' => $password,
    'notes' => $notes,
    'category' => $category !== '' ? $category : 'ap',
];

$data[] = $new;
file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));

// simpan lokasi baru jika belum ada
$locFile = __DIR__ . '/../storage/locations.json';
$locData = file_exists($locFile) ? json_decode(file_get_contents($locFile), true) : [];
if (!is_array($locData)) $locData = [];
if ($location !== '' && !in_array($location, $locData, true)) {
    $locData[] = $location;
    file_put_contents($locFile, json_encode($locData, JSON_PRETTY_PRINT));
}

echo json_encode(['message' => 'Router ditambahkan', 'data' => $new]);
