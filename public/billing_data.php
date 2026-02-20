<?php
declare(strict_types=1);

require_once __DIR__ . '/app_timezone.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, OPTIONS');

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

$billingFile = __DIR__ . '/../storage/billing.json';
$priceFile = __DIR__ . '/../storage/pppoe_prices.json';
$targetMonth = date('Y-m');
if (isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month'])) {
    $targetMonth = $_GET['month'];
}
$historyFile = __DIR__ . '/../storage/billing_history.json';
$contactFile = __DIR__ . '/../storage/pppoe_contacts.json';

$billing = file_exists($billingFile) ? json_decode(file_get_contents($billingFile), true) : [];
if (!is_array($billing)) $billing = [];
$priceMap = file_exists($priceFile) ? json_decode(file_get_contents($priceFile), true) : [];
if (!is_array($priceMap)) $priceMap = [];
$historyGrouped = billingHistoryLoadGrouped($historyFile);
$history = billingHistoryFlattenGrouped($historyGrouped);
$contacts = file_exists($contactFile) ? json_decode(file_get_contents($contactFile), true) : [];
if (!is_array($contacts)) $contacts = [];
$contactMap = [];
foreach ($contacts as $row) {
    if (!is_array($row)) continue;
    $u = trim((string) ($row['username'] ?? ''));
    $p = trim((string) ($row['profile'] ?? ''));
    $r = trim((string) ($row['router_id'] ?? ''));
    $wa = trim((string) ($row['wa'] ?? ''));
    if ($u === '' || $p === '' || $r === '' || $wa === '') continue;
    $contactMap[$u . '|' . $p . '|' . $r] = $wa;
}
$locationsSet = [];
$allowedLocations = getAllowedLocations($currentUser);
$applyLocFilter = count($allowedLocations) > 0;
$isAdmin = isAdmin($currentUser);
$currentName = strtolower(trim((string) ($currentUser['name'] ?? '')));
$currentEmail = strtolower(trim((string) ($currentUser['email'] ?? '')));

// Ambil data PPPoE dari endpoint yang sudah ada
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
// fallback: eksekusi langsung via PHP CLI
if ($pppoeData === null || !is_array($pppoeData)) {
    $pppoeJson = @shell_exec('php ' . escapeshellarg(__DIR__ . '/pppoe_data.php'));
    if ($pppoeJson !== null) {
        $pppoeData = json_decode($pppoeJson, true);
    }
}
if (!is_array($pppoeData)) {
    http_response_code(500);
    echo json_encode(['error' => 'Gagal mengambil data PPPoE dari endpoint ' . ($url ?: '')]);
    exit;
}

$allUsers = [];
foreach (($pppoeData['active'] ?? []) as $row) {
    $uname = $row['username'] ?? '';
    if ($uname === '') continue;
    $loc = deriveLocation($uname);
    if ($applyLocFilter && ($loc === '' || !isset($allowedLocations[$loc]))) {
        continue;
    }
    $allUsers[$uname] = [
        'username' => $uname,
        'profile' => $row['profile'] ?? '',
        'router_id' => $row['router_id'] ?? '',
        'router_name' => $row['router_name'] ?? '',
        'location' => $loc,
    ];
    if ($loc !== '') $locationsSet[$loc] = true;
}
foreach (($pppoeData['inactive_users'] ?? []) as $row) {
    $uname = $row['username'] ?? '';
    if ($uname === '') continue;
    if (!isset($allUsers[$uname])) {
        $loc = deriveLocation($uname);
        if ($applyLocFilter && ($loc === '' || !isset($allowedLocations[$loc]))) {
            continue;
        }
        $allUsers[$uname] = [
            'username' => $uname,
            'profile' => $row['profile'] ?? '',
            'router_id' => $row['router_id'] ?? '',
            'router_name' => $row['router_name'] ?? '',
            'location' => $loc,
        ];
        if ($loc !== '') $locationsSet[$loc] = true;
    }
}

$paid = [];
$unpaid = [];
$totalThisMonth = 0;
$totalUnpaidAmount = 0;
$paidByTotals = [];

