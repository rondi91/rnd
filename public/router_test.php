<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use RouterOS\Client;
use RouterOS\Query;
use phpseclib3\Net\SSH2;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$routersFile = __DIR__ . '/../storage/mikrotik.json';
$routers = file_exists($routersFile) ? json_decode(file_get_contents($routersFile), true) : [];
if (!is_array($routers)) $routers = [];

$routerId = trim((string) ($_GET['router_id'] ?? ''));
if ($routerId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'router_id wajib diisi']);
    exit;
}

$router = null;
foreach ($routers as $row) {
    if ((string) ($row['id'] ?? '') === $routerId) {
        $router = $row;
        break;
    }
}
if (!$router) {
    http_response_code(404);
    echo json_encode(['error' => 'Router tidak ditemukan']);
    exit;
}

$host = trim((string) ($router['host'] ?? ''));
$user = trim((string) ($router['username'] ?? ''));
$pass = trim((string) ($router['password'] ?? ''));
if ($host === '' || $user === '' || $pass === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Kredensial router tidak lengkap']);
    exit;
}

$portApi = testPort($host, 8728, 2);
$portSsh = testPort($host, 22, 2);

$api = ['ok' => false, 'message' => 'Port 8728 tertutup'];
if ($portApi) {
    try {
        $client = new Client([
            'host' => $host,
            'user' => $user,
            'pass' => $pass,
            'port' => 8728,
            'timeout' => 4,
            'attempts' => 1,
        ]);
        $identity = $client->query(new Query('/system/identity/print'))->read();
        $name = '';
        if (is_array($identity)) {
            foreach ($identity as $row) {
                if (!is_array($row)) continue;
                $name = (string) ($row['name'] ?? '');
                break;
            }
        }
        $api = ['ok' => true, 'message' => $name !== '' ? ('OK (' . $name . ')') : 'OK'];
    } catch (Throwable $e) {
        $api = ['ok' => false, 'message' => $e->getMessage()];
    }
}

$ssh = ['ok' => false, 'message' => 'Port 22 tertutup'];
if ($portSsh) {
    try {
        $client = new SSH2($host, 22, 5);
        if (!$client->login($user, $pass)) {
            $ssh = ['ok' => false, 'message' => 'Login gagal'];
        } else {
            $ssh = ['ok' => true, 'message' => 'OK'];
        }
    } catch (Throwable $e) {
        $ssh = ['ok' => false, 'message' => $e->getMessage()];
    }
}

echo json_encode([
    'router' => [
        'id' => $routerId,
        'name' => $router['name'] ?? '',
        'host' => $host,
    ],
    'port' => [
        'api_open' => $portApi,
        'ssh_open' => $portSsh,
    ],
    'api' => $api,
    'ssh' => $ssh,
]);

function testPort(string $host, int $port, int $timeout): bool
{
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if ($fp) {
        fclose($fp);
        return true;
    }
    return false;
}
