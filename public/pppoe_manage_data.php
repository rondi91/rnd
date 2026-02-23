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
$routers = file_exists($routersFile) ? json_decode((string) file_get_contents($routersFile), true) : [];
if (!is_array($routers)) {
    $routers = [];
}

$serverRouters = array_values(array_filter($routers, static function ($r) {
    return is_array($r) && strtolower(trim((string) ($r['category'] ?? ''))) === 'server';
}));

$statusFilter = strtolower(trim((string) ($_GET['status'] ?? '')));
$qFilter = strtolower(trim((string) ($_GET['q'] ?? '')));

$rows = [];
$errors = [];
$failedRouters = [];
$serverOptions = [];
$failedServerMap = [];

foreach ($serverRouters as $router) {
    $routerId = (string) ($router['id'] ?? '');
    $routerName = (string) ($router['name'] ?? '');
    $host = trim((string) ($router['host'] ?? ''));
    $user = trim((string) ($router['username'] ?? ''));
    $pass = (string) ($router['password'] ?? '');

    $serverOptions[] = [
        'id' => $routerId,
        'name' => $routerName,
        'host' => $host,
    ];

    if ($host === '' || $user === '' || $pass === '') {
        $msg = 'Kredensial tidak lengkap.';
        $errors[] = 'Router ' . ($routerName !== '' ? $routerName : $host) . ': ' . $msg;
        $failedRouters[] = [
            'id' => $routerId,
            'name' => $routerName,
            'host' => $host,
            'reason' => $msg,
        ];
        if ($routerId !== '') {
            $failedServerMap[$routerId] = true;
        }
        continue;
    }

    try {
        $client = new Client([
            'host' => $host,
            'user' => $user,
            'pass' => $pass,
            'port' => 8728,
            'timeout' => 4,
            'attempts' => 1,
        ]);

        $secretRows = $client->query(new Query('/ppp/secret/print'))->read();
        $activeRows = $client->query(new Query('/ppp/active/print'))->read();

        if (!is_array($secretRows)) {
            $secretRows = [];
        }
        if (!is_array($activeRows)) {
            $activeRows = [];
        }

        $activeByName = [];
        foreach ($activeRows as $activeRow) {
            if (!is_array($activeRow)) {
                continue;
            }
            $username = trim((string) ($activeRow['name'] ?? ($activeRow['user'] ?? '')));
            if ($username === '') {
                continue;
            }
            $activeByName[strtolower($username)] = $activeRow;
        }

        foreach ($secretRows as $secretRow) {
            if (!is_array($secretRow)) {
                continue;
            }
            $username = trim((string) ($secretRow['name'] ?? ''));
            if ($username === '') {
                continue;
            }
            $usernameKey = strtolower($username);
            $activeRow = $activeByName[$usernameKey] ?? null;

            $item = [
                'username' => $username,
                'profile' => (string) ($secretRow['profile'] ?? ''),
                'router_id' => $routerId,
                'router_name' => $routerName,
                'router_host' => $host,
                'status' => $activeRow ? 'active' : 'inactive',
                'ip_address' => (string) ($activeRow['address'] ?? ''),
                'uptime' => (string) ($activeRow['uptime'] ?? ''),
            ];
            if (!matchQuery($item, $qFilter)) {
                continue;
            }
            $rows[] = $item;
            unset($activeByName[$usernameKey]);
        }

        // Active tanpa secret tetap ditampilkan.
        foreach ($activeByName as $usernameKey => $activeRow) {
            if (!is_array($activeRow)) {
                continue;
            }
            $username = trim((string) ($activeRow['name'] ?? ($activeRow['user'] ?? $usernameKey)));
            if ($username === '') {
                continue;
            }
            $item = [
                'username' => $username,
                'profile' => (string) ($activeRow['profile'] ?? ''),
                'router_id' => $routerId,
                'router_name' => $routerName,
                'router_host' => $host,
                'status' => 'active',
                'ip_address' => (string) ($activeRow['address'] ?? ''),
                'uptime' => (string) ($activeRow['uptime'] ?? ''),
            ];
            if (!matchQuery($item, $qFilter)) {
                continue;
            }
            $rows[] = $item;
        }
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        $errors[] = 'Router ' . ($routerName !== '' ? $routerName : $host) . ': ' . $msg;
        $failedRouters[] = [
            'id' => $routerId,
            'name' => $routerName,
            'host' => $host,
            'reason' => $msg,
        ];
        if ($routerId !== '') {
            $failedServerMap[$routerId] = true;
        }
    }
}

if (in_array($statusFilter, ['active', 'inactive'], true)) {
    $rows = array_values(array_filter($rows, static function ($row) use ($statusFilter) {
        return strtolower((string) ($row['status'] ?? '')) === $statusFilter;
    }));
}

usort($rows, static function ($a, $b) {
    $u = strcasecmp((string) ($a['username'] ?? ''), (string) ($b['username'] ?? ''));
    if ($u !== 0) {
        return $u;
    }
    $r = strcasecmp((string) ($a['router_name'] ?? ''), (string) ($b['router_name'] ?? ''));
    if ($r !== 0) {
        return $r;
    }
    return strcasecmp((string) ($a['profile'] ?? ''), (string) ($b['profile'] ?? ''));
});

