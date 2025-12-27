<?php
declare(strict_types=1);

require_once __DIR__ . '/app_timezone.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

session_start();
$currentUser = $_SESSION['user'] ?? null;
if (!$currentUser || !is_array($currentUser)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
$currentUser = normalizeUser($currentUser);

require_once __DIR__ . '/billing_history_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = isset($input['action']) ? strtolower(trim((string) $input['action'])) : '';

$billingFile = __DIR__ . '/../storage/billing.json';
$priceFile = __DIR__ . '/../storage/pppoe_prices.json';
$historyFile = __DIR__ . '/../storage/billing_history.json';
$data = file_exists($billingFile) ? json_decode(file_get_contents($billingFile), true) : [];
if (!is_array($data)) $data = [];
$priceMap = file_exists($priceFile) ? json_decode(file_get_contents($priceFile), true) : [];
if (!is_array($priceMap)) $priceMap = [];
$historyGrouped = billingHistoryLoadGrouped($historyFile);

if ($action === 'clear_month') {
    if (!isAdmin($currentUser)) {
        http_response_code(403);
        echo json_encode(['error' => 'Hanya admin yang bisa menghapus pembayaran bulan']);
        exit;
    }
    $monthField = isset($input['month']) && preg_match('/^\d{4}-\d{2}$/', $input['month']) ? $input['month'] : '';
    if ($monthField === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Bulan tidak valid']);
        exit;
    }
    $removed = 0;
    if (isset($historyGrouped[$monthField]) && is_array($historyGrouped[$monthField])) {
        $removed = count($historyGrouped[$monthField]);
        unset($historyGrouped[$monthField]);
        billingHistorySaveGrouped($historyFile, $historyGrouped);
    }
    echo json_encode([
        'message' => 'Pembayaran bulan ' . $monthField . ' dihapus',
        'removed' => $removed
    ]);
    exit;
}

if ($action === 'pay') {
    $username = trim((string) ($input['username'] ?? ''));
    $profile = trim((string) ($input['profile'] ?? ''));
    $routerId = trim((string) ($input['router_id'] ?? ''));
    $months = (int) ($input['months'] ?? 0);
    $monthField = isset($input['month']) && preg_match('/^\d{4}-\d{2}$/', $input['month']) ? $input['month'] : date('Y-m');
    $overrideAmount = isset($input['amount']) && $input['amount'] !== null ? (float)$input['amount'] : null;
    $backupMode = isset($input['backup_mode']) ? strtolower(trim((string) $input['backup_mode'])) : 'new';
    $monthNum = substr($monthField, 5, 2);
    $yearNum = substr($monthField, 0, 4);
    if ($username === '' || $profile === '' || $routerId === '' || $months < 1) {
        http_response_code(400);
        echo json_encode(['error' => 'username, profile, router_id, months wajib diisi']);
        exit;
    }
    if (!hasLocationAccess($currentUser, $username)) {
        http_response_code(403);
        echo json_encode(['error' => 'Tidak ada izin lokasi untuk user ini']);
        exit;
    }

    $found = false;
    $priceUsed = 0;
    if (isset($priceMap[$routerId][$profile])) {
        $priceUsed = (float) $priceMap[$routerId][$profile];
    }

    foreach ($data as &$row) {
        if (trim((string) ($row['username'] ?? '')) === $username &&
            trim((string) ($row['profile'] ?? '')) === $profile &&
            trim((string) ($row['router_id'] ?? '')) === $routerId) {
            $found = true;
            if (!$priceUsed && isset($row['price'])) {
                $priceUsed = (float) $row['price'];
            }
            $row['price'] = $priceUsed;
            $row['months_due'] = max(0, (int) ($row['months_due'] ?? 0) - $months);
            $row['status'] = ($row['months_due'] ?? 0) <= 0 ? 'paid' : 'unpaid';
            $row['last_payment'] = $monthField . '-01';
            $row['last_payment_month'] = $monthNum;
            $row['last_payment_year'] = $yearNum;
            $row['last_paid_months'] = $months;
            break;
        }
    }
    unset($row);

    if (!$found) {
        if (!$priceUsed && isset($priceMap[$routerId][$profile])) {
            $priceUsed = (float) $priceMap[$routerId][$profile];
        }
        $data[] = [
            'username' => $username,
            'profile' => $profile,
            'router_id' => $routerId,
            'months_due' => 0,
            'status' => 'paid',
            'last_payment' => $monthField . '-01',
            'last_payment_month' => $monthNum,
            'last_payment_year' => $yearNum,
            'last_paid_months' => $months,
            'price' => $priceUsed,
        ];
    }

    if (false === file_put_contents($billingFile, json_encode($data, JSON_PRETTY_PRINT))) {
        http_response_code(500);
        echo json_encode(['error' => 'Gagal menyimpan data billing']);
        exit;
    }

    // catat riwayat per bulan agar tidak hilang ketika bulan berikutnya dibayar
    // hapus history lama untuk user+profile+router+bulan yang sama (agar tidak dobel)
    $monthList = isset($historyGrouped[$monthField]) && is_array($historyGrouped[$monthField]) ? $historyGrouped[$monthField] : [];
    $monthList = array_values(array_filter($monthList, function ($row) use ($username, $profile, $routerId) {
        return !(
            ($row['username'] ?? '') === $username &&
            ($row['profile'] ?? '') === $profile &&
            (string) ($row['router_id'] ?? '') === (string) $routerId
        );
    }));
    $monthList[] = [
        'username' => $username,
        'profile' => $profile,
        'router_id' => $routerId,
        'month' => $monthField,
        'payment_month' => $monthNum,
        'payment_year' => $yearNum,
        'months_paid' => $months,
        'price' => ($overrideAmount !== null ? $overrideAmount : $priceUsed),
        'paid_by' => (string) ($currentUser['name'] ?? ($currentUser['email'] ?? 'Unknown')),
        'paid_by_id' => $currentUser['id'] ?? null,
        'paid_at' => date('Y-m-d H:i:s'),
    ];
    $historyGrouped[$monthField] = $monthList;
    billingHistorySaveGrouped($historyFile, $historyGrouped);

    $backupResult = savePaymentBackup($data, $historyGrouped, [
        'username' => $username,
        'profile' => $profile,
        'router_id' => $routerId,
        'month' => $monthField,
        'months' => $months,
        'amount' => ($overrideAmount !== null ? $overrideAmount : $priceUsed),
        'paid_by' => (string) ($currentUser['name'] ?? ($currentUser['email'] ?? 'Unknown')),
        'paid_by_id' => $currentUser['id'] ?? null,
    ], $backupMode);

    echo json_encode([
        'message' => 'Pembayaran tercatat',
        'data' => $data,
        'backup' => $backupResult,
    ]);
    exit;
}

if ($action === 'unpay') {
    $username = trim((string) ($input['username'] ?? ''));
    $profile = trim((string) ($input['profile'] ?? ''));
    $routerId = trim((string) ($input['router_id'] ?? ''));
    $months = (int) ($input['months'] ?? 1);
    $monthField = isset($input['month']) && preg_match('/^\d{4}-\d{2}$/', $input['month']) ? $input['month'] : date('Y-m');
    if ($username === '' || $profile === '' || $routerId === '') {
        http_response_code(400);
        echo json_encode(['error' => 'username, profile, router_id wajib diisi']);
        exit;
    }
    if (!hasLocationAccess($currentUser, $username)) {
        http_response_code(403);
        echo json_encode(['error' => 'Tidak ada izin lokasi untuk user ini']);
        exit;
    }
    $months = $months < 1 ? 1 : $months;
    $priceUsed = 0;
    if (isset($priceMap[$routerId][$profile])) {
        $priceUsed = (float) $priceMap[$routerId][$profile];
    }
    $found = false;
    foreach ($data as &$row) {
        if (trim((string) ($row['username'] ?? '')) === $username &&
            trim((string) ($row['profile'] ?? '')) === $profile &&
            trim((string) ($row['router_id'] ?? '')) === $routerId) {
            $found = true;
            if (!$priceUsed && isset($row['price'])) $priceUsed = (float)$row['price'];
            $row['price'] = $priceUsed;
            $row['months_due'] = $months;
            $row['status'] = 'unpaid';
            break;
        }
    }
    unset($row);
    if (!$found) {
        $data[] = [
            'username' => $username,
            'profile' => $profile,
            'router_id' => $routerId,
            'months_due' => $months,
            'status' => 'unpaid',
            'price' => $priceUsed,
            'last_payment' => '',
            'last_paid_months' => 0,
        ];
    }
    file_put_contents($billingFile, json_encode($data, JSON_PRETTY_PRINT));

    // hapus riwayat pembayaran bulan yang dibatalkan
    if (isset($historyGrouped[$monthField]) && is_array($historyGrouped[$monthField])) {
        $monthList = array_values(array_filter($historyGrouped[$monthField], function ($row) use ($username, $profile, $routerId) {
            return !(
                ($row['username'] ?? '') === $username &&
                ($row['profile'] ?? '') === $profile &&
                (string) ($row['router_id'] ?? '') === (string) $routerId
            );
        }));
        if (count($monthList) > 0) {
            $historyGrouped[$monthField] = $monthList;
        } else {
            unset($historyGrouped[$monthField]);
        }
        billingHistorySaveGrouped($historyFile, $historyGrouped);
    }
    echo json_encode(['message' => 'Status diubah menjadi belum bayar', 'data' => $data]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Aksi tidak dikenal']);

function normalizeUser(array $user): array
{
    $perms = $user['permissions'] ?? [];
    if (is_string($perms)) {
        $perms = array_values(array_filter(array_map('trim', explode(',', $perms))));
    }
    if (!is_array($perms)) {
        $perms = [];
    }
    $user['permissions'] = $perms;
    $user['role'] = $user['role'] ?? '';
    return $user;
}

function isAdmin(array $user): bool
{
    $role = strtolower((string) ($user['role'] ?? ''));
    if ($role === 'admin') return true;
    $perms = $user['permissions'] ?? [];
    return in_array('*', $perms, true) || in_array('all', $perms, true);
}

function savePaymentBackup(array $billingData, array $historyGrouped, array $event, string $mode = 'new'): array
{
    $baseDir = __DIR__ . '/../storage/payment_backups';
    if (!is_dir($baseDir)) {
        if (!mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
            return ['ok' => false, 'error' => 'Gagal membuat folder backup'];
        }
    }
    $mode = strtolower(trim($mode));
    if (!in_array($mode, ['new', 'replace'], true)) {
        $mode = 'new';
    }
    $stamp = date('Ymd_His');
    $filename = $mode === 'replace' ? 'payment_backup_latest.json' : ('payment_backup_' . $stamp . '.json');
    $path = $baseDir . '/' . $filename;
    $payload = [
        'version' => 1,
        'created_at' => date('c'),
        'mode' => $mode,
        'event' => $event,
        'files' => [
            'billing.json' => $billingData,
            'billing_history.json' => $historyGrouped,
        ],
    ];
    $ok = file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    if ($ok === false) {
        return ['ok' => false, 'error' => 'Gagal menyimpan backup'];
    }
    return ['ok' => true, 'file' => $filename];
}

function deriveLocation(string $username): string
{
    $pos = strrpos($username, '@');
    if ($pos === false) return '';
    $loc = substr($username, $pos + 1);
    return strtoupper(trim($loc));
}

function hasLocationAccess(array $user, string $username): bool
{
    if (isAdmin($user)) return true;
    $perms = $user['permissions'] ?? [];
    $allowed = [];
    foreach ($perms as $perm) {
        $perm = trim((string) $perm);
        if ($perm === '' || strpos($perm, ':') === false) continue;
        [$prefix, $loc] = array_map('trim', explode(':', $perm, 2));
        $prefix = strtolower($prefix);
        if (!in_array($prefix, ['billing', 'billing_location', 'location'], true)) {
            continue;
        }
        $loc = strtoupper($loc);
        if ($loc !== '') $allowed[$loc] = true;
    }
    if (!$allowed) return true;
    $userLoc = deriveLocation($username);
    return $userLoc !== '' && isset($allowed[$userLoc]);
}
