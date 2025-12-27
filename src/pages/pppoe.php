<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use phpseclib3\Net\SSH2;
use RouterOS\Client;
use RouterOS\Query;

$routersFile = __DIR__ . '/../../storage/mikrotik.json';
$sampleFile = __DIR__ . '/../../storage/pppoe.json';

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
            'source' => $row['source'] ?? 'unknown',
        ]);
        if (!empty($row['username'])) {
            $activeUsersSet[$row['username']] = true;
        }
    }
}

// Fallback ke sample bila tidak ada data live.
if (count($activeConnections) === 0 && file_exists($sampleFile)) {
    $pppoeSample = json_decode(file_get_contents($sampleFile), true);
    if (is_array($pppoeSample)) {
        $routerById = [];
        foreach ($serverRouters as $router) {
            $routerById[(int) ($router['id'] ?? 0)] = $router;
        }
        foreach ($pppoeSample as $row) {
            $rid = (int) ($row['router_id'] ?? 0);
            if (isset($routerById[$rid])) {
                $router = $routerById[$rid];
                recordProfile($row['profile'] ?? '', $profileOptions, $profilesByRouter, $rid);
                $activeConnections[] = array_merge($row, [
                    'router_name' => $router['name'] ?? '',
                    'router_host' => $router['host'] ?? '',
                    'source' => 'sample',
                ]);
            }
        }
    }
}

// Sort uptime asc/desc berdasarkan query dir (default desc).
$sortDir = isset($_GET['dir']) && strtolower($_GET['dir']) === 'asc' ? 'asc' : 'desc';
$profileFilter = isset($_GET['profile']) ? trim($_GET['profile']) : '';
$routerFilter = isset($_GET['router_id']) ? trim($_GET['router_id']) : '';
$profileList = ($routerFilter !== '' && isset($profilesByRouter[$routerFilter]))
    ? array_keys($profilesByRouter[$routerFilter])
    : array_keys($profileOptions);
sort($profileList, SORT_NATURAL | SORT_FLAG_CASE);
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

$initialData = [
    'summary' => [
        'total' => $totalUsers,
        'active' => $activeCount,
        'inactive' => $inactiveCount,
    ],
    'active' => array_values($activeConnections),
    'inactive_users' => $inactiveUsers,
];
if ($routerFilter !== '') {
    $activeConnections = array_values(array_filter($activeConnections, function ($row) use ($routerFilter) {
        return isset($row['router_id']) && (string) $row['router_id'] === $routerFilter;
    }));
}
if ($profileFilter !== '') {
    $activeConnections = array_values(array_filter($activeConnections, function ($row) use ($profileFilter) {
        return isset($row['profile']) && strcasecmp($row['profile'], $profileFilter) === 0;
    }));
}
if (count($activeConnections) > 1) {
    usort($activeConnections, function ($a, $b) use ($sortDir) {
        $ua = uptimeToSeconds($a['uptime'] ?? '');
        $ub = uptimeToSeconds($b['uptime'] ?? '');
        if ($ua === $ub) return 0;
        return $sortDir === 'asc' ? ($ua <=> $ub) : ($ub <=> $ua);
    });
}
$nextDir = $sortDir === 'asc' ? 'desc' : 'asc';

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

    // 1) Coba via API RouterOS (port 8728)
    $viaApi = fetchViaApi($host, $user, $pass, $errors, $profiles, $profilesByRouter, $routerId, $routerName, $allUsers, $activeUsersSet, $userMeta);
    if (!empty($viaApi)) {
        return array_map(function ($row) {
            $row['source'] = 'api-8728';
            return $row;
        }, $viaApi);
    }

    // 2) Coba via SSH (phpseclib) jika API gagal
    $viaSsh = fetchViaSsh($host, $user, $pass, $errors, $profiles, $profilesByRouter, $routerId, $routerName, $allUsers, $activeUsersSet, $userMeta);
    if (!empty($viaSsh)) {
        return array_map(function ($row) {
            $row['source'] = 'ssh+phpseclib';
            return $row;
        }, $viaSsh);
    }

    // 3) Coba via REST RouterOS jika SSH gagal
    $viaRest = fetchViaRest($host, $user, $pass, $errors, $profiles, $profilesByRouter, $routerId, $routerName, $allUsers, $activeUsersSet, $userMeta);
    if (!empty($viaRest)) {
        return array_map(function ($row) {
            $row['source'] = 'rest';
            return $row;
        }, $viaRest);
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
        // Ambil profil dari secret
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
        // Ambil daftar profile PPP
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
            // abaikan pesan jika memang tidak ada data; hindari memenuhi UI
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
        $isConnRefused = stripos($msg, 'connection refused') !== false || stripos($msg, 'failed to connect') !== false;
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
            $errors[] = 'Gagal login SSH ke ' . $host . '. Periksa kredensial atau izin /ppp/active.';
            return [];
        }
        // Ambil profil dari secret lebih dulu
        $secretOut = $ssh->exec("/ppp/secret/print detail without-paging");
        $secretProfiles = parseSecretProfiles($secretOut, $profiles, $profilesByRouter, $routerId, $allUsers);
        // Ambil daftar profile PPP
        $profileOut = $ssh->exec("/ppp/profile/print detail without-paging");
        $profileNames = parseProfileNames($profileOut);
        foreach ($profileNames as $pn) {
            recordProfile($pn, $profiles, $profilesByRouter, $routerId);
        }

        $output = $ssh->exec("/ppp/active/print detail without-paging");
        if ($output === false || trim($output) === '') {
            $errors[] = 'Tidak ada output PPPoE dari ' . $host . ' via SSH.';
            return [];
        }
        return normalizePppoeOutput($output, $secretProfiles, $profiles, $profilesByRouter, $routerId, $routerName, $allUsers, $activeUsersSet, $userMeta);
    } catch (\Throwable $e) {
        $errors[] = 'Error SSH ke ' . $host . ': ' . $e->getMessage();
        return [];
    }
}

