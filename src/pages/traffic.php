<?php
$file = __DIR__ . '/../../storage/mikrotik.json';
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
if (!is_array($data)) $data = [];

$locations = [];
$categories = [];
foreach ($data as $row) {
    if (!empty($row['location'])) $locations[] = $row['location'];
    if (!empty($row['category'])) $categories[] = strtolower(trim($row['category']));
}
$locations = array_values(array_unique($locations));
$categories = array_values(array_unique($categories));
?>
<div class="page-head">
    <h1>Traffic (Mikrotik)</h1>
    <p>Monitor trafik per interface (monitor-traffic) dan simpan pilihan interface per router.</p>
</div>

<section class="card">
    <div style="display:flex; gap:0.75rem; flex-wrap:wrap; margin-bottom:0.75rem;">
        <label>Lokasi
            <select id="traffic-location">
                <option value="">Semua</option>
                <?php foreach ($locations as $loc): ?>
                    <option value="<?php echo htmlspecialchars($loc); ?>"><?php echo htmlspecialchars($loc); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Kategori
            <select id="traffic-category">
                <option value="">Semua</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label style="flex:1; min-width:200px;">Search
            <input type="search" id="traffic-search" placeholder="nama / host / lokasi / catatan">
        </label>
        <button type="button" class="ghost" id="traffic-refresh">Refresh</button>
        <label style="display:flex; align-items:center; gap:0.3rem;">
            <span>Auto reload (detik)</span>
            <select id="traffic-interval">
                <option value="0" selected>Off</option>
                <option value="1">1</option>
                <option value="2">2</option>
                <option value="3">3</option>
                <option value="4">4</option>
                <option value="5">5</option>
            </select>
        </label>
        <span id="traffic-status" class="muted traffic-status"></span>
    </div>

    <div id="traffic-cards" class="traffic-cards">
        <div class="muted">Klik refresh untuk memuat data.</div>
    </div>
</section>

