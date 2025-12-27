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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$username = trim((string) ($input['username'] ?? ''));
$profile = trim((string) ($input['profile'] ?? ''));
$routerId = trim((string) ($input['router_id'] ?? ''));
$waRaw = trim((string) ($input['wa'] ?? ''));

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

$waNormalized = normalizeWa($waRaw);
$contactFile = __DIR__ . '/../storage/pppoe_contacts.json';
$contacts = file_exists($contactFile) ? json_decode(file_get_contents($contactFile), true) : [];
if (!is_array($contacts)) $contacts = [];

$contacts = array_values(array_filter($contacts, function ($row) use ($username, $profile, $routerId) {
    return !(
        ($row['username'] ?? '') === $username &&
        ($row['profile'] ?? '') === $profile &&
        (string) ($row['router_id'] ?? '') === (string) $routerId
    );
}));

if ($waNormalized !== '') {
    $contacts[] = [
        'username' => $username,
        'profile' => $profile,
        'router_id' => $routerId,
        'wa' => $waNormalized,
        'updated_at' => date('Y-m-d H:i:s'),
        'updated_by' => (string) ($currentUser['name'] ?? ($currentUser['email'] ?? '')),
    ];
}

if (false === file_put_contents($contactFile, json_encode($contacts, JSON_PRETTY_PRINT))) {
    http_response_code(500);
    echo json_encode(['error' => 'Gagal menyimpan kontak WA']);
    exit;
}

echo json_encode([
    'message' => $waNormalized !== '' ? 'Kontak WA tersimpan' : 'Kontak WA dihapus',
    'wa' => $waNormalized
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

function normalizeWa(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value);
    if ($digits === null) $digits = '';
    if ($digits === '') return '';
    if (strpos($digits, '0') === 0) {
        return '62' . substr($digits, 1);
    }
    if (strpos($digits, '8') === 0) {
        return '62' . $digits;
    }
    return $digits;
}
