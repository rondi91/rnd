<?php
declare(strict_types=1);

require_once __DIR__ . '/app_timezone.php';

header('Access-Control-Allow-Origin: *');

session_start();
$currentUser = $_SESSION['user'] ?? null;
if (!$currentUser || !is_array($currentUser)) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}
$currentUser = normalizeUser($currentUser);
if (!hasBillingAccess($currentUser)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

require_once __DIR__ . '/billing_history_helpers.php';

$format = strtolower((string) ($_GET['format'] ?? 'csv'));
if (!in_array($format, ['csv', 'excel', 'pdf'], true)) {
    $format = 'csv';
}

$month = date('Y-m');
if (isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', (string) $_GET['month'])) {
    $month = (string) $_GET['month'];
}

$historyFile = __DIR__ . '/../storage/billing_history.json';
$routersFile = __DIR__ . '/../storage/mikrotik.json';
$historyGrouped = billingHistoryLoadGrouped($historyFile);
$routers = loadJsonArray($routersFile);
$routerMap = [];
foreach ($routers as $router) {
    if (!empty($router['id'])) {
        $routerMap[(string) $router['id']] = (string) ($router['name'] ?? ('Router #' . $router['id']));
    }
}

$allowedLocations = getAllowedLocations($currentUser);
$applyLocation = count($allowedLocations) > 0;

$rows = [];
$sumPrice = 0;
$sumTotal = 0;
$sumMonths = 0;
$adminTotals = [];
$monthRows = isset($historyGrouped[$month]) && is_array($historyGrouped[$month]) ? $historyGrouped[$month] : [];
foreach ($monthRows as $row) {
    if (!is_array($row)) continue;
    $rowMonth = billingHistoryResolveMonth($row, $month);
    $username = trim((string) ($row['username'] ?? ''));
    if ($username === '') continue;
    $location = deriveLocation($username);
    if ($applyLocation && ($location === '' || !isset($allowedLocations[$location]))) {
        continue;
    }
    $profile = (string) ($row['profile'] ?? '');
    $routerId = (string) ($row['router_id'] ?? '');
    $routerName = $routerMap[$routerId] ?? ($routerId !== '' ? 'Router #' . $routerId : '-');
    $monthsPaid = (int) ($row['months_paid'] ?? 1);
    $price = (float) ($row['price'] ?? 0);
    $total = $price * max(1, $monthsPaid);
    $sumPrice += $price;
    $sumTotal += $total;
    $sumMonths += $monthsPaid;
    $rows[] = [
        'username' => $username,
        'profile' => $profile,
        'router_name' => $routerName,
        'router_id' => $routerId,
        'location' => $location,
        'month' => $rowMonth,
        'months_paid' => $monthsPaid,
        'price' => $price,
        'total' => $total,
        'paid_by' => (string) ($row['paid_by'] ?? ''),
        'paid_at' => (string) ($row['paid_at'] ?? ''),
    ];
    $paidBy = (string) ($row['paid_by'] ?? 'Unknown');
    if (!isset($adminTotals[$paidBy])) {
        $adminTotals[$paidBy] = ['count' => 0, 'total' => 0];
    }
    $adminTotals[$paidBy]['count'] += 1;
    $adminTotals[$paidBy]['total'] += $total;
}

usort($rows, function ($a, $b) {
    return strcmp((string) $a['paid_at'], (string) $b['paid_at']);
});

if ($format === 'csv') {
    $filename = 'billing_' . $month . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['No', 'Username', 'Profile', 'Router', 'Lokasi', 'Bulan', 'Bulan Dibayar', 'Harga', 'Total', 'Admin Bayar', 'Tanggal Bayar']);
    foreach ($rows as $idx => $r) {
        fputcsv($out, [
            $idx + 1,
            $r['username'],
            $r['profile'],
            $r['router_name'],
            $r['location'],
            $r['month'],
            $r['months_paid'],
            formatRupiah($r['price']),
            formatRupiah($r['total']),
            $r['paid_by'],
            $r['paid_at'],
        ]);
    }
    fputcsv($out, []);
    fputcsv($out, ['TOTAL', count($rows) . ' transaksi', '', '', '', $month, $sumMonths, formatRupiah($sumPrice), formatRupiah($sumTotal), '', '']);
    if (!empty($adminTotals)) {
        fputcsv($out, []);
        fputcsv($out, ['TOTAL ADMIN BAYAR']);
        foreach ($adminTotals as $name => $info) {
            fputcsv($out, [
                'Admin',
                $name,
                'Transaksi',
                $info['count'],
                'Total',
                formatRupiah((float) $info['total'])
            ]);
        }
    }
    fclose($out);
    exit;
}