<style>
.traffic-cards { display:flex; flex-direction:column; gap:1rem; }
.traf-card {
    background: #111c2f;
    color: #dce6ff;
    border: 1px solid #1f3558;
    border-radius: 14px;
    padding: 1rem;
    display: grid;
    grid-template-columns: 240px 1fr 180px;
    gap: 0.75rem;
}
.traf-left { display:flex; flex-direction:column; gap:0.25rem; }
.traf-host { color:#9bb0d4; }
.traf-mid { display:flex; flex-direction:column; gap:0.35rem; }
.traf-line { display:flex; align-items:center; gap:0.5rem; }
.traf-bar { flex:1; height:8px; border-radius:8px; background:#1f3b63; position:relative; overflow:hidden; }
.traf-bar span { position:absolute; inset:0; background:#1fd07a; width:0%; transition: width 0.4s ease; }
.traf-right { display:flex; flex-direction:column; align-items:flex-end; gap:0.5rem; }
.traf-pill { padding:0.35rem 0.7rem; border-radius:999px; background:#19b36b; color:#0f1c2f; font-weight:700; }
.traf-actions { display:flex; gap:0.4rem; }
.traf-btn { padding:0.45rem 0.8rem; border:none; border-radius:8px; cursor:pointer; font-weight:700; }
.traf-btn.primary { background:#1d7bff; color:#fff; }
.traf-btn.danger { background:#e95b5b; color:#fff; }
.traf-select { background:#0f1c2f; color:#dce6ff; border:1px solid #1f3558; padding:0.35rem; border-radius:8px; }
.muted.small { font-size:0.9rem; color:#9bb0d4; }
.traffic-status { min-width:160px; display:inline-block; }
@media (max-width: 900px) {
    .traf-card { grid-template-columns: 1fr; }
    .traf-right { align-items:flex-start; }
}
</style>

<!-- Bandwidth Test Modal -->
<div id="btest-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:9998;"></div>
<div id="btest-modal" style="display:none; position:fixed; inset:0; z-index:9999; align-items:center; justify-content:center; padding:12px; box-sizing:border-box;">
    <div style="width:520px; max-width:95vw; max-height:90vh; overflow:auto; background:#0f1a2a; color:#dce6ff; border-radius:16px; box-shadow:0 20px 50px rgba(0,0,0,0.45);">
        <div style="padding:14px 16px; background:linear-gradient(135deg,#28c0ff,#1d7bff); color:#fff; display:flex; align-items:center; justify-content:space-between;">
            <div style="font-size:20px; font-weight:700;">Bandwidth Test</div>
            <button id="btest-close" style="background:transparent; border:none; color:#fff; font-size:20px; cursor:pointer;">×</button>
        </div>
        <div style="padding:14px; display:flex; flex-direction:column; gap:10px;">
            <div style="display:flex; gap:10px; flex-wrap:wrap; font-size:14px;">
                <span><strong>AP:</strong> <span id="btest-ap"></span></span>
                <span>•</span>
                <span><strong>Interface:</strong> <span id="btest-if"></span></span>
            </div>
            <label>Server test
                <select id="btest-server" style="width:100%; padding:8px; border-radius:8px; border:1px solid #1f3558; background:#0f1c2f; color:#dce6ff;">
                    <option value="172.16.30.1">172.16.30.1</option>
                    <option value="172.16.40.1">172.16.40.1</option>
                    <option value="10.20.100.1">10.20.100.1</option>
                </select>
            </label>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                <label>Direction
                    <select id="btest-direction" style="width:100%; padding:8px; border-radius:8px; border:1px solid #1f3558; background:#0f1c2f; color:#dce6ff;">
                        <option value="receive">Receive (RX)</option>
                        <option value="transmit">Transmit (TX)</option>
                        <option value="both">Both</option>
                    </select>
                </label>
                <label>Protocol
                    <select id="btest-proto" style="width:100%; padding:8px; border-radius:8px; border:1px solid #1f3558; background:#0f1c2f; color:#dce6ff;">
                        <option value="udp">UDP</option>
                        <option value="tcp">TCP</option>
                    </select>
                </label>
            </div>
            <label>Duration (s)
                <input id="btest-duration" type="number" min="1" value="10" style="width:100%; padding:8px; border-radius:8px; border:1px solid #1f3558; background:#0f1c2f; color:#dce6ff;">
            </label>
            <div id="btest-result" style="background:#0d1523; border:1px solid #1f3558; border-radius:10px; padding:10px; display:flex; flex-direction:column; gap:8px;">
                <div id="btest-result-title" style="font-weight:700; font-size:16px;">Belum ada hasil</div>
                <div id="btest-result-meta" class="muted small"></div>
            <div class="btest-bar-block">
                <div class="btest-row"><span>TX</span><div class="btest-bar"><span id="btest-bar-tx"></span></div><span id="btest-val-tx">-</span></div>
                <div class="btest-row"><span>RX</span><div class="btest-bar"><span id="btest-bar-rx"></span></div><span id="btest-val-rx">-</span></div>
                <div class="muted small">Skala: <span id="btest-scale">-</span></div>
            </div>
            <div id="btest-gauge-wrap" style="display:flex; gap:12px; align-items:center; padding:8px 0;">
                <svg viewBox="0 0 320 180" width="180" height="100" style="overflow:visible;">
                    <path id="btest-gauge-bg" d="M20 170 A140 140 0 0 1 300 170" fill="none" stroke="#1f3558" stroke-width="18" stroke-linecap="round"/>
                    <path id="btest-gauge-fg" d="M20 170 A140 140 0 0 1 300 170" fill="none" stroke="url(#gradGauge)" stroke-width="18" stroke-linecap="round" style="stroke-dasharray:0;stroke-dashoffset:0;transition:stroke-dashoffset 0.6s ease;"/>
                    <line id="btest-gauge-needle" x1="160" y1="170" x2="160" y2="60" stroke="#e0e8ff" stroke-width="5" stroke-linecap="round" transform="rotate(-135 160 170)" style="transition: transform 0.6s ease;" />
                    <defs>
                        <linearGradient id="gradGauge" x1="0%" y1="0%" x2="100%" y2="0%">
                            <stop offset="0%" stop-color="#20e0ff"/>
                            <stop offset="50%" stop-color="#42f5c5"/>
                            <stop offset="100%" stop-color="#ffaa4c"/>
                        </linearGradient>
                    </defs>
                </svg>
                <div style="display:flex; flex-direction:column; gap:4px;">
                    <div id="btest-gauge-value" style="font-size:26px; font-weight:700;">-</div>
                    <div id="btest-gauge-unit" class="muted small">bps</div>
                </div>
            </div>
            <div id="btest-peak" class="muted small"></div>
            <div id="btest-footer" class="muted small"></div>
            <button id="btest-raw-toggle" class="ghost" type="button" style="align-self:flex-start; margin-top:6px;">Lihat raw response</button>
            <pre id="btest-raw" style="display:none; white-space:pre-wrap; background:#0b1220; border:1px solid #1f3558; border-radius:8px; padding:8px; color:#cde6ff; max-height:200px; overflow:auto;"></pre>
            <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:6px;">
                <button id="btest-cancel" style="padding:9px 14px; border:none; border-radius:10px; background:#1f3558; color:#dce6ff; cursor:pointer;">Tutup</button>
                <button id="btest-run" style="padding:9px 14px; border:none; border-radius:10px; background:#1d7bff; color:#fff; cursor:pointer; font-weight:700;">Jalankan</button>
            </div>
        </div>
    </div>
    </div>
    </div>

<script>
(function(){
    var cardsWrap = document.getElementById('traffic-cards');
    var locSel = document.getElementById('traffic-location');
    var catSel = document.getElementById('traffic-category');
    var searchInput = document.getElementById('traffic-search');
    var refreshBtn = document.getElementById('traffic-refresh');
    var statusBox = document.getElementById('traffic-status');
    var intervalSel = document.getElementById('traffic-interval');
    var timer = null;
    var dataset = [];
    var isLoading = false;
    var userSelecting = false;
    var userSelectingTimer = null;
    var modalOpen = false;
    var modalEl = document.getElementById('btest-modal');
    var modalOverlay = document.getElementById('btest-overlay');
    var modalAp = document.getElementById('btest-ap');
    var modalIf = document.getElementById('btest-if');
    var modalServerSelect = document.getElementById('btest-server');
    var modalDir = document.getElementById('btest-direction');
    var modalProto = document.getElementById('btest-proto');
    var modalDur = document.getElementById('btest-duration');
    var modalRun = document.getElementById('btest-run');
    var modalCancel = document.getElementById('btest-cancel');
    var modalCloseBtn = document.getElementById('btest-close');
    var currentModalItem = null;
    var modalResult = document.getElementById('btest-result');
    var modalResultTitle = document.getElementById('btest-result-title');
    var modalResultMeta = document.getElementById('btest-result-meta');
    var modalBarTx = document.getElementById('btest-bar-tx');
    var modalBarRx = document.getElementById('btest-bar-rx');
    var modalValTx = document.getElementById('btest-val-tx');
    var modalValRx = document.getElementById('btest-val-rx');
    var modalScale = document.getElementById('btest-scale');
    var modalPeak = document.getElementById('btest-peak');
    var modalFooter = document.getElementById('btest-footer');
    var modalRaw = document.getElementById('btest-raw');
    var modalRawToggle = document.getElementById('btest-raw-toggle');
    var gaugeBg = document.getElementById('btest-gauge-bg');
    var gaugeFg = document.getElementById('btest-gauge-fg');
    var gaugeNeedle = document.getElementById('btest-gauge-needle');
    var gaugeValue = document.getElementById('btest-gauge-value');
    var gaugeUnit = document.getElementById('btest-gauge-unit');
    var gaugeLen = gaugeFg ? gaugeFg.getTotalLength() : 0;
    var gaugeCurrent = 0;
    var barTxCurrent = 0;
    var barRxCurrent = 0;
    var gaugeScaleBase = 1;
    if (gaugeFg && gaugeLen) {
        gaugeFg.style.strokeDasharray = gaugeLen;
        gaugeFg.style.strokeDashoffset = gaugeLen;
    }

    refreshBtn && refreshBtn.addEventListener('click', loadData);
    locSel && locSel.addEventListener('change', applyFilter);
    catSel && catSel.addEventListener('change', applyFilter);
    searchInput && searchInput.addEventListener('input', applyFilter);
    intervalSel && intervalSel.addEventListener('change', applyInterval);
    if (cardsWrap) {
        cardsWrap.addEventListener('focusin', function(e){
            if (e.target && e.target.classList.contains('iface-select')) {
                userSelecting = true;
                if (userSelectingTimer) clearTimeout(userSelectingTimer);
                pauseInterval();
                if (statusBox) statusBox.textContent = 'Pause reload saat memilih interface...';
            }
        });
        cardsWrap.addEventListener('focusout', function(e){
            if (e.target && e.target.classList.contains('iface-select')) {
                if (userSelectingTimer) clearTimeout(userSelectingTimer);
                userSelectingTimer = setTimeout(function(){
                    userSelecting = false;
                    applyInterval(); // lanjutkan interval setelah user selesai memilih
                }, 1200);
            }
        });
    }
    modalCancel && modalCancel.addEventListener('click', closeModal);
    modalCloseBtn && modalCloseBtn.addEventListener('click', closeModal);
    modalOverlay && modalOverlay.addEventListener('click', closeModal);
    modalRun && modalRun.addEventListener('click', function(){
        runBandwidthTest();
    });
    modalRawToggle && modalRawToggle.addEventListener('click', function(){
        if (!modalRaw) return;
        if (modalRaw.style.display === 'none' || modalRaw.style.display === '') {
            modalRaw.style.display = 'block';
            modalRawToggle.textContent = 'Sembunyikan raw response';
        } else {
            modalRaw.style.display = 'none';
            modalRawToggle.textContent = 'Lihat raw response';
        }
    });
    function loadData() {
        if (userSelecting) {
            // skip refresh while user memilih interface agar dropdown tidak menutup
            return;
        }
        if (isLoading) return;
        if (modalOpen) {
            // jangan refresh saat modal terbuka
            return;
        }
        isLoading = true;
        var loc = (locSel && locSel.value) ? encodeURIComponent(locSel.value) : '';
        var cat = (catSel && catSel.value) ? encodeURIComponent(catSel.value) : '';
        var q = (searchInput && searchInput.value) ? encodeURIComponent(searchInput.value) : '';
        var qs = '?location=' + loc + '&category=' + cat + '&q=' + q;

        fetch('traffic_data.php' + qs, { cache: 'no-store' })
            .then(function(res){ return res.json(); })
            .then(function(json){
                if (json.error) throw new Error(json.error);
                dataset = json.data || [];
                renderCards(dataset);
                var errCount = (json.errors && json.errors.length) ? json.errors.length : 0;
                var ts = new Date().toLocaleTimeString();
                if (statusBox) statusBox.textContent = 'Total router: ' + dataset.length + ' | Error: ' + errCount + ' @ ' + ts;
            })
            .catch(function(err){
                if (statusBox) statusBox.textContent = 'Gagal: ' + err.message;
                if (cardsWrap) cardsWrap.innerHTML = '<div class="muted">Gagal memuat: ' + err.message + '</div>';
            })
            .finally(function(){
                isLoading = false;
            });
    }

    function applyFilter() {
        // muat ulang ke server hanya router yang sesuai filter
        loadData();
    }

    function renderCards(list) {
        if (!cardsWrap) return;
        cardsWrap.innerHTML = '';
        var loc = (locSel && locSel.value.toLowerCase()) || '';
        var cat = (catSel && catSel.value.toLowerCase()) || '';
        var q = (searchInput && searchInput.value.toLowerCase().trim()) || '';

        var filtered = list.filter(function(item){
            var locVal = (item.location || '').toLowerCase();
            var catVal = (item.category || '').toLowerCase();
            var hay = ((item.name || '') + ' ' + (item.host || '') + ' ' + (item.location || '') + ' ' + (item.notes || '')).toLowerCase();
            if (loc && locVal !== loc) return false;
            if (cat && catVal !== cat) return false;
            if (q && hay.indexOf(q) === -1) return false;
            return true;
        });

        // urutkan berdasar lokasi lalu nama
        filtered.sort(function(a, b){
            var la = (a.location || '').toLowerCase();
            var lb = (b.location || '').toLowerCase();
            if (la < lb) return -1;
            if (la > lb) return 1;
            var na = (a.name || '').toLowerCase();
            var nb = (b.name || '').toLowerCase();
            if (na < nb) return -1;
            if (na > nb) return 1;
            return 0;
        });

        var maxVal = filtered.reduce(function(max, item){
            var rxv = parseFloat(item.rx_kbps || 0) || 0;
            var txv = parseFloat(item.tx_kbps || 0) || 0;
            return Math.max(max, rxv, txv);
        }, 0);
        var scaleUse = maxVal <= 0 ? 1 : maxVal;

        if (filtered.length === 0) {
            cardsWrap.innerHTML = '<div class="muted">Tidak ada data yang cocok.</div>';
            return;
        }

        filtered.forEach(function(item, idx){
            var card = document.createElement('div');
            card.className = 'traf-card';
            var rx = formatSpeed(item.rx_kbps);
            var tx = formatSpeed(item.tx_kbps);
            var rxPct = Math.min(100, Math.max(5, ((item.rx_kbps || 0) / scaleUse) * 100));
            var txPct = Math.min(100, Math.max(5, ((item.tx_kbps || 0) / scaleUse) * 100));
            var ifList = Array.isArray(item.interfaces) ? item.interfaces.slice() : [];
            if (item.interface && ifList.indexOf(item.interface) === -1) {
                ifList.unshift(item.interface); // pastikan opsi terpilih ada di dropdown
            }
            var ifOptions = ifList.map(function(ifc){
                var sel = (ifc === item.interface) ? 'selected' : '';
                var safe = escapeHtml(ifc);
                return '<option value="' + safe + '" ' + sel + '>' + safe + '</option>';
            }).join('');
            var lastUpdate = item.last_update ? new Date(item.last_update).toLocaleTimeString() : '';
            var statusLabel = (item.status === 'running') ? 'Running' : (item.status || 'unknown');
            var statusDetail = item.error ? ' | ' + escapeHtml(item.error) : '';
            var cpuInfo = (item.cpu != null) ? ('CPU: ' + item.cpu.toFixed(1) + '%') : '';
            card.innerHTML = '' +
                '<div class="traf-left">' +
                    '<div style="font-weight:700;">' + (idx + 1) + '. ' + escapeHtml(item.name || '') + '</div>' +
                    '<div class="traf-host">' + escapeHtml(item.host || '') + '</div>' +
                    '<div class="muted small">' + escapeHtml(item.location || '') + ' | ' + escapeHtml(item.category || '') + '</div>' +
                '</div>' +
                '<div class="traf-mid">' +
                    '<div style="font-weight:700;">Interface: <span class="iface-name">' + escapeHtml(item.interface || '') + '</span></div>' +
                    '<div class="traf-line"><span class="traf-label">RX ' + rx + '</span><div class="traf-bar"><span style="width:' + rxPct + '%; background:' + barColor(item.rx_kbps) + ';"></span></div></div>' +
                    '<div class="traf-line"><span class="traf-label">TX ' + tx + '</span><div class="traf-bar"><span style="width:' + txPct + '%; background:' + barColor(item.tx_kbps) + ';"></span></div></div>' +
                    (cpuInfo ? '<div class="muted small">' + cpuInfo + '</div>' : '') +
                '</div>' +
                '<div class="traf-right">' +
                    '<select class="traf-select iface-select">' + ifOptions + '</select>' +
                    '<span class="traf-pill">' + statusLabel + '</span>' +
                    '<div class="traf-actions">' +
                        '<button class="traf-btn primary btest-btn">Bandwidth Test</button>' +
                        '<button class="traf-btn danger">Hapus</button>' +
                    '</div>' +
                    '<div class="muted small">Mode: TX & RX | TCP | Durasi: 10s</div>' +
                    '<div class="muted small">' + (lastUpdate ? ('Update: ' + lastUpdate) : '') + statusDetail + '</div>' +
                '</div>';
            cardsWrap.appendChild(card);
            var select = card.querySelector('.iface-select');
            if (select) {
                // sinkronkan nilai select dengan interface aktif
                select.value = item.interface || (ifList && ifList[0]) || '';
                select.addEventListener('change', function(){
                    saveInterface(item.id, select.value);
                });
            }
            var btestBtn = card.querySelector('.btest-btn');
            if (btestBtn) {
                btestBtn.addEventListener('click', function(){
                    openBtestModal(item);
                });
            }
        });
    }

    function saveInterface(routerId, iface) {
        if (!routerId || !iface) return;
        fetch('traffic_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ router_id: routerId, interface: iface })
        })
        .then(function(res){ return res.json(); })
        .then(function(json){
            if (json.error) throw new Error(json.error);
            loadData();
        })
        .catch(function(err){
            if (statusBox) statusBox.textContent = 'Gagal simpan interface: ' + err.message;
        });

        // tahan auto refresh sebentar setelah pilih supaya dropdown tidak tertutup
        userSelecting = true;
        if (userSelectingTimer) clearTimeout(userSelectingTimer);
        userSelectingTimer = setTimeout(function(){ userSelecting = false; }, 1200);
    }

    function applyInterval() {
        pauseInterval();
        var sec = intervalSel ? parseInt(intervalSel.value, 10) : 0;
        if (statusBox) statusBox.textContent = 'Auto reload: ' + (sec > 0 ? (sec + ' detik') : 'off');
        if (sec > 0) {
            timer = setInterval(loadData, sec * 1000);
        }
    }

    function pauseInterval() {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
    }

    function formatSpeed(kbps) {
        if (kbps == null || isNaN(kbps)) return '-';
        var val = Number(kbps);
        var units = ['Kbps', 'Mbps', 'Gbps'];
        var idx = 0;
        while (val >= 1000 && idx < units.length - 1) {
            val = val / 1000;
            idx++;
        }
        return val.toFixed(val >= 10 ? 1 : 2) + ' ' + units[idx];
    }

    function barColor(kbps) {
        var v = Number(kbps) || 0;
        var m = v / 1000; // konversi ke Mbps
        if (m >= 100) return '#e95b5b'; // merah
        if (m >= 50) return '#e7c84d';  // kuning
        return '#1fd07a';              // hijau
    }

    function guessTestServer(host) {
        var h = (host || '').trim();
        if (h.startsWith('172.16.30.')) return '172.16.30.1';
        if (h.startsWith('172.16.40.')) return '172.16.40.1';
        if (h === '192.168.255.20') return '10.20.100.1';
        return '172.16.30.1';
    }

    function findRouterByHost(host) {
        var h = (host || '').trim();
        return (dataset || []).find(function(r){ return (r.host || '').trim() === h; }) || null;
    }

    function resetBtestResult(msg) {
        if (modalResultTitle) modalResultTitle.textContent = msg || 'Belum ada hasil';
        if (modalResultMeta) modalResultMeta.textContent = '';
        if (modalValTx) modalValTx.textContent = '-';
        if (modalValRx) modalValRx.textContent = '-';
        if (modalBarTx) modalBarTx.style.width = '0%';
        if (modalBarRx) modalBarRx.style.width = '0%';
        if (modalScale) modalScale.textContent = '-';
        if (modalPeak) modalPeak.textContent = '';
        if (modalFooter) modalFooter.textContent = '';
        if (modalRaw) modalRaw.textContent = '';
        gaugeCurrent = 0;
        barTxCurrent = 0;
        barRxCurrent = 0;
        updateGauge(0, 1);
    }

    function renderBtestResult(json, body) {
        var resp = json && json.response;
        var rows = Array.isArray(resp) ? resp : [];
        // pilih baris terakhir sebagai snapshot, dan cari peak dari semua
        var row = rows.length ? rows[rows.length - 1] : {};
        var txBps = Number(row['tx-current'] || row['tx-bits-per-second'] || 0);
        var rxBps = Number(row['rx-current'] || row['rx-bits-per-second'] || 0);
        var peakTx = Math.max(txBps, Number(row['tx-10-second-average'] || 0), Number(row['tx-total-average'] || 0));
        var peakRx = Math.max(rxBps, Number(row['rx-10-second-average'] || 0), Number(row['rx-total-average'] || 0));
        var loss = (row['lost-packets'] != null) ? row['lost-packets'] : null;
        var status = row['status'] || 'selesai';
        rows.forEach(function(r){
            var txv = Number(r['tx-current'] || r['tx-bits-per-second'] || 0);
            var rxv = Number(r['rx-current'] || r['rx-bits-per-second'] || 0);
            if (txv > peakTx) peakTx = txv;
            if (rxv > peakRx) peakRx = rxv;
        });
        // jika snapshot 0 gunakan puncak agar tidak terlihat kosong
        if (txBps === 0) txBps = peakTx;
        if (rxBps === 0) rxBps = peakRx;

        gaugeScaleBase = Math.max(txBps, rxBps, peakTx, peakRx, 1);
        var scaleUse = gaugeScaleBase;
        var scaleMbps = (scaleUse / 1_000_000);

        if (modalResultTitle) modalResultTitle.textContent = 'Hasil Bandwidth Test – ' + (currentModalItem.name || '');
        if (modalResultMeta) modalResultMeta.textContent = (currentModalItem.host || '') + '   •   Interface: ' + (currentModalItem.interface || '') +
            '   •   Server: ' + (body.target || '') + '   •   Mode: ' + (body.direction || '') + '   •   Durasi: ' + (body.duration || 0) + 's';

        if (modalValTx) modalValTx.textContent = formatBps(txBps);
        if (modalValRx) modalValRx.textContent = formatBps(rxBps);
        if (modalScale) modalScale.textContent = scaleMbps.toFixed(2) + ' Mbps';
        if (modalPeak) modalPeak.textContent = 'Puncak TX: ' + formatBps(peakTx) + '   •   Puncak RX: ' + formatBps(peakRx) + (loss!==null ? ('   •   Lost: ' + loss) : '');
        if (modalFooter) modalFooter.textContent = 'MODE: ' + (body.direction || '') + ' + ' + (body.protocol || '') + '   •   DURASI: ' + (body.duration || 0) + 's   •   Selesai: ' + new Date().toLocaleString();
        if (modalRaw) modalRaw.textContent = JSON.stringify(resp, null, 2);
        if (modalRawToggle) modalRawToggle.textContent = 'Lihat raw response';
        if (modalRaw) modalRaw.style.display = 'none';
        animateResult(txBps, rxBps, peakTx, peakRx, scaleUse);
    }

    function formatBps(bps) {
        var v = Number(bps) || 0;
        if (v >= 1_000_000_000) return (v/1_000_000_000).toFixed(2) + ' Gbps';
        if (v >= 1_000_000) return (v/1_000_000).toFixed(2) + ' Mbps';
        if (v >= 1_000) return (v/1_000).toFixed(2) + ' Kbps';
        return v.toFixed(0) + ' bps';
    }

    function animateResult(txTarget, rxTarget, peakTx, peakRx, scale) {
        var startTx = barTxCurrent;
        var startRx = barRxCurrent;
        var startGauge = gaugeCurrent;
        var maxVal = Math.max(txTarget, rxTarget, peakTx, peakRx, 1);
        var duration = 600;
        var start = null;
        function step(ts) {
            if (!start) start = ts;
            var p = Math.min(1, (ts - start) / duration);
            var currTx = startTx + (txTarget - startTx) * p;
            var currRx = startRx + (rxTarget - startRx) * p;
            var currGauge = startGauge + (maxVal - startGauge) * p;
            applyBars(currTx, currRx, peakTx, peakRx, scale);
            updateGauge(currGauge, scale);
            if (p < 1) {
                window.requestAnimationFrame(step);
            } else {
                barTxCurrent = txTarget;
                barRxCurrent = rxTarget;
                gaugeCurrent = maxVal;
            }
        }
        window.requestAnimationFrame(step);
    }

    function applyBars(tx, rx, peakTx, peakRx, scale) {
        if (modalBarTx) modalBarTx.style.width = Math.min(100, (tx/scale)*100) + '%';
        if (modalBarRx) modalBarRx.style.width = Math.min(100, (rx/scale)*100) + '%';
        if (modalValTx) modalValTx.textContent = formatBps(tx);
        if (modalValRx) modalValRx.textContent = formatBps(rx);
    }

    function updateGauge(valBps, scaleBps) {
        if (!gaugeFg || !gaugeNeedle || !gaugeLen) return;
        var scale = Math.max(1, scaleBps || 1);
        var ratio = Math.min(1, Math.max(0, valBps) / scale);
        gaugeFg.style.strokeDashoffset = gaugeLen * (1 - ratio);
        // jarum bergerak pada rentang -90 (kiri) sampai +90 (kanan)
        var angle = -90 + (180 * ratio);
        gaugeNeedle.style.transform = 'rotate(' + angle + 'deg)';
        if (gaugeValue) gaugeValue.textContent = formatBps(valBps);
        if (gaugeUnit) gaugeUnit.textContent = 'bps';
    }

    function openBtestModal(item) {
        if (!modalEl || !modalOverlay) return;
        pauseInterval();
        modalOpen = true;
        currentModalItem = item;
        modalAp.textContent = (item.name || '') + (item.host ? ' (' + item.host + ')' : '');
        modalIf.textContent = item.interface || '';

        if (modalServerSelect) {
            modalServerSelect.value = guessTestServer(item.host || '');
        }

        modalDir.value = 'receive';
        modalProto.value = 'udp';
        modalDur.value = '10';
        resetBtestResult();

        modalOverlay.style.display = 'block';
        modalEl.style.display = 'flex';
    }

    function runBandwidthTest() {
        if (!currentModalItem) return;
        var body = {
            router_id: currentModalItem.id,
            target: modalServerSelect ? modalServerSelect.value : '',
            direction: modalDir ? modalDir.value : 'receive',
            protocol: modalProto ? modalProto.value : 'udp',
            duration: modalDur ? parseInt(modalDur.value || '10', 10) : 10
        };
        var targetCred = findRouterByHost(body.target);
        if (targetCred) {
            body.target_user = targetCred.username || '';
            body.target_pass = targetCred.password || '';
        }
        modalRun.disabled = true;
        modalRun.textContent = 'Menjalankan...';
        resetBtestResult('Menjalankan bandwidth-test...');
        fetch('traffic_btest.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        })
        .then(function(res){ return res.json(); })
        .then(function(json){
            if (json.error) throw new Error(json.error);
            modalRun.textContent = 'Selesai';
            if (statusBox) statusBox.textContent = 'Btest OK: ' + (json.target || '') + ' (' + (json.router || '') + ')';
            renderBtestResult(json, body);
        })
        .catch(function(err){
            modalRun.textContent = 'Gagal';
            if (statusBox) statusBox.textContent = 'Btest error: ' + err.message;
            resetBtestResult('Error: ' + err.message);
        })
        .finally(function(){
            setTimeout(function(){
                modalRun.disabled = false;
                modalRun.textContent = 'Jalankan';
            }, 1500);
        });
    }

    function closeModal() {
        modalOpen = false;
        if (modalOverlay) modalOverlay.style.display = 'none';
        if (modalEl) modalEl.style.display = 'none';
        applyInterval();
    }

    function escapeHtml(str) {
        return String(str || '').replace(/[&<>\"']/g, function (m) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[m]);
        });
    }

    // initial load + optional interval
    loadData();
    applyInterval();
})();
</script>