// indeks riwayat pembayaran per user+profile+router per bulan
$historyMap = [];
foreach ($history as $h) {
    $month = isset($h['month']) ? (string) $h['month'] : '';
    if ($month === '') continue;
    $key = ($h['username'] ?? '') . '|' . ($h['profile'] ?? '') . '|' . ($h['router_id'] ?? '');
    if (!isset($historyMap[$key])) $historyMap[$key] = [];
    $historyMap[$key][$month] = $h;
}
foreach ($allUsers as $uname => $info) {
    $profile = $info['profile'] ?? '';
    $rid = $info['router_id'] ?? '';
    $price = 0;
    if (isset($priceMap[$rid][$profile])) {
        $price = (float) $priceMap[$rid][$profile];
    }

    // merge billing record jika ada
    $record = null;
    foreach ($billing as $row) {
        if (($row['username'] ?? '') === $uname && ($row['profile'] ?? '') === $profile && (string)($row['router_id'] ?? '') === (string)$rid) {
            $record = $row;
            break;
        }
    }
    $status = strtolower((string) ($record['status'] ?? 'unpaid'));
    $monthsDue = (int) ($record['months_due'] ?? 1);
    $lastPayment = $record['last_payment'] ?? '';
    $lastPaidMonths = (int) ($record['last_paid_months'] ?? 0);
    if ($price === 0 && isset($record['price'])) {
        $price = (float) $record['price'];
    }

    $key = $uname . '|' . $profile . '|' . $rid;
    $wa = $contactMap[$key] ?? '';
    $historyHit = $historyMap[$key][$targetMonth] ?? null;
    // jika status billing terakhir adalah unpaid, abaikan riwayat paid untuk bulan ini
    if ($status === 'unpaid') {
        $historyHit = null;
        $monthsDue = $monthsDue > 0 ? $monthsDue : 1;
    }

    if ($historyHit) {
        $priceH = (float) ($historyHit['price'] ?? $price);
        $monthsH = (int) ($historyHit['months_paid'] ?? ($historyHit['last_paid_months'] ?? 1));
        $paid[] = [
            'username' => $uname,
            'profile' => $profile,
            'router_id' => $rid,
            'router_name' => $info['router_name'] ?? '',
            'location' => $info['location'] ?? '',
            'wa' => $wa,
            'price' => $priceH,
            'last_payment' => $historyHit['paid_at'] ?? ($historyHit['month'] ?? ''),
            'paid_month' => $historyHit['month'] ?? '',
            'last_paid_months' => $monthsH,
            'paid_by' => $historyHit['paid_by'] ?? '',
        ];
    } else {
        // hitung label bulan tunggakan
        $monthsList = [];
        $start = strtotime($targetMonth . '-01');
        for ($i = 0; $i < max(1, $monthsDue); $i++) {
            $ts = strtotime('-' . $i . ' month', $start);
            $monthsList[] = date('F Y', $ts);
        }
        $monthsList = array_reverse($monthsList);
        $unpaid[] = [
            'username' => $uname,
            'profile' => $profile,
            'router_id' => $rid,
            'router_name' => $info['router_name'] ?? '',
            'location' => $info['location'] ?? '',
            'wa' => $wa,
            'price' => $price,
            'months_due' => max(1, $monthsDue),
            'month_names' => implode(', ', $monthsList),
            'months_due_raw' => max(1, $monthsDue),
        ];
    }
}

// Buang entri dengan tagihan 0
$unpaid = array_values(array_filter($unpaid, function($row){
    $price = (float)($row['price'] ?? 0);
    $months = (int)($row['months_due'] ?? 0);
    return ($price * max(1, $months)) > 0;
}));
$totalUnpaidAmount = 0;
foreach ($unpaid as $row) {
    $price = (float)($row['price'] ?? 0);
    $months = (int)($row['months_due'] ?? 0);
    $totalUnpaidAmount += $price * max(1, $months);
}
$paid = array_values(array_filter($paid, function($row){
    $price = (float)($row['price'] ?? 0);
    $months = (int)($row['last_paid_months'] ?? 0);
    // paid dengan harga 0 & bulan 0 tidak berarti apa-apa
    return ($price * max(1, $months)) > 0;
}));

// Jika ada status unpaid untuk user yang sama di bulan ini, jangan tampilkan di paid
$unpaidKeys = [];
foreach ($unpaid as $u) {
    $unpaidKeys[$u['username'] . '|' . $u['profile'] . '|' . $u['router_id']] = true;
}
$paid = array_values(array_filter($paid, function($p) use ($unpaidKeys){
    $key = $p['username'] . '|' . $p['profile'] . '|' . $p['router_id'];
    return !isset($unpaidKeys[$key]);
}));

// Samakan sumber hitung: total bulan ini dan total admin bayar dihitung dari daftar paid final
$totalThisMonth = 0;
$paidByTotals = [];
foreach ($paid as $row) {
    $price = (float)($row['price'] ?? 0);
    $months = (int)($row['last_paid_months'] ?? 1);
    $amount = $price * max(1, $months);
    $totalThisMonth += $amount;

    $name = (string) ($row['paid_by'] ?? 'Unknown');
    $nameKey = strtolower(trim($name));
    if (!$isAdmin) {
        if ($nameKey === '') continue;
        if ($currentName === '' && $currentEmail === '') continue;
        if ($nameKey !== $currentName && $nameKey !== $currentEmail) continue;
    }
    if (!isset($paidByTotals[$name])) $paidByTotals[$name] = 0;
    $paidByTotals[$name] += $amount;
}

echo json_encode([
    'paid' => $paid,
    'unpaid' => $unpaid,
    'total_this_month' => $totalThisMonth,
    'total_unpaid_amount' => $totalUnpaidAmount,
    'locations' => array_values(array_keys($locationsSet)),
    'paid_by_totals' => buildPaidByTotals($paidByTotals),
    'is_admin' => $isAdmin,
]);

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

function getAllowedLocations(array $user): array
{
    if (isAdmin($user)) return [];
    $perms = $user['permissions'] ?? [];
    $allowed = [];
    foreach ($perms as $perm) {
        $perm = trim((string) $perm);
        if ($perm === '') continue;
        if (strpos($perm, ':') === false) continue;
        [$prefix, $loc] = array_map('trim', explode(':', $perm, 2));
        $prefix = strtolower($prefix);
        if (!in_array($prefix, ['billing', 'billing_location', 'location'], true)) {
            continue;
        }
        $loc = strtoupper($loc);
        if ($loc !== '') $allowed[$loc] = true;
    }
    return $allowed;
}

function deriveLocation(string $username): string
{
    $pos = strrpos($username, '@');
    if ($pos === false) return '';
    $loc = substr($username, $pos + 1);
    return strtoupper(trim($loc));
}

function buildPaidByTotals(array $map): array
{
    arsort($map);
    $out = [];
    foreach ($map as $name => $total) {
        $out[] = ['name' => $name, 'total' => $total];
    }
    return $out;
}