$activeCount = 0;
$inactiveCount = 0;
foreach ($rows as $row) {
    if (($row['status'] ?? '') === 'active') {
        $activeCount++;
    } else {
        $inactiveCount++;
    }
}

$serverDescriptors = [];
foreach ($serverOptions as $server) {
    $sid = (string) ($server['id'] ?? '');
    $serverDescriptors[] = [
        'id' => $sid,
        'name' => (string) ($server['name'] ?? ''),
        'host' => (string) ($server['host'] ?? ''),
        'failed' => $sid !== '' ? isset($failedServerMap[$sid]) : false,
    ];
}

$matrixMap = [];
foreach ($rows as $row) {
    $username = trim((string) ($row['username'] ?? ''));
    if ($username === '') {
        continue;
    }
    $uKey = strtolower($username);
    if (!isset($matrixMap[$uKey])) {
        $defaultServers = [];
        foreach ($serverDescriptors as $server) {
            $sid = (string) ($server['id'] ?? '');
            if ($sid === '') {
                continue;
            }
            $isFailed = !empty($server['failed']);
            $defaultServers[$sid] = [
                'router_id' => $sid,
                'router_name' => (string) ($server['name'] ?? ''),
                'exists' => $isFailed ? null : false,
                'status' => $isFailed ? 'unknown' : 'absent',
                'profile' => '',
                'ip_address' => '',
                'uptime' => '',
            ];
        }
        $matrixMap[$uKey] = [
            'username' => $username,
            'servers' => $defaultServers,
            'duplicate_count' => 0,
            'active_count' => 0,
            'inactive_count' => 0,
            'has_duplicate_inactive' => false,
        ];
    }

    $sid = trim((string) ($row['router_id'] ?? ''));
    if ($sid === '') {
        continue;
    }
    $status = strtolower(trim((string) ($row['status'] ?? 'inactive')));
    if ($status !== 'active') {
        $status = 'inactive';
    }
    $newCell = [
        'router_id' => $sid,
        'router_name' => (string) ($row['router_name'] ?? ''),
        'exists' => true,
        'status' => $status,
        'profile' => (string) ($row['profile'] ?? ''),
        'ip_address' => (string) ($row['ip_address'] ?? ''),
        'uptime' => (string) ($row['uptime'] ?? ''),
    ];
    $existingCell = $matrixMap[$uKey]['servers'][$sid] ?? null;
    if (is_array($existingCell) && (($existingCell['exists'] ?? null) === true)) {
        $existingStatus = (string) ($existingCell['status'] ?? '');
        $newStatus = (string) ($newCell['status'] ?? '');
        // Jika ada konflik data pada user+server yang sama, prioritaskan status aktif.
        if ($existingStatus === 'active' && $newStatus !== 'active') {
            continue;
        }
        if ($existingStatus === 'active' && $newStatus === 'active') {
            // Pertahankan data aktif dengan IP jika sudah ada.
            if ((string) ($existingCell['ip_address'] ?? '') !== '' && (string) ($newCell['ip_address'] ?? '') === '') {
                continue;
            }
        }
    }
    $matrixMap[$uKey]['servers'][$sid] = $newCell;
}

$usersMatrix = array_values($matrixMap);
foreach ($usersMatrix as &$matrixRow) {
    $duplicateCount = 0;
    $activeServerCount = 0;
    $inactiveServerCount = 0;
    foreach (($matrixRow['servers'] ?? []) as $cell) {
        if (!is_array($cell)) {
            continue;
        }
        if (($cell['exists'] ?? null) === true) {
            $duplicateCount++;
            if (($cell['status'] ?? '') === 'active') {
                $activeServerCount++;
            } else {
                $inactiveServerCount++;
            }
        }
    }
    $matrixRow['duplicate_count'] = $duplicateCount;
    $matrixRow['active_count'] = $activeServerCount;
    $matrixRow['inactive_count'] = $inactiveServerCount;
    $matrixRow['has_duplicate_inactive'] = $duplicateCount > 1 && $inactiveServerCount > 0;
}
unset($matrixRow);
usort($usersMatrix, static function ($a, $b) {
    return strcasecmp((string) ($a['username'] ?? ''), (string) ($b['username'] ?? ''));
});

echo json_encode([
    'data' => array_values($rows),
    'servers' => $serverDescriptors,
    'users_matrix' => $usersMatrix,
    'errors' => array_values($errors),
    'failed_routers' => array_values($failedRouters),
    'summary' => [
        'total' => count($rows),
        'active' => $activeCount,
        'inactive' => $inactiveCount,
        'server_total' => count($serverOptions),
        'server_failed' => count($failedRouters),
    ],
]);
exit;

/**
 * @param array<string,mixed> $item
 */
function matchQuery(array $item, string $qFilter): bool
{
    if ($qFilter === '') {
        return true;
    }
    $haystack = strtolower(trim(
        (string) ($item['username'] ?? '') . ' ' .
        (string) ($item['profile'] ?? '') . ' ' .
        (string) ($item['router_name'] ?? '') . ' ' .
        (string) ($item['router_host'] ?? '') . ' ' .
        (string) ($item['ip_address'] ?? '')
    ));
    return strpos($haystack, $qFilter) !== false;
}