function fetchViaRest(string $host, string $user, string $pass, array &$errors, array &$profiles, array &$profilesByRouter, ?string $routerId, string $routerName, array &$allUsers, array &$activeUsersSet, array &$userMeta): array
{
    if (!function_exists('curl_init')) {
        $errors[] = 'PHP cURL tidak tersedia untuk REST ke ' . $host . '.';
        return [];
    }
    $base = stripos($host, 'http') === 0 ? $host : 'https://' . $host;
    $activeUrl = $base . '/rest/ppp/active';
    $secretUrl = $base . '/rest/ppp/secret';
    $profileUrl = $base . '/rest/ppp/profile';

    // Ambil secret terlebih dulu
    $secretProfiles = [];
    $ch2 = curl_init($secretUrl);
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $user . ':' . $pass,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 3,
    ]);
    $secResp = curl_exec($ch2);
    $secCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);
    if ($secResp !== false && $secCode < 400) {
        $secDecoded = json_decode($secResp, true);
        if (is_array($secDecoded)) {
            foreach ($secDecoded as $s) {
                if (!empty($s['name']) && isset($s['profile'])) {
                    $secretProfiles[$s['name']] = $s['profile'];
                    recordProfile($s['profile'], $profiles, $profilesByRouter, $routerId);
                    $allUsers[$s['name']] = true;
                }
            }
        }
    }

    // Ambil daftar profile PPP
    $ch3 = curl_init($profileUrl);
    curl_setopt_array($ch3, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $user . ':' . $pass,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 3,
    ]);
    $profResp = curl_exec($ch3);
    $profCode = curl_getinfo($ch3, CURLINFO_HTTP_CODE);
    curl_close($ch3);
    if ($profResp !== false && $profCode < 400) {
        $profDecoded = json_decode($profResp, true);
        if (is_array($profDecoded)) {
            foreach ($profDecoded as $pr) {
                if (!empty($pr['name'])) {
                    recordProfile($pr['name'], $profiles, $profilesByRouter, $routerId);
                }
            }
        }
    }

    // Ambil active
    $ch = curl_init($activeUrl);
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
        if (!$isConnRefused) {
            $errors[] = 'REST PPPoE ' . $host . ' gagal (HTTP ' . $code . '): ' . $msg;
        }
        return [];
    }
    $decoded = json_decode($resp, true);
    if (!is_array($decoded)) {
        $errors[] = 'REST PPPoE ' . $host . ' tidak valid JSON.';
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

function parseSecretProfiles(?string $output, array &$profileSet = [], array &$profilesByRouter = [], ?string $routerId = null, array &$allUsers = []): array
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

function uptimeToSeconds(string $uptime): int
{
    $uptime = trim($uptime);
    if ($uptime === '') return 0;

    // Format like 1w2d3h4m5s
    if (preg_match('/^(?:(\d+)w)?(?:(\d+)d)?(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/i', $uptime, $m)) {
        $weeks = (int) ($m[1] ?? 0);
        $days = (int) ($m[2] ?? 0);
        $hours = (int) ($m[3] ?? 0);
        $mins = (int) ($m[4] ?? 0);
        $secs = (int) ($m[5] ?? 0);
        return $weeks * 604800 + $days * 86400 + $hours * 3600 + $mins * 60 + $secs;
    }

    // Format like HH:MM:SS
    if (preg_match('/^(\d+):(\d{2}):(\d{2})$/', $uptime, $m)) {
        $hours = (int) $m[1];
        $mins = (int) $m[2];
        $secs = (int) $m[3];
        return $hours * 3600 + $mins * 60 + $secs;
    }

    return 0;
}
?>

<div class="page-head">
    <h1>PPPoE Active Connection</h1>
    <p>Menampilkan koneksi aktif dari router kategori <strong>server</strong> via RouterOS API (port 8728), fallback SSH/REST bila perlu.</p>
</div>

<section class="card">
    <h2>Ringkasan</h2>
    <p class="muted">
        Router server terdeteksi: <?php echo count($serverRouters); ?> |
        Koneksi aktif: <?php echo count($activeConnections); ?> |
        Sumber: <?php
            if (!count($activeConnections)) {
                echo 'n/a';
            } elseif ($activeConnections[0]['source'] === 'api-8728') {
                echo 'live API 8728';
            } elseif ($activeConnections[0]['source'] === 'ssh+phpseclib') {
                echo 'live SSH';
            } elseif ($activeConnections[0]['source'] === 'rest') {
                echo 'live REST';
            } else {
                echo 'sample file';
            }
        ?> |
        Waktu muat: <?php echo date('d M Y H:i:s'); ?>
    </p>
    <?php if (count($serverRouters) === 0): ?>
        <div class="alert">Tidak ada router berkategori server. Tambah router baru dengan kategori "server" di menu Router Mikrotik.</div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
        <div class="alert" style="margin-top:0.5rem;">
            <strong><?php echo count($errors); ?> router error:</strong>
            <?php foreach ($errors as $err): ?>
                <div><?php echo htmlspecialchars($err); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-grid" style="margin-bottom:0;">
        <article class="card">
            <div class="muted">Total User</div>
            <div class="metric" id="metric-total"><?php echo $totalUsers; ?></div>
        </article>
        <article class="card">
            <div class="muted">User Aktif</div>
            <div class="metric" id="metric-active"><?php echo $activeCount; ?></div>
        </article>
        <article class="card">
            <div class="muted">Tidak Aktif</div>
            <div class="metric" id="metric-inactive"><?php echo $inactiveCount; ?></div>
            <?php if (count($inactiveUsers) > 0): ?>
                <button type="button" class="ghost" id="open-inactive-modal" style="margin-top:0.5rem;">Detail</button>
            <?php endif; ?>
        </article>
    </div>
    <h2>Active Connection</h2>
    <form method="get" style="margin-bottom: 0.75rem;">
        <input type="hidden" name="page" value="pppoe">
        <input type="hidden" name="dir" value="<?php echo htmlspecialchars($sortDir); ?>">
        <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
            <label style="display:flex; align-items:center; gap:0.35rem;">
                <span>Router (server)</span>
                <select name="router_id">
                    <option value="">Semua</option>
                    <?php foreach ($routerOptions as $rid => $rdata): ?>
                        <option value="<?php echo htmlspecialchars($rid); ?>" <?php echo $routerFilter === (string) $rid ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($rdata['name'] ?? ('Router #' . $rid)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="display:flex; align-items:center; gap:0.35rem;">
                <span>Profile</span>
                <select name="profile">
                    <option value="">Semua</option>
                    <?php foreach ($profileList as $opt): ?>
                        <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo strcasecmp($opt, $profileFilter) === 0 ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($opt); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="ghost">Terapkan</button>
            <?php if ($profileFilter !== '' || $routerFilter !== ''): ?>
                <a class="ghost" href="?page=pppoe&dir=<?php echo htmlspecialchars($sortDir); ?>">Reset</a>
            <?php endif; ?>
            <button type="button" class="ghost" id="pppoe-refresh">Refresh</button>
            <label style="display:flex; align-items:center; gap:0.35rem;">
                <input type="checkbox" id="pppoe-auto-refresh">
                <span>Auto refresh</span>
            </label>
            <button type="button" class="ghost" id="pppoe-add-user">Tambah User</button>
        </div>
    </form>
    <div style="margin-bottom: 0.75rem; display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
        <label style="display:flex; align-items:center; gap:0.35rem;">
            <span>Cari (live)</span>
            <input type="search" id="pppoe-search" placeholder="username / IP / profile">
        </label>
    </div>
    <div class="table-wrapper">
        <div style="margin-bottom:0.5rem; display:flex; gap:0.5rem; align-items:center;">
            <button type="button" id="disconnect-selected" class="ghost" disabled>Remove Active (ceklist)</button>
            <span class="muted" style="font-size:0.9rem;">Centang user, lalu klik remove untuk putuskan koneksi aktif.</span>
        </div>
        <table class="table-responsive">
            <thead>
                <tr>
                    <th style="width:50px; text-align:center;">No</th>
                    <th style="width:36px;"><input type="checkbox" id="check-all"></th>
                    <th>User</th>
                    <th>IP Address</th>
                    <th><button type="button" class="ghost" id="sort-uptime">Uptime (<?php echo $sortDir === 'asc' ? 'asc' : 'desc'; ?>)</button></th>
                    <th>Profile</th>
                    <th>Router</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody id="active-table-body">
                <?php if (count($activeConnections) === 0): ?>
                    <tr><td colspan="8">Belum ada koneksi aktif untuk router server atau gagal mengambil via SSH.</td></tr>
                <?php else: ?>
                    <?php foreach ($activeConnections as $idx => $conn): ?>
                        <?php
                            $searchText = strtolower(trim(
                                ($conn['username'] ?? '') . ' ' .
                                ($conn['ip_address'] ?? '') . ' ' .
                                ($conn['profile'] ?? '') . ' ' .
                                ($conn['router_name'] ?? '') . ' ' .
                                ($conn['router_host'] ?? '')
                            ));
                        ?>
                        <tr class="pppoe-row"
                            data-search="<?php echo htmlspecialchars($searchText); ?>"
                            data-router-id="<?php echo htmlspecialchars($conn['router_id'] ?? ''); ?>"
                            data-router-name="<?php echo htmlspecialchars($conn['router_name'] ?? ''); ?>"
                            data-username="<?php echo htmlspecialchars($conn['username'] ?? ''); ?>"
                            data-uptime="<?php echo htmlspecialchars($conn['uptime'] ?? ''); ?>"
                            data-profile="<?php echo htmlspecialchars($conn['profile'] ?? ''); ?>"
                        >
                            <td data-label="No" class="pppoe-no" style="text-align:center;"><?php echo $idx + 1; ?></td>
                            <td data-label="Pilih"><input type="checkbox" class="row-check"></td>
                            <td data-label="User"><?php echo htmlspecialchars($conn['username'] ?? ''); ?></td>
                            <td data-label="IP Address">
                                <?php $ip = $conn['ip_address'] ?? ''; ?>
                                <?php if ($ip): ?>
                                    <a href="http://<?php echo htmlspecialchars($ip); ?>" target="_blank" rel="noreferrer">
                                        <?php echo htmlspecialchars($ip); ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td data-label="Uptime"><?php echo htmlspecialchars($conn['uptime'] ?? ''); ?></td>
                            <td data-label="Profile"><?php echo htmlspecialchars($conn['profile'] ?? ''); ?></td>
                            <td data-label="Router"><?php echo htmlspecialchars($conn['router_name'] ?? ''); ?></td>
                            <td data-label="Aksi"><button type="button" class="ghost pppoe-edit-btn">Edit</button></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr id="pppoe-no-match" style="display:none;"><td colspan="8">Tidak ada hasil untuk pencarian.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <h2>Catatan</h2>
    <p class="muted">
        - Prioritas: API RouterOS port 8728 (<code>/ppp/active/print</code>), fallback SSH (phpseclib) lalu REST.<br>
        - Host/username/password diambil dari data router (menu Router Mikrotik). Jika gagal, halaman menampilkan pesan error dan fallback ke <code>storage/pppoe.json</code> bila ada.<br>
        - Pastikan layanan API (ip service 8728) aktif, atau SSH/REST tersedia jika API ditutup.
    </p>
</section>

<style>
.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.45);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 50;
}
.modal {
    background: #fff;
    border-radius: 14px;
    padding: 1.2rem;
    min-width: 320px;
    max-width: 760px;
    width: 95%;
    box-shadow: 0 18px 40px rgba(0,0,0,0.22);
}
.modal header {
    font-weight: 700;
    margin-bottom: 0.75rem;
    font-size: 1.1rem;
}
.modal .muted {
    color: #4b5563;
    margin-bottom: 0.75rem;
}
.modal footer {
    display: flex;
    gap: 0.5rem;
    justify-content: flex-end;
    margin-top: 1rem;
}
.modal .status {
    font-size: 0.9rem;
    color: #666;
    min-height: 1.2em;
}
.modal .field-label {
    font-size: 0.9rem;
    color: #4b5563;
    margin-bottom: 0.25rem;
}
.modal .input {
    width: 100%;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    padding: 0.65rem 0.8rem;
    font-size: 1rem;
    background: #f9fafb;
}
.modal .input[readonly] {
    color: #111827;
}
</style>

