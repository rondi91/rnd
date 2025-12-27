<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(['error' => 'Fatal: ' . $err['message']]);
    }
});

require_once __DIR__ . '/../vendor/autoload.php';

use RouterOS\Client;
use RouterOS\Query;
use phpseclib3\Net\SSH2;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$routersFile = __DIR__ . '/../storage/mikrotik.json';
$routers = file_exists($routersFile) ? json_decode(file_get_contents($routersFile), true) : [];
if (!is_array($routers)) $routers = [];

$input = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];
}

$routerId = trim((string) ($_GET['router_id'] ?? ($input['router_id'] ?? '')));
$duration = (int) ($_GET['duration'] ?? ($input['duration'] ?? 10));
if ($duration < 1) $duration = 1;
if ($duration > 120) $duration = 120;
$mode = strtolower(trim((string) ($_GET['mode'] ?? ($input['mode'] ?? 'auto'))));
if ($mode === '') $mode = 'auto';
if ($mode === 'usage') $mode = 'monitor';
$action = strtolower(trim((string) ($input['action'] ?? 'scan')));
if ($routerId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'router_id wajib diisi']);
    exit;
}

$router = null;
foreach ($routers as $row) {
    if ((string) ($row['id'] ?? '') === $routerId) {
        $router = $row;
        break;
    }
}
if (!$router) {
    http_response_code(404);
    echo json_encode(['error' => 'Router tidak ditemukan']);
    exit;
}
if (strtolower(trim((string) ($router['category'] ?? ''))) !== 'ap') {
    http_response_code(400);
    echo json_encode(['error' => 'Router bukan kategori AP']);
    exit;
}

$host = trim((string) ($router['host'] ?? ''));
$user = trim((string) ($router['username'] ?? ''));
$pass = trim((string) ($router['password'] ?? ''));
if ($host === '' || $user === '' || $pass === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Kredensial router tidak lengkap']);
    exit;
}

