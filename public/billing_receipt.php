<?php
declare(strict_types=1);

require_once __DIR__ . '/app_timezone.php';
require_once __DIR__ . '/billing_history_helpers.php';

session_start();
$currentUser = $_SESSION['user'] ?? null;
if (!$currentUser || !is_array($currentUser)) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}
$currentUser = normalizeUser($currentUser);

$username = trim((string) ($_GET['username'] ?? ''));
$profile = trim((string) ($_GET['profile'] ?? ''));
$routerId = trim((string) ($_GET['router_id'] ?? ''));
$month = '';
if (isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', (string) $_GET['month'])) {
    $month = (string) $_GET['month'];
}

if ($username === '' || $profile === '' || $routerId === '') {
    http_response_code(400);
    echo 'Parameter tidak lengkap.';
    exit;
}
if (!hasLocationAccess($currentUser, $username)) {
    http_response_code(403);
    echo 'Akses ditolak.';
    exit;
}

$historyFile = __DIR__ . '/../storage/billing_history.json';
$historyGrouped = billingHistoryLoadGrouped($historyFile);

$record = null;
$recordMonth = '';
if ($month !== '' && isset($historyGrouped[$month]) && is_array($historyGrouped[$month])) {
    foreach ($historyGrouped[$month] as $row) {
        if (($row['username'] ?? '') === $username &&
            ($row['profile'] ?? '') === $profile &&
            (string) ($row['router_id'] ?? '') === (string) $routerId
        ) {
            $record = $row;
            $recordMonth = $month;
            break;
        }
    }
}

if (!$record) {
    $latestTs = 0;
    foreach ($historyGrouped as $mKey => $items) {
        if (!is_array($items)) continue;
        foreach ($items as $row) {
            if (($row['username'] ?? '') === $username &&
                ($row['profile'] ?? '') === $profile &&
                (string) ($row['router_id'] ?? '') === (string) $routerId
            ) {
                $ts = 0;
                if (!empty($row['paid_at'])) {
                    $ts = strtotime((string) $row['paid_at']) ?: 0;
                } elseif (!empty($row['month'])) {
                    $ts = strtotime((string) $row['month'] . '-01') ?: 0;
                }
                if ($ts >= $latestTs) {
                    $latestTs = $ts;
                    $record = $row;
                    $recordMonth = (string) ($row['month'] ?? $mKey);
                }
            }
        }
    }
}

$routerFile = __DIR__ . '/../storage/mikrotik.json';
$routerData = file_exists($routerFile) ? json_decode(file_get_contents($routerFile), true) : [];
if (!is_array($routerData)) $routerData = [];
$routerMap = [];
foreach ($routerData as $r) {
    if (!empty($r['id'])) {
        $routerMap[(string) $r['id']] = (string) ($r['name'] ?? ('Router #' . $r['id']));
    }
}
$routerName = $routerMap[$routerId] ?? ('Router #' . $routerId);

