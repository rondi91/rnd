<?php
require_once __DIR__ . '/../../public/billing_history_helpers.php';

$historyFile = __DIR__ . '/../../storage/billing_history.json';
$historyGrouped = billingHistoryLoadGrouped($historyFile);
$historyFlat = billingHistoryFlattenGrouped($historyGrouped);
$monthNow = date('Y-m');
$yearNow = date('Y');

$totalPaidThisMonth = 0;
if (isset($historyGrouped[$monthNow]) && is_array($historyGrouped[$monthNow])) {
    foreach ($historyGrouped[$monthNow] as $row) {
        $price = (float) ($row['price'] ?? 0);
        $monthsPaid = (int) ($row['months_paid'] ?? 1);
        $totalPaidThisMonth += $price * max(1, $monthsPaid);
    }
}

$monthlyByYear = [];
foreach ($historyGrouped as $monthKey => $items) {
    if (!is_array($items) || !preg_match('/^(\d{4})-(\d{2})$/', (string) $monthKey, $m)) {
        continue;
    }
    $year = $m[1];
    $monthNum = (int) $m[2];
    if (!isset($monthlyByYear[$year])) $monthlyByYear[$year] = [];
    foreach ($items as $row) {
        if (!is_array($row)) continue;
        $price = (float) ($row['price'] ?? 0);
        $monthsPaid = (int) ($row['months_paid'] ?? 1);
        $monthlyByYear[$year][$monthNum] = ($monthlyByYear[$year][$monthNum] ?? 0) + ($price * max(1, $monthsPaid));
    }
}

$chartData = [];
foreach ($monthlyByYear as $year => $months) {
    $chartData[$year] = [];
    for ($i = 1; $i <= 12; $i++) {
        $chartData[$year][] = (float) ($months[$i] ?? 0);
    }
}
if (count($chartData) === 0) {
    $chartData[$yearNow] = array_fill(0, 12, 0);
}
$availableYears = array_keys($chartData);
sort($availableYears, SORT_NATURAL);

$routerFile = __DIR__ . '/../../storage/mikrotik.json';
$routerData = file_exists($routerFile) ? json_decode(file_get_contents($routerFile), true) : [];
if (!is_array($routerData)) $routerData = [];
$routerMap = [];
foreach ($routerData as $router) {
    if (!empty($router['id'])) {
        $routerMap[(string) $router['id']] = (string) ($router['name'] ?? ('Router #' . $router['id']));
    }
}

// Ambil data PPPoE secret & active
$pppoeData = null;
$pppoeError = '';
$url = '';
if (!empty($_SERVER['HTTP_HOST'])) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base = rtrim(dirname($_SERVER['REQUEST_URI'] ?? ''), '/\\');
    $url = $scheme . '://' . $_SERVER['HTTP_HOST'] . $base . '/pppoe_data.php';
    $pppoeJson = @file_get_contents($url);
    if ($pppoeJson !== false) {
        $pppoeData = json_decode($pppoeJson, true);
    }
}
if ($pppoeData === null || !is_array($pppoeData)) {
    $pppoeJson = @shell_exec('php ' . escapeshellarg(__DIR__ . '/../../public/pppoe_data.php'));
    if ($pppoeJson !== null) {
        $pppoeData = json_decode($pppoeJson, true);
    }
}
if (!is_array($pppoeData)) {
    $pppoeError = 'Gagal mengambil data PPPoE.';
    $pppoeData = ['summary' => ['total' => 0, 'active' => 0], 'active' => [], 'inactive_users' => []];
}

$summary = $pppoeData['summary'] ?? [];
$totalSecret = (int) ($summary['total'] ?? 0);
$totalActive = (int) ($summary['active'] ?? 0);
$serverTotals = [];
$activeRows = $pppoeData['active'] ?? [];
$inactiveRows = $pppoeData['inactive_users'] ?? [];

