<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use RouterOS\Client;
use RouterOS\Query;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$file = __DIR__ . '/../storage/mikrotik.json';
$routers = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
if (!is_array($routers)) $routers = [];

// filter parameter
$locFilter = isset($_GET['location']) ? strtolower(trim((string)$_GET['location'])) : '';
$catFilter = isset($_GET['category']) ? strtolower(trim((string)$_GET['category'])) : '';
$qFilter   = isset($_GET['q']) ? strtolower(trim((string)$_GET['q'])) : '';

$result = [];
$errors = [];
$now = date('c');

foreach ($routers as $router) {
    $locVal = strtolower(trim((string)($router['location'] ?? '')));
    $catVal = strtolower(trim((string)($router['category'] ?? '')));
    $hay = strtolower(trim(
        (($router['name'] ?? '') . ' ' .
         ($router['host'] ?? '') . ' ' .
         ($router['location'] ?? '') . ' ' .
         ($router['notes'] ?? ''))
    ));

    if ($locFilter !== '' && $locVal !== $locFilter) {
        continue;
    }
    if ($catFilter !== '' && $catVal !== $catFilter) {
        continue;
    }
    if ($qFilter !== '' && strpos($hay, $qFilter) === false) {
        continue;
    }

    $host = $router['host'] ?? '';
    $user = $router['username'] ?? '';
    $pass = $router['password'] ?? '';
    $savedIface = $router['traffic_interface'] ?? '';
    $interfaces = [];
    $rx = null;
    $tx = null;
    $status = 'error';
    $ifaceUsed = $savedIface !== '' ? $savedIface : 'ether1';
    $errorMsg = null;
    $cpu = null;

    if ($host === '' || $user === '') {
        $errors[] = 'Router ' . ($router['name'] ?? '') . ' tidak memiliki host/username.';
        continue;
    }

    try {
        $client = new Client([
            'host' => $host,
            'user' => $user,
            'pass' => $pass ?? '',
            'port' => 8728,
            'timeout' => 3,
            'attempts' => 1,
        ]);

        // ambil daftar interface
        $ifPrint = $client->query(new Query('/interface/print'))->read();
        if (is_array($ifPrint)) {
            foreach ($ifPrint as $row) {
                if (!isset($row['name'])) continue;
                $interfaces[] = $row['name'];
            }
        }
        // Gunakan interface yang tersimpan jika ada, jika kosong ambil interface pertama
        if ($savedIface === '') {
            $ifaceUsed = $interfaces[0] ?? 'ether1';
        } elseif (!empty($interfaces)) {
            // pakai yang tersimpan, walau tidak ada di list (misal sudah di-rename)
            $ifaceUsed = $savedIface;
        }

        // monitor traffic
        $monitorQuery = (new Query('/interface/monitor-traffic'))
            ->equal('interface', $ifaceUsed)
            ->equal('once', 'true');

        $monitor = $client->query($monitorQuery)->read();

        // fallback older style once=''
        if ((!is_array($monitor) || !isset($monitor[0])) && !empty($ifaceUsed)) {
            $monitorFallback = $client->query(
                (new Query('/interface/monitor-traffic'))
                    ->equal('interface', $ifaceUsed)
                    ->equal('once', '')
            )->read();
            if (is_array($monitorFallback) && isset($monitorFallback[0])) {
                $monitor = $monitorFallback;
            }
        }

        if (is_array($monitor) && isset($monitor[0])) {
            $row = $monitor[0];
            $rx = isset($row['rx-bits-per-second']) ? ((float)$row['rx-bits-per-second']) / 1000 : 0;
            $tx = isset($row['tx-bits-per-second']) ? ((float)$row['tx-bits-per-second']) / 1000 : 0;
            $status = 'running';
        } else {
            $status = 'no-data';
            $rx = 0;
            $tx = 0;
        }

        // CPU usage
        try {
            $sys = $client->query(new Query('/system/resource/print'))->read();
            if (is_array($sys) && isset($sys[0]['cpu-load'])) {
                $cpu = (float)$sys[0]['cpu-load'];
            }
        } catch (\Throwable $e) {
            $cpu = null;
            // we don't add to errors to avoid spamming; only set cpu null
        }
    } catch (\Throwable $e) {
        $errMsg = $e->getMessage();
        // beberapa router menolak koneksi (port 443 / REST PPPoE); abaikan pesan agar tidak memenuhi UI
        $shouldReport = (stripos($errMsg, 'connection refused') === false) && (stripos($errMsg, 'rest pppoe') === false);
        if ($shouldReport) {
            $errors[] = 'Router ' . ($router['name'] ?? $host) . ': ' . $errMsg;
            $errorMsg = $errMsg;
        } else {
            $errorMsg = '';
        }
        $status = 'error';
        $rx = 0;
        $tx = 0;
    }

    $result[] = [
        'id' => $router['id'] ?? null,
        'name' => $router['name'] ?? '',
        'host' => $host,
        'location' => $router['location'] ?? '',
        'category' => $router['category'] ?? '',
        'username' => $user,
        'notes' => $router['notes'] ?? '',
        'interfaces' => $interfaces,
        'interface' => $ifaceUsed,
        'rx_kbps' => $rx,
        'tx_kbps' => $tx,
        'status' => $status,
        'error' => $errorMsg,
        'last_update' => $now,
        'cpu' => $cpu,
    ];
}

echo json_encode(['data' => $result, 'errors' => $errors]);
