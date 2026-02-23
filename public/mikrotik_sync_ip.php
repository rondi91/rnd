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

$routersFile = __DIR__ . '/../storage/mikrotik.json';
$routers = file_exists($routersFile) ? json_decode(file_get_contents($routersFile), true) : [];
if (!is_array($routers)) {
    $routers = [];
}

if (count($routers) === 0) {
    echo json_encode([
        'message' => 'Tidak ada router untuk disinkronkan',
        'updated' => 0,
        'checked' => 0,
        'not_found' => [],
    ]);
    exit;
}

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
if (!is_array($pppoeData)) {
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

$pppoeByUser = [];

$pushCandidate = static function (array &$bucket, array $row, bool $isActive): void {
    $username = trim((string) ($row['username'] ?? ''));
    if ($username === '') {
        return;
    }
    $key = strtolower($username);
    if (!isset($bucket[$key]) || !is_array($bucket[$key])) {
        $bucket[$key] = [];
    }
    $bucket[$key][] = [
        'username' => $username,
        'ip' => trim((string) ($row['ip_address'] ?? '')),
        'router_id' => trim((string) ($row['router_id'] ?? '')),
        'router_name' => trim((string) ($row['router_name'] ?? '')),
        'is_active' => $isActive,
    ];
};

foreach (($pppoeData['active'] ?? []) as $row) {
    if (!is_array($row)) {
        continue;
    }
    $pushCandidate($pppoeByUser, $row, true);
}
foreach (($pppoeData['inactive_users'] ?? []) as $row) {
    if (!is_array($row)) {
        continue;
    }
    $pushCandidate($pppoeByUser, $row, false);
}

$updated = 0;
$checked = 0;
$notFound = [];
$notMatchedServer = [];

foreach ($routers as &$router) {
    if (!is_array($router)) continue;
    $account = trim((string) ($router['pppoe_account'] ?? ''));
    if ($account === '') continue;
    $checked++;
    $key = strtolower($account);

    $hits = $pppoeByUser[$key] ?? [];
    if (!is_array($hits) || count($hits) === 0) {
        $notFound[] = $account;
        continue;
    }

    $sourceId = trim((string) ($router['source_server_id'] ?? ''));
    $sourceName = trim((string) ($router['source_server_name'] ?? ''));

    if ($sourceId !== '') {
        $hits = array_values(array_filter($hits, static fn ($row) => (string) ($row['router_id'] ?? '') === $sourceId));
    } elseif ($sourceName !== '') {
        $sourceNameLower = strtolower($sourceName);
        $hits = array_values(array_filter($hits, static fn ($row) => strtolower((string) ($row['router_name'] ?? '')) === $sourceNameLower));
    }

    if (count($hits) === 0) {
        $notMatchedServer[] = $account;
        continue;
    }

    usort($hits, static function (array $a, array $b): int {
        $aScore = (!empty($a['is_active']) ? 10 : 0) + ((string) ($a['ip'] ?? '') !== '' ? 1 : 0);
        $bScore = (!empty($b['is_active']) ? 10 : 0) + ((string) ($b['ip'] ?? '') !== '' ? 1 : 0);
        return $bScore <=> $aScore;
    });
    $hit = $hits[0];

    $changed = false;
    $ip = (string) ($hit['ip'] ?? '');
    if ($ip !== '' && (string) ($router['host'] ?? '') !== $ip) {
        $router['host'] = $ip;
        $changed = true;
    }

    $sid = (string) ($hit['router_id'] ?? '');
    $sname = (string) ($hit['router_name'] ?? '');
    if ($sid !== '' && (string) ($router['source_server_id'] ?? '') !== $sid) {
        $router['source_server_id'] = $sid;
        $changed = true;
    }
    if ($sname !== '' && (string) ($router['source_server_name'] ?? '') !== $sname) {
        $router['source_server_name'] = $sname;
        $changed = true;
    }
    if ((string) ($router['pppoe_account'] ?? '') !== $hit['username']) {
        $router['pppoe_account'] = $hit['username'];
        $changed = true;
    }

    if ($changed) {
        $updated++;
    }
}
unset($router);

file_put_contents($routersFile, json_encode($routers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo json_encode([
    'message' => 'Sinkron selesai',
    'updated' => $updated,
    'checked' => $checked,
    'not_found' => array_values(array_unique($notFound)),
    'not_matched_server' => array_values(array_unique($notMatchedServer)),
    'errors' => is_array($pppoeData['errors'] ?? null) ? $pppoeData['errors'] : [],
]);