$allRows = array_merge($activeRows, $inactiveRows);
foreach ($allRows as $row) {
    $rid = (string) ($row['router_id'] ?? '');
    $rname = (string) ($row['router_name'] ?? '');
    if ($rid === '' && $rname === '') continue;
    $key = $rid !== '' ? $rid : $rname;
    if (!isset($serverTotals[$key])) {
        $serverTotals[$key] = [
            'id' => $rid,
            'name' => $rname !== '' ? $rname : ('Router ' . $key),
            'users' => [],
            'active_users' => [],
            'inactive_users' => [],
        ];
    }
    $uname = (string) ($row['username'] ?? '');
    if ($uname !== '') {
        $serverTotals[$key]['users'][$uname] = true;
    }
}
foreach ($activeRows as $row) {
    $rid = (string) ($row['router_id'] ?? '');
    $rname = (string) ($row['router_name'] ?? '');
    if ($rid === '' && $rname === '') continue;
    $key = $rid !== '' ? $rid : $rname;
    if (!isset($serverTotals[$key])) continue;
    $uname = (string) ($row['username'] ?? '');
    if ($uname !== '') {
        $serverTotals[$key]['active_users'][$uname] = true;
    }
}
foreach ($inactiveRows as $row) {
    $rid = (string) ($row['router_id'] ?? '');
    $rname = (string) ($row['router_name'] ?? '');
    if ($rid === '' && $rname === '') continue;
    $key = $rid !== '' ? $rid : $rname;
    if (!isset($serverTotals[$key])) continue;
    $uname = (string) ($row['username'] ?? '');
    if ($uname !== '') {
        $serverTotals[$key]['inactive_users'][$uname] = true;
    }
}
$serverList = array_values($serverTotals);
usort($serverList, function ($a, $b) {
    return strcmp($a['name'], $b['name']);
});
if ($totalSecret === 0 && count($serverList) > 0) {
    $totalSecret = 0;
    foreach ($serverList as $srv) {
        $totalSecret += count($srv['users']);
    }
}

function formatRupiah(float $value): string
{
    return 'Rp ' . number_format($value, 0, ',', '.');
}

function paymentTimestamp(array $row): int
{
    if (!empty($row['paid_at'])) {
        $ts = strtotime((string) $row['paid_at']);
        if ($ts !== false) return $ts;
    }
    if (!empty($row['month'])) {
        $ts = strtotime((string) $row['month'] . '-01');
        if ($ts !== false) return $ts;
    }
    return 0;
}

$lastPayments = $historyFlat;
usort($lastPayments, function ($a, $b) {
    return paymentTimestamp($b) <=> paymentTimestamp($a);
});
$lastPayments = array_slice($lastPayments, 0, 10);
?>

<div class="page-head">
    <h1>Dashboard</h1>
    <p>Ringkasan cepat untuk admin.</p>
</div>

<section class="card-grid">
    <article class="card">
        <div class="muted">Total pembayaran bulan ini</div>
        <div class="metric"><?php echo htmlspecialchars(formatRupiah($totalPaidThisMonth)); ?></div>
    </article>
    <article class="card">
        <div class="muted">Total user secret (semua server)</div>
        <div class="metric"><?php echo htmlspecialchars((string) $totalSecret); ?></div>
    </article>
    <article class="card">
        <div class="muted">Total active</div>
        <div class="metric"><?php echo htmlspecialchars((string) $totalActive); ?></div>
    </article>
</section>