<div id="pppoe-modal" class="modal-backdrop" style="display:none;">
    <div class="modal">
        <header>Edit PPPoE User</header>
        <div class="muted">Pilih router dan profil baru. Koneksi aktif akan di-drop setelah simpan.</div>
        <div style="display:grid; gap:0.75rem;">
            <label style="display:flex; flex-direction:column;">
                <span class="field-label">Router (server)</span>
                <select id="pppoe-modal-router" class="input"></select>
            </label>
            <label style="display:flex; flex-direction:column;">
                <span class="field-label">User</span>
                <input type="text" id="pppoe-modal-username" class="input" readonly>
            </label>
            <label style="display:flex; flex-direction:column;">
                <span class="field-label">Profil</span>
                <select id="pppoe-modal-profile" class="input"></select>
            </label>
            <div class="status" id="pppoe-modal-status"></div>
        </div>
        <footer>
            <button type="button" class="ghost" id="pppoe-modal-cancel">Batal</button>
            <button type="button" class="ghost" id="pppoe-modal-delete" style="border:1px solid #ef4444; color:#b91c1c;">Hapus Secret</button>
            <button type="button" id="pppoe-modal-save">Simpan & Drop Koneksi</button>
        </footer>
    </div>
</div>
<?php include __DIR__ . '/../partials/inactive-modal.php'; ?>

