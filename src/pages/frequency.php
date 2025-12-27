<?php
$routersFile = __DIR__ . '/../../storage/mikrotik.json';
$routers = file_exists($routersFile) ? json_decode(file_get_contents($routersFile), true) : [];
if (!is_array($routers)) $routers = [];
$aps = array_values(array_filter($routers, function ($r) {
    return isset($r['category']) && strtolower(trim((string) $r['category'])) === 'ap';
}));
?>
<div class="page-head no-print">
    <h1>Cek Frequency</h1>
    <p>Pilih router AP untuk cek frekuensi terbaik (top 10).</p>
</div>

<section class="card">
    <div class="no-print" style="display:flex; gap:0.75rem; flex-wrap:wrap; align-items:center;">
        <label style="display:flex; align-items:center; gap:0.35rem;">
            <span>Router AP</span>
            <select id="freq-router">
                <option value="">-- Pilih Router --</option>
                <?php foreach ($aps as $ap): ?>
                    <option value="<?php echo htmlspecialchars($ap['id'] ?? ''); ?>">
                        <?php echo htmlspecialchars(($ap['name'] ?? '') . ' (' . ($ap['host'] ?? '') . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label style="display:flex; align-items:center; gap:0.35rem;">
            <span>Durasi Scan</span>
            <input id="freq-duration" type="number" min="1" max="120" step="1" value="10" style="width:90px;">
            <span>detik</span>
        </label>
        <label style="display:flex; align-items:center; gap:0.35rem;">
            <span>Metode</span>
            <select id="freq-mode">
                <option value="auto" selected>Auto</option>
                <option value="monitor">Frequency Monitor</option>
                <option value="scan">Wireless Scan</option>
                <option value="snooper">Snooper</option>
            </select>
        </label>
        <button type="button" class="ghost" id="freq-scan">Scan Frequency</button>
        <button type="button" class="ghost" id="freq-test">Test Koneksi</button>
        <button type="button" class="ghost" id="freq-set-scan">Set Scan List 5100-5825</button>
        <button type="button" class="ghost" id="freq-print" style="display:none;">Cetak</button>
    </div>
    <div class="muted" id="freq-info" style="margin-top:0.5rem;"></div>
    <div id="freq-recommend" class="alert" style="display:none; margin-top:0.5rem; background:#eef2ff; border-color:#c7d2fe; color:#1e3a8a;"></div>
    <div id="freq-status" class="alert no-print" style="display:none;"></div>
    <div id="freq-countdown" class="muted no-print" style="display:none; margin-top:0.35rem;"></div>
    <div id="freq-errors" class="alert no-print" style="display:none;"></div>
    <div id="freq-test-result" class="alert no-print" style="display:none;"></div>
    <details id="freq-debug" class="no-print" style="display:none; margin-top:0.5rem;">
        <summary style="cursor:pointer;">Debug</summary>
        <pre id="freq-debug-body" style="white-space:pre-wrap; font-size:0.85rem; margin-top:0.5rem;"></pre>
    </details>
    <div id="freq-result" style="margin-top:0.75rem; display:none;">
        <h3 style="margin:0 0 0.5rem;">Top 10 Frequency Terbaik</h3>
        <div class="table-wrapper">
            <table class="table-responsive">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Frequency</th>
                        <th id="freq-best-info">Info</th>
                    </tr>
                </thead>
                <tbody id="freq-best-body">
                </tbody>
            </table>
        </div>
        <h3 style="margin:0.8rem 0 0.5rem;">Hasil Scan Lengkap</h3>
        <div class="table-wrapper">
            <table class="table-responsive">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Frequency</th>
                        <th>Signal</th>
                        <th>Noise</th>
                        <th>Busy</th>
                    </tr>
                </thead>
                <tbody id="freq-all-body"></tbody>
            </table>
        </div>
        <div class="muted" id="freq-scan-list" style="margin-top:0.5rem;"></div>
    </div>
    <div id="freq-print-view" class="freq-print">
        <h2 style="margin:0 0 0.35rem;">Hasil Frequency Terbaik</h2>
        <div class="muted" id="freq-print-meta"></div>
        <div class="muted" id="freq-print-scan" style="margin-top:0.25rem;"></div>
        <div class="table-wrapper" style="margin-top:0.5rem;">
            <table>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Frequency</th>
                        <th id="freq-best-info-print">Info</th>
                    </tr>
                </thead>
                <tbody id="freq-print-body"></tbody>
            </table>
        </div>
    </div>
</section>

<style>
.freq-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.2rem 0.55rem;
    border-radius: 999px;
    font-weight: 700;
    background: #e5f6ff;
    color: #1d4ed8;
}
.freq-print {
    display: none;
}
@media print {
    body {
        background: #fff;
    }
    .no-print {
        display: none !important;
    }
    .card {
        border: none;
        box-shadow: none;
        padding: 0;
    }
    .table-wrapper {
        overflow: visible;
    }
    .freq-print {
        display: block;
    }
    #freq-result {
        display: none !important;
    }
}
</style>

