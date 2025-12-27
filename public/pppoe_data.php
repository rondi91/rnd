<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use phpseclib3\Net\SSH2;
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
    $routers = [];
}

$serverRouters = array_filter($routers, function ($r) {
    return isset($r['category']) && strtolower(trim($r['category'])) === 'server';
});

$activeConnections = [];
$errors = [];
$profileOptions = [];
$profilesByRouter = [];
$routerOptions = [];
$allUsers = [];
$activeUsersSet = [];
$userMeta = [];

foreach ($serverRouters as $router) {
    if (isset($router['id'])) {
        $routerOptions[(string) $router['id']] = $router;
    }
    $fetched = fetchRouterActive($router, $errors, $profileOptions, $profilesByRouter, $allUsers, $activeUsersSet, $userMeta);
    foreach ($fetched as $row) {
        $activeConnections[] = array_merge($row, [
            'router_id' => $router['id'] ?? null,
            'router_name' => $router['name'] ?? '',
            'router_host' => $router['host'] ?? '',
        ]);
        if (!empty($row['username'])) {
            $activeUsersSet[$row['username']] = true;
        }
    }
}

$sortDir = isset($_GET['dir']) && strtolower($_GET['dir']) === 'asc' ? 'asc' : 'desc';
usort($activeConnections, function ($a, $b) use ($sortDir) {
    $ua = uptimeToSeconds($a['uptime'] ?? '');
    $ub = uptimeToSeconds($b['uptime'] ?? '');
    return $sortDir === 'asc' ? ($ua <=> $ub) : ($ub <=> $ua);
});

$totalUsers = count($allUsers);
$activeCount = count($activeUsersSet);
$inactiveCount = max(0, $totalUsers - $activeCount);
$inactiveUsers = [];
foreach ($allUsers as $uname => $_) {
    if (!isset($activeUsersSet[$uname])) {
        $info = $userMeta[$uname] ?? [];
        $inactiveUsers[] = [
            'username' => $uname,
            'profile' => $info['profile'] ?? '',
            'router_name' => $info['router_name'] ?? '',
            'router_id' => $info['router_id'] ?? '',
        ];
    }
}
usort($inactiveUsers, function($a,$b){
    return strcmp($a['username'], $b['username']);
});

echo json_encode([
    'summary' => [
        'total' => $totalUsers,
        'active' => $activeCount,
        'inactive' => $inactiveCount,
    ],
    'active' => $activeConnections,
    'inactive_users' => $inactiveUsers,
    'errors' => $errors,
]);
exit;

function fetchRouterActive(array $router, array &$errors, array &$profiles, array &$profilesByRouter, array &$allUsers, array &$activeUsersSet, array &$userMeta): array
{
    $host = $router['host'] ?? '';
    $user = $router['username'] ?? '';
    $pass = $router['password'] ?? '';
    $routerId = isset($router['id']) ? (string) $router['id'] : null;
    $routerName = $router['name'] ?? '';
    if ($host === '' || $user === '' || $pass === '') {
        $errors[] = 'Router ' . ($router['name'] ?? '') . ' tidak memiliki host/username/password lengkap.';
        return [];
    }

    $viaApi = fetchViaApi($host, $user, $pass, $errors, $profiles, $profilesByRouter, $routerId, $routerName, $allUsers, $activeUsersSet, $userMeta);
    if (!empty($viaApi)) {
        return $viaApi;
    }

    $viaSsh = fetchViaSsh($host, $user, $pass, $errors, $profiles, $profilesByRouter, $routerId, $routerName, $allUsers, $activeUsersSet, $userMeta);
    if (!empty($viaSsh)) {
        return $viaSsh;
    }

    $viaRest = fetchViaRest($host, $user, $pass, $errors, $profiles, $profilesByRouter, $routerId, $routerName, $allUsers, $activeUsersSet, $userMeta);
    if (!empty($viaRest)) {
        return $viaRest;
    }

    return [];
}

