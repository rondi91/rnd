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

$routersFile = __DIR__ . '/../storage/mikrotik.json';
$routers = file_exists($routersFile) ? json_decode(file_get_contents($routersFile), true) : [];
if (!is_array($routers)) {
    echo json_encode(['error' => 'No router data']);
    exit;
}

$locationFilter = isset($_GET['location']) ? trim((string) $_GET['location']) : '';
$qFilter = isset($_GET['q']) ? strtolower(trim((string) $_GET['q'])) : '';

$aps = array_values(array_filter($routers, function ($r) {
    return isset($r['category']) && strtolower(trim($r['category'])) === 'ap';
}));

if ($locationFilter !== '') {
    $aps = array_values(array_filter($aps, function ($ap) use ($locationFilter) {
        return strcasecmp(trim((string) ($ap['location'] ?? '')), $locationFilter) === 0;
    }));
}

$list = [];
$errors = [];

foreach ($aps as $ap) {
    $host = $ap['host'] ?? '';
    $user = $ap['username'] ?? '';
    $pass = $ap['password'] ?? '';
    if ($host === '' || $user === '' || $pass === '') {
        $errors[] = 'Router AP ' . ($ap['name'] ?? '') . ' kredensial tidak lengkap.';
        continue;
    }
    try {
        $client = new Client([
            'host' => $host,
            'user' => $user,
            'pass' => $pass,
            'port' => 8728,
            'timeout' => 5,
            'attempts' => 1,
        ]);
        $resp = $client->query(new Query('/interface/wireless/registration-table/print'))->read();
        $freq = '';
        try {
            $wlan = $client->query(new Query('/interface/wireless/print'))->read();
            if (is_array($wlan) && isset($wlan[0]['frequency'])) {
                $freq = $wlan[0]['frequency'];
            }
        } catch (\Throwable $e) {
            // ignore frequency error
        }
        if (is_array($resp)) {
            foreach ($resp as $row) {
                $item = [
                    'router_name' => $ap['name'] ?? '',
                    'router_id' => $ap['id'] ?? '',
                    'router_location' => $ap['location'] ?? '',
                    'frequency' => $freq,
                    'interface' => $row['interface'] ?? '',
                    'mac' => $row['mac-address'] ?? '',
                    'signal' => $row['signal-strength'] ?? '',
                    'uptime' => $row['uptime'] ?? '',
                    'tx_rate' => $row['tx-rate'] ?? '',
                    'rx_rate' => $row['rx-rate'] ?? '',
                    'last_ip' => $row['last-ip'] ?? ($row['last-ip-from-dhcp'] ?? ''),
                    'radio_name' => $row['radio-name'] ?? '',
                ];
                if ($qFilter !== '') {
                    $hay = strtolower(
                        ($item['router_name'] ?? '') . ' ' .
                        ($item['interface'] ?? '') . ' ' .
                        ($item['radio_name'] ?? '') . ' ' .
                        ($item['last_ip'] ?? '')
                    );
                    if (strpos($hay, $qFilter) === false) {
                        continue;
                    }
                }
                $list[] = $item;
            }
        }
    } catch (\Throwable $e) {
        $errors[] = 'Gagal ambil wireless register dari ' . ($ap['name'] ?? $host) . ': ' . $e->getMessage();
    }
}

echo json_encode([
    'data' => $list,
    'errors' => $errors,
]);