try {
    $client = new Client([
        'host' => $host,
        'user' => $user,
        'pass' => $pass,
        'port' => 8728,
        'timeout' => max(8, $duration + 5),
        'attempts' => 1,
    ]);

    $ifaceList = $client->query(new Query('/interface/wireless/print'))->read();
    if (!is_array($ifaceList) || count($ifaceList) === 0) {
        throw new RuntimeException('Interface wireless tidak ditemukan');
    }
    $ifaceSummary = [];
    foreach ($ifaceList as $row) {
        if (!is_array($row)) continue;
        $ifaceSummary[] = [
            'name' => (string) ($row['name'] ?? ''),
            'id' => (string) ($row['.id'] ?? ''),
            'disabled' => (string) ($row['disabled'] ?? ''),
            'band' => (string) ($row['band'] ?? ''),
            'frequency' => (string) ($row['frequency'] ?? ''),
            'scan_list' => (string) ($row['scan-list'] ?? ''),
        ];
    }
    $iface = null;
    foreach ($ifaceList as $row) {
        if (!is_array($row)) continue;
        $disabled = (string) ($row['disabled'] ?? '');
        if ($disabled === 'true') continue;
        $iface = $row;
        break;
    }
    if ($iface === null) {
        foreach ($ifaceList as $row) {
            if (is_array($row)) {
                $iface = $row;
                break;
            }
        }
    }
    if ($iface === null) {
        throw new RuntimeException('Interface wireless tidak valid');
    }
    $ifaceName = (string) ($iface['name'] ?? '');
    $ifaceId = (string) ($iface['.id'] ?? '');
    $scanList = (string) ($iface['scan-list'] ?? '');

    if ($action === 'set_scan') {
        $addRange = '5100-5825';
        $newList = $scanList;
        if ($newList === '') {
            $newList = $addRange;
        } elseif (stripos($newList, $addRange) === false) {
            $newList = $newList . ',' . $addRange;
        }
        $setQuery = new Query('/interface/wireless/set');
        $setQuery->equal('numbers', $ifaceId !== '' ? $ifaceId : $ifaceName);
        $setQuery->equal('scan-list', $newList);
        $client->query($setQuery)->read();
        echo json_encode([
            'message' => 'Scan list diperbarui',
            'interface' => $ifaceName,
            'scan_list' => $newList
        ]);
        exit;
    }

    $scanErrors = [];
    $scanMeta = [
        'scan_count' => 0,
        'scan_print_count' => 0,
        'ssh_count' => 0,
        'ssh_monitor_count' => 0,
        'ssh_snooper_count' => 0,
    ];
    $rows = [];
    $source = '';
    $rawMonitorOutput = '';
    $rawSnooperOutput = '';
    if ($mode === 'monitor') {
        $rows = runFrequencyMonitorSsh($host, $user, $pass, $ifaceName, $duration, $scanErrors, $scanMeta, $rawMonitorOutput);
        $source = 'ssh-frequency-monitor';
    } elseif ($mode === 'scan') {
        $rows = runWirelessScan($client, $ifaceName, $duration, $scanErrors, $scanMeta);
        $source = 'scan';
        if (!is_array($rows) || count($rows) === 0) {
            $rows = runWirelessScanSsh($host, $user, $pass, $ifaceName, $duration, $scanErrors, $scanMeta);
            if (is_array($rows) && count($rows) > 0) {
                $source = 'ssh-scan';
            }
        }
    } elseif ($mode === 'snooper') {
        $rows = runWirelessSnooperSsh($host, $user, $pass, $ifaceName, $duration, $scanErrors, $scanMeta, $rawSnooperOutput);
        $source = 'ssh-snooper';
    } else {
        $rows = runFrequencyMonitorSsh($host, $user, $pass, $ifaceName, $duration, $scanErrors, $scanMeta, $rawMonitorOutput);
        if (is_array($rows) && count($rows) > 0) {
            $source = 'ssh-frequency-monitor';
        }
        if (!is_array($rows) || count($rows) === 0) {
            $rows = runWirelessScan($client, $ifaceName, $duration, $scanErrors, $scanMeta);
            $source = 'scan';
        }
        if (!is_array($rows) || count($rows) === 0) {
            $rows = runWirelessScanSsh($host, $user, $pass, $ifaceName, $duration, $scanErrors, $scanMeta);
            if (is_array($rows) && count($rows) > 0) {
                $source = 'ssh-scan';
            }
        }
        if (!is_array($rows) || count($rows) === 0) {
            $rows = runWirelessSnooperSsh($host, $user, $pass, $ifaceName, $duration, $scanErrors, $scanMeta, $rawSnooperOutput);
            if (is_array($rows) && count($rows) > 0) {
                $source = 'ssh-snooper';
            }
        }
    }
    $rawSample = null;
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $rawSample = $row;
            break;
        }
    }

    $parsed = [];
    foreach ($rows as $row) {
        $freq = pickFrequencyValue($row);
        $signal = pickSignalValue($row);
        $noise = pickNoiseValue($row);
        $busy = pickBusyValue($row);
        if ($freq === '' && $signal === null && $noise === null && $busy === null) {
            continue;
        }
        $parsed[] = [
            'frequency' => $freq,
            'signal' => $signal,
            'noise' => $noise,
            'busy' => $busy,
        ];
    }

    usort($parsed, function ($a, $b) {
        $sa = $a['busy'] !== null ? $a['busy'] : $a['signal'];
        $sb = $b['busy'] !== null ? $b['busy'] : $b['signal'];
        if ($sa === null && $sb === null) return 0;
        if ($sa === null) return 1;
        if ($sb === null) return -1;
        return $sa <=> $sb;
    });

    $bestMode = resolveBestMode($mode, $source);
    $best = buildBestFrequencies($parsed, $bestMode, 10);
    $recommendation = null;
    if (is_array($best) && count($best) > 0) {
        $recommendation = $best[0];
    }
    if (count($parsed) === 0) {
        if (is_array($rows) && count($rows) > 0) {
            $scanErrors[] = 'Data scan tidak bisa dipetakan. Lihat debug raw_row.';
        } else {
            $scanErrors[] = 'Tidak ada data frequency. Pastikan interface wireless mendukung scan.';
        }
    } else {
        // jika ada data, sembunyikan error dari percobaan command yang gagal
        $scanErrors = [];
    }

    $resourceInfo = [];
    try {
        $sys = $client->query(new Query('/system/resource/print'))->read();
        if (is_array($sys)) {
            foreach ($sys as $item) {
                if (!is_array($item)) continue;
                $resourceInfo = [
                    'version' => (string) ($item['version'] ?? ''),
                    'board' => (string) ($item['board-name'] ?? ''),
                ];
                break;
            }
        }
    } catch (Throwable $e) {
        $resourceInfo = ['error' => $e->getMessage()];
    }

    echo json_encode([
        'router' => [
            'id' => $routerId,
            'name' => $router['name'] ?? '',
            'host' => $host,
        ],
        'interface' => $ifaceName,
        'scan_list' => $scanList,
        'source' => $source,
        'mode' => $mode,
        'duration' => $duration,
        'best' => $best,
        'best_mode' => $bestMode,
        'recommendation' => $recommendation,
        'rows' => $parsed,
        'errors' => $scanErrors,
        'debug' => [
            'selected_interface' => $ifaceName,
            'interfaces' => $ifaceSummary,
            'resource' => $resourceInfo,
            'counts' => $scanMeta,
            'raw_row' => $rawSample,
            'raw_monitor' => $rawMonitorOutput,
            'raw_snooper' => $rawSnooperOutput,
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Gagal scan: ' . $e->getMessage()]);
}

function pickSignalValue(array $row): ?int
{
    $keys = [
        'signal',
        'signal-strength',
        'noise-floor',
        'noise',
        'avg-signal',
        'tx-signal',
    ];
    foreach ($keys as $key) {
        if (!isset($row[$key])) continue;
        $val = trim((string) $row[$key]);
        if ($val === '') continue;
        $num = (int) preg_replace('/[^0-9\-]/', '', $val);
        return $num;
    }
    return null;
}

function pickNoiseValue(array $row): ?int
{
    $keys = [
        'noise',
        'noise-floor',
        'nf',
    ];
    foreach ($keys as $key) {
        if (!isset($row[$key])) continue;
        $val = trim((string) $row[$key]);
        if ($val === '') continue;
        $num = (int) preg_replace('/[^0-9\-]/', '', $val);
        return $num;
    }
    return null;
}

function pickBusyValue(array $row): ?float
{
    $keys = [
        'busy',
        'usage',
        'occupancy',
        'percent',
    ];
    foreach ($keys as $key) {
        if (!isset($row[$key])) continue;
        $val = trim((string) $row[$key]);
        if ($val === '') continue;
        if (preg_match('/\d+(?:\.\d+)?/', $val, $m)) {
            return (float) $m[0];
        }
    }
    return null;
}

function pickFrequencyValue(array $row): string
{
    if (isset($row['frequency'])) {
        return (string) $row['frequency'];
    }
    if (isset($row['freq'])) {
        return (string) $row['freq'];
    }
    if (isset($row['channel'])) {
        $chan = (string) $row['channel'];
        if (preg_match('/(\d{4,5})/', $chan, $m)) {
            return $m[1];
        }
    }
    if (isset($row['freq-range'])) {
        return (string) $row['freq-range'];
    }
    return '';
}

function resolveBestMode(string $mode, string $source): string
{
    $mode = strtolower(trim($mode));
    if ($mode !== '' && $mode !== 'auto') {
        return $mode;
    }
    $src = strtolower((string) $source);
    if ($src !== '') {
        if (strpos($src, 'monitor') !== false) return 'monitor';
        if (strpos($src, 'snooper') !== false) return 'snooper';
        if (strpos($src, 'scan') !== false) return 'scan';
    }
    return 'auto';
}

function buildBestFrequencies(array $rows, string $mode, int $limit = 10): array
{
    $bucket = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $freq = (string) ($row['frequency'] ?? '');
        if ($freq === '') continue;
        if (!isset($bucket[$freq])) {
            $bucket[$freq] = [
                'count' => 0,
                'signal_sum' => 0,
                'signal_count' => 0,
                'max_signal' => null,
                'min_signal' => null,
                'noise_sum' => 0,
                'noise_count' => 0,
                'busy_sum' => 0,
                'busy_count' => 0,
            ];
        }
        $bucket[$freq]['count'] += 1;
        if ($row['signal'] !== null && $row['signal'] !== '') {
            $signal = (int) $row['signal'];
            $bucket[$freq]['signal_sum'] += $signal;
            $bucket[$freq]['signal_count'] += 1;
            if ($bucket[$freq]['max_signal'] === null || $signal > $bucket[$freq]['max_signal']) {
                $bucket[$freq]['max_signal'] = $signal;
            }
            if ($bucket[$freq]['min_signal'] === null || $signal < $bucket[$freq]['min_signal']) {
                $bucket[$freq]['min_signal'] = $signal;
            }
        }
        if ($row['noise'] !== null && $row['noise'] !== '') {
            $noise = (int) $row['noise'];
            $bucket[$freq]['noise_sum'] += $noise;
            $bucket[$freq]['noise_count'] += 1;
        }
        if ($row['busy'] !== null && $row['busy'] !== '') {
            $busy = (float) $row['busy'];
            $bucket[$freq]['busy_sum'] += $busy;
            $bucket[$freq]['busy_count'] += 1;
        }
    }

    $list = [];
    foreach ($bucket as $freq => $agg) {
        $avgSignal = $agg['signal_count'] > 0 ? ($agg['signal_sum'] / $agg['signal_count']) : null;
        $avgNoise = $agg['noise_count'] > 0 ? ($agg['noise_sum'] / $agg['noise_count']) : null;
        $avgBusy = $agg['busy_count'] > 0 ? ($agg['busy_sum'] / $agg['busy_count']) : null;
        $list[] = [
            'frequency' => (string) $freq,
            'signal' => $avgSignal !== null ? (int) round($avgSignal) : null,
            'noise' => $avgNoise !== null ? (int) round($avgNoise) : null,
            'busy' => $avgBusy !== null ? (float) round($avgBusy, 1) : null,
            'count' => $agg['count'],
            'max_signal' => $agg['max_signal'],
        ];
    }

    $mode = strtolower(trim($mode));
    if ($mode === 'auto') {
        $hasBusy = false;
        foreach ($list as $item) {
            if ($item['busy'] !== null) {
                $hasBusy = true;
                break;
            }
        }
        $mode = $hasBusy ? 'monitor' : 'scan';
    }

    if ($mode === 'monitor' || $mode === 'snooper') {
        usort($list, function ($a, $b) {
            $ab = $a['busy'];
            $bb = $b['busy'];
            if ($ab === null && $bb !== null) return 1;
            if ($ab !== null && $bb === null) return -1;
            if ($ab !== null && $bb !== null && $ab != $bb) {
                return $ab <=> $bb;
            }
            $an = $a['noise'];
            $bn = $b['noise'];
            if ($an === null && $bn !== null) return 1;
            if ($an !== null && $bn === null) return -1;
            if ($an !== null && $bn !== null && $an != $bn) {
                return $an <=> $bn;
            }
            return (int) $a['frequency'] <=> (int) $b['frequency'];
        });
    } else {
        usort($list, function ($a, $b) {
            if ($a['count'] != $b['count']) {
                return $a['count'] <=> $b['count'];
            }
            $am = $a['max_signal'];
            $bm = $b['max_signal'];
            if ($am === null && $bm !== null) return 1;
            if ($am !== null && $bm === null) return -1;
            if ($am !== null && $bm !== null && $am != $bm) {
                return $am <=> $bm;
            }
            $as = $a['signal'];
            $bs = $b['signal'];
            if ($as === null && $bs !== null) return 1;
            if ($as !== null && $bs === null) return -1;
            if ($as !== null && $bs !== null && $as != $bs) {
                return $as <=> $bs;
            }
            return (int) $a['frequency'] <=> (int) $b['frequency'];
        });
    }

    if ($limit > 0) {
        return array_slice($list, 0, $limit);
    }
    return $list;
}

function readFrequencyUsage(Client $client, string $ifaceName, array &$errors): array
{
    try {
        $scanQuery = new Query('/interface/wireless/frequency-usage/print');
        if ($ifaceName !== '') {
            $scanQuery->equal('interface', $ifaceName);
        }
        $rows = $client->query($scanQuery)->read();
        $list = normalizeRows($rows, $errors, 'frequency-usage');
        if (count($list) === 0 && $ifaceName !== '') {
            $scanQueryAll = new Query('/interface/wireless/frequency-usage/print');
            $rowsAll = $client->query($scanQueryAll)->read();
            $list = normalizeRows($rowsAll, $errors, 'frequency-usage');
        }
        return $list;
    } catch (Throwable $e) {
        $errors[] = 'frequency-usage gagal: ' . $e->getMessage();
        return [];
    }
}

function runWirelessScan(Client $client, string $ifaceName, int $duration, array &$errors, array &$meta): array
{
    if ($ifaceName === '') return [];
    $variants = [
        ['label' => 'scan numbers+duration', 'params' => ['numbers' => $ifaceName, 'duration' => (string) $duration]],
        ['label' => 'scan interface+duration', 'params' => ['interface' => $ifaceName, 'duration' => (string) $duration]],
        ['label' => 'scan numbers', 'params' => ['numbers' => $ifaceName]],
        ['label' => 'scan interface', 'params' => ['interface' => $ifaceName]],
        ['label' => 'scan no-params', 'params' => []],
    ];

    foreach ($variants as $variant) {
        try {
            $scanQuery = new Query('/interface/wireless/scan');
            foreach ($variant['params'] as $key => $val) {
                $scanQuery->equal($key, $val);
            }
            $rows = $client->query($scanQuery)->read();
            $rows = normalizeRows($rows, $errors, $variant['label']);
            $meta['scan_count'] = is_array($rows) ? count($rows) : 0;
            if (is_array($rows) && count($rows) > 0) {
                return $rows;
            }
        } catch (Throwable $e) {
            $errors[] = $variant['label'] . ' gagal: ' . $e->getMessage();
        }
    }

    try {
        sleep(max(1, $duration));
        $printQuery = new Query('/interface/wireless/scan/print');
        if ($ifaceName !== '') {
            $printQuery->equal('interface', $ifaceName);
        }
        $rowsPrint = $client->query($printQuery)->read();
        $rowsPrint = normalizeRows($rowsPrint, $errors, 'scan/print');
        $meta['scan_print_count'] = is_array($rowsPrint) ? count($rowsPrint) : 0;
        return $rowsPrint;
    } catch (Throwable $e) {
        $errors[] = 'scan/print gagal: ' . $e->getMessage();
        return [];
    }
}

function runWirelessScanSsh(string $host, string $user, string $pass, string $ifaceName, int $duration, array &$errors, array &$meta): array
{
    try {
        $ssh = new SSH2($host, 22, 5);
        if (!$ssh->login($user, $pass)) {
            $errors[] = 'ssh gagal: login gagal';
            return [];
        }
        $ssh->setTimeout(max(3, $duration + 5));
        $iface = $ifaceName !== '' ? $ifaceName : 'wlan1';
        $cmd = '/interface wireless scan ' . $iface . ' duration=' . $duration;
        $output = $ssh->exec($cmd);
        if (!is_string($output) || trim($output) === '') {
            $errors[] = 'ssh scan kosong';
            return [];
        }
        $rows = parseWirelessScanOutput($output);
        $meta['ssh_count'] = count($rows);
        if (count($rows) === 0) {
            $errors[] = 'ssh scan tidak bisa diparsing';
        }
        return $rows;
    } catch (Throwable $e) {
        $errors[] = 'ssh gagal: ' . $e->getMessage();
        return [];
    }
}

function runFrequencyMonitorSsh(string $host, string $user, string $pass, string $ifaceName, int $duration, array &$errors, array &$meta, string &$rawOutput): array
{
    try {
        $ssh = new SSH2($host, 22, 5);
        if (!$ssh->login($user, $pass)) {
            $errors[] = 'ssh monitor gagal: login gagal';
            return [];
        }
        $ssh->setTimeout(max(3, $duration + 5));
        $iface = $ifaceName !== '' ? $ifaceName : 'wlan1';
        $cmd = '/interface wireless frequency-monitor ' . $iface . ' duration=' . $duration;
        $output = $ssh->exec($cmd);
        $rawOutput = trim((string) $output);
        if (!is_string($output) || trim($output) === '') {
            $errors[] = 'ssh frequency-monitor kosong';
            return [];
        }
        $rows = parseFrequencyMonitorOutput($output);
        $meta['ssh_monitor_count'] = count($rows);
        if (count($rows) === 0) {
            $errors[] = 'ssh frequency-monitor tidak bisa diparsing';
        }
        return $rows;
    } catch (Throwable $e) {
        $errors[] = 'ssh monitor gagal: ' . $e->getMessage();
        return [];
    }
}

function runWirelessSnooperSsh(string $host, string $user, string $pass, string $ifaceName, int $duration, array &$errors, array &$meta, string &$rawOutput): array
{
    try {
        $ssh = new SSH2($host, 22, 5);
        if (!$ssh->login($user, $pass)) {
            $errors[] = 'ssh snooper gagal: login gagal';
            return [];
        }
        $ssh->setTimeout(max(3, $duration + 5));
        $iface = $ifaceName !== '' ? $ifaceName : 'wlan1';
        $commands = [
            'interface wireless snooper snoop ' . $iface . ' duration=' . $duration,
            '/interface wireless snooper snoop ' . $iface . ' duration=' . $duration,
            'interface wireless snooper snoop ' . $iface . ' time=' . $duration,
            '/interface wireless snooper snoop ' . $iface . ' time=' . $duration,
            'interface wireless snooper snoop ' . $iface,
            '/interface wireless snooper snoop ' . $iface,
            'interface wireless snooper snoop interface=' . $iface,
            '/interface wireless snooper snoop interface=' . $iface,
            '/interface wireless snooper print',
            '/interface wireless snooper/print',
        ];
        foreach ($commands as $cmd) {
            $output = $ssh->exec($cmd);
            $rawOutput = trim((string) $output);
            if (!is_string($output) || trim($output) === '') {
                $errors[] = 'ssh snooper kosong: ' . $cmd;
                continue;
            }
            if (isSnooperError($rawOutput)) {
                $errors[] = 'ssh snooper gagal: ' . $rawOutput;
                continue;
            }
            $rows = parseWirelessSnooperOutput($output);
            $meta['ssh_snooper_count'] = count($rows);
            if (count($rows) > 0) {
                return $rows;
            }
            $errors[] = 'ssh snooper tidak bisa diparsing: ' . $cmd;
        }
        return [];
    } catch (Throwable $e) {
        $errors[] = 'ssh snooper gagal: ' . $e->getMessage();
        return [];
    }
}

function parseWirelessScanOutput(string $output): array
{
    $lines = preg_split('/\r?\n/', $output);
    $rows = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, 'Flags:') === 0) continue;
        if (stripos($line, 'ADDRESS') === 0) continue;
        if (preg_match('/\\b(\\d{4,5})\\S*\\s+(-?\\d+)\\s+(-?\\d+)\\s+(-?\\d+)\\b/', $line, $m)) {
            $rows[] = [
                'frequency' => $m[1],
                'signal' => (int) $m[2],
                'noise' => (int) $m[3],
                'snr' => (int) $m[4],
            ];
        }
    }
    return $rows;
}