$paidBy = $record['paid_by'] ?? '-';
$paidAt = $record['paid_at'] ?? '';
$monthLabel = formatMonthId($recordMonth !== '' ? $recordMonth : $month);
$monthsPaid = (int) ($record['months_paid'] ?? 1);
$monthsPaid = max(1, $monthsPaid);
$price = (float) ($record['price'] ?? 0);
$total = $price * $monthsPaid;
$periodLabel = buildPaidPeriod($recordMonth !== '' ? $recordMonth : $month, $monthsPaid);
$invoiceId = 'INV-' . date('Ymd-His');
$printedAt = date('Y-m-d H:i:s');
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nota Pembayaran</title>
    <style>
        :root {
            --border: #d1d5db;
            --text: #111827;
            --muted: #6b7280;
        }
        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, Geneva, sans-serif;
            background: #f5f7fb;
            color: var(--text);
        }
        .wrap {
            max-width: 720px;
            margin: 0 auto;
            padding: 24px 16px 40px;
        }
        .receipt {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 20px;
            box-shadow: 0 12px 30px rgba(0,0,0,0.08);
            position: relative;
        }
        .receipt h1 {
            margin: 0;
            font-size: 1.4rem;
        }
        .meta {
            color: var(--muted);
            font-size: 0.9rem;
            margin-top: 4px;
        }
        .divider {
            height: 1px;
            background: var(--border);
            margin: 16px 0;
        }
        .row {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            padding: 6px 0;
        }
        .row span {
            color: var(--muted);
        }
        .items {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        .items th,
        .items td {
            text-align: left;
            padding: 8px 6px;
            border-bottom: 1px solid var(--border);
        }
        .items th {
            color: var(--muted);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        .total {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 12px;
            font-size: 1.05rem;
            font-weight: 700;
        }
        .footer {
            margin-top: 16px;
            color: var(--muted);
            font-size: 0.9rem;
        }
        .actions {
            margin-top: 16px;
            display: flex;
            gap: 8px;
        }
        .stamp {
            position: absolute;
            right: 20px;
            bottom: 18px;
            width: 170px;
            height: 170px;
            border: 3px solid #16a34a;
            color: #166534;
            border-radius: 999px;
            transform: rotate(-8deg);
            background: rgba(22, 163, 74, 0.08);
            box-shadow: 0 6px 14px rgba(22, 163, 74, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
        }
        .stamp::before {
            content: "";
            position: absolute;
            inset: 26px;
            border: 2px solid #16a34a;
            border-radius: 999px;
            opacity: 0.9;
        }
        .stamp-title {
            font-size: 1.1rem;
            font-weight: 900;
            letter-spacing: 0.45em;
            text-transform: uppercase;
            padding-left: 0.45em;
        }
        .stamp-ring {
            position: absolute;
            inset: 6px;
            pointer-events: none;
        }
        .stamp-ring text {
            font-size: 12px;
            letter-spacing: 0.2em;
            font-weight: 900;
        }
        button {
            cursor: pointer;
            border: none;
            background: #1f4b99;
            color: #fff;
            padding: 10px 14px;
            border-radius: 10px;
            font-weight: 700;
        }
        button.ghost {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text);
        }
        @media print {
            body { background: #fff; }
            .wrap { padding: 0; }
        .receipt { box-shadow: none; border: none; }
        .actions { display: none; }
        .stamp { opacity: 0.7; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="receipt">
            <?php if ($record): ?>
                <div class="stamp">
                    <svg class="stamp-ring" viewBox="0 0 120 120" aria-hidden="true">
                        <defs>
                            <path id="stamp-circle" d="M60,10 a50,50 0 1,1 0,100 a50,50 0 1,1 0,-100" />
                        </defs>
                        <text fill="#166534">
                            <textPath href="#stamp-circle" startOffset="50%" text-anchor="middle">
                                RND NET • RND NET • RND NET • RND NET •
                            </textPath>
                        </text>
                    </svg>
                    <div class="stamp-title">Lunas</div>
                </div>
            <?php endif; ?>
            <h1>Nota Pembayaran</h1>
            <div class="meta">No: <?php echo htmlspecialchars($invoiceId); ?> | Dicetak: <?php echo htmlspecialchars($printedAt); ?></div>
            <div class="divider"></div>
            <?php if (!$record): ?>
                <div>Data pembayaran tidak ditemukan.</div>
            <?php else: ?>
                <div class="row"><span>Nama User</span><strong><?php echo htmlspecialchars($username); ?></strong></div>
                <div class="row"><span>Profile</span><strong><?php echo htmlspecialchars($profile); ?></strong></div>
                <div class="row"><span>Router</span><strong><?php echo htmlspecialchars($routerName); ?></strong></div>
                <div class="row"><span>Periode</span><strong><?php echo htmlspecialchars($periodLabel); ?></strong></div>
                <div class="row"><span>Bulan Dibayar</span><strong><?php echo htmlspecialchars($monthLabel); ?></strong></div>
                <div class="row"><span>Admin Bayar</span><strong><?php echo htmlspecialchars((string) $paidBy); ?></strong></div>
                <div class="row"><span>Waktu Bayar</span><strong><?php echo htmlspecialchars((string) $paidAt); ?></strong></div>
                <table class="items">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Harga</th>
                            <th>Bulan</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>PPPoE <?php echo htmlspecialchars($profile); ?></td>
                            <td><?php echo htmlspecialchars(formatRupiah($price)); ?></td>
                            <td><?php echo htmlspecialchars((string) $monthsPaid); ?></td>
                            <td><?php echo htmlspecialchars(formatRupiah($total)); ?></td>
                        </tr>
                    </tbody>
                </table>
                <div class="total">
                    <span>Total Pembayaran</span>
                    <span><?php echo htmlspecialchars(formatRupiah($total)); ?></span>
                </div>
                <div class="footer">Terima kasih atas pembayarannya.</div>
            <?php endif; ?>
        </div>
        <div class="actions">
            <button type="button" onclick="window.print()">Cetak</button>
            <button type="button" class="ghost" onclick="window.close()">Tutup</button>
        </div>
    </div>
    <script>
        window.addEventListener('load', function(){
            setTimeout(function(){ window.print(); }, 300);
        });
    </script>
</body>
</html>
<?php
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

function formatRupiah(float $value): string
{
    return 'Rp ' . number_format($value, 0, ',', '.');
}

function formatMonthId(string $ym): string
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) return $ym;
    $year = $m[1];
    $month = (int) $m[2];
    $names = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    $label = $names[$month] ?? $m[2];
    return $label . ' ' . $year;
}

function buildPaidPeriod(string $month, int $monthsPaid): string
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', $month, $m)) {
        return $month;
    }
    $monthsPaid = max(1, $monthsPaid);
    $startTs = strtotime($month . '-01');
    if ($startTs === false) return $month;
    $startTs = strtotime('-' . ($monthsPaid - 1) . ' month', $startTs);
    $startLabel = formatMonthId(date('Y-m', $startTs));
    $endLabel = formatMonthId($month);
    if ($startLabel === $endLabel) return $startLabel;
    return $startLabel . ' - ' . $endLabel;
}
?>
