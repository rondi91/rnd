<?php
$routersFile = __DIR__ . '/../../storage/mikrotik.json';
$routers = file_exists($routersFile) ? json_decode(file_get_contents($routersFile), true) : [];
if (!is_array($routers)) $routers = [];

$aps = array_values(array_filter($routers, function ($r) {
    return isset($r['category']) && strtolower(trim($r['category'])) === 'ap';
}));

$servers = array_values(array_filter($routers, function ($r) {
    return isset($r['category']) && strtolower(trim($r['category'])) === 'server';
}));

$locationsFile = __DIR__ . '/../../storage/locations.json';
$locations = file_exists($locationsFile) ? json_decode(file_get_contents($locationsFile), true) : [];
if (!is_array($locations)) $locations = [];
$locationOptions = [];
foreach ($locations as $loc) {
    $loc = trim((string) $loc);
    if ($loc !== '') $locationOptions[$loc] = true;
}
foreach ($aps as $ap) {
    $loc = trim((string) ($ap['location'] ?? ''));
    if ($loc !== '') $locationOptions[$loc] = true;
}
$locationOptions = array_keys($locationOptions);
sort($locationOptions, SORT_NATURAL | SORT_FLAG_CASE);
?>
<div class="page-head">
    <h1>Wireless AP</h1>
    <p>Daftar router dengan kategori AP.</p>
    <button type="button" class="ghost" id="wireless-add-btn" style="margin-top:0.5rem;">Add Router</button>
    <button type="button" class="ghost" id="wireless-import-btn" style="margin-top:0.5rem;">Pilih dari PPPoE Active</button>
</div>

<section class="card">
    <h2>Wireless Registration (AP)</h2>
    <div style="margin-bottom:0.5rem; display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
        <button type="button" class="ghost" id="wireless-reg-refresh">Refresh</button>
        <label style="display:flex; align-items:center; gap:0.35rem;">
            <span>Filter</span>
            <input type="search" id="wireless-reg-filter" placeholder="cari router / radio">
        </label>
        <label style="display:flex; align-items:center; gap:0.35rem;">
            <span>Lokasi</span>
            <select id="wireless-location-filter">
                <option value="">Semua</option>
                <?php foreach ($locationOptions as $loc): ?>
                    <option value="<?php echo htmlspecialchars($loc); ?>"><?php echo htmlspecialchars($loc); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label style="display:flex; align-items:center; gap:0.3rem;">
            <span>Auto reload (detik)</span>
            <select id="wireless-reg-interval">
                <option value="0">Off</option>
                <option value="1">1</option>
                <option value="2">2</option>
                <option value="3">3</option>
                <option value="4">4</option>
                <option value="5">5</option>
            </select>
        </label>
        <span id="wireless-reg-status" class="muted"></span>
    </div>
    <div id="wireless-reg-errors" class="alert" style="display:none; margin-bottom:0.5rem;"></div>
    <div id="wireless-reg-container" class="reg-container"></div>
</section>

