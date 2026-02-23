<?php
// Simple JSON CRUD router for the admin template.
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$resource = isset($_GET['resource']) ? trim($_GET['resource']) : 'users';
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;

$routes = [
    'users' => __DIR__ . '/../storage/users.json',
    'mikrotik' => __DIR__ . '/../storage/mikrotik.json',
];

if (!isset($routes[$resource])) {
    sendJson(['error' => 'Resource not found'], 404);
}

$file = $routes[$resource];
$data = loadData($file);
$method = $_SERVER['REQUEST_METHOD'];
$input = decodeJsonInput();

switch ($method) {
    case 'GET':
        if ($id) {
            $item = findById($data, $id);
            $item ? sendJson($item) : sendJson(['error' => 'Not found'], 404);
        } else {
            sendJson(['data' => $data]);
        }
        break;
    case 'POST':
        $newItem = buildItem($resource, $input);
        $newItem['id'] = nextId($data);
        $data[] = $newItem;
        saveData($file, $data);
        sendJson($newItem, 201);
        break;
    case 'PUT':
    case 'PATCH':
        if (!$id) {
            sendJson(['error' => 'ID required'], 400);
        }
        $updated = false;
        foreach ($data as &$row) {
            if ((int) $row['id'] === $id) {
                $row = array_merge($row, buildItem($resource, $input, $row));
                $updated = true;
                break;
            }
        }
        if (!$updated) {
            sendJson(['error' => 'Not found'], 404);
        }
        saveData($file, $data);
        sendJson(['message' => 'Updated', 'data' => findById($data, $id)]);
        break;
    case 'DELETE':
        if (!$id) {
            sendJson(['error' => 'ID required'], 400);
        }
        $before = count($data);
        $data = array_values(array_filter($data, function ($row) use ($id) {
            return (int) $row['id'] !== $id;
        }));
        if ($before === count($data)) {
            sendJson(['error' => 'Not found'], 404);
        }
        saveData($file, $data);
        sendJson(['message' => 'Deleted']);
        break;
    default:
        sendJson(['error' => 'Method not allowed'], 405);
}

function loadData(string $file): array
{
    if (!file_exists($file)) {
        return [];
    }
    $raw = file_get_contents($file);
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function saveData(string $file, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT);
    file_put_contents($file, $json);
}

function nextId(array $data): int
{
    $max = 0;
    foreach ($data as $row) {
        $max = max($max, (int) ($row['id'] ?? 0));
    }
    return $max + 1;
}

function buildItem(string $resource, array $input, array $existing = []): array
{
    switch ($resource) {
        case 'mikrotik':
            return [
                'name' => $input['name'] ?? ($existing['name'] ?? 'Router'),
                'host' => $input['host'] ?? ($existing['host'] ?? ''),
                'location' => $input['location'] ?? ($existing['location'] ?? ''),
                'category' => $input['category'] ?? ($existing['category'] ?? ''),
                'notes' => $input['notes'] ?? ($existing['notes'] ?? ''),
                'username' => $input['username'] ?? ($existing['username'] ?? ''),
                'password' => $input['password'] ?? ($existing['password'] ?? ''),
                'pppoe_account' => $input['pppoe_account'] ?? ($existing['pppoe_account'] ?? ''),
                'source_server_id' => $input['source_server_id'] ?? ($existing['source_server_id'] ?? ''),
                'source_server_name' => $input['source_server_name'] ?? ($existing['source_server_name'] ?? ''),
            ];
        case 'users':
        default:
            $passwordInput = array_key_exists('password', $input) ? (string) $input['password'] : null;
            $password = $existing['password'] ?? '';
            if ($passwordInput !== null) {
                $password = trim($passwordInput);
                if ($password === '' && isset($existing['password'])) {
                    $password = $existing['password'];
                }
            }
            $permInput = $input['permissions'] ?? ($existing['permissions'] ?? []);
            if (is_string($permInput)) {
                $permInput = array_values(array_filter(array_map('trim', explode(',', $permInput))));
            }
            if (!is_array($permInput)) {
                $permInput = [];
            }
            return [
                'name' => $input['name'] ?? ($existing['name'] ?? 'Tanpa Nama'),
                'email' => $input['email'] ?? ($existing['email'] ?? ''),
                'status' => $input['status'] ?? ($existing['status'] ?? 'aktif'),
                'password' => $password,
                'role' => $input['role'] ?? ($existing['role'] ?? ''),
                'permissions' => $permInput,
            ];
    }
}

function findById(array $data, int $id): ?array
{
    foreach ($data as $row) {
        if ((int) $row['id'] === $id) {
            return $row;
        }
    }
    return null;
}

function decodeJsonInput(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function sendJson($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}