if ($format === 'excel') {
    $filename = 'billing_' . $month . '.xls';
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "<html><head><meta charset=\"UTF-8\"></head><body>";
    echo '<table border="1" cellpadding="4" cellspacing="0">';
    echo '<tr>';
    echo '<th>No</th><th>Username</th><th>Profile</th><th>Router</th><th>Lokasi</th><th>Bulan</th><th>Bulan Dibayar</th><th>Harga</th><th>Total</th><th>Admin Bayar</th><th>Tanggal Bayar</th>';
    echo '</tr>';
    foreach ($rows as $idx => $r) {
        echo '<tr>';
        echo '<td>' . ($idx + 1) . '</td>';
        echo '<td>' . escapeHtml($r['username']) . '</td>';
        echo '<td>' . escapeHtml($r['profile']) . '</td>';
        echo '<td>' . escapeHtml($r['router_name']) . '</td>';
        echo '<td>' . escapeHtml($r['location']) . '</td>';
        echo '<td>' . escapeHtml($r['month']) . '</td>';
        echo '<td>' . escapeHtml((string) $r['months_paid']) . '</td>';
        echo '<td>' . escapeHtml(formatRupiah($r['price'])) . '</td>';
        echo '<td>' . escapeHtml(formatRupiah($r['total'])) . '</td>';
        echo '<td>' . escapeHtml($r['paid_by']) . '</td>';
        echo '<td>' . escapeHtml($r['paid_at']) . '</td>';
        echo '</tr>';
    }
    echo '<tr>';
    echo '<td><strong>TOTAL</strong></td>';
    echo '<td colspan="4"><strong>' . count($rows) . ' transaksi</strong></td>';
    echo '<td><strong>' . escapeHtml($month) . '</strong></td>';
    echo '<td><strong>' . escapeHtml((string) $sumMonths) . '</strong></td>';
    echo '<td><strong>' . escapeHtml(formatRupiah($sumPrice)) . '</strong></td>';
    echo '<td><strong>' . escapeHtml(formatRupiah($sumTotal)) . '</strong></td>';
    echo '<td></td><td></td>';
    echo '</tr>';
    if (!empty($adminTotals)) {
        echo '<tr><td colspan="11"></td></tr>';
        echo '<tr><td colspan="11"><strong>TOTAL ADMIN BAYAR</strong></td></tr>';
        foreach ($adminTotals as $name => $info) {
            echo '<tr>';
            echo '<td>Admin</td>';
            echo '<td colspan="3">' . escapeHtml($name) . '</td>';
            echo '<td>Transaksi</td>';
            echo '<td>' . escapeHtml((string) $info['count']) . '</td>';
            echo '<td>Total</td>';
            echo '<td colspan="2">' . escapeHtml(formatRupiah((float) $info['total'])) . '</td>';
            echo '<td colspan="2"></td>';
            echo '</tr>';
        }
    }
    echo '</table></body></html>';
    exit;
}

$filename = 'billing_' . $month . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo buildPdfDocument($rows, $month, $sumPrice, $sumTotal, $sumMonths, $adminTotals);
exit;