function fetchViaApi(string $host, string $user, string $pass, array &$errors, array &$profiles, array &$profilesByRouter, ?string $routerId, string $routerName, array &$allUsers, array &$activeUsersSet, array &$userMeta): array
{
    try {
        $client = new Client([
            'host' => $host,
            'user' => $user,
            'pass' => $pass,
            'port' => 8728,
            'timeout' => 3,
            'attempts' => 1,
        ]);

        $secretRows = $client->query(new Query('/ppp/secret/print'))->read();
        $secretProfiles = [];
        if (is_array($secretRows)) {
            foreach ($secretRows as $s) {
                if (!empty($s['name']) && isset($s['profile'])) {
                    $secretProfiles[$s['name']] = $s['profile'];
                    recordProfile($s['profile'], $profiles, $profilesByRouter, $routerId);
                    $allUsers[$s['name']] = true;
                    recordUserMeta($s['name'], $routerId, $routerName, $s['profile'], $userMeta);
                }
            }
        }
        $profileRows = $client->query(new Query('/ppp/profile/print'))->read();
        if (is_array($profileRows)) {
            foreach ($profileRows as $pr) {
                if (!empty($pr['name'])) {
                    recordProfile($pr['name'], $profiles, $profilesByRouter, $routerId);
                }
            }
        }
        $response = $client->query(new Query('/ppp/active/print'))->read();
        if (!is_array($response) || count($response) === 0) {
            return [];
        }
        $result = [];
        foreach ($response as $row) {
            $uname = $row['name'] ?? ($row['user'] ?? '');
            $result[] = [
                'username' => $uname,
                'ip_address' => $row['address'] ?? '',
                'uptime' => $row['uptime'] ?? '',
                'profile' => $row['profile'] ?? ($secretProfiles[$uname] ?? ''),
                'router_id' => $routerId,
                'router_name' => $routerName,
            ];
            recordProfile($row['profile'] ?? '', $profiles, $profilesByRouter, $routerId);
            if (isset($secretProfiles[$uname])) {
                recordProfile($secretProfiles[$uname], $profiles, $profilesByRouter, $routerId);
            }
            if ($uname !== '') {
                $activeUsersSet[$uname] = true;
                $allUsers[$uname] = true;
                recordUserMeta($uname, $routerId, $routerName, $row['profile'] ?? ($secretProfiles[$uname] ?? ''), $userMeta);
            }
        }
        return $result;
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        $isConnRefused = stripos($msg, 'connection refused') !== false;
        if (!$isConnRefused) {
            $errors[] = 'API 8728 gagal ke ' . $host . ': ' . $msg;
        }
        return [];

    }
}

function fetchViaSsh(string $host, string $user, string $pass, array &$errors, array &$profiles, array &$profilesByRouter, ?string $routerId, string $routerName, array &$allUsers, array &$activeUsersSet, array &$userMeta): array
{
    try {
        $ssh = new SSH2($host, 22, 3);
        if (!$ssh->login($user, $pass)) {
            return [];
        }
        $secretOut = $ssh->exec("/ppp/secret/print detail without-paging");
        $secretProfiles = parseSecretProfiles($secretOut, $profiles, $profilesByRouter, $routerId, $allUsers, $routerName, $userMeta);
        $profileOut = $ssh->exec("/ppp/profile/print detail without-paging");
        $profileNames = parseProfileNames($profileOut);
        foreach ($profileNames as $pn) {
            recordProfile($pn, $profiles, $profilesByRouter, $routerId);
        }

        $output = $ssh->exec("/ppp/active/print detail without-paging");
        if ($output === false || trim($output) === '') {
            return [];
        }
        return normalizePppoeOutput($output, $secretProfiles, $profiles, $profilesByRouter, $routerId, $routerName, $allUsers, $activeUsersSet, $userMeta);
    } catch (\Throwable $e) {
        $errors[] = 'SSH error ' . $host . ': ' . $e->getMessage();
        return [];
    }
}

