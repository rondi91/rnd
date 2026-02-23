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

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload tidak valid']);
    exit;
}

$action = trim((string) ($input['action'] ?? ''));
if (!in_array($action, ['move_users', 'delete_secrets'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Aksi tidak dikenali']);
    exit;
}

$routersFile = __DIR__ . '/../storage/mikrotik.json';
$routers = file_exists($routersFile) ? json_decode((string) file_get_contents($routersFile), true) : [];
if (!is_array($routers)) {
    $routers = [];
}

$servers = [];
foreach ($routers as $router) {
    if (!is_array($router)) {
        continue;
    }
    if (strtolower(trim((string) ($router['category'] ?? ''))) !== 'server') {
        continue;
    }
    $rid = trim((string) ($router['id'] ?? ''));
    if ($rid === '') {
        continue;
    }
    $servers[$rid] = $router;
}

$clientCache = [];
$clientErrors = [];
$secretCache = [];

if ($action === 'delete_secrets') {
    $items = $input['items'] ?? [];
    if (!is_array($items) || count($items) === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'items wajib diisi']);
        exit;
    }

    $cleanItems = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $username = trim((string) ($item['username'] ?? ''));
        $routerId = trim((string) ($item['router_id'] ?? ''));
        if ($username === '' || $routerId === '') {
            continue;
        }
        $key = $routerId . '::' . strtolower($username);
        $cleanItems[$key] = [
            'username' => $username,
            'router_id' => $routerId,
            'status' => (string) ($item['status'] ?? ''),
        ];
    }
    if (count($cleanItems) === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Data items tidak valid']);
        exit;
    }

    $results = [];
    $deletedUsers = 0;
    $failed = 0;
    $removedActiveTotal = 0;
    $removedSecretTotal = 0;

    foreach ($cleanItems as $item) {
        $username = (string) ($item['username'] ?? '');
        $routerId = (string) ($item['router_id'] ?? '');
        $router = $servers[$routerId] ?? null;
        if (!is_array($router)) {
            $failed++;
            $results[] = [
                'username' => $username,
                'router_id' => $routerId,
                'ok' => false,
                'message' => 'Router tidak ditemukan.',
            ];
            continue;
        }
        $client = getRouterClient($router, $clientCache, $clientErrors);
        if ($client === null) {
            $failed++;
            $results[] = [
                'username' => $username,
                'router_id' => $routerId,
                'ok' => false,
                'message' => 'Gagal konek ke router.',
            ];
            continue;
        }

        try {
            $rm = removeUserFromRouter($client, $username);
            $removedActiveTotal += (int) ($rm['removed_active'] ?? 0);
            $removedSecretTotal += (int) ($rm['removed_secret'] ?? 0);
            unset($secretCache[$routerId]);

            if (!empty($rm['removed'])) {
                $deletedUsers++;
                $results[] = [
                    'username' => $username,
                    'router_id' => $routerId,
                    'router_name' => (string) ($router['name'] ?? ''),
                    'ok' => true,
                    'removed_active' => (int) ($rm['removed_active'] ?? 0),
                    'removed_secret' => (int) ($rm['removed_secret'] ?? 0),
                    'message' => 'Secret dihapus' . ((int) ($rm['removed_active'] ?? 0) > 0 ? ' + active diputus' : ''),
                ];
            } else {
                $failed++;
                $results[] = [
                    'username' => $username,
                    'router_id' => $routerId,
                    'router_name' => (string) ($router['name'] ?? ''),
                    'ok' => false,
                    'message' => 'User tidak ditemukan di router.',
                ];
            }
        } catch (\Throwable $e) {
            $failed++;
            $results[] = [
                'username' => $username,
                'router_id' => $routerId,
                'router_name' => (string) ($router['name'] ?? ''),
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    echo json_encode([
        'message' => 'Proses hapus secret selesai',
        'deleted_users' => $deletedUsers,
        'failed' => $failed,
        'removed_active_total' => $removedActiveTotal,
        'removed_secret_total' => $removedSecretTotal,
        'results' => $results,
        'client_errors' => $clientErrors,
    ]);
    exit;
}

$targetRouterId = trim((string) ($input['target_router_id'] ?? ''));
$removeFromServer1 = !empty($input['remove_same_from_server1']);
$server1RouterId = trim((string) ($input['server1_router_id'] ?? ''));

$usernames = $input['usernames'] ?? [];
if (!is_array($usernames)) {
    $usernames = [];
}
$cleanUsers = [];
foreach ($usernames as $u) {
    $uname = trim((string) $u);
    if ($uname === '') {
        continue;
    }
    $cleanUsers[strtolower($uname)] = $uname;
}
$cleanUsers = array_values($cleanUsers);

if ($targetRouterId === '' || count($cleanUsers) === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'target_router_id dan usernames wajib diisi']);
    exit;
}

if (!isset($servers[$targetRouterId])) {
    http_response_code(404);
    echo json_encode(['error' => 'Server tujuan tidak ditemukan']);
    exit;
}