<div id="wireless-add-modal" class="modal-backdrop" style="display:none;">
    <div class="modal" style="max-width:480px;">
        <header>Tambah Router AP</header>
        <div style="display:grid; gap:0.6rem;">
            <label style="display:flex; flex-direction:column; gap:0.3rem;">
                <span>Pilih Router (kategori server)</span>
                <select id="ap-server">
                    <option value="">-- Pilih --</option>
                    <?php foreach ($servers as $srv): ?>
                        <option value="<?php echo htmlspecialchars($srv['id'] ?? ''); ?>"
                            data-name="<?php echo htmlspecialchars($srv['name'] ?? ''); ?>"
                            data-host="<?php echo htmlspecialchars($srv['host'] ?? ''); ?>"
                            data-location="<?php echo htmlspecialchars($srv['location'] ?? ''); ?>"
                            data-username="<?php echo htmlspecialchars($srv['username'] ?? ''); ?>"
                            data-password="<?php echo htmlspecialchars($srv['password'] ?? ''); ?>">
                            <?php echo htmlspecialchars(($srv['name'] ?? '') . ' (' . ($srv['host'] ?? '') . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="display:flex; flex-direction:column; gap:0.3rem;">
                <span>Nama</span>
                <input type="text" id="ap-name" required>
            </label>
            <label style="display:flex; flex-direction:column; gap:0.3rem;">
                <span>Host/IP</span>
                <input type="text" id="ap-host" required>
            </label>
            <label style="display:flex; flex-direction:column; gap:0.3rem;">
                <span>Lokasi</span>
                <input type="text" id="ap-location" list="ap-location-list">
                <datalist id="ap-location-list">
                    <?php foreach ($locations as $loc): ?>
                        <option value="<?php echo htmlspecialchars($loc); ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </label>
            <label style="display:flex; flex-direction:column; gap:0.3rem;">
                <span>Username</span>
                <input type="text" id="ap-username">
            </label>
            <label style="display:flex; flex-direction:column; gap:0.3rem;">
                <span>Password</span>
                <input type="text" id="ap-password">
            </label>
            <label style="display:flex; flex-direction:column; gap:0.3rem;">
                <span>Catatan</span>
                <input type="text" id="ap-notes">
            </label>
            <input type="hidden" id="ap-category" value="ap">
            <div class="status" id="ap-status" style="min-height:1rem;"></div>
        </div>
        <footer>
            <button type="button" class="ghost" id="ap-cancel">Batal</button>
            <button type="button" id="ap-save">Simpan</button>
        </footer>
    </div>
</div>

<div id="wireless-import-modal" class="modal-backdrop" style="display:none;">
    <div class="modal dark" style="max-width:720px; width:90%;">
        <header>Pilih AP dari PPPoE Active</header>
        <div style="display:flex; gap:0.5rem; margin-bottom:0.5rem;">
            <input type="search" id="import-search" placeholder="Filter identity atau IP..." style="flex:1;">
            <button class="pill-btn ghost" id="import-refresh">Segarkan</button>
        </div>
        <div class="status" id="import-status"></div>
        <div class="list" id="import-list" style="margin:0.5rem 0;"></div>
        <footer><button type="button" class="pill-btn ghost" id="import-close">Tutup</button></footer>
    </div>
</div>

<style>
.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.45);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 50;
}
.modal {
    background: #fff;
    border-radius: 12px;
    padding: 1rem;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
}
.modal header { font-weight: 700; margin-bottom: 0.5rem; }
.modal footer { display: flex; gap: 0.5rem; justify-content: flex-end; margin-top: 1rem; }
.modal .status { font-size: 0.9rem; color: #666; }
.modal.dark {
    background: #0f1c2f;
    color: #dbe8ff;
    border: 1px solid #1f3558;
}
.modal.dark header { color: #fff; }
.modal.dark input, .modal.dark select {
    background: #102544;
    color: #e8f0ff;
    border: 1px solid #1f3b63;
}
.modal.dark .list {
    max-height: 320px;
    overflow: auto;
    border: 1px solid #1f3b63;
    border-radius: 8px;
}
.pill-btn {
    padding: 0.35rem 0.8rem;
    border-radius: 999px;
    border: none;
    cursor: pointer;
    background: #2d7dff;
    color: #fff;
    font-weight: 700;
}
.pill-btn.ghost {
    background: transparent;
    border: 1px solid #2d7dff;
    color: #2d7dff;
}
.reg-container {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}
.reg-location {
    background: #0f1c2f;
    color: #dbe8ff;
    border: 1px solid #1f3558;
    border-radius: 12px;
    padding: 0.75rem;
}
.reg-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
    font-weight: 700;
}
.reg-router {
    background: #111f35;
    border: 1px solid #1f3558;
    border-radius: 10px;
    padding: 0.75rem;
    margin-top: 0.5rem;
}
.reg-router .small {
    color: #9bb0d4;
    font-size: 0.9rem;
}
.reg-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 0.5rem;
    min-width: 720px;
}
.reg-table th,
.reg-table td {
    padding: 0.35rem;
    text-align: left;
    border-bottom: 1px solid #1f3558;
    white-space: nowrap;
}
.reg-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 8px;
    color: #0f1c2f;
    font-weight: 700;
}
.reg-badge.signal-ok { background: #9cffd0; }
.reg-badge.signal-mid { background: #ffd27f; }
.reg-badge.signal-bad { background: #ff8a8a; }
.reg-router {
    overflow-x: auto;
}

@media (max-width: 820px) {
    .reg-location {
        padding: 0.6rem;
    }
    .reg-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.2rem;
    }
    .reg-router {
        padding: 0.6rem;
    }
    .reg-table {
        min-width: 600px;
    }
    .reg-table th,
    .reg-table td {
        padding: 0.3rem;
        font-size: 0.92rem;
    }
}

@media (max-width: 640px) {
    .reg-table {
        min-width: 520px;
    }
    .reg-header {
        font-size: 0.95rem;
    }
}
</style>

<script>
(function(){
    var addBtn = document.getElementById('wireless-add-btn');
    var modal = document.getElementById('wireless-add-modal');
    var nameInput = document.getElementById('ap-name');
    var hostInput = document.getElementById('ap-host');
    var locInput = document.getElementById('ap-location');
    var userInput = document.getElementById('ap-username');
    var passInput = document.getElementById('ap-password');
    var notesInput = document.getElementById('ap-notes');
    var statusBox = document.getElementById('ap-status');
    var saveBtn = document.getElementById('ap-save');
    var cancelBtn = document.getElementById('ap-cancel');
    var tableBody = document.getElementById('wireless-table-body');
    var serverSelect = document.getElementById('ap-server');
    var serversMap = <?php echo json_encode(array_column($servers, null, 'id'), JSON_UNESCAPED_SLASHES); ?>;
    var importModal = document.getElementById('wireless-import-modal');
    var importSearch = document.getElementById('import-search');
    var importRefresh = document.getElementById('import-refresh');
    var importStatus = document.getElementById('import-status');
    var importList = document.getElementById('import-list');
    var importClose = document.getElementById('import-close');
    var regRefresh = document.getElementById('wireless-reg-refresh');
    var regStatus = document.getElementById('wireless-reg-status');
    var regErrors = document.getElementById('wireless-reg-errors');
    var regContainer = document.getElementById('wireless-reg-container');
    var regInterval = document.getElementById('wireless-reg-interval');
    var regTimer = null;
    var regFilter = document.getElementById('wireless-reg-filter');
    var locationFilter = document.getElementById('wireless-location-filter');
    var regData = null;
    var regFilterTimer = null;

    function openModal() {
        if (!modal) return;
        modal.style.display = 'flex';
        statusBox.textContent = '';
        nameInput.value = '';
        hostInput.value = '';
        locInput.value = '';
        userInput.value = 'rondi';
        passInput.value = '21184662';
        notesInput.value = '';
        if (serverSelect) serverSelect.value = '';
    }
    function closeModal() {
        if (modal) modal.style.display = 'none';
    }

    addBtn && addBtn.addEventListener('click', openModal);
    var importBtn = document.getElementById('wireless-import-btn');
    importBtn && importBtn.addEventListener('click', function(){
        if (importModal) {
            importModal.style.display = 'flex';
            loadImportData();
        }
    });

    cancelBtn && cancelBtn.addEventListener('click', closeModal);
    modal && modal.addEventListener('click', function(e){
        if (e.target === modal) closeModal();
    });

    serverSelect && serverSelect.addEventListener('change', function(){
        var rid = serverSelect.value;
        if (rid && serversMap[rid]) {
            var srv = serversMap[rid];
            nameInput.value = srv.name || '';
            hostInput.value = srv.host || '';
            locInput.value = srv.location || '';
            userInput.value = srv.username || '';
            passInput.value = srv.password || '';
        }
    });

    saveBtn && saveBtn.addEventListener('click', function(){
        var payload = {
            name: nameInput.value.trim(),
            host: hostInput.value.trim(),
            location: locInput.value.trim(),
            username: userInput.value.trim(),
            password: passInput.value.trim(),
            notes: notesInput.value.trim(),
            category: 'ap',
            router_id: serverSelect ? serverSelect.value : ''
        };
        if (!payload.name || !payload.host) {
            statusBox.textContent = 'Nama dan Host/IP wajib diisi.';
            return;
        }
        statusBox.textContent = 'Menyimpan...';
        saveBtn.disabled = true;
        fetch('wireless_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function(res){ return res.json(); })
        .then(function(json){
            if (json.error) throw new Error(json.error);
            statusBox.textContent = 'Berhasil ditambah.';
            appendRow(payload);
            setTimeout(closeModal, 500);
        })
        .catch(function(err){
            statusBox.textContent = 'Gagal: ' + err.message;
        })
        .finally(function(){
            saveBtn.disabled = false;
        });
    });

    function appendRow(item) {
        if (!tableBody) return;
        if (tableBody.children.length === 1 && tableBody.children[0].children.length === 1) {
            tableBody.innerHTML = '';
        }
        var tr = document.createElement('tr');
        ['name','host','location','username','notes'].forEach(function(key){
            var td = document.createElement('td');
            td.textContent = item[key] || '';
            tr.appendChild(td);
        });
        tableBody.appendChild(tr);
    }

    // Import PPPoE Active handlers
    importClose && importClose.addEventListener('click', function(){
        if (importModal) importModal.style.display = 'none';
    });
    importModal && importModal.addEventListener('click', function(e){
        if (e.target === importModal) importModal.style.display = 'none';
    });
    importRefresh && importRefresh.addEventListener('click', loadImportData);
    importSearch && importSearch.addEventListener('input', applyImportFilter);
    regRefresh && regRefresh.addEventListener('click', loadRegister);
    regInterval && regInterval.addEventListener('change', startRegInterval);
    regFilter && regFilter.addEventListener('input', function(){
        scheduleRegRefresh();
    });
    locationFilter && locationFilter.addEventListener('change', function(){
        loadRegister();
    });

    function loadImportData() {
        if (!importStatus) return;
        importStatus.textContent = 'Memuat...';
        fetch('pppoe_data.php')
            .then(function(res){ return res.json(); })
            .then(function(json){
                if (json.error) throw new Error(json.error);
                renderImportList(json);
                var total = (json.summary && json.summary.total) || 0;
                importStatus.textContent = 'Menemukan ' + total + ' akun PPPoE.';
            })
            .catch(function(err){
                importStatus.textContent = 'Gagal memuat: ' + err.message;
            });
    }

    function renderImportList(data) {
        if (!importList) return;
        importList.innerHTML = '';
        var items = [];
        (data.active || []).forEach(function(a){
            items.push({
                username: a.username || '',
                ip: a.ip_address || '',
                profile: a.profile || '',
                router: a.router_name || '',
                routerId: a.router_id || '',
                status: 'AKTIF'
            });
        });
        (data.inactive_users || []).forEach(function(u){
            items.push({
                username: u.username || '',
                ip: '',
                profile: u.profile || '',
                router: u.router_name || '',
                routerId: u.router_id || '',
                status: 'TIDAK AKTIF'
            });
        });
        items.sort(function(a,b){ return a.username.localeCompare(b.username); });
        if (items.length === 0) {
            importList.innerHTML = '<div style="padding:0.5rem; color:#b9c7e5;">Tidak ada data.</div>';
            return;
        }
        items.forEach(function(item){
            var card = document.createElement('div');
            card.style.padding = '0.5rem';
            card.style.borderBottom = '1px solid #1f3558';
            card.innerHTML = '' +
                '<div style="font-weight:700;">' + escapeHtml(item.username) + '</div>' +
                '<div style="color:#9bb0d4; font-size:0.9rem;">' + (item.ip ? escapeHtml(item.ip) : '-') + '</div>' +
                '<div style="color:#c4d2f0; font-size:0.9rem;">' + escapeHtml(item.router) + '</div>' +
                '<div style="display:flex; align-items:center; gap:0.5rem; margin-top:0.25rem;">' +
                    '<span style="color:#7bd6ff; font-weight:700;">' + escapeHtml(item.status) + '</span>' +
                    '<span style="color:#ffdd8a;">' + escapeHtml(item.profile) + '</span>' +
                    '<button type="button" class="pill-btn ghost import-pick">Pilih</button>' +
                '</div>';
            card.querySelector('.import-pick').addEventListener('click', function(){
                // prefilling add modal with username/password same, host ip/username
                openModal();
                nameInput.value = item.username;
                hostInput.value = item.ip || item.username;
                userInput.value = 'rondi';
                passInput.value = '21184662';
                notesInput.value = 'from PPPoE ' + item.status.toLowerCase();
                if (serverSelect && item.routerId && serversMap[item.routerId]) {
                    serverSelect.value = item.routerId;
                }
                importModal.style.display = 'none';
            });
            importList.appendChild(card);
        });
        applyImportFilter();
    }

    function applyImportFilter() {
        if (!importList || !importSearch) return;
        var q = importSearch.value.toLowerCase().trim();
        Array.prototype.forEach.call(importList.children, function(card){
            var hay = card.textContent.toLowerCase();
            card.style.display = (!q || hay.indexOf(q) !== -1) ? '' : 'none';
        });
    }

    function escapeHtml(str) {
        return String(str || '').replace(/[&<>"']/g, function (m) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]);
        });
    }

    function loadRegister() {
        if (regStatus) regStatus.textContent = 'Memuat...';
        var params = new URLSearchParams();
        var filters = getRegFilters();
        if (filters.q) params.append('q', filters.q);
        if (filters.location) params.append('location', filters.location);
        var url = 'wireless_register.php' + (params.toString() ? ('?' + params.toString()) : '');

        fetch(url)
            .then(function(res){ return res.json(); })
            .then(function(json){
                if (json.error) throw new Error(json.error);
                regData = json;
                renderRegister(regData);
                var errCount = (json.errors && json.errors.length) ? json.errors.length : 0;
                if (regStatus) {
                    var err = errCount ? ' (' + errCount + ' error)' : '';
                    regStatus.textContent = 'Memuat ' + ((json.data && json.data.length) || 0) + ' client' + err;
                }
                if (regErrors) {
                    if (errCount) {
                        regErrors.style.display = 'block';
                        regErrors.innerHTML = json.errors.map(function(e){ return '<div>' + escapeHtml(e) + '</div>'; }).join('');
                    } else {
                        regErrors.style.display = 'none';
                        regErrors.innerHTML = '';
                    }
                }
            })
            .catch(function(err){
                if (regStatus) regStatus.textContent = 'Gagal memuat: ' + err.message;
                if (regContainer) regContainer.innerHTML = '<div>Gagal memuat: ' + err.message + '</div>';
                if (regErrors) { regErrors.style.display = 'none'; regErrors.innerHTML = ''; }
            });
    }

    function renderRegister(json) {
        if (!regContainer) return;
        regContainer.innerHTML = '';
        var list = (json && json.data) || [];
        if (list.length === 0) {
            regContainer.innerHTML = '<div>Tidak ada data.</div>';
            return;
        }
        var grouped = {};
        list.forEach(function(item){
            var loc = (item.router_location || 'Tanpa lokasi').toLowerCase();
            if (!grouped[loc]) grouped[loc] = { name: loc.toUpperCase(), routers: {} };
            var rkey = item.router_name || 'Router';
            if (!grouped[loc].routers[rkey]) {
                grouped[loc].routers[rkey] = [];
            }
            // apply filter on router name or radio name
            grouped[loc].routers[rkey].push(item);
        });

        var locIndex = 1;
        Object.keys(grouped).sort().forEach(function(locKey){
            var section = grouped[locKey];
            var locDiv = document.createElement('div');
            locDiv.className = 'reg-location';
            locDiv.innerHTML = '<div class="reg-header"><div>' + (locIndex++) + '. ' + section.name + '</div><div>' + Object.keys(section.routers).length + ' router</div></div>';

            Object.keys(section.routers).sort().forEach(function(rname){
                var clients = section.routers[rname];
                var routerCard = document.createElement('div');
                routerCard.className = 'reg-router';
                var clientCount = clients.length;
                var freqText = clients[0] && clients[0].frequency ? clients[0].frequency + ' MHz' : '';
                routerCard.innerHTML = '<div class="reg-header"><div>' + rname + '</div><div class="small">' + clientCount + ' client ' + (freqText ? '(' + freqText + ')' : '') + '</div></div>';

            var table = document.createElement('table');
            table.className = 'reg-table';
            table.innerHTML = '<thead><tr><th>Radio</th><th>Signal</th><th>TX</th><th>RX</th><th>Uptime</th><th>MAC</th><th>Last IP</th><th>Radio Name</th></tr></thead>';
            var tbody = document.createElement('tbody');
            clients.forEach(function(c){
                var tr = document.createElement('tr');
                var sig = c.signal || '';
                var badgeClass = 'signal-mid';
                var sVal = parseInt(sig, 10);
                if (!isNaN(sVal)) {
                    if (sVal >= -55) badgeClass = 'signal-ok';
                    else if (sVal <= -70) badgeClass = 'signal-bad';
                }
                tr.innerHTML = '' +
                    '<td>' + (c.radio_name || '') + '</td>' +
                    '<td><span class="reg-badge ' + badgeClass + '">' + sig + '</span></td>' +
                    '<td>' + (c.tx_rate || '') + '</td>' +
                    '<td>' + (c.rx_rate || '') + '</td>' +
                    '<td>' + (c.uptime || '') + '</td>' +
                    '<td>' + (c.mac || '') + '</td>' +
                    '<td>' + (c.last_ip || '') + '</td>' +
                    '<td>' + (c.interface || '') + '</td>';
                tbody.appendChild(tr);
            });
            table.appendChild(tbody);
            routerCard.appendChild(table);
            locDiv.appendChild(routerCard);
        });
        regContainer.appendChild(locDiv);
        });
    }

    function startRegInterval() {
        stopRegInterval();
        var sec = regInterval ? parseInt(regInterval.value, 10) : 0;
        if (sec && sec > 0) {
            loadRegister();
            regTimer = setInterval(loadRegister, sec * 1000);
        }
    }

    function stopRegInterval() {
        if (regTimer) {
            clearInterval(regTimer);
            regTimer = null;
        }
    }

    function scheduleRegRefresh() {
        if (regFilterTimer) clearTimeout(regFilterTimer);
        regFilterTimer = setTimeout(loadRegister, 400);
    }

    function getRegFilters() {
        return {
            q: regFilter ? regFilter.value.trim() : '',
            location: locationFilter ? locationFilter.value.trim() : ''
        };
    }

    // initial load
    loadRegister();
})();
</script>