<div id="pppoe-add-modal" class="modal-backdrop" style="display:none;">
    <div class="modal">
        <header>Tambah PPPoE User</header>
        <div style="display:grid; gap:0.5rem;">
            <label style="display:flex; flex-direction:column; gap:0.3rem;">
                <span>Router (server)</span>
                <select id="add-router"></select>
            </label>
            <label style="display:flex; flex-direction:column; gap:0.3rem;">
                <span>Username</span>
                <input type="text" id="add-username" placeholder="username">
            </label>
            <label style="display:flex; flex-direction:column; gap:0.3rem;">
                <span>Profile</span>
                <select id="add-profile"></select>
            </label>
            <div class="status" id="add-status"></div>
            <p class="muted" style="font-size:0.9rem;">Password akan disamakan dengan username, service = any.</p>
        </div>
        <footer>
            <button type="button" class="ghost" id="add-cancel">Batal</button>
            <button type="button" id="add-save">Simpan</button>
        </footer>
    </div>
</div>


<script>
var initialPppoeData = <?php echo json_encode($initialData, JSON_UNESCAPED_SLASHES); ?>;
(function() {
    var searchInput = document.getElementById('pppoe-search');
    var activeTableBody = document.getElementById('active-table-body');
    var rows = [];
    var noMatchRow = null;
    var filterRouter = document.querySelector('select[name="router_id"]');
    var filterProfile = document.querySelector('select[name="profile"]');
    var profileMap = <?php echo json_encode($profilesByRouter, JSON_UNESCAPED_SLASHES); ?>;
    var routerMap = <?php echo json_encode($routerOptions, JSON_UNESCAPED_SLASHES); ?>;
    var modal = document.getElementById('pppoe-modal');
    var modalUsername = document.getElementById('pppoe-modal-username');
    var modalRouter = document.getElementById('pppoe-modal-router');
    var modalProfile = document.getElementById('pppoe-modal-profile');
    var modalSave = document.getElementById('pppoe-modal-save');
    var modalCancel = document.getElementById('pppoe-modal-cancel');
    var modalDelete = document.getElementById('pppoe-modal-delete');
    var currentRow = null;
    var currentContext = 'active'; // 'active' | 'inactive'
    var modalStatus = document.getElementById('pppoe-modal-status');
    var inactiveModal = document.getElementById('inactive-modal');
    var inactiveOpen = document.getElementById('open-inactive-modal');
    var inactiveClose = document.getElementById('inactive-close');
    var refreshBtn = document.getElementById('pppoe-refresh');
    var autoRefreshToggle = document.getElementById('pppoe-auto-refresh');
    var metricTotal = document.getElementById('metric-total');
    var metricActive = document.getElementById('metric-active');
    var metricInactive = document.getElementById('metric-inactive');
    var inactiveTableBody = document.getElementById('inactive-table-body');
    var addModal = document.getElementById('pppoe-add-modal');
    var addRouter = document.getElementById('add-router');
    var addUsername = document.getElementById('add-username');
    var addProfile = document.getElementById('add-profile');
    var addStatus = document.getElementById('add-status');
    var addSave = document.getElementById('add-save');
    var addCancel = document.getElementById('add-cancel');
    var addOpen = document.getElementById('pppoe-add-user');
    var autoRefreshTimer = null;
    var sortDir = '<?php echo $sortDir; ?>';
    var sortBtn = document.getElementById('sort-uptime');
    var lastData = initialPppoeData || { active: [], summary: {}, inactive_users: [] };

    function applyFilter() {
        if (!activeTableBody) return;
        rows = Array.prototype.slice.call(activeTableBody.querySelectorAll('.pppoe-row'));
        noMatchRow = activeTableBody.querySelector('#pppoe-no-match');
        var q = (searchInput && searchInput.value ? searchInput.value.toLowerCase().trim() : '');
        var visible = 0;
        rows.forEach(function (row) {
            var hay = (row.dataset.search || '').toLowerCase();
            var show = !q || hay.indexOf(q) !== -1;
            row.style.display = show ? '' : 'none';
            if (show) {
                visible++;
                var noCell = row.querySelector('.pppoe-no');
                if (noCell) noCell.textContent = visible;
            }
        });
        if (noMatchRow) {
            noMatchRow.style.display = visible === 0 ? '' : 'none';
        }
        updateBulkState();
    }

    if (searchInput) {
        searchInput.addEventListener('input', applyFilter);
        applyFilter();
    }

    function populateRouterSelect() {
        if (!modalRouter) return;
        modalRouter.innerHTML = '';
        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = '-- Pilih router --';
        modalRouter.appendChild(placeholder);
        Object.keys(routerMap || {}).forEach(function(rid){
            var opt = document.createElement('option');
            opt.value = rid;
            opt.textContent = routerMap[rid].name || ('Router #' + rid);
            modalRouter.appendChild(opt);
        });
    }

    function openModal(row, context) {
        currentRow = row;
        currentContext = context || 'active';
        var uname = row.dataset.username || '';
        var rid = row.dataset.routerId || '';
        var currentProfile = row.dataset.profile || (row.querySelector('td:nth-child(4)') || {}).textContent || '';
        populateRouterSelect();
        if (modalRouter) {
            if (rid && !modalRouter.querySelector('option[value="' + rid + '"]')) {
                var opt = document.createElement('option');
                opt.value = rid;
                opt.textContent = row.dataset.routerName || ('Router #' + rid);
                modalRouter.appendChild(opt);
            }
            modalRouter.value = rid || '';
        }
        if (modalUsername) {
            modalUsername.value = uname;
        }
        buildProfileOptions(modalRouter ? modalRouter.value : rid, currentProfile);
        modal.style.display = 'flex';
        setStatus('');
    }

    function closeModal() {
        modal.style.display = 'none';
        currentRow = null;
    }

    function buildProfileOptions(routerId, currentProfile) {
        if (!modalProfile) return;
        modalProfile.innerHTML = '';
        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = '-- Pilih profile --';
        modalProfile.appendChild(placeholder);
        if (!routerId) return;
        var opts = profileMap[routerId] ? Object.keys(profileMap[routerId]) : {};
        var profiles = Array.isArray(opts) ? opts : Object.keys(opts);
        profiles.sort(function(a,b){ return a.localeCompare(b); });
        profiles.forEach(function(p){
            var opt = document.createElement('option');
            opt.value = p;
            opt.textContent = p;
            if (p === currentProfile) opt.selected = true;
            modalProfile.appendChild(opt);
        });
        if (currentProfile && modalProfile.value !== currentProfile) {
            var extra = document.createElement('option');
            extra.value = currentProfile;
            extra.textContent = currentProfile + ' (lama)';
            extra.selected = true;
            modalProfile.appendChild(extra);
        }
    }

    function removeRow(row) {
        if (!row || !row.parentNode) return;
        row.parentNode.removeChild(row);
        rows = rows.filter(function(r){ return r !== row; });
        applyFilter();
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.pppoe-edit-btn');
        if (btn) {
            var row = btn.closest('.pppoe-row');
            if (row) openModal(row, 'active');
        }
        var del = e.target.closest('.pppoe-delete-btn');
        if (del) {
            var r = del.closest('.pppoe-row');
            if (r) handleDelete(r);
        }
        var delInactive = e.target.closest('.inactive-delete-btn');
        if (delInactive) {
            var uname = delInactive.dataset.username || '';
            var row = delInactive.closest('tr');
            var routerId = getInactiveRouterId(row) || delInactive.dataset.routerId || '';
            var routerName = row ? (row.dataset.routerName || '') : '';
            handleDeleteInactive(uname, routerName, routerId);
        }
        var editInactive = e.target.closest('.inactive-edit-btn');
        if (editInactive) {
            var rowE = editInactive.closest('tr');
            handleEditInactive(rowE);
        }
    });

    var checkAll = document.getElementById('check-all');
    var disconnectBtn = document.getElementById('disconnect-selected');
    function visibleChecks() {
        if (!activeTableBody) return [];
        var boxes = activeTableBody.querySelectorAll('.row-check');
        return Array.prototype.filter.call(boxes, function(b){
            var row = b.closest('.pppoe-row');
            return row && row.style.display !== 'none';
        });
    }
    function updateBulkState() {
        if (!disconnectBtn) return;
        var visible = visibleChecks();
        var checked = visible.filter(function(b){ return b.checked; });
        disconnectBtn.disabled = checked.length === 0;
        if (checkAll) {
            if (visible.length === 0) {
                checkAll.checked = false;
                checkAll.indeterminate = false;
            } else {
                checkAll.checked = checked.length === visible.length;
                checkAll.indeterminate = checked.length > 0 && checked.length < visible.length;
            }
        }
    }
    checkAll && checkAll.addEventListener('change', function(){
        var boxes = visibleChecks();
        boxes.forEach(function(b){ b.checked = checkAll.checked; });
        updateBulkState();
    });
    activeTableBody && activeTableBody.addEventListener('change', function(e){
        if (e.target && e.target.classList.contains('row-check')) {
            updateBulkState();
        }
    });
    disconnectBtn && disconnectBtn.addEventListener('click', function(){
        var boxes = visibleChecks().filter(function(b){ return b.checked; });
        if (boxes.length === 0) return;
        if (!confirm('Putuskan koneksi aktif untuk ' + boxes.length + ' user?')) return;
        boxes.forEach(function(box){
            var row = box.closest('.pppoe-row');
            if (!row) return;
            var uname = row.dataset.username || '';
            var rid = row.dataset.routerId || '';
            if (!uname || !rid) return;
            fetch('pppoe_action.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'disconnect', router_id: rid, username: uname })
            })
            .then(function(res){ return res.json(); })
            .then(function(json){
                removeRow(row);
            })
            .catch(function(err){ console.error('Bulk remove error', err); });
        });
        updateBulkState();
    });
    
    modalCancel && modalCancel.addEventListener('click', closeModal);
    modal && modal.addEventListener('click', function (e) {
        if (e.target === modal) closeModal();
    });
    if (modalRouter) {
        modalRouter.addEventListener('change', function(){
            buildProfileOptions(modalRouter.value, '');
        });
    }

    modalSave && modalSave.addEventListener('click', function () {
        if (!currentRow) return;
        var newProfile = modalProfile ? (modalProfile.value || '').trim() : '';
        var rid = modalRouter ? (modalRouter.value || '').trim() : (currentRow.dataset.routerId || '').trim();
        var uname = currentRow.dataset.username || '';
        var oldRid = (currentRow.dataset.routerId || '').trim();
        if (!rid) {
            setStatus('Pilih router terlebih dahulu');
            return;
        }
        if (!newProfile) {
            setStatus('Pilih profile terlebih dahulu');
            return;
        }
        setStatus('Menyimpan...');
        modalSave.disabled = true;

        var promise;
        if (oldRid && oldRid !== rid) {
            // Pindah router: buat di router baru dulu, lalu hapus di router lama
            promise = fetch('pppoe_action.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ router_id: rid, username: uname, profile: newProfile, action: 'add' })
            })
            .then(function(res){ return res.json(); })
            .then(function(json){
                if (json.error) throw new Error(json.error);
                return fetch('pppoe_action.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ router_id: oldRid, username: uname, action: 'delete' })
                }).then(function(res){ return res.json(); }).catch(function(){ return {}; });
            });
        } else {
            // Router sama: cukup update profil
            promise = fetch('pppoe_action.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ router_id: rid, username: uname, profile: newProfile, action: 'update' })
            }).then(function(res){ return res.json(); });
        }

        promise
        .then(function(json){
            if (json && json.error) throw new Error(json.error);
            currentRow.dataset.routerId = rid;
            currentRow.dataset.routerName = routerMap[rid] ? (routerMap[rid].name || '') : '';
            currentRow.dataset.profile = newProfile;
            if (currentContext === 'active') {
                removeRow(currentRow);
            } else {
                var cells = currentRow.querySelectorAll('td');
                if (cells[0]) cells[0].textContent = currentRow.dataset.routerName || rid;
                if (cells[2]) cells[2].textContent = newProfile;
            }
            refreshData();
            closeModal();
        })
        .catch(function(err){
            setStatus('Gagal: ' + err.message);
        })
        .finally(function(){
            modalSave.disabled = false;
        });
    });
    modalDelete && modalDelete.addEventListener('click', function(){
        if (!currentRow) return;
        // gunakan router yang dipilih di dropdown
        if (modalRouter) {
            currentRow.dataset.routerId = modalRouter.value || currentRow.dataset.routerId || '';
            currentRow.dataset.routerName = routerMap[currentRow.dataset.routerId] ? (routerMap[currentRow.dataset.routerId].name || '') : (currentRow.dataset.routerName || '');
        }
        handleDelete(currentRow);
        closeModal();
    });
    
    function toggleInactive(show) {
        if (!inactiveModal) return;
        inactiveModal.style.display = show ? 'flex' : 'none';
    }
    inactiveOpen && inactiveOpen.addEventListener('click', function(){ toggleInactive(true); });
    inactiveClose && inactiveClose.addEventListener('click', function(){ toggleInactive(false); });
    inactiveModal && inactiveModal.addEventListener('click', function(e){
        if (e.target === inactiveModal) toggleInactive(false);
    });

    sortBtn && sortBtn.addEventListener('click', function () {
        sortDir = sortDir === 'asc' ? 'desc' : 'asc';
        sortBtn.textContent = 'Uptime (' + sortDir + ')';
        sortRows();
        applyFilter();
    });

    refreshBtn && refreshBtn.addEventListener('click', refreshData);
    autoRefreshToggle && autoRefreshToggle.addEventListener('change', function () {
        if (autoRefreshToggle.checked) {
            startAutoRefresh();
        } else {
            stopAutoRefresh();
        }
    });

    addOpen && addOpen.addEventListener('click', function(){
        openAddModal();
    });
    addCancel && addCancel.addEventListener('click', closeAddModal);
    addModal && addModal.addEventListener('click', function(e){
        if (e.target === addModal) closeAddModal();
    });

    function renderData(data) {
        if (!data) return;
        if (metricTotal) metricTotal.textContent = data.summary ? data.summary.total : '';
        if (metricActive) metricActive.textContent = data.summary ? data.summary.active : '';
        if (metricInactive) metricInactive.textContent = data.summary ? data.summary.inactive : '';

        buildActiveTable(data);

        if (inactiveTableBody) {
            inactiveTableBody.innerHTML = '';
            (data.inactive_users || []).forEach(function(u){
                var tr = document.createElement('tr');
                tr.dataset.username = u.username || '';
                tr.dataset.profile = u.profile || '';
                tr.dataset.routerId = u.router_id || '';
                tr.dataset.routerName = u.router_name || '';

                var tdRouter = document.createElement('td');
                tdRouter.setAttribute('data-label', 'Router');
                tdRouter.style.padding = '10px 8px';
                tdRouter.style.fontWeight = '600';
                tdRouter.textContent = u.router_name || '-';
                tr.appendChild(tdRouter);

                var tdUser = document.createElement('td');
                tdUser.setAttribute('data-label', 'User');
                tdUser.style.padding = '10px 8px';
                tdUser.style.fontWeight = '700';
                tdUser.textContent = u.username || '';
                tr.appendChild(tdUser);

                var tdProfile = document.createElement('td');
                tdProfile.setAttribute('data-label', 'Profile');
                tdProfile.style.padding = '10px 8px';
                tdProfile.textContent = u.profile || '';
                tr.appendChild(tdProfile);

                var actionTd = document.createElement('td');
                actionTd.setAttribute('data-label', 'Aksi');
                actionTd.style.padding = '10px 8px';
                actionTd.style.textAlign = 'right';
                var editBtn = document.createElement('button');
                editBtn.type = 'button';
                editBtn.className = 'pill-btn inactive-edit-btn';
                editBtn.style.padding = '8px 12px';
                editBtn.textContent = 'Edit';
                var delBtn = document.createElement('button');
                delBtn.type = 'button';
                delBtn.className = 'pill-btn ghost inactive-delete-btn';
                delBtn.style.padding = '8px 12px';
                delBtn.textContent = 'Hapus';
                delBtn.dataset.username = u.username || '';
                delBtn.dataset.routerName = u.router_name || '';
                delBtn.dataset.routerId = u.router_id || '';
                actionTd.appendChild(editBtn);
                actionTd.appendChild(document.createTextNode(' '));
                actionTd.appendChild(delBtn);
                tr.appendChild(actionTd);
                inactiveTableBody.appendChild(tr);
            });
        }
    }

    function buildActiveTable(data) {
        var tbody = activeTableBody;
        if (!tbody) return;
        tbody.innerHTML = '';

        var list = data.active || [];
        // apply filter router/profile
        var fRouter = filterRouter ? filterRouter.value : '';
        var fProfile = filterProfile ? filterProfile.value : '';
        list = list.filter(function(item){
            var okRouter = !fRouter || (String(item.router_id || '') === fRouter);
            var okProfile = !fProfile || (String(item.profile || '').toLowerCase() === fProfile.toLowerCase());
            return okRouter && okProfile;
        });

        list = list.slice().sort(function(a,b){
            var ua = uptimeToSeconds(a.uptime || '');
            var ub = uptimeToSeconds(b.uptime || '');
            if (ua === ub) {
                var sa = (a.uptime || '').toLowerCase();
                var sb = (b.uptime || '').toLowerCase();
                return sortDir === 'asc' ? sa.localeCompare(sb) : sb.localeCompare(sa);
            }
            return sortDir === 'asc' ? (ua - ub) : (ub - ua);
        });

        if (list.length === 0) {
            var trEmpty = document.createElement('tr');
            var tdEmpty = document.createElement('td');
            tdEmpty.colSpan = 8;
            tdEmpty.textContent = 'Belum ada koneksi aktif untuk router server atau gagal mengambil via SSH.';
            trEmpty.appendChild(tdEmpty);
            tbody.appendChild(trEmpty);
            rows = [];
            noMatchRow = null;
            return;
        }

        list.forEach(function(conn){
            var tr = document.createElement('tr');
            tr.className = 'pppoe-row';
            tr.dataset.search = (
                (conn.username || '') + ' ' +
                (conn.ip_address || '') + ' ' +
                (conn.profile || '') + ' ' +
                (conn.router_name || '')
            ).toLowerCase();
            tr.dataset.routerId = conn.router_id || '';
            tr.dataset.username = conn.username || '';
            tr.dataset.uptime = conn.uptime || '';

            var tdNo = document.createElement('td');
            tdNo.setAttribute('data-label', 'No');
            tdNo.className = 'pppoe-no';
            tdNo.style.textAlign = 'center';
            tr.appendChild(tdNo);

            var tdCheck = document.createElement('td');
            tdCheck.setAttribute('data-label', 'Pilih');
            var chk = document.createElement('input');
            chk.type = 'checkbox';
            chk.className = 'row-check';
            tdCheck.appendChild(chk);
            tr.appendChild(tdCheck);

            var tdUser = document.createElement('td');
            tdUser.setAttribute('data-label', 'User');
            tdUser.textContent = conn.username || '';
            tr.appendChild(tdUser);

            var tdIp = document.createElement('td');
            tdIp.setAttribute('data-label', 'IP Address');
            if (conn.ip_address) {
                var a = document.createElement('a');
                a.href = 'http://' + conn.ip_address;
                a.target = '_blank';
                a.rel = 'noreferrer';
                a.textContent = conn.ip_address;
                tdIp.appendChild(a);
            }
            tr.appendChild(tdIp);

            var tdUptime = document.createElement('td');
            tdUptime.setAttribute('data-label', 'Uptime');
            tdUptime.textContent = conn.uptime || '';
            tr.appendChild(tdUptime);

            var tdProfile = document.createElement('td');
            tdProfile.setAttribute('data-label', 'Profile');
            tdProfile.textContent = conn.profile || '';
            tr.appendChild(tdProfile);

            var tdRouter = document.createElement('td');
            tdRouter.setAttribute('data-label', 'Router');
            tdRouter.textContent = conn.router_name || '';
            tr.appendChild(tdRouter);

                var tdAction = document.createElement('td');
        tdAction.setAttribute('data-label', 'Aksi');
        tdAction.className = 'table-actions';
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'ghost pppoe-edit-btn';
        btn.textContent = 'Edit';
        tdAction.appendChild(btn);
        var delBtn = document.createElement('button');
        delBtn.type = 'button';
        delBtn.className = 'ghost pppoe-delete-btn';
        delBtn.textContent = 'Hapus';
        delBtn.style.marginLeft = '6px';
        tr.appendChild(tdAction);
                var delBtn = document.createElement('button');
                delBtn.type = 'button';
                delBtn.className = 'ghost pppoe-delete-btn';
                delBtn.textContent = 'Hapus';
                delBtn.style.marginLeft = '6px';
                tdAction.appendChild(delBtn);
                tr.appendChild(tdAction);

            tbody.appendChild(tr);
        });
        noMatchRow = document.createElement('tr');
        noMatchRow.id = 'pppoe-no-match';
        noMatchRow.style.display = 'none';
        var tdNo = document.createElement('td');
        tdNo.colSpan = 8;
        tdNo.textContent = 'Tidak ada hasil untuk pencarian.';
        noMatchRow.appendChild(tdNo);
        tbody.appendChild(noMatchRow);
        rows = Array.prototype.slice.call(activeTableBody.querySelectorAll('.pppoe-row'));
        sortRows();
        applyFilter();
    }

    function setStatus(msg) {
        if (modalStatus) {
            modalStatus.textContent = msg;
        }
    }

    function openAddModal() {
        if (!addModal) return;
        populateAddRouters();
        if (addRouter) addRouter.value = '';
        populateAddProfiles('');
        addUsername.value = '';
        addStatus.textContent = '';
        addModal.style.display = 'flex';
    }

    function closeAddModal() {
        if (addModal) addModal.style.display = 'none';
    }

    function populateAddRouters() {
        if (!addRouter) return;
        addRouter.innerHTML = '';
        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = '-- Pilih router --';
        addRouter.appendChild(placeholder);
        Object.keys(routerMap || {}).forEach(function(rid){
            var opt = document.createElement('option');
            opt.value = rid;
            opt.textContent = routerMap[rid].name || ('Router #' + rid);
            addRouter.appendChild(opt);
        });
    }

    
    function populateAddProfiles(rid) {
        if (!addProfile) return;
        addProfile.innerHTML = '';
        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = '-- Pilih profile --';
        addProfile.appendChild(placeholder);
        if (!rid) return;
        var profilesObj = profileMap[rid] || {};
        Object.keys(profilesObj).sort().forEach(function(p){
            var opt = document.createElement('option');
            opt.value = p;
            opt.textContent = p;
            addProfile.appendChild(opt);
        });
    }

    addRouter && addRouter.addEventListener('change', function(){
        populateAddProfiles(addRouter.value);
        if (addProfile) addProfile.value = '';
    });

    addSave && addSave.addEventListener('click', function(){
        var rid = addRouter ? (addRouter.value || '').trim() : '';
        var uname = addUsername ? addUsername.value.trim() : '';
        var prof = addProfile ? (addProfile.value || '').trim() : '';
        if (!rid || !uname || !prof) {
            if (addStatus) addStatus.textContent = 'Router, username, profile wajib diisi';
            return;
        }
        addSave.disabled = true;
        addStatus.textContent = 'Menyimpan...';
        fetch('pppoe_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'add', router_id: rid, username: uname, profile: prof })
        })
        .then(function(res){ return res.json(); })
        .then(function(json){
            if (json.error) throw new Error(json.error);
            addStatus.textContent = 'Berhasil tambah user';
            refreshData();
            setTimeout(closeAddModal, 500);
        })
        .catch(function(err){
            addStatus.textContent = 'Gagal: ' + err.message;
        })
        .finally(function(){
            addSave.disabled = false;
        });
    });