$targetRouter = $servers[$targetRouterId];
$server1Router = resolveServer1Router($servers, $server1RouterId);

$targetClient = getRouterClient($targetRouter, $clientCache, $clientErrors);
if ($targetClient === null) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Gagal konek ke server tujuan',
        'client_errors' => $clientErrors,
    ]);
    exit;
}

$orderedSourceRouters = buildSourceRouters($servers, $targetRouterId, $server1Router);

$results = [];
$moved = 0;
$failed = 0;
$removedFromServer1Count = 0;

foreach ($cleanUsers as $username) {
    $usernameKey = strtolower($username);
    try {
        $targetSecrets = getRouterSecrets($targetRouter, $clientCache, $clientErrors, $secretCache);
        $targetSecret = $targetSecrets[$usernameKey] ?? null;

        $sourceSecret = null;
        $sourceRouter = null;
        foreach ($orderedSourceRouters as $source) {
            $sourceSecrets = getRouterSecrets($source, $clientCache, $clientErrors, $secretCache);
            if (isset($sourceSecrets[$usernameKey])) {
                $sourceSecret = $sourceSecrets[$usernameKey];
                $sourceRouter = $source;
                break;
            }
        }

        if ($sourceSecret === null && $targetSecret === null) {
            $failed++;
            $results[] = [
                'username' => $username,
                'ok' => false,
                'message' => 'Secret tidak ditemukan di server manapun.',
            ];
            continue;
        }

        $template = is_array($sourceSecret) ? $sourceSecret : (is_array($targetSecret) ? $targetSecret : []);
        upsertTargetSecret($targetClient, $username, $template, $targetSecret);

        // Update cache target agar operasi user berikutnya konsisten.
        unset($secretCache[(string) ($targetRouter['id'] ?? '')]);

        $removedServer1 = false;
        if ($removeFromServer1 && is_array($server1Router)) {
            $server1Id = (string) ($server1Router['id'] ?? '');
            if ($server1Id !== '' && $server1Id !== $targetRouterId) {
                $server1Client = getRouterClient($server1Router, $clientCache, $clientErrors);
                if ($server1Client !== null) {
                    $rm = removeUserFromRouter($server1Client, $username);
                    $removedServer1 = $rm['removed'];
                    if ($removedServer1) {
                        $removedFromServer1Count++;
                    }
                    unset($secretCache[$server1Id]);
                }
            }
        }

        $moved++;
        $results[] = [
            'username' => $username,
            'ok' => true,
            'message' => 'Dipindahkan ke ' . ($targetRouter['name'] ?? $targetRouterId),
            'source_router' => $sourceRouter['name'] ?? '',
            'target_router' => $targetRouter['name'] ?? '',
            'removed_from_server1' => $removedServer1,
        ];
    } catch (\Throwable $e) {
        $failed++;
        $results[] = [
            'username' => $username,
            'ok' => false,
            'message' => $e->getMessage(),
        ];
    }
}

echo json_encode([
    'message' => 'Proses selesai',
    'moved' => $moved,
    'failed' => $failed,
    'removed_from_server1' => $removedFromServer1Count,
    'target_router' => [
        'id' => (string) ($targetRouter['id'] ?? ''),
        'name' => (string) ($targetRouter['name'] ?? ''),
    ],
    'server1_router' => is_array($server1Router) ? [
        'id' => (string) ($server1Router['id'] ?? ''),
        'name' => (string) ($server1Router['name'] ?? ''),
    ] : null,
    'results' => $results,
    'client_errors' => $clientErrors,
]);
exit;

/**
 * @param array<string,array<string,mixed>> $servers
 */
function resolveServer1Router(array $servers, string $preferredId): ?array
{
    if ($preferredId !== '' && isset($servers[$preferredId])) {
        return $servers[$preferredId];
    }
    foreach ($servers as $router) {
        $name = strtolower((string) ($router['name'] ?? ''));
        if (strpos($name, 'server 1') !== false || strpos($name, 'server1') !== false) {
            return $router;
        }
    }
    if (isset($servers['1'])) {
        return $servers['1'];
    }
    return null;
}

/**
 * @param array<string,array<string,mixed>> $servers
 * @return array<int,array<string,mixed>>
 */
function buildSourceRouters(array $servers, string $targetRouterId, ?array $server1Router): array
{
    $list = [];
    foreach ($servers as $id => $router) {
        if ((string) $id === $targetRouterId) {
            continue;
        }
        $list[] = $router;
    }
    usort($list, static function ($a, $b) use ($server1Router) {
        $server1Id = (string) ($server1Router['id'] ?? '');
        $aId = (string) ($a['id'] ?? '');
        $bId = (string) ($b['id'] ?? '');
        if ($server1Id !== '') {
            if ($aId === $server1Id && $bId !== $server1Id) {
                return -1;
            }
            if ($aId !== $server1Id && $bId === $server1Id) {
                return 1;
            }
        }
        return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });
    return $list;
}