function fetchViaRest(string $host, string $user, string $pass, array &$errors, array &$profiles, array &$profilesByRouter, ?string $routerId, string $routerName, array &$allUsers, array &$activeUsersSet, array &$userMeta): array
{
    if (!function_exists('curl_init')) {
        return [];
    }
    $base = stripos($host, 'http') === 0 ? $host : 'https://' . $host;
    $activeUrl = $base . '/rest/ppp/active';
    $secretUrl = $base . '/rest/ppp/secret';
    $profileUrl = $base . '/rest/ppp/profile';

    $secretProfiles = [];
    $secDecoded = curlJson($secretUrl, $user, $pass);
    if (is_array($secDecoded)) {
        foreach ($secDecoded as $s) {
            if (!empty($s['name']) && isset($s['profile'])) {
                $secretProfiles[$s['name']] = $s['profile'];
                recordProfile($s['profile'], $profiles, $profilesByRouter, $routerId);
                $allUsers[$s['name']] = true;
                recordUserMeta($s['name'], $routerId, $routerName, $s['profile'], $userMeta);
            }
        }
    }

    $profDecoded = curlJson($profileUrl, $user, $pass);
    if (is_array($profDecoded)) {
        foreach ($profDecoded as $pr) {
            if (!empty($pr['name'])) {
                recordProfile($pr['name'], $profiles, $profilesByRouter, $routerId);
            }
        }
    }

    $decoded = curlJson($activeUrl, $user, $pass, $errors, $host);
    if (!is_array($decoded)) {
        return [];
    }

    $result = [];
    foreach ($decoded as $row) {
        $uname = $row['name'] ?? ($row['user'] ?? '');
        $result[] = [
            'username' => $uname,
            'ip_address' => $row['address'] ?? '',
            'uptime' => $row['uptime'] ?? '',
            'profile' => $row['profile'] ?? ($secretProfiles[$uname] ?? ''),
            'router_id' => $routerId,
            'router_name' => $routerName,
        ];
        recordProfile($row['profile'] ?? '', $profiles, $profilesByRouter, $routerId);
        if (isset($secretProfiles[$uname])) {
            recordProfile($secretProfiles[$uname], $profiles, $profilesByRouter, $routerId);
        }
        if ($uname !== '') {
            $activeUsersSet[$uname] = true;
            $allUsers[$uname] = true;
            recordUserMeta($uname, $routerId, $routerName, $row['profile'] ?? ($secretProfiles[$uname] ?? ''), $userMeta);
        }
    }
    return $result;
}

function curlJson(string $url, string $user, string $pass, array &$errors = [], string $hostLabel = ''): mixed
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $user . ':' . $pass,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 3,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false || $code >= 400) {
        $msg = $err ?: 'unknown';
        $isConnRefused = stripos($msg, 'connection refused') !== false || stripos($msg, 'failed to connect') !== false;
        if ($errors !== null && !$isConnRefused) {
            $errors[] = 'REST ' . $hostLabel . ' gagal (HTTP ' . $code . '): ' . $msg;
        }
        return null;
    }
    $decoded = json_decode($resp, true);
    return $decoded;
}

function normalizePppoeOutput(string $output, array $secretProfiles = [], array &$profiles = [], array &$profilesByRouter = [], ?string $routerId = null, string $routerName = '', array &$allUsers = [], array &$activeUsersSet = [], array &$userMeta = []): array
{
    $rows = [];
    $lines = preg_split('/\r?\n/', trim($output));
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        preg_match_all('/([A-Za-z0-9\-]+)=(".*?"|\S+)/', $line, $matches, PREG_SET_ORDER);
        $data = [];
        foreach ($matches as $m) {
            $key = $m[1];
            $val = trim($m[2], '"');
            $data[$key] = $val;
        }
        if (!empty($data)) {
            $uname = $data['name'] ?? ($data['user'] ?? '');
            $rows[] = [
                'username' => $uname,
                'ip_address' => $data['address'] ?? '',
                'uptime' => $data['uptime'] ?? '',
                'profile' => $data['profile'] ?? ($secretProfiles[$uname] ?? ''),
                'router_id' => $routerId,
                'router_name' => $routerName,
            ];
            recordProfile($data['profile'] ?? '', $profiles, $profilesByRouter, $routerId);
            if (isset($secretProfiles[$uname])) {
                recordProfile($secretProfiles[$uname], $profiles, $profilesByRouter, $routerId);
            }
            if ($uname !== '') {
                $activeUsersSet[$uname] = true;
                $allUsers[$uname] = true;
                recordUserMeta($uname, $routerId, $routerName, $data['profile'] ?? ($secretProfiles[$uname] ?? ''), $userMeta);
            }
        }
    }
    return $rows;
}