function handleDelete(row) {
        var uname = row.dataset.username || '';
        var rid = row.dataset.routerId || '';
        if (!uname || !rid) return;
        if (!confirm('Hapus user ' + uname + '?')) return;
        fetch('pppoe_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', router_id: rid, username: uname })
        })
        .then(function(res){ return res.json(); })
        .then(function(json){
            if (json.error) throw new Error(json.error);
            removeRow(row);
        })
        .catch(function(err){
            alert('Gagal menghapus: ' + err.message);
        });
    }

    function handleDeleteInactive(uname, routerNameHint, routerIdHint) {
        if (!uname) return;
        var rid = routerIdHint || '';
        // jika belum ada routerId, coba cocokan dari baris aktif
        if (!rid) {
            rows.forEach(function(r){
                if (r.dataset && r.dataset.username === uname && r.dataset.routerId) {
                    rid = r.dataset.routerId;
                }
            });
        }
        // fallback: jika hanya ada satu router server
        if (!rid) {
            var keys = Object.keys(routerMap || {});
            if (keys.length === 1) rid = keys[0];
        }
        if (!rid) {
            alert('Tidak bisa menghapus: router server tidak ditemukan untuk user ini');
            return;
        }
        if (!confirm('Hapus user ' + uname + ' di router ' + (routerNameHint || rid) + '?')) return;
        fetch('pppoe_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', router_id: rid, username: uname })
        })
        .then(function(res){ return res.json(); })
        .then(function(json){
            if (json.error) throw new Error(json.error);
            // Hapus dari tabel inactive modal
            if (inactiveTableBody) {
                var trs = inactiveTableBody.querySelectorAll('tr');
                trs.forEach(function(tr){
                    var unameCell = tr.dataset.username || '';
                    if (unameCell === uname) {
                        tr.remove();
                    }
                });
            }
            // Hapus juga baris active jika ada
            var foundRow = rows.find(function(r){ return r.dataset.username === uname; });
            if (foundRow) {
                removeRow(foundRow);
            }
        })
        .catch(function(err){
            alert('Gagal menghapus: ' + err.message);
        });
    }

    function refreshData() {
        if (refreshBtn) refreshBtn.disabled = true;
        fetch('pppoe_data.php?dir=' + encodeURIComponent(sortDir))
            .then(function (res) { return res.json(); })
            .then(function (json) {
                if (json.error) throw new Error(json.error);
                lastData = json;
                renderData(lastData);
            })
            .catch(function (err) {
                console.error('Refresh error', err);
            })
            .finally(function () {
                if (refreshBtn) refreshBtn.disabled = false;
            });
    }

    function startAutoRefresh() {
        stopAutoRefresh();
        autoRefreshTimer = setInterval(refreshData, 1000);
    }

    function stopAutoRefresh() {
        if (autoRefreshTimer) {
            clearInterval(autoRefreshTimer);
            autoRefreshTimer = null;
        }
    }

    function getInactiveRouterId(row) {
        if (!row) return '';
        return row.dataset.routerId || '';
    }

    function handleEditInactive(row) {
        if (!row) return;
        toggleInactive(false);
        openModal(row, 'inactive');
    }

    function sortRows() {
        if (!activeTableBody) return;
        var list = Array.prototype.slice.call(activeTableBody.querySelectorAll('.pppoe-row'));
        list.sort(function(a,b){
            var ua = uptimeToSeconds(a.dataset.uptime || '');
            var ub = uptimeToSeconds(b.dataset.uptime || '');
            if (ua === ub) {
                var sa = (a.dataset.uptime || '').toLowerCase();
                var sb = (b.dataset.uptime || '').toLowerCase();
                return sortDir === 'asc' ? sa.localeCompare(sb) : sb.localeCompare(sa);
            }
            return sortDir === 'asc' ? (ua - ub) : (ub - ua);
        });
        // re-append in sorted order before noMatchRow
        list.forEach(function(row){
            activeTableBody.appendChild(row);
        });
        if (noMatchRow) {
            activeTableBody.appendChild(noMatchRow);
        }
    }

    function uptimeToSeconds(str) {
        if (!str) return 0;
        str = str.trim().toLowerCase();

        // Format with units (supports without spacing): "1w2d3h4m5s", "9h47m27s", "2d 03:04:05"
        var total = 0, m;
        var re = /(\d+)\s*([wdhms])/g;
        while ((m = re.exec(str)) !== null) {
            var v = parseInt(m[1], 10);
            switch (m[2]) {
                case 'w': total += v * 604800; break;
                case 'd': total += v * 86400; break;
                case 'h': total += v * 3600; break;
                case 'm': total += v * 60; break;
                case 's': total += v; break;
            }
        }
        if (total > 0) return total;

        // Format day + HH:MM:SS (e.g., "1d 02:03:04")
        var matchDayTime = str.match(/^(\d+)d\s+(\d+):(\d{2}):(\d{2})$/);
        if (matchDayTime) {
            return parseInt(matchDayTime[1],10)*86400 +
                   parseInt(matchDayTime[2],10)*3600 +
                   parseInt(matchDayTime[3],10)*60 +
                   parseInt(matchDayTime[4],10);
        }

        // Format HH:MM:SS
        var match2 = str.match(/^(\d+):(\d{2}):(\d{2})$/);
        if (match2) {
            return parseInt(match2[1], 10)*3600 + parseInt(match2[2], 10)*60 + parseInt(match2[3], 10);
        }
        return 0;
    }

    // initial render (ensuring sort applied)
    renderData(lastData);
    if (autoRefreshToggle && autoRefreshToggle.checked) {
        startAutoRefresh();
    }
})();
</script>