<section class="card">
    <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap;">
        <div>
            <h2 style="margin:0;">Total user secret per server</h2>
            <p class="muted" style="margin:0.35rem 0 0;">Diambil dari PPPoE secret semua server.</p>
        </div>
        <?php if ($pppoeError): ?>
            <span class="badge badge-danger">Gagal load PPPoE</span>
        <?php endif; ?>
    </div>
    <div class="table-wrapper" style="margin-top:0.75rem;">
        <table class="table-responsive">
            <thead>
                <tr>
                    <th>Server</th>
                    <th>Total Secret</th>
                    <th>Active</th>
                    <th>Tidak Active</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($serverList) === 0): ?>
                    <tr><td colspan="4">Belum ada data server PPPoE.</td></tr>
                <?php else: ?>
                    <?php foreach ($serverList as $srv): ?>
                        <tr>
                            <td data-label="Server"><?php echo htmlspecialchars($srv['name']); ?></td>
                            <td data-label="Total Secret"><?php echo htmlspecialchars((string) count($srv['users'])); ?></td>
                            <td data-label="Active"><?php echo htmlspecialchars((string) count($srv['active_users'])); ?></td>
                            <td data-label="Tidak Active"><?php echo htmlspecialchars((string) count($srv['inactive_users'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap;">
        <div>
            <h2 style="margin:0;">10 pembayaran terakhir</h2>
            <p class="muted" style="margin:0.35rem 0 0;">Diambil dari billing history.</p>
        </div>
    </div>
    <div class="table-wrapper" style="margin-top:0.75rem;">
        <table class="table-responsive">
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>User</th>
                    <th>Profile</th>
                    <th>Router</th>
                    <th>Total</th>
                    <th>Admin</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($lastPayments) === 0): ?>
                    <tr><td colspan="6">Belum ada pembayaran.</td></tr>
                <?php else: ?>
                    <?php foreach ($lastPayments as $row): ?>
                        <?php
                            $monthsPaid = (int) ($row['months_paid'] ?? 1);
                            $price = (float) ($row['price'] ?? 0);
                            $total = $price * max(1, $monthsPaid);
                            $rid = (string) ($row['router_id'] ?? '');
                            $rname = $routerMap[$rid] ?? ($rid !== '' ? ('Router #' . $rid) : '-');
                            $paidAt = (string) ($row['paid_at'] ?? '');
                            $paidAt = $paidAt !== '' ? $paidAt : (string) ($row['month'] ?? '');
                        ?>
                        <tr>
                            <td data-label="Tanggal"><?php echo htmlspecialchars($paidAt); ?></td>
                            <td data-label="User"><?php echo htmlspecialchars((string) ($row['username'] ?? '')); ?></td>
                            <td data-label="Profile"><?php echo htmlspecialchars((string) ($row['profile'] ?? '')); ?></td>
                            <td data-label="Router"><?php echo htmlspecialchars($rname); ?></td>
                            <td data-label="Total"><?php echo htmlspecialchars(formatRupiah($total)); ?></td>
                            <td data-label="Admin"><?php echo htmlspecialchars((string) ($row['paid_by'] ?? '-')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap;">
        <div>
            <h2 style="margin:0;">Grafik pembayaran per bulan</h2>
            <p class="muted" style="margin:0.35rem 0 0;">Rekap pembayaran berdasarkan billing history.</p>
        </div>
        <label style="display:flex; align-items:center; gap:0.4rem;">
            <span>Tahun</span>
            <select id="dashboard-year">
                <?php foreach ($availableYears as $year): ?>
                    <option value="<?php echo htmlspecialchars($year); ?>" <?php echo $year === $yearNow ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($year); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <div id="dashboard-chart" class="chart"></div>
    <div class="muted" id="dashboard-chart-total" style="margin-top:0.5rem;"></div>
</section>

<style>
.chart {
    height: 220px;
    display: grid;
    grid-template-columns: repeat(12, minmax(24px, 1fr));
    gap: 0.5rem;
    align-items: end;
    padding: 1rem;
    background: #f9fafb;
    border: 1px solid var(--border);
    border-radius: 14px;
    margin-top: 0.75rem;
}
.chart-bar {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.35rem;
}
.chart-bar .bar {
    width: 100%;
    background: var(--primary);
    border-radius: 8px 8px 0 0;
    min-height: 6px;
}
.chart-bar .label {
    font-size: 0.75rem;
    color: var(--muted);
}
.chart-bar .value {
    font-size: 0.75rem;
    color: #111827;
}
@media (max-width: 720px) {
    .chart {
        grid-template-columns: repeat(12, 56px);
        overflow-x: auto;
        padding-bottom: 0.6rem;
    }
    .chart-bar {
        min-width: 56px;
    }
    .chart-bar .value,
    .chart-bar .label {
        font-size: 0.7rem;
    }
    table th,
    table td {
        padding: 0.5rem;
        font-size: 0.85rem;
    }
}
</style>

<script>
(function(){
    var chartData = <?php echo json_encode($chartData, JSON_UNESCAPED_SLASHES); ?>;
    var years = <?php echo json_encode($availableYears, JSON_UNESCAPED_SLASHES); ?>;
    var yearSelect = document.getElementById('dashboard-year');
    var chart = document.getElementById('dashboard-chart');
    var totalBox = document.getElementById('dashboard-chart-total');

    function fmt(num) {
        return 'Rp ' + Number(num || 0).toLocaleString('id-ID');
    }

    function render(year) {
        if (!chart) return;
        var data = chartData[year] || [];
        chart.innerHTML = '';
        var max = 0;
        var total = 0;
        data.forEach(function(v){
            var val = Number(v || 0);
            total += val;
            if (val > max) max = val;
        });
        if (max <= 0) max = 1;
        var labels = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
        data.forEach(function(v, i){
            var val = Number(v || 0);
            var height = Math.round((val / max) * 150) + 6;
            var wrap = document.createElement('div');
            wrap.className = 'chart-bar';
            var bar = document.createElement('div');
            bar.className = 'bar';
            bar.style.height = height + 'px';
            var value = document.createElement('div');
            value.className = 'value';
            value.textContent = fmt(val);
            var label = document.createElement('div');
            label.className = 'label';
            label.textContent = labels[i] || '-';
            wrap.appendChild(value);
            wrap.appendChild(bar);
            wrap.appendChild(label);
            chart.appendChild(wrap);
        });
        if (totalBox) {
            totalBox.textContent = 'Total pembayaran ' + year + ': ' + fmt(total);
        }
    }

    if (yearSelect) {
        yearSelect.addEventListener('change', function(){
            render(yearSelect.value);
        });
    }

    var initialYear = (yearSelect && yearSelect.value) ? yearSelect.value : (years[0] || '');
    if (initialYear) render(initialYear);
})();
</script>