function parseSecretProfiles(?string $output, array &$profileSet = [], array &$profilesByRouter = [], ?string $routerId = null, array &$allUsers = [], string $routerName = '', array &$userMeta = []): array
{
    if ($output === null || trim($output) === '') {
        return [];
    }
    $profiles = [];
    $lines = preg_split('/\r?\n/', trim($output));
    foreach ($lines as $line) {
        preg_match_all('/([A-Za-z0-9\-]+)=(".*?"|\S+)/', trim($line), $matches, PREG_SET_ORDER);
        $data = [];
        foreach ($matches as $m) {
            $key = $m[1];
            $val = trim($m[2], '"');
            $data[$key] = $val;
        }
        if (!empty($data['name']) && isset($data['profile'])) {
            $profiles[$data['name']] = $data['profile'];
            recordProfile($data['profile'], $profileSet, $profilesByRouter, $routerId);
            $allUsers[$data['name']] = true;
            recordUserMeta($data['name'], $routerId, $routerName, $data['profile'], $userMeta);
        }
    }
    return $profiles;
}

function parseProfileNames(?string $output): array
{
    if ($output === null || trim($output) === '') {
        return [];
    }
    $names = [];
    $lines = preg_split('/\r?\n/', trim($output));
    foreach ($lines as $line) {
        preg_match_all('/([A-Za-z0-9\-]+)=(".*?"|\S+)/', trim($line), $matches, PREG_SET_ORDER);
        $data = [];
        foreach ($matches as $m) {
            $key = $m[1];
            $val = trim($m[2], '"');
            $data[$key] = $val;
        }
        if (!empty($data['name'])) {
            $names[] = $data['name'];
        }
    }
    return $names;
}

function recordProfile(?string $profile, array &$profiles, array &$profilesByRouter = [], ?string $routerId = null): void
{
    $profile = trim((string) $profile);
    if ($profile === '') {
        return;
    }
    $profiles[$profile] = true;
    if ($routerId !== null) {
        if (!isset($profilesByRouter[$routerId])) {
            $profilesByRouter[$routerId] = [];
        }
        $profilesByRouter[$routerId][$profile] = true;
    }
}

function recordUserMeta(string $username, ?string $routerId, string $routerName, ?string $profile, array &$userMeta): void
{
    if ($username === '') return;
    if (!isset($userMeta[$username])) {
        $userMeta[$username] = [
            'router_id' => $routerId,
            'router_name' => $routerName,
            'profile' => $profile ?? '',
        ];
    } else {
        if ($profile !== null && $profile !== '') {
            $userMeta[$username]['profile'] = $profile;
        }
        if ($routerId !== null) {
            $userMeta[$username]['router_id'] = $routerId;
        }
        if ($userMeta[$username]['router_name'] === '' && $routerName !== '') {
            $userMeta[$username]['router_name'] = $routerName;
        }
    }
}

function uptimeToSeconds(string $str): int
{
    $str = trim(strtolower($str));
    if ($str === '') return 0;
    $total = 0;
    preg_match_all('/(\d+)\s*w/', $str, $m); foreach ($m[1] ?? [] as $v) $total += (int)$v * 604800;
    preg_match_all('/(\d+)\s*d/', $str, $m); foreach ($m[1] ?? [] as $v) $total += (int)$v * 86400;
    preg_match_all('/(\d+)\s*h/', $str, $m); foreach ($m[1] ?? [] as $v) $total += (int)$v * 3600;
    preg_match_all('/(\d+)\s*m/', $str, $m); foreach ($m[1] ?? [] as $v) $total += (int)$v * 60;
    preg_match_all('/(\d+)\s*s/', $str, $m); foreach ($m[1] ?? [] as $v) $total += (int)$v;
    if ($total > 0) return $total;
    if (preg_match('/^(\d+)d\s+(\d+):(\d{2}):(\d{2})$/', $str, $m)) {
        return (int)$m[1]*86400 + (int)$m[2]*3600 + (int)$m[3]*60 + (int)$m[4];
    }
    if (preg_match('/^(\d+):(\d{2}):(\d{2})$/', $str, $m)) {
        return (int)$m[1]*3600 + (int)$m[2]*60 + (int)$m[3];
    }
    return 0;
}