function parseFrequencyMonitorOutput(string $output): array
{
    $lines = preg_split('/\r?\n/', $output);
    $rows = [];
    $bucket = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || stripos($line, 'Flags:') === 0) continue;
        if (preg_match('/\bFREQ\b/i', $line) && preg_match('/\bUSE\b/i', $line)) {
            continue;
        }
        if (!preg_match('/\b(\d{4,5})\s*MHz\b/i', $line, $mf) && !preg_match('/\b(\d{4,5})\b/', $line, $mf)) {
            continue;
        }
        $freq = $mf[1];
        $busy = null;
        if (preg_match('/(\d+(?:\.\d+)?)%/', $line, $mb)) {
            $busy = (float) $mb[1];
        } elseif (preg_match('/\buse[:=]\s*(\d+(?:\.\d+)?)/i', $line, $mb)) {
            $busy = (float) $mb[1];
        }
        $noise = null;
        if (preg_match_all('/-\d{2,3}/', $line, $mn) && count($mn[0]) > 0) {
            $noise = (int) end($mn[0]);
        }
        if (!isset($bucket[$freq])) {
            $bucket[$freq] = ['busy_sum' => 0.0, 'busy_count' => 0, 'noise_sum' => 0, 'noise_count' => 0];
        }
        if ($busy !== null) {
            $bucket[$freq]['busy_sum'] += $busy;
            $bucket[$freq]['busy_count'] += 1;
        }
        if ($noise !== null) {
            $bucket[$freq]['noise_sum'] += $noise;
            $bucket[$freq]['noise_count'] += 1;
        }
    }
    foreach ($bucket as $freq => $agg) {
        $busy = $agg['busy_count'] > 0 ? ($agg['busy_sum'] / $agg['busy_count']) : null;
        $noise = $agg['noise_count'] > 0 ? (int) round($agg['noise_sum'] / $agg['noise_count']) : null;
        $rows[] = [
            'frequency' => (string) $freq,
            'busy' => $busy,
            'noise' => $noise,
        ];
    }
    return $rows;
}