function normalizeUser(array $user): array
{
    $perms = $user['permissions'] ?? [];
    if (is_string($perms)) {
        $perms = array_values(array_filter(array_map('trim', explode(',', $perms))));
    }
    if (!is_array($perms)) $perms = [];
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

function hasBillingAccess(array $user): bool
{
    if (isAdmin($user)) return true;
    $perms = $user['permissions'] ?? [];
    foreach ($perms as $perm) {
        if ($perm === 'billing') return true;
        if (strpos($perm, ':') !== false) {
            [$base] = array_map('trim', explode(':', $perm, 2));
            $base = strtolower($base);
            if (in_array($base, ['billing', 'billing_location', 'location'], true)) {
                return true;
            }
        }
    }
    return false;
}

function getAllowedLocations(array $user): array
{
    if (isAdmin($user)) return [];
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
    return $allowed;
}

function deriveLocation(string $username): string
{
    $pos = strrpos($username, '@');
    if ($pos === false) return '';
    $loc = substr($username, $pos + 1);
    return strtoupper(trim($loc));
}

function loadJsonArray(string $file): array
{
    $data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    return is_array($data) ? $data : [];
}

function formatRupiah(float $value): string
{
    return 'Rp ' . number_format($value, 0, ',', '.');
}

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function buildPdfDocument(array $rows, string $month, float $sumPrice, float $sumTotal, int $sumMonths, array $adminTotals): string
{
    $pageWidth = 842;
    $pageHeight = 595;
    $marginX = 30;
    $marginY = 30;
    $fontSize = 8;
    $rowHeight = 16;
    $textOffset = 11;
    $textPaddingX = 3;

    $columns = [
        ['label' => 'No', 'width' => 20, 'max' => 5],
        ['label' => 'Username', 'width' => 110, 'max' => 20],
        ['label' => 'Profile', 'width' => 110, 'max' => 20],
        ['label' => 'Router', 'width' => 70, 'max' => 14],
        ['label' => 'Lokasi', 'width' => 55, 'max' => 10],
        ['label' => 'Bulan', 'width' => 45, 'max' => 7],
        ['label' => 'Bln Bayar', 'width' => 45, 'max' => 6],
        ['label' => 'Harga', 'width' => 55, 'max' => 12],
        ['label' => 'Total', 'width' => 55, 'max' => 12],
        ['label' => 'Admin', 'width' => 60, 'max' => 12],
        ['label' => 'Tanggal', 'width' => 110, 'max' => 16],
    ];

    $tableRows = [];
    foreach ($rows as $idx => $r) {
        $tableRows[] = [
            (string) ($idx + 1),
            (string) $r['username'],
            (string) $r['profile'],
            (string) $r['router_name'],
            (string) $r['location'],
            (string) $r['month'],
            (string) $r['months_paid'],
            formatRupiah((float) $r['price']),
            formatRupiah((float) $r['total']),
            (string) $r['paid_by'],
            (string) $r['paid_at'],
        ];
    }
    $tableRows[] = array_fill(0, count($columns), '');
    $tableRows[] = [
        'TOTAL',
        count($rows) . ' trx',
        '',
        '',
        '',
        $month,
        (string) $sumMonths,
        formatRupiah($sumPrice),
        formatRupiah($sumTotal),
        '',
        '',
    ];
    if (!empty($adminTotals)) {
        $tableRows[] = array_fill(0, count($columns), '');
        $tableRows[] = [
            'ADMIN',
            'Total Admin Bayar',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
        ];
        foreach ($adminTotals as $name => $info) {
            $tableRows[] = [
                'Admin',
                (string) $name,
                'Transaksi ' . (string) $info['count'],
                '',
                '',
                '',
                '',
                '',
                formatRupiah((float) $info['total']),
                '',
                '',
            ];
        }
    }

    $pages = [];
    $index = 0;
    $totalRows = count($tableRows);
    while ($index < $totalRows || $index === 0) {
        $content = '';
        $y = $pageHeight - $marginY;
        $content .= pdfTextAt($marginX, $y, 'Laporan Pembayaran PPPoE', 12);
        $y -= 14;
        $content .= pdfTextAt($marginX, $y, 'Bulan: ' . $month, 9);
        $y -= 12;
        $content .= pdfTextAt($marginX, $y, 'Dicetak: ' . date('Y-m-d H:i:s'), 9);
        $y -= 16;

        $headerCells = array_map(function ($c) { return $c['label']; }, $columns);
        $content .= pdfRowBorder($columns, $marginX, $y, $rowHeight);
        $content .= pdfRow($headerCells, $columns, $marginX, $y, $fontSize, $textOffset, $textPaddingX);
        $y -= $rowHeight;

        $rowsPerPage = (int) floor(($y - $marginY) / $rowHeight);
        if ($rowsPerPage < 1) $rowsPerPage = 1;

        $count = 0;
        while ($index < $totalRows && $count < $rowsPerPage) {
            $content .= pdfRowBorder($columns, $marginX, $y, $rowHeight);
            $content .= pdfRow($tableRows[$index], $columns, $marginX, $y, $fontSize, $textOffset, $textPaddingX);
            $y -= $rowHeight;
            $index++;
            $count++;
        }

        $pages[] = $content;
        if ($totalRows === 0) break;
    }

    return renderPdfDocument($pages, $pageWidth, $pageHeight);
}

function pdfRow(array $cells, array $columns, int $startX, int $y, int $fontSize, int $textOffset, int $textPaddingX): string
{
    $out = '';
    $x = $startX;
    foreach ($columns as $i => $col) {
        $text = isset($cells[$i]) ? (string) $cells[$i] : '';
        $text = truncateText($text, (int) ($col['max'] ?? 16));
        $out .= pdfTextAt($x + $textPaddingX, $y - $textOffset, $text, $fontSize);
        $x += (int) $col['width'];
    }
    return $out;
}

function pdfRowBorder(array $columns, int $startX, int $y, int $rowHeight): string
{
    $top = $y;
    $bottom = $y - $rowHeight;
    $x = $startX;
    $totalWidth = 0;
    foreach ($columns as $col) {
        $totalWidth += (int) ($col['width'] ?? 0);
    }
    $right = $startX + $totalWidth;
    $out = "0.3 w\n0 0 0 RG\n";
    $out .= "{$startX} {$top} m {$right} {$top} l S\n";
    $out .= "{$startX} {$bottom} m {$right} {$bottom} l S\n";
    $out .= "{$x} {$bottom} m {$x} {$top} l S\n";
    foreach ($columns as $col) {
        $x += (int) ($col['width'] ?? 0);
        $out .= "{$x} {$bottom} m {$x} {$top} l S\n";
    }
    return $out;
}

function pdfTextAt(int $x, int $y, string $text, int $fontSize): string
{
    return "BT\n/F1 {$fontSize} Tf\n1 0 0 1 {$x} {$y} Tm\n(" . pdfEscape($text) . ") Tj\nET\n";
}

function truncateText(string $text, int $maxLen): string
{
    $text = trim($text);
    if ($maxLen < 1) return '';
    if (strlen($text) <= $maxLen) return $text;
    if ($maxLen <= 3) return substr($text, 0, $maxLen);
    return substr($text, 0, $maxLen - 3) . '...';
}

function renderPdfDocument(array $contents, int $pageWidth, int $pageHeight): string
{
    if (count($contents) === 0) {
        $contents = ["BT\n/F1 12 Tf\n1 0 0 1 40 550 Tm\n(Empty) Tj\nET\n"];
    }

    $objects = [];
    $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[] = '';
    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $fontObjNum = 3;
    $kids = [];

    foreach ($contents as $content) {
        $contentObjNum = count($objects) + 1;
        $objects[] = "<< /Length " . strlen($content) . " >>\nstream\n{$content}\nendstream";
        $pageObjNum = count($objects) + 1;
        $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pageWidth} {$pageHeight}] /Resources << /Font << /F1 {$fontObjNum} 0 R >> >> /Contents {$contentObjNum} 0 R >>";
        $kids[] = $pageObjNum . ' 0 R';
    }

    $objects[1] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $i => $obj) {
        $objNum = $i + 1;
        $offsets[$objNum] = strlen($pdf);
        $pdf .= $objNum . " 0 obj\n" . $obj . "\nendobj\n";
    }
    $startXref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$startXref}\n%%EOF";
    return $pdf;
}

function pdfEscape(string $text): string
{
    $text = str_replace("\\", "\\\\", $text);
    $text = str_replace("(", "\\(", $text);
    $text = str_replace(")", "\\)", $text);
    $text = str_replace(["\r", "\n"], ' ', $text);
    return $text;
}