<script>
(function(){
    var routerSelect = document.getElementById('freq-router');
    var scanBtn = document.getElementById('freq-scan');
    var durationSelect = document.getElementById('freq-duration');
    var modeSelect = document.getElementById('freq-mode');
    var testBtn = document.getElementById('freq-test');
    var setScanBtn = document.getElementById('freq-set-scan');
    var printBtn = document.getElementById('freq-print');
    var statusBox = document.getElementById('freq-status');
    var errorBox = document.getElementById('freq-errors');
    var testBox = document.getElementById('freq-test-result');
    var debugBox = document.getElementById('freq-debug');
    var debugBody = document.getElementById('freq-debug-body');
    var infoBox = document.getElementById('freq-info');
    var recommendBox = document.getElementById('freq-recommend');
    var countdownBox = document.getElementById('freq-countdown');
    var resultBox = document.getElementById('freq-result');
    var bestBody = document.getElementById('freq-best-body');
    var allBody = document.getElementById('freq-all-body');
    var scanListBox = document.getElementById('freq-scan-list');
    var printView = document.getElementById('freq-print-view');
    var printBody = document.getElementById('freq-print-body');
    var printMeta = document.getElementById('freq-print-meta');
    var printScan = document.getElementById('freq-print-scan');
    var bestInfoHeader = document.getElementById('freq-best-info');
    var bestInfoPrintHeader = document.getElementById('freq-best-info-print');
    var currentBestMode = 'auto';
    var countdownTimer = null;
    var pendingTimer = null;

    function formatBusy(val) {
        if (val === null || val === undefined) return '-';
        var num = Number(val);
        if (isNaN(num)) return '-';
        var rounded = Math.round(num * 10) / 10;
        return rounded.toLocaleString('id-ID') + '%';
    }

    function setStatus(msg, isError) {
        if (!statusBox) return;
        if (!msg) {
            statusBox.style.display = 'none';
            statusBox.textContent = '';
            return;
        }
        statusBox.style.display = 'block';
        statusBox.textContent = msg;
        statusBox.style.background = isError ? '#fff4e5' : '#ecfdf3';
        statusBox.style.borderColor = isError ? '#ffddae' : '#bbf7d0';
        statusBox.style.color = isError ? '#7a4d00' : '#166534';
    }

    function setErrors(list) {
        if (!errorBox) return;
        if (!list || !list.length) {
            errorBox.style.display = 'none';
            errorBox.innerHTML = '';
            return;
        }
        errorBox.style.display = 'block';
        errorBox.innerHTML = list.map(function(item){
            return '<div>' + String(item || '') + '</div>';
        }).join('');
    }

    function setDebug(data) {
        if (!debugBox || !debugBody) return;
        if (!data) {
            debugBox.style.display = 'none';
            debugBody.textContent = '';
            return;
        }
        debugBox.style.display = 'block';
        debugBody.textContent = JSON.stringify(data, null, 2);
    }

    function setTestResult(html) {
        if (!testBox) return;
        if (!html) {
            testBox.style.display = 'none';
            testBox.innerHTML = '';
            return;
        }
        testBox.style.display = 'block';
        testBox.innerHTML = html;
    }

    function clearCountdown() {
        if (countdownTimer) {
            clearInterval(countdownTimer);
            countdownTimer = null;
        }
        if (countdownBox) {
            countdownBox.style.display = 'none';
            countdownBox.textContent = '';
        }
    }

    function clearPending() {
        if (pendingTimer) {
            clearTimeout(pendingTimer);
            pendingTimer = null;
        }
    }

    function startCountdown(seconds) {
        if (!countdownBox) return;
        clearCountdown();
        var remaining = Number(seconds || 0);
        if (!remaining || remaining < 1) return;
        countdownBox.style.display = 'block';
        countdownBox.textContent = 'Sisa waktu scan: ' + remaining + ' detik';
        countdownTimer = setInterval(function(){
            remaining -= 1;
            if (remaining <= 0) {
                countdownBox.textContent = 'Sisa waktu scan: 0 detik (menunggu hasil...)';
                clearInterval(countdownTimer);
                countdownTimer = null;
                return;
            }
            countdownBox.textContent = 'Sisa waktu scan: ' + remaining + ' detik';
        }, 1000);
    }

    function updateBestHeader(mode) {
        var label = 'Info';
        if (mode === 'monitor' || mode === 'snooper') {
            label = 'Busy/Noise';
        } else if (mode === 'scan') {
            label = 'AP/Signal';
        }
        if (bestInfoHeader) bestInfoHeader.textContent = label;
        if (bestInfoPrintHeader) bestInfoPrintHeader.textContent = label;
    }

    function buildBestInfo(item, mode) {
        if (mode === 'monitor' || mode === 'snooper') {
            var busy = formatBusy(item.busy);
            var noise = (item.noise !== null && item.noise !== undefined) ? item.noise : '-';
            return busy + ' | Noise ' + noise;
        }
        if (mode === 'scan') {
            var count = (item.count !== null && item.count !== undefined) ? item.count : '-';
            var signal = (item.signal !== null && item.signal !== undefined) ? item.signal : '-';
            return 'AP ' + count + ' | Sig ' + signal;
        }
        if (item.busy !== null && item.busy !== undefined) {
            return formatBusy(item.busy);
        }
        if (item.signal !== null && item.signal !== undefined) {
            return item.signal;
        }
        return '-';
    }

    function renderBest(list, mode) {
        if (!bestBody) return;
        bestBody.innerHTML = '';
        if (!list || list.length === 0) {
            bestBody.innerHTML = '<tr><td colspan="3">Tidak ada data.</td></tr>';
            if (printBody) printBody.innerHTML = '<tr><td colspan="3">Tidak ada data.</td></tr>';
            return;
        }
        currentBestMode = mode || 'auto';
        updateBestHeader(currentBestMode);
        list.forEach(function(item, idx){
            var tr = document.createElement('tr');
            var freqLabel = item.frequency ? (item.frequency + ' MHz') : '-';
            var signalLabel = buildBestInfo(item, currentBestMode);
            tr.innerHTML =
                '<td data-label="No">' + (idx + 1) + '</td>' +
                '<td data-label="Frequency"><span class="freq-badge">' + freqLabel + '</span></td>' +
                '<td data-label="Info">' + signalLabel + '</td>';
            bestBody.appendChild(tr);
            if (printBody) {
                var ptr = document.createElement('tr');
                ptr.innerHTML =
                    '<td>' + (idx + 1) + '</td>' +
                    '<td>' + freqLabel + '</td>' +
                    '<td>' + signalLabel + '</td>';
                printBody.appendChild(ptr);
            }
        });
    }

    function renderAll(list) {
        if (!allBody) return;
        allBody.innerHTML = '';
        if (!list || list.length === 0) {
            allBody.innerHTML = '<tr><td colspan="5">Tidak ada data.</td></tr>';
            return;
        }
        list.forEach(function(item, idx){
            var freqLabel = item.frequency ? (item.frequency + ' MHz') : '-';
            var signalLabel = (item.signal !== null && item.signal !== undefined) ? item.signal : '-';
            var noiseLabel = (item.noise !== null && item.noise !== undefined) ? item.noise : '-';
            var busyLabel = formatBusy(item.busy);
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td data-label="No">' + (idx + 1) + '</td>' +
                '<td data-label="Frequency">' + freqLabel + '</td>' +
                '<td data-label="Signal">' + signalLabel + '</td>' +
                '<td data-label="Noise">' + noiseLabel + '</td>' +
                '<td data-label="Busy">' + busyLabel + '</td>';
            allBody.appendChild(tr);
        });
    }

    function fetchScan() {
        var rid = routerSelect ? routerSelect.value : '';
        if (!rid) {
            setStatus('Pilih router AP terlebih dahulu.', true);
            return;
        }
        var duration = durationSelect ? parseInt(durationSelect.value, 10) : 10;
        if (!duration || duration < 1) duration = 10;
        var mode = modeSelect ? (modeSelect.value || 'auto') : 'auto';
        setStatus('Memproses scan...', false);
        setErrors([]);
        setDebug(null);
        setTestResult('');
        clearCountdown();
        clearPending();
        if (infoBox) infoBox.textContent = '';
        if (recommendBox) {
            recommendBox.style.display = 'none';
            recommendBox.textContent = '';
        }
        if (resultBox) resultBox.style.display = 'none';
        startCountdown(duration);
        var startTime = Date.now();
        fetch('frequency_scan.php?router_id=' + encodeURIComponent(rid) + '&duration=' + encodeURIComponent(duration) + '&mode=' + encodeURIComponent(mode))
            .then(function(res){ return res.json(); })
            .then(function(json){
                if (json.error) throw new Error(json.error);
                var elapsed = Date.now() - startTime;
                var delay = Math.max(0, (duration * 1000) - elapsed);
                var applyResult = function() {
                    clearCountdown();
                    renderBest(json.best || [], json.best_mode || json.mode || mode);
                    renderAll(json.rows || []);
                    if (scanListBox) {
                        scanListBox.textContent = 'Scan list saat ini: ' + (json.scan_list || '-');
                    }
                    setErrors(json.errors || []);
                    setDebug(json.debug || null);
                    if (infoBox) {
                        infoBox.textContent = 'Interface: ' + (json.interface || '-') + ' | Router: ' + ((json.router && json.router.name) ? json.router.name : '-');
                    }
                    if (infoBox && json.source) {
                        infoBox.textContent += ' | Sumber: ' + json.source;
                    }
                    if (infoBox && json.duration) {
                        infoBox.textContent += ' | Durasi: ' + json.duration + ' detik';
                    }
                    if (infoBox && json.mode) {
                        infoBox.textContent += ' | Mode: ' + json.mode;
                    }
                    if (recommendBox && json.recommendation && json.recommendation.frequency) {
                        var rec = json.recommendation;
                        var recParts = [];
                        if (rec.busy !== null && rec.busy !== undefined) recParts.push('Busy ' + formatBusy(rec.busy));
                        if (rec.noise !== null && rec.noise !== undefined) recParts.push('Noise ' + rec.noise);
                        if (rec.count !== null && rec.count !== undefined) recParts.push('AP ' + rec.count);
                        if (rec.signal !== null && rec.signal !== undefined) recParts.push('Signal ' + rec.signal);
                        var recText = 'Rekomendasi: ' + rec.frequency + ' MHz';
                        if (recParts.length) recText += ' (' + recParts.join(', ') + ')';
                        recommendBox.textContent = recText;
                        recommendBox.style.display = 'block';
                    }
                    if (printMeta) {
                        var when = new Date();
                        var meta = 'Router: ' + ((json.router && json.router.name) ? json.router.name : '-') +
                            ' | Interface: ' + (json.interface || '-') +
                            ' | Waktu: ' + when.toLocaleString('id-ID');
                        printMeta.textContent = meta;
                    }
                    if (printMeta && json.source) {
                        printMeta.textContent += ' | Sumber: ' + json.source;
                    }
                    if (printMeta && json.duration) {
                        printMeta.textContent += ' | Durasi: ' + json.duration + ' detik';
                    }
                    if (printMeta && json.mode) {
                        printMeta.textContent += ' | Mode: ' + json.mode;
                    }
                    if (printMeta && json.recommendation && json.recommendation.frequency) {
                        printMeta.textContent += ' | Rekomendasi: ' + json.recommendation.frequency + ' MHz';
                    }
                    if (printScan) {
                        printScan.textContent = 'Scan list: ' + (json.scan_list || '-');
                    }
                    if (resultBox) resultBox.style.display = '';
                    if (printBtn) printBtn.style.display = '';
                    setStatus('Scan selesai.', false);
                };
                if (delay > 0) {
                    if (statusBox) setStatus('Menunggu durasi selesai...', false);
                    pendingTimer = setTimeout(function(){
                        pendingTimer = null;
                        applyResult();
                    }, delay);
                } else {
                    applyResult();
                }
            })
            .catch(function(err){
                clearPending();
                clearCountdown();
                setStatus('Gagal scan: ' + err.message, true);
                setErrors([err.message]);
            });
    }

    function setScanList() {
        var rid = routerSelect ? routerSelect.value : '';
        if (!rid) {
            setStatus('Pilih router AP terlebih dahulu.', true);
            return;
        }
        setStatus('Mengubah scan list...', false);
        setTestResult('');
        fetch('frequency_scan.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'set_scan', router_id: rid })
        })
        .then(function(res){ return res.json(); })
        .then(function(json){
            if (json.error) throw new Error(json.error);
            if (scanListBox) {
                scanListBox.textContent = 'Scan list saat ini: ' + (json.scan_list || '-');
            }
            setStatus(json.message || 'Scan list diperbarui.', false);
        })
            .catch(function(err){
                setStatus('Gagal set scan list: ' + err.message, true);
            });
    }

    function testConnection() {
        var rid = routerSelect ? routerSelect.value : '';
        if (!rid) {
            setStatus('Pilih router AP terlebih dahulu.', true);
            return;
        }
        setStatus('Menguji koneksi...', false);
        setErrors([]);
        setDebug(null);
        setTestResult('');
        fetch('router_test.php?router_id=' + encodeURIComponent(rid))
            .then(function(res){ return res.json(); })
            .then(function(json){
                if (json.error) throw new Error(json.error);
                var html = '';
                if (json.port) {
                    html += '<div>Port 8728: ' + (json.port.api_open ? 'OK' : 'Tutup') + '</div>';
                    html += '<div>Port 22: ' + (json.port.ssh_open ? 'OK' : 'Tutup') + '</div>';
                }
                if (json.api) {
                    html += '<div>API: ' + (json.api.ok ? 'OK' : 'Gagal') + ' - ' + (json.api.message || '-') + '</div>';
                }
                if (json.ssh) {
                    html += '<div>SSH: ' + (json.ssh.ok ? 'OK' : 'Gagal') + ' - ' + (json.ssh.message || '-') + '</div>';
                }
                setTestResult(html);
                setStatus('Test selesai.', false);
            })
            .catch(function(err){
                setStatus('Gagal test: ' + err.message, true);
                setErrors([err.message]);
            });
    }

    scanBtn && scanBtn.addEventListener('click', fetchScan);
    testBtn && testBtn.addEventListener('click', testConnection);
    setScanBtn && setScanBtn.addEventListener('click', setScanList);
    printBtn && printBtn.addEventListener('click', function(){
        window.print();
    });
})();
</script>