function parseWirelessSnooperOutput(string $output): array
{
    $lines = preg_split('/\r?\n/', $output);
    $rows = [];
    $headerCols = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, 'Flags:') === 0) continue;
        if (stripos($line, 'CHANNEL') === 0) {
            $headerCols = preg_split('/\s{2,}/', $line);
            if (count($headerCols) <= 1) {
                $headerCols = preg_split('/\s+/', $line);
            }
            continue;
        }
        if (stripos($line, 'INTERFACE') === 0 || stripos($line, 'ADDRESS') === 0) {
            $headerCols = preg_split('/\s{2,}/', $line);
            if (count($headerCols) <= 1) {
                $headerCols = preg_split('/\s+/', $line);
            }
            continue;
        }
        if ($headerCols && count($headerCols) > 0) {
            $cols = preg_split('/\s{2,}/', $line);
            if (count($cols) <= 1) {
                $cols = preg_split('/\s+/', $line);
            }
            $map = [];
            foreach ($headerCols as $idx => $name) {
                $key = strtolower(trim($name));
                $map[$key] = $cols[$idx] ?? '';
            }
            $freqVal = '';
            foreach ($map as $k => $v) {
                if (strpos($k, 'freq') !== false || strpos($k, 'chan') !== false) {
                    $freqVal = (string) $v;
                    break;
                }
            }
            if ($freqVal !== '') {
                if (preg_match('/\b(\d{4,5})\b/', $freqVal, $mf)) {
                    $freqVal = $mf[1];
                } elseif (preg_match('/\b(\d{2,3})\b/', $freqVal, $mf)) {
                    $freqVal = $mf[1];
                }
            }
            $signal = null;
            foreach ($map as $k => $v) {
                if (strpos($k, 'signal') !== false || $k === 'sig') {
                    if (preg_match('/-\d{2,3}/', (string) $v, $ms)) {
                        $signal = (int) $ms[0];
                    }
                }
            }
            $busy = null;
            foreach ($map as $k => $v) {
                if (strpos($k, 'busy') !== false || strpos($k, 'use') !== false || strpos($k, 'occupancy') !== false) {
                    if (preg_match('/\d+(?:\.\d+)?/', (string) $v, $mb)) {
                        $busy = (float) $mb[0];
                    }
                }
            }
            $noise = null;
            foreach ($map as $k => $v) {
                if (strpos($k, 'noise') !== false || $k === 'nf') {
                    if (preg_match('/-\d{2,3}/', (string) $v, $mn)) {
                        $noise = (int) $mn[0];
                    }
                }
            }
            if ($freqVal !== '' || $signal !== null || $busy !== null || $noise !== null) {
                $rows[] = [
                    'frequency' => $freqVal,
                    'signal' => $signal,
                    'busy' => $busy,
                    'noise' => $noise,
                ];
            }
            continue;
        }
        if (!preg_match('/\b(\d{4,5})\b/', $line, $mf)) {
            continue;
        }
        $freq = $mf[1];
        $signal = null;
        if (preg_match('/(-\d{2,3})\b/', $line, $ms)) {
            $signal = (int) $ms[1];
        }
        $rows[] = [
            'frequency' => $freq,
            'signal' => $signal,
        ];
    }
    return $rows;
}

function isSnooperError(string $output): bool
{
    $lower = strtolower($output);
    return strpos($lower, 'syntax error') !== false
        || strpos($lower, 'expected command name') !== false
        || strpos($lower, 'unknown parameter') !== false
        || strpos($lower, 'no such command') !== false
        || strpos($lower, 'no such item') !== false
        || strpos($lower, 'failure') !== false;
}
function normalizeRows($rows, array &$errors, string $label): array
{
    if (!is_array($rows)) return [];
    if (isset($rows['message']) || isset($rows['error'])) {
        $msg = $rows['message'] ?? $rows['error'];
        $errors[] = $label . ' gagal: ' . $msg;
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        if (isset($row['message']) || isset($row['error'])) {
            $msg = $row['message'] ?? $row['error'];
            $errors[] = $label . ' gagal: ' . $msg;
            continue;
        }
        $out[] = $row;
    }
    return $out;
}