/**
 * @param array<string,mixed> $router
 * @param array<string,Client|null> $clientCache
 * @param array<int,string> $clientErrors
 */
function getRouterClient(array $router, array &$clientCache, array &$clientErrors): ?Client
{
    $id = (string) ($router['id'] ?? ($router['host'] ?? ''));
    if (array_key_exists($id, $clientCache)) {
        return $clientCache[$id];
    }

    $host = trim((string) ($router['host'] ?? ''));
    $user = trim((string) ($router['username'] ?? ''));
    $pass = (string) ($router['password'] ?? '');

    if ($host === '' || $user === '' || $pass === '') {
        $clientErrors[] = 'Router ' . ((string) ($router['name'] ?? $id)) . ': kredensial tidak lengkap.';
        $clientCache[$id] = null;
        return null;
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
        $clientCache[$id] = $client;
        return $client;
    } catch (\Throwable $e) {
        $clientErrors[] = 'Router ' . ((string) ($router['name'] ?? $host)) . ': ' . $e->getMessage();
        $clientCache[$id] = null;
        return null;
    }
}

/**
 * @param array<string,mixed> $router
 * @param array<string,Client|null> $clientCache
 * @param array<int,string> $clientErrors
 * @param array<string,array<string,array<string,mixed>>> $secretCache
 * @return array<string,array<string,mixed>>
 */
function getRouterSecrets(array $router, array &$clientCache, array &$clientErrors, array &$secretCache): array
{
    $id = (string) ($router['id'] ?? ($router['host'] ?? ''));
    if (isset($secretCache[$id])) {
        return $secretCache[$id];
    }

    $client = getRouterClient($router, $clientCache, $clientErrors);
    if ($client === null) {
        $secretCache[$id] = [];
        return [];
    }

    $map = [];
    try {
        $rows = $client->query(new Query('/ppp/secret/print'))->read();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = trim((string) ($row['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $map[strtolower($name)] = $row;
            }
        }
    } catch (\Throwable $e) {
        $clientErrors[] = 'Secret load ' . ((string) ($router['name'] ?? $id)) . ': ' . $e->getMessage();
    }

    $secretCache[$id] = $map;
    return $map;
}

/**
 * @param array<string,mixed> $template
 * @param array<string,mixed>|null $existingTarget
 */
function upsertTargetSecret(Client $targetClient, string $username, array $template, ?array $existingTarget): void
{
    $profile = trim((string) ($template['profile'] ?? ''));
    $password = (string) ($template['password'] ?? '');
    $service = trim((string) ($template['service'] ?? ''));
    $comment = (string) ($template['comment'] ?? '');

    if (is_array($existingTarget) && isset($existingTarget['.id'])) {
        $set = (new Query('/ppp/secret/set'))
            ->equal('numbers', (string) $existingTarget['.id']);
        $hasUpdate = false;
        if ($profile !== '') {
            $set->equal('profile', $profile);
            $hasUpdate = true;
        }
        if ($password !== '') {
            $set->equal('password', $password);
            $hasUpdate = true;
        }
        if ($service !== '') {
            $set->equal('service', $service);
            $hasUpdate = true;
        }
        if ($comment !== '') {
            $set->equal('comment', $comment);
            $hasUpdate = true;
        }
        if (!$hasUpdate) {
            return;
        }
        $targetClient->query($set)->read();
        return;
    }

    $add = (new Query('/ppp/secret/add'))
        ->equal('name', $username)
        ->equal('profile', $profile !== '' ? $profile : 'default')
        ->equal('password', $password !== '' ? $password : $username)
        ->equal('service', $service !== '' ? $service : 'pppoe');
    if ($comment !== '') {
        $add->equal('comment', $comment);
    }
    $targetClient->query($add)->read();
}

/**
 * @return array{removed:bool,removed_active:int,removed_secret:int}
 */
function removeUserFromRouter(Client $client, string $username): array
{
    $removedActive = 0;
    $removedSecret = 0;

    $activeRows = $client->query(
        (new Query('/ppp/active/print'))->where('name', $username)
    )->read();
    if (is_array($activeRows)) {
        foreach ($activeRows as $row) {
            if (!is_array($row) || !isset($row['.id'])) {
                continue;
            }
            $client->query(
                (new Query('/ppp/active/remove'))->equal('numbers', (string) $row['.id'])
            )->read();
            $removedActive++;
        }
    }

    $secretRows = $client->query(
        (new Query('/ppp/secret/print'))->where('name', $username)
    )->read();
    if (is_array($secretRows)) {
        foreach ($secretRows as $row) {
            if (!is_array($row) || !isset($row['.id'])) {
                continue;
            }
            $client->query(
                (new Query('/ppp/secret/remove'))->equal('numbers', (string) $row['.id'])
            )->read();
            $removedSecret++;
        }
    }

    return [
        'removed' => ($removedActive + $removedSecret) > 0,
        'removed_active' => $removedActive,
        'removed_secret' => $removedSecret,
    ];
}
