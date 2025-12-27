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
$routerId = isset($input['router_id']) ? (string) $input['router_id'] : '';
$username = trim($input['username'] ?? '');
$newProfile = trim($input['profile'] ?? '');
$action = trim($input['action'] ?? 'update');

$routersFile = __DIR__ . '/../storage/mikrotik.json';
$routers = file_exists($routersFile) ? json_decode(file_get_contents($routersFile), true) : [];
$router = null;
if (is_array($routers)) {
    foreach ($routers as $r) {
        if ((string)($r['id'] ?? '') === $routerId && strtolower(trim($r['category'] ?? '')) === 'server') {
            $router = $r;
            break;
        }
    }
}

if ($router === null) {
    http_response_code(404);
    echo json_encode(['error' => 'Router tidak ditemukan atau bukan kategori server']);
    exit;
}

$host = $router['host'] ?? '';
$user = $router['username'] ?? '';
$pass = $router['password'] ?? '';

try {
    $client = new Client([
        'host' => $host,
        'user' => $user,
        'pass' => $pass,
        'port' => 8728,
        'timeout' => 5,
        'attempts' => 1,
    ]);

    if ($action === 'disconnect') {
        if ($routerId === '' || $username === '') {
            http_response_code(400);
            echo json_encode(['error' => 'router_id dan username diperlukan untuk putuskan koneksi']);
            exit;
        }
        $activeList = $client->query(
            (new Query('/ppp/active/print'))
                ->where('name', $username)
        )->read();
        if (is_array($activeList)) {
            foreach ($activeList as $row) {
                if (!isset($row['.id'])) continue;
                $client->query(
                    (new Query('/ppp/active/remove'))
                        ->equal('numbers', $row['.id'])
                )->read();
            }
        }
        echo json_encode(['message' => 'Koneksi aktif diputus']);
        exit;
    }

    if ($action === 'delete') {
        if ($routerId === '' || $username === '') {
            http_response_code(400);
            echo json_encode(['error' => 'router_id dan username diperlukan untuk hapus']);
            exit;
        }
        // Putuskan active
        $activeList = $client->query(
            (new Query('/ppp/active/print'))
                ->where('name', $username)
        )->read();
        if (is_array($activeList)) {
            foreach ($activeList as $row) {
                if (!isset($row['.id'])) continue;
                $client->query(
                    (new Query('/ppp/active/remove'))
                        ->equal('numbers', $row['.id'])
                )->read();
            }
        }
        // Hapus secret memakai .id supaya tidak hanya memutus koneksi aktif
        $secretList = $client->query(
            (new Query('/ppp/secret/print'))
                ->where('name', $username)
        )->read();
        $removedSecret = false;
        if (is_array($secretList)) {
            foreach ($secretList as $row) {
                if (!isset($row['.id'])) {
                    continue;
                }
                $client->query(
                    (new Query('/ppp/secret/remove'))
                        ->equal('numbers', $row['.id'])
                )->read();
                $removedSecret = true;
            }
        }
        // fallback: jika .id tidak ditemukan, coba dengan name langsung
        if (!$removedSecret) {
            $client->query(
                (new Query('/ppp/secret/remove'))
                    ->equal('numbers', $username)
            )->read();
        }
        echo json_encode(['message' => 'User secret dihapus dan koneksi aktif diputus']);
        exit;
    }

    if ($action === 'add') {
        if ($routerId === '' || $username === '' || $newProfile === '') {
            http_response_code(400);
            echo json_encode(['error' => 'router_id, username, profile diperlukan untuk tambah']);
            exit;
        }
        $client->query(
            (new Query('/ppp/secret/add'))
                ->equal('name', $username)
                ->equal('password', $username)
                ->equal('profile', $newProfile)
                ->equal('service', 'any')
        )->read();
        echo json_encode(['message' => 'User secret ditambahkan (password sama dengan username)']);
        exit;
    }

    // default: update profile + disconnect
    if ($routerId === '' || $username === '' || $newProfile === '') {
        http_response_code(400);
        echo json_encode(['error' => 'router_id, username, profile diperlukan']);
        exit;
    }

    // Cari .id secret supaya set profile tidak gagal ketika "numbers" butuh id
    $secret = $client->query(
        (new Query('/ppp/secret/print'))
            ->where('name', $username)
    )->read();
    if (!is_array($secret) || count($secret) === 0 || !isset($secret[0]['.id'])) {
        http_response_code(404);
        echo json_encode(['error' => 'Secret user tidak ditemukan di router']);
        exit;
    }
    $secretId = $secret[0]['.id'];

    $client->query(
        (new Query('/ppp/secret/set'))
            ->equal('numbers', $secretId)
            ->equal('profile', $newProfile)
    )->read();

    $activeList = $client->query(
        (new Query('/ppp/active/print'))
            ->where('name', $username)
    )->read();

    if (is_array($activeList) && count($activeList) > 0) {
        foreach ($activeList as $row) {
            if (!isset($row['.id'])) {
                continue;
            }
            $client->query(
                (new Query('/ppp/active/remove'))
                    ->equal('numbers', $row['.id'])
            )->read();
        }
    }

    echo json_encode(['message' => 'Profil diperbarui dan koneksi aktif dihapus']);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Gagal mengubah profile: ' . $e->getMessage()]);
}
