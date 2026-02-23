<?php
$routersFile = __DIR__ . '/../../storage/mikrotik.json';
$routers = file_exists($routersFile) ? json_decode((string) file_get_contents($routersFile), true) : [];
if (!is_array($routers)) {
    $routers = [];
}
$serverRouters = array_values(array_filter($routers, static function ($row) {
    return is_array($row) && strtolower(trim((string) ($row['category'] ?? ''))) === 'server';
}));
usort($serverRouters, static function ($a, $b) {
    return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});
?>

<div class="page-head">
    <h1>Manage PPPoE</h1>
    <p>Kelola user PPPoE aktif dan tidak aktif dari semua server.</p>
</div>

<section class="card">
    <div class="manage-head">
        <button type="button" class="ghost" id="manage-pppoe-refresh">Refresh</button>
        <label class="manage-field">
            <span>Search User</span>
            <input type="search" id="manage-pppoe-user-search" placeholder="username PPPoE">
        </label>
        <label class="manage-field">
            <span>Cari</span>
            <input type="search" id="manage-pppoe-search" placeholder="username / profile / server / IP">
        </label>
        <label class="manage-field">
            <span>Server tujuan</span>
            <select id="manage-pppoe-target-server">
                <option value="">-- Pilih server --</option>
                <?php foreach ($serverRouters as $srv): ?>
                    <option value="<?php echo htmlspecialchars((string) ($srv['id'] ?? '')); ?>">
                        <?php echo htmlspecialchars((string) ($srv['name'] ?? '')); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="button" id="manage-pppoe-move-btn">Pindahkan user terpilih (0)</button>
        <button type="button" class="ghost" id="manage-pppoe-delete-btn">Hapus Secret terpilih (0)</button>
    </div>
    <div class="manage-head" style="margin-top:0.5rem;">
        <label class="manage-check">
            <input type="checkbox" id="manage-pppoe-remove-server1" checked>
            <span>Hapus akun yang sama di SERVER 1</span>
        </label>
        <label class="manage-field">
            <span>Server 1</span>
            <select id="manage-pppoe-server1-select">
                <option value="">Auto detect (nama mengandung SERVER 1)</option>
                <?php foreach ($serverRouters as $srv): ?>
                    <option value="<?php echo htmlspecialchars((string) ($srv['id'] ?? '')); ?>">
                        <?php echo htmlspecialchars((string) ($srv['name'] ?? '')); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <span id="manage-pppoe-status" class="muted"></span>
    </div>
    <div class="summary-grid" id="manage-pppoe-summary">
        <div class="summary-box"><span>Total</span><strong id="sum-total">0</strong></div>
        <div class="summary-box"><span>Aktif</span><strong id="sum-active">0</strong></div>
        <div class="summary-box"><span>Tidak Aktif</span><strong id="sum-inactive">0</strong></div>
        <div class="summary-box"><span>Router Error</span><strong id="sum-failed-router">0</strong></div>
    </div>
</section>

<section class="card">
    <h2 style="margin:0 0 0.35rem;">Rekap Semua Server</h2>
    <p class="muted" style="margin:0 0 0.65rem;">
        1 baris = 1 username. Status per server membantu deteksi akun duplikat.
    </p>
    <div class="manage-head" style="margin-bottom:0.55rem;">
        <button type="button" class="ghost" id="manage-pppoe-matrix-delete-selected">Hapus Duplikat Tidak Aktif Terpilih (0)</button>
        <label class="manage-field">
            <span>Cari User Rekap</span>
            <input type="search" id="manage-pppoe-matrix-user-search" placeholder="username rekap">
        </label>
        <label class="manage-check">
            <input type="checkbox" id="manage-pppoe-matrix-duplicate-only">
            <span>Hanya tampilkan user duplikat</span>
        </label>
        <label class="manage-field">
            <span>Filter User</span>
            <select id="manage-pppoe-matrix-user-filter">
                <option value="all">Semua</option>
                <option value="has_active">Punya akun aktif</option>
                <option value="all_inactive">Semua tidak aktif</option>
                <option value="duplicate_inactive">Duplikat tidak aktif</option>
            </select>
        </label>
        <span class="muted">Centang user yang duplikat untuk hapus akun yang tidak aktif.</span>
    </div>
    <div class="table-wrapper">
        <table class="table-responsive" id="manage-pppoe-matrix-table">
            <thead id="manage-pppoe-matrix-head">
                <tr>
                    <th style="width:70px;">No</th>
                    <th style="width:56px; text-align:center;">
                        <input type="checkbox" id="manage-pppoe-matrix-check-all" aria-label="Pilih semua duplikat">
                    </th>
                    <th>Username</th>
                    <th>Duplikat</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody id="manage-pppoe-matrix-body">
                <tr><td colspan="5">Memuat rekap semua server...</td></tr>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <div class="tabs">
        <button type="button" class="tab-btn is-active" data-tab="active" id="tab-active">Aktif (0)</button>
        <button type="button" class="tab-btn" data-tab="inactive" id="tab-inactive">Tidak Aktif (0)</button>
    </div>
    <div class="manage-head" style="margin-top:0.6rem;">
        <label class="manage-field">
            <span>Filter Server</span>
            <select id="manage-pppoe-filter-server">
                <option value="">Semua server</option>
                <?php foreach ($serverRouters as $srv): ?>
                    <option value="<?php echo htmlspecialchars((string) ($srv['id'] ?? '')); ?>">
                        <?php echo htmlspecialchars((string) ($srv['name'] ?? '')); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="manage-field">
            <span>Cari User Tabel</span>
            <input type="search" id="manage-pppoe-table-user-search" placeholder="username tab aktif/tidak aktif">
        </label>
        <label class="manage-field">
            <span>Per halaman</span>
            <select id="manage-pppoe-page-size">
                <option value="10">10</option>
                <option value="25" selected>25</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </select>
        </label>
        <div class="manage-pager">
            <button type="button" class="ghost" id="manage-pppoe-page-prev">Prev</button>
            <span id="manage-pppoe-page-info" class="muted">Halaman 1/1</span>
            <button type="button" class="ghost" id="manage-pppoe-page-next">Next</button>
        </div>
    </div>
    <div id="manage-pppoe-errors" class="alert" style="display:none; margin:0.6rem 0;"></div>
    <div id="manage-pppoe-result" class="alert" style="display:none; margin:0.6rem 0;"></div>
    <div class="table-wrapper">
        <table class="table-responsive">
            <thead>
                <tr>
                    <th style="width:70px;">No</th>
                    <th style="width:56px; text-align:center;">
                        <input type="checkbox" id="manage-pppoe-check-all" aria-label="Pilih semua">
                    </th>
                    <th>Username <button type="button" class="ghost sort-btn" data-sort="username">Sort</button></th>
                    <th>Profile <button type="button" class="ghost sort-btn" data-sort="profile">Sort</button></th>
                    <th>Server <button type="button" class="ghost sort-btn" data-sort="router_name">Sort</button></th>
                    <th>Status <button type="button" class="ghost sort-btn" data-sort="status">Sort</button></th>
                    <th>IP <button type="button" class="ghost sort-btn" data-sort="ip_address">Sort</button></th>
                    <th>Uptime <button type="button" class="ghost sort-btn" data-sort="uptime">Sort</button></th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody id="manage-pppoe-body">
                <tr><td colspan="9">Memuat data...</td></tr>
            </tbody>
        </table>
    </div>
</section>

<style>
.manage-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.6rem;
}
.manage-field {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
}
.manage-check {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
}
.manage-pager {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
}
.sort-btn {
    margin-left: 0.3rem;
    padding: 0.15rem 0.42rem;
    font-size: 0.78rem;
    line-height: 1.1;
}
.matrix-status {
    display: inline-block;
    border-radius: 999px;
    padding: 0.2rem 0.5rem;
    font-size: 0.75rem;
    font-weight: 700;
    white-space: nowrap;
}
.matrix-active {
    background: #dcfce7;
    color: #166534;
}
.matrix-inactive {
    background: #fee2e2;
    color: #991b1b;
}
.matrix-absent {
    background: #f1f5f9;
    color: #475569;
}
.matrix-unknown {
    background: #fff7ed;
    color: #9a3412;
}
.summary-grid {
    margin-top: 0.75rem;
    display: grid;
    gap: 0.6rem;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
}
.summary-box {
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    background: #f8fafc;
    padding: 0.65rem 0.8rem;
}
.summary-box span {
    display: block;
    color: #64748b;
    font-size: 0.85rem;
}
.summary-box strong {
    display: block;
    margin-top: 0.2rem;
    font-size: 1.45rem;
}
.tabs {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}
.tab-btn {
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    background: #fff;
    color: #0f172a;
    padding: 0.5rem 0.8rem;
    font-weight: 700;
    cursor: pointer;
}
.tab-btn.is-active {
    background: #1d4ed8;
    border-color: #1d4ed8;
    color: #fff;
}
.status-badge {
    display: inline-block;
    border-radius: 999px;
    padding: 0.2rem 0.55rem;
    font-weight: 700;
    font-size: 0.8rem;
}
.status-active {
    background: #dcfce7;
    color: #166534;
}
.status-inactive {
    background: #fee2e2;
    color: #991b1b;
}
@media (max-width: 760px) {
    .manage-field {
        width: 100%;
        justify-content: space-between;
    }
    .manage-field input,
    .manage-field select {
        width: 64%;
    }
}
</style>

<script>
(function () {
    var btnRefresh = document.getElementById('manage-pppoe-refresh');
    var btnMove = document.getElementById('manage-pppoe-move-btn');
    var btnDelete = document.getElementById('manage-pppoe-delete-btn');
    var userSearchInput = document.getElementById('manage-pppoe-user-search');
    var searchInput = document.getElementById('manage-pppoe-search');
    var targetServer = document.getElementById('manage-pppoe-target-server');
    var removeServer1 = document.getElementById('manage-pppoe-remove-server1');
    var server1Select = document.getElementById('manage-pppoe-server1-select');
    var statusBox = document.getElementById('manage-pppoe-status');
    var errorsBox = document.getElementById('manage-pppoe-errors');
    var resultBox = document.getElementById('manage-pppoe-result');
    var matrixTable = document.getElementById('manage-pppoe-matrix-table');
    var matrixHead = document.getElementById('manage-pppoe-matrix-head');
    var matrixBody = document.getElementById('manage-pppoe-matrix-body');
    var matrixDeleteSelectedBtn = document.getElementById('manage-pppoe-matrix-delete-selected');
    var matrixUserSearch = document.getElementById('manage-pppoe-matrix-user-search');
    var matrixDuplicateOnly = document.getElementById('manage-pppoe-matrix-duplicate-only');
    var matrixUserFilter = document.getElementById('manage-pppoe-matrix-user-filter');
    var tableBody = document.getElementById('manage-pppoe-body');
    var checkAll = document.getElementById('manage-pppoe-check-all');
    var tabActive = document.getElementById('tab-active');
    var tabInactive = document.getElementById('tab-inactive');
    var filterServer = document.getElementById('manage-pppoe-filter-server');
    var tableUserSearch = document.getElementById('manage-pppoe-table-user-search');
    var pageSizeSelect = document.getElementById('manage-pppoe-page-size');
    var pagePrev = document.getElementById('manage-pppoe-page-prev');
    var pageNext = document.getElementById('manage-pppoe-page-next');
    var pageInfo = document.getElementById('manage-pppoe-page-info');
    var sortButtons = document.querySelectorAll('.sort-btn');

    var sumTotal = document.getElementById('sum-total');
    var sumActive = document.getElementById('sum-active');
    var sumInactive = document.getElementById('sum-inactive');
    var sumFailedRouter = document.getElementById('sum-failed-router');

    var currentTab = 'active';
    var serverDefs = [];
    var allRows = [];
    var usersMatrix = [];
    var filteredRows = [];
    var selectedRows = new Map();
    var selectedMatrixUsers = new Map();
    var searchTimer = null;
    var currentPage = 1;
    var sortKey = 'username';
    var sortDir = 'asc';

    function setResult(message, isError) {
        if (!resultBox) return;
        if (!message) {
            resultBox.style.display = 'none';
            resultBox.innerHTML = '';
            return;
        }
        resultBox.style.display = 'block';
        resultBox.style.background = isError ? '#fee2e2' : '#dcfce7';
        resultBox.style.color = isError ? '#991b1b' : '#166534';
        resultBox.style.borderColor = isError ? '#fecaca' : '#86efac';
        resultBox.innerHTML = message;
    }

    function setErrors(payload) {
        if (!errorsBox) return;
        var failedRouters = Array.isArray(payload.failed_routers) ? payload.failed_routers : [];
        var rawErrors = Array.isArray(payload.errors) ? payload.errors : [];
        if (failedRouters.length === 0 && rawErrors.length === 0) {
            errorsBox.style.display = 'none';
            errorsBox.innerHTML = '';
            return;
        }

        var html = '';
        if (failedRouters.length > 0) {
            html += '<div><strong>Router tidak bisa terhubung:</strong></div>';
            html += failedRouters.map(function (row) {
                var name = row && row.name ? row.name : (row && row.host ? row.host : '-');
                var host = row && row.host ? (' (' + row.host + ')') : '';
                var reason = row && row.reason ? row.reason : 'Unknown';
                return '<div>' + escapeHtml(name + host + ': ' + reason) + '</div>';
            }).join('');
        }
        if (rawErrors.length > 0) {
            html += '<details style="margin-top:0.35rem;"><summary>Detail error</summary>';
            html += rawErrors.map(function (msg) {
                return '<div>' + escapeHtml(msg) + '</div>';
            }).join('');
            html += '</details>';
        }
        errorsBox.style.display = 'block';
        errorsBox.innerHTML = html;
    }

    function updateSummary(payload) {
        var summary = payload && payload.summary ? payload.summary : {};
        var total = Number(summary.total || 0);
        var active = Number(summary.active || 0);
        var inactive = Number(summary.inactive || 0);
        var failed = Number(summary.server_failed || 0);

        if (sumTotal) sumTotal.textContent = total;
        if (sumActive) sumActive.textContent = active;
        if (sumInactive) sumInactive.textContent = inactive;
        if (sumFailedRouter) sumFailedRouter.textContent = failed;
        if (tabActive) tabActive.textContent = 'Aktif (' + active + ')';
        if (tabInactive) tabInactive.textContent = 'Tidak Aktif (' + inactive + ')';
    }

    function renderMatrix() {
        if (!matrixHead || !matrixBody) return;

        var qUserGlobal = (userSearchInput && userSearchInput.value ? userSearchInput.value : '').toLowerCase().trim();
        var qUserMatrix = (matrixUserSearch && matrixUserSearch.value ? matrixUserSearch.value : '').toLowerCase().trim();
        var q = (searchInput && searchInput.value ? searchInput.value : '').toLowerCase().trim();
        var duplicateOnly = !!(matrixDuplicateOnly && matrixDuplicateOnly.checked);
        var userFilterMode = matrixUserFilter ? String(matrixUserFilter.value || 'all') : 'all';

        var visibleRows = usersMatrix.filter(function (row) {
            var uname = String(row.username || '').toLowerCase();
            if (duplicateOnly && Number(row.duplicate_count || 0) <= 1) {
                return false;
            }
            if (userFilterMode === 'has_active' && Number(row.active_count || 0) <= 0) {
                return false;
            }
            if (userFilterMode === 'all_inactive') {
                var dupCount = Number(row.duplicate_count || 0);
                var activeCount = Number(row.active_count || 0);
                var inactiveCount = Number(row.inactive_count || 0);
                if (!(dupCount > 0 && activeCount === 0 && inactiveCount > 0)) {
                    return false;
                }
            }
            if (userFilterMode === 'duplicate_inactive' && !row.has_duplicate_inactive) {
                return false;
            }
            if (qUserGlobal && uname.indexOf(qUserGlobal) === -1) {
                return false;
            }
            if (qUserMatrix && uname.indexOf(qUserMatrix) === -1) {
                return false;
            }
            if (!q) return true;
            if (uname.indexOf(q) !== -1) return true;
            var servers = row.servers || {};
            for (var sid in servers) {
                if (!Object.prototype.hasOwnProperty.call(servers, sid)) continue;
                var cell = servers[sid] || {};
                var hay = [
                    cell.router_name || '',
                    cell.profile || '',
                    cell.ip_address || '',
                    cell.status || ''
                ].join(' ').toLowerCase();
                if (hay.indexOf(q) !== -1) return true;
            }
            return false;
        });

        var headHtml = '<tr>' +
            '<th style="width:70px;">No</th>' +
            '<th style="width:56px; text-align:center;"><input type="checkbox" id="manage-pppoe-matrix-check-all" aria-label="Pilih semua duplikat"></th>' +
            '<th>Username</th>' +
            '<th>Duplikat</th>';
        serverDefs.forEach(function (srv) {
            headHtml += '<th>' + escapeHtml(srv.name || ('Server ' + (srv.id || ''))) + '</th>';
        });
        headHtml += '<th>Aksi</th></tr>';
        matrixHead.innerHTML = headHtml;

        matrixBody.innerHTML = '';
        if (visibleRows.length === 0) {
            matrixBody.innerHTML = '<tr><td colspan="' + (5 + serverDefs.length) + '">Tidak ada data rekap.</td></tr>';
            updateMatrixDeleteButtonLabel();
            return;
        }

        visibleRows.forEach(function (row, index) {
            var matrixKey = getMatrixRowKey(row);
            var selectable = !!row.has_duplicate_inactive;
            var checked = selectable && selectedMatrixUsers.has(matrixKey);
            var tr = document.createElement('tr');
            var html = '' +
                '<td data-label="No">' + (index + 1) + '</td>' +
                '<td data-label="Pilih" style="text-align:center;">' +
                    '<input type="checkbox" class="matrix-row-check" data-matrix-key="' + escapeAttr(matrixKey) + '" ' + (checked ? 'checked ' : '') + (selectable ? '' : 'disabled ') + '>' +
                '</td>' +
                '<td data-label="Username">' + escapeHtml(row.username || '') + '</td>' +
                '<td data-label="Duplikat">' + String(row.duplicate_count || 0) +
                    ' <span class="muted" style="font-size:0.78rem;">(A:' + String(row.active_count || 0) + ' / T:' + String(row.inactive_count || 0) + ')</span></td>';

            serverDefs.forEach(function (srv) {
                var sid = String(srv.id || '');
                var cell = (row.servers && row.servers[sid]) ? row.servers[sid] : null;
                html += '<td data-label="' + escapeAttr(srv.name || sid) + '">' + renderMatrixCell(cell) + '</td>';
            });

            var rowJson = encodeURIComponent(JSON.stringify(row));
            if (row.has_duplicate_inactive) {
                html += '<td data-label="Aksi"><button type="button" class="ghost matrix-delete-inactive" data-row="' + rowJson + '">Hapus Duplikat Tidak Aktif</button></td>';
            } else {
                html += '<td data-label="Aksi"><span class="muted">-</span></td>';
            }

            tr.innerHTML = html;
            matrixBody.appendChild(tr);
        });

        Array.prototype.forEach.call(matrixBody.querySelectorAll('.matrix-row-check'), function (check) {
            check.addEventListener('change', function () {
                var key = String(check.getAttribute('data-matrix-key') || '').trim();
                if (!key) return;
                if (check.checked) {
                    var row = findMatrixRowByKey(key);
                    if (row) selectedMatrixUsers.set(key, row);
                } else {
                    selectedMatrixUsers.delete(key);
                }
                syncMatrixCheckAllState();
                updateMatrixDeleteButtonLabel();
            });
        });

        Array.prototype.forEach.call(matrixBody.querySelectorAll('.matrix-delete-inactive'), function (btn) {
            btn.addEventListener('click', function () {
                var raw = String(btn.getAttribute('data-row') || '');
                if (!raw) return;
                var row;
                try {
                    row = JSON.parse(decodeURIComponent(raw));
                } catch (e) {
                    return;
                }
                var payloadItems = buildInactiveDuplicateDeleteItems(row);
                if (!payloadItems.length) {
                    setResult('Tidak ada duplikat tidak aktif yang bisa dihapus.', true);
                    return;
                }
                deleteSecrets(
                    payloadItems,
                    'Hapus duplikat tidak aktif untuk user ' + (row.username || '-') + ' (' + payloadItems.length + ' server)?'
                );
            });
        });

        var matrixCheckAll = document.getElementById('manage-pppoe-matrix-check-all');
        if (matrixCheckAll) {
            matrixCheckAll.addEventListener('change', function () {
                Array.prototype.forEach.call(matrixBody.querySelectorAll('.matrix-row-check'), function (check) {
                    if (check.disabled) return;
                    var key = String(check.getAttribute('data-matrix-key') || '').trim();
                    if (!key) return;
                    check.checked = matrixCheckAll.checked;
                    if (check.checked) {
                        var row = findMatrixRowByKey(key);
                        if (row) selectedMatrixUsers.set(key, row);
                    } else {
                        selectedMatrixUsers.delete(key);
                    }
                });
                syncMatrixCheckAllState();
                updateMatrixDeleteButtonLabel();
            });
        }

        syncMatrixCheckAllState();
        updateMatrixDeleteButtonLabel();
    }

    function renderMatrixCell(cell) {
        if (!cell || typeof cell !== 'object') {
            return '<span class="matrix-status matrix-unknown">?</span>';
        }
        var status = String(cell.status || 'unknown');
        if (status === 'active') {
            var txt = 'Aktif';
            if (cell.ip_address) txt += ' (' + escapeHtml(cell.ip_address) + ')';
            return '<span class="matrix-status matrix-active">' + txt + '</span>';
        }
        if (status === 'inactive') {
            return '<span class="matrix-status matrix-inactive">Tidak Aktif</span>';
        }
        if (status === 'absent') {
            return '<span class="matrix-status matrix-absent">Tidak Ada</span>';
        }
        return '<span class="matrix-status matrix-unknown">Unknown</span>';
    }

    function buildInactiveDuplicateDeleteItems(matrixRow) {
        if (!matrixRow || typeof matrixRow !== 'object') return [];
        var servers = matrixRow.servers || {};
        var inactiveItems = [];
        for (var sid in servers) {
            if (!Object.prototype.hasOwnProperty.call(servers, sid)) continue;
            var cell = servers[sid];
            if (!cell || cell.exists !== true) continue;
            if (String(cell.status || '') !== 'inactive') continue;
            inactiveItems.push({
                username: matrixRow.username || '',
                router_id: sid,
                status: 'inactive',
                row_key: String(sid) + '::' + String(matrixRow.username || '').toLowerCase()
            });
        }
        // Safety: jika semua duplikat tidak aktif (tidak ada yang aktif), sisakan 1 akun.
        var activeCount = Number(matrixRow.active_count || 0);
        if (activeCount === 0 && inactiveItems.length > 1) {
            inactiveItems = inactiveItems.slice(1);
        }
        return inactiveItems;
    }

    function getMatrixRowKey(matrixRow) {
        if (!matrixRow) return '';
        return String(matrixRow.username || '').toLowerCase();
    }

    function findMatrixRowByKey(key) {
        if (!key) return null;
        for (var i = 0; i < usersMatrix.length; i++) {
            var row = usersMatrix[i];
            if (getMatrixRowKey(row) === key) return row;
        }
        return null;
    }

    function syncMatrixCheckAllState() {
        var matrixCheckAll = document.getElementById('manage-pppoe-matrix-check-all');
        if (!matrixCheckAll || !matrixBody) return;
        var boxes = matrixBody.querySelectorAll('.matrix-row-check:not([disabled])');
        if (!boxes || boxes.length === 0) {
            matrixCheckAll.checked = false;
            return;
        }
        var allChecked = true;
        Array.prototype.forEach.call(boxes, function (box) {
            if (!box.checked) allChecked = false;
        });
        matrixCheckAll.checked = allChecked;
    }

    function updateMatrixDeleteButtonLabel() {
        if (!matrixDeleteSelectedBtn) return;
        matrixDeleteSelectedBtn.textContent = 'Hapus Duplikat Tidak Aktif Terpilih (' + selectedMatrixUsers.size + ')';
    }

    function applyFilter() {
        var qUser = (userSearchInput && userSearchInput.value ? userSearchInput.value : '').toLowerCase().trim();
        var qTableUser = (tableUserSearch && tableUserSearch.value ? tableUserSearch.value : '').toLowerCase().trim();
        var q = (searchInput && searchInput.value ? searchInput.value : '').toLowerCase().trim();
        var serverId = (filterServer && filterServer.value ? String(filterServer.value).trim() : '');
        filteredRows = allRows.filter(function (row) {
            if ((row.status || '') !== currentTab) {
                return false;
            }
            if (serverId !== '' && String(row.router_id || '') !== serverId) {
                return false;
            }
            if (qUser) {
                var uname = String(row.username || '').toLowerCase();
                if (uname.indexOf(qUser) === -1) {
                    return false;
                }
            }
            if (qTableUser) {
                var uname2 = String(row.username || '').toLowerCase();
                if (uname2.indexOf(qTableUser) === -1) {
                    return false;
                }
            }
            if (!q) return true;
            var hay = [
                row.username || '',
                row.profile || '',
                row.router_name || '',
                row.router_host || '',
                row.ip_address || ''
            ].join(' ').toLowerCase();
            return hay.indexOf(q) !== -1;
        });
        sortFilteredRows();
        var totalPages = getTotalPages();
        if (currentPage > totalPages) {
            currentPage = totalPages;
        }
        renderRows();
        updatePagination();
        updateSortButtons();
        renderMatrix();
    }

    function renderRows() {
        if (!tableBody) return;
        tableBody.innerHTML = '';
        if (filteredRows.length === 0) {
            tableBody.innerHTML = '<tr><td colspan="9">Tidak ada data.</td></tr>';
            if (checkAll) checkAll.checked = false;
            updateActionButtonLabel();
            return;
        }

        var pageSize = getPageSize();
        var start = (currentPage - 1) * pageSize;
        var pageRows = filteredRows.slice(start, start + pageSize);

        pageRows.forEach(function (row, pageIndex) {
            var rowKey = getRowKey(row);
            var isChecked = selectedRows.has(rowKey);
            var tr = document.createElement('tr');

            var statusClass = row.status === 'active' ? 'status-active' : 'status-inactive';
            var statusText = row.status === 'active' ? 'Aktif' : 'Tidak Aktif';

            tr.innerHTML = '' +
                '<td data-label="No">' + (start + pageIndex + 1) + '</td>' +
                '<td data-label="Pilih" style="text-align:center;">' +
                    '<input type="checkbox" class="row-check" data-row-key="' + escapeAttr(rowKey) + '" ' + (isChecked ? 'checked' : '') + '>' +
                '</td>' +
                '<td data-label="Username">' + escapeHtml(row.username || '') + '</td>' +
                '<td data-label="Profile">' + escapeHtml(row.profile || '-') + '</td>' +
                '<td data-label="Server">' + escapeHtml(row.router_name || '-') + '</td>' +
                '<td data-label="Status"><span class="status-badge ' + statusClass + '">' + statusText + '</span></td>' +
                '<td data-label="IP">' + escapeHtml(row.ip_address || '-') + '</td>' +
                '<td data-label="Uptime">' + escapeHtml(row.uptime || '-') + '</td>' +
                '<td data-label="Aksi"><button type="button" class="ghost row-delete-secret" data-row-key="' + escapeAttr(rowKey) + '">Hapus Secret</button></td>';

            tableBody.appendChild(tr);
        });

        Array.prototype.forEach.call(tableBody.querySelectorAll('.row-check'), function (check) {
            check.addEventListener('change', function () {
                var key = String(check.getAttribute('data-row-key') || '').trim();
                if (!key) return;
                if (check.checked) {
                    var row = findRowByKey(key);
                    if (row) selectedRows.set(key, row);
                } else {
                    selectedRows.delete(key);
                }
                syncCheckAllState();
                updateActionButtonLabel();
            });
        });
        Array.prototype.forEach.call(tableBody.querySelectorAll('.row-delete-secret'), function (btn) {
            btn.addEventListener('click', function () {
                var key = String(btn.getAttribute('data-row-key') || '').trim();
                if (!key) return;
                var row = findRowByKey(key);
                if (!row) return;
                deleteSecrets([{
                    username: row.username || '',
                    router_id: row.router_id || '',
                    status: row.status || '',
                    row_key: key
                }], 'Hapus secret user ' + (row.username || '-') + ' di server ' + (row.router_name || '-') + '?');
            });
        });

        syncCheckAllState();
        updateActionButtonLabel();
    }

    function getPageSize() {
        var n = pageSizeSelect ? parseInt(pageSizeSelect.value, 10) : 25;
        if (!n || n < 1) return 25;
        return n;
    }

    function getTotalPages() {
        if (filteredRows.length === 0) return 1;
        return Math.max(1, Math.ceil(filteredRows.length / getPageSize()));
    }

    function updatePagination() {
        var totalPages = getTotalPages();
        if (currentPage < 1) currentPage = 1;
        if (currentPage > totalPages) currentPage = totalPages;
        if (pageInfo) {
            pageInfo.textContent = 'Halaman ' + currentPage + '/' + totalPages + ' (' + filteredRows.length + ' data)';
        }
        if (pagePrev) pagePrev.disabled = currentPage <= 1;
        if (pageNext) pageNext.disabled = currentPage >= totalPages;
    }

    function sortFilteredRows() {
        filteredRows.sort(function (a, b) {
            var av = normalizeSortValue(a, sortKey);
            var bv = normalizeSortValue(b, sortKey);
            var cmp = 0;

            if (sortKey === 'uptime') {
                cmp = uptimeToSeconds(String(av || '')) - uptimeToSeconds(String(bv || ''));
            } else if (sortKey === 'status') {
                var aw = String(av || '') === 'active' ? 0 : 1;
                var bw = String(bv || '') === 'active' ? 0 : 1;
                cmp = aw - bw;
            } else {
                cmp = String(av || '').localeCompare(String(bv || ''), undefined, { sensitivity: 'base', numeric: true });
            }
            return sortDir === 'asc' ? cmp : -cmp;
        });
    }

    function normalizeSortValue(row, key) {
        if (!row) return '';
        return row[key] || '';
    }

    function uptimeToSeconds(text) {
        var s = String(text || '').trim();
        if (!s) return 0;
        var total = 0;
        var m;
        var re = /(\d+)([wdhms])/g;
        while ((m = re.exec(s)) !== null) {
            var n = parseInt(m[1], 10);
            var u = m[2];
            if (u === 'w') total += n * 7 * 24 * 3600;
            else if (u === 'd') total += n * 24 * 3600;
            else if (u === 'h') total += n * 3600;
            else if (u === 'm') total += n * 60;
            else if (u === 's') total += n;
        }
        return total;
    }

    function setSort(nextKey) {
        if (!nextKey) return;
        if (sortKey === nextKey) {
            sortDir = sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            sortKey = nextKey;
            sortDir = 'asc';
        }
        currentPage = 1;
        applyFilter();
    }

    function updateSortButtons() {
        if (!sortButtons) return;
        Array.prototype.forEach.call(sortButtons, function (btn) {
            var key = btn.getAttribute('data-sort') || '';
            if (key === sortKey) {
                btn.textContent = sortDir === 'asc' ? 'Asc' : 'Desc';
            } else {
                btn.textContent = 'Sort';
            }
        });
    }

    function syncCheckAllState() {
        if (!checkAll) return;
        var boxes = tableBody ? tableBody.querySelectorAll('.row-check') : [];
        if (!boxes || boxes.length === 0) {
            checkAll.checked = false;
            return;
        }
        var allChecked = true;
        Array.prototype.forEach.call(boxes, function (box) {
            if (!box.checked) allChecked = false;
        });
        checkAll.checked = allChecked;
    }

    function getRowKey(row) {
        if (!row) return '';
        return String(row.router_id || '') + '::' + String(row.username || '').toLowerCase();
    }

    function findRowByKey(key) {
        if (!key) return null;
        for (var i = 0; i < allRows.length; i++) {
            var row = allRows[i];
            if (getRowKey(row) === key) return row;
        }
        return null;
    }

    function updateActionButtonLabel() {
        var count = selectedRows.size;
        if (btnMove) {
            btnMove.textContent = 'Pindahkan user terpilih (' + count + ')';
        }
        if (btnDelete) {
            btnDelete.textContent = 'Hapus Secret terpilih (' + count + ')';
        }
    }

    function setTab(tab) {
        currentTab = tab === 'inactive' ? 'inactive' : 'active';
        currentPage = 1;
        if (tabActive) tabActive.classList.toggle('is-active', currentTab === 'active');
        if (tabInactive) tabInactive.classList.toggle('is-active', currentTab === 'inactive');
        applyFilter();
    }

    function loadData() {
        setResult('', false);
        if (statusBox) statusBox.textContent = 'Memuat...';
        fetch('pppoe_manage_data.php')
            .then(function (res) { return res.json(); })
            .then(function (json) {
                if (json.error) throw new Error(json.error);
                serverDefs = Array.isArray(json.servers) ? json.servers : [];
                allRows = Array.isArray(json.data) ? json.data : [];
                usersMatrix = Array.isArray(json.users_matrix) ? json.users_matrix : [];
                setErrors(json);
                updateSummary(json);
                applyFilter();
                if (statusBox) {
                    var summary = json.summary || {};
                    statusBox.textContent = 'Total ' + Number(summary.total || 0) + ' akun, router error ' + Number(summary.server_failed || 0);
                }
            })
            .catch(function (err) {
                if (statusBox) statusBox.textContent = 'Gagal memuat: ' + err.message;
                if (tableBody) tableBody.innerHTML = '<tr><td colspan="9">Gagal memuat data: ' + escapeHtml(err.message) + '</td></tr>';
                if (errorsBox) {
                    errorsBox.style.display = 'none';
                    errorsBox.innerHTML = '';
                }
            });
    }

    function moveSelectedUsers() {
        if (selectedRows.size === 0) {
            setResult('Pilih minimal 1 user.', true);
            return;
        }
        var target = targetServer ? String(targetServer.value || '').trim() : '';
        if (!target) {
            setResult('Pilih server tujuan terlebih dahulu.', true);
            return;
        }
        var nameMap = {};
        selectedRows.forEach(function (row) {
            var uname = String((row && row.username) || '').trim();
            if (!uname) return;
            nameMap[uname.toLowerCase()] = uname;
        });
        var usernames = Object.keys(nameMap).map(function (k) { return nameMap[k]; });
        if (usernames.length === 0) {
            setResult('Tidak ada username valid yang dipilih.', true);
            return;
        }
        var confirmText = 'Pindahkan ' + usernames.length + ' user ke server tujuan sekarang?';
        if (!confirm(confirmText)) {
            return;
        }

        btnMove.disabled = true;
        if (statusBox) statusBox.textContent = 'Memproses pemindahan...';
        fetch('pppoe_manage_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'move_users',
                usernames: usernames,
                target_router_id: target,
                remove_same_from_server1: !!(removeServer1 && removeServer1.checked),
                server1_router_id: server1Select ? (server1Select.value || '') : ''
            })
        })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                if (json.error) throw new Error(json.error);
                var moved = Number(json.moved || 0);
                var failed = Number(json.failed || 0);
                var removed = Number(json.removed_from_server1 || 0);
                var msg = 'Selesai. Berhasil: ' + moved + ', gagal: ' + failed + ', terhapus di SERVER 1: ' + removed + '.';
                setResult(escapeHtml(msg), failed > 0);
                if (statusBox) statusBox.textContent = msg;
                if (moved > 0) {
                    selectedRows.clear();
                }
                loadData();
            })
            .catch(function (err) {
                var msg = 'Gagal proses: ' + err.message;
                setResult(escapeHtml(msg), true);
                if (statusBox) statusBox.textContent = msg;
            })
            .finally(function () {
                btnMove.disabled = false;
                updateActionButtonLabel();
            });
    }

    function deleteSelectedSecrets() {
        if (selectedRows.size === 0) {
            setResult('Pilih minimal 1 user untuk dihapus secret.', true);
            return;
        }
        var items = [];
        selectedRows.forEach(function (row, key) {
            items.push({
                username: row && row.username ? row.username : '',
                router_id: row && row.router_id ? row.router_id : '',
                status: row && row.status ? row.status : '',
                row_key: key
            });
        });
        deleteSecrets(items, 'Hapus secret ' + items.length + ' user terpilih? User aktif juga akan diputus koneksinya.');
    }

    function deleteSelectedInactiveDuplicates() {
        if (selectedMatrixUsers.size === 0) {
            setResult('Pilih minimal 1 user duplikat pada rekap semua server.', true);
            return;
        }
        var merged = new Map();
        selectedMatrixUsers.forEach(function (matrixRow) {
            var items = buildInactiveDuplicateDeleteItems(matrixRow);
            items.forEach(function (it) {
                if (!it || !it.row_key) return;
                merged.set(String(it.row_key), it);
            });
        });
        var payloadItems = Array.from(merged.values());
        if (payloadItems.length === 0) {
            setResult('Tidak ada duplikat tidak aktif yang bisa dihapus dari pilihan.', true);
            return;
        }
        deleteSecrets(
            payloadItems,
            'Hapus duplikat tidak aktif terpilih (' + payloadItems.length + ' akun pada beberapa server)?'
        );
    }

    function deleteSecrets(items, confirmText) {
        if (!Array.isArray(items) || items.length === 0) {
            return;
        }
        if (!confirm(confirmText || 'Hapus secret user?')) {
            return;
        }
        if (btnDelete) btnDelete.disabled = true;
        if (statusBox) statusBox.textContent = 'Memproses hapus secret...';
        fetch('pppoe_manage_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'delete_secrets',
                items: items
            })
        })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                if (json.error) throw new Error(json.error);
                var ok = Number(json.deleted_users || 0);
                var failed = Number(json.failed || 0);
                var secretRemoved = Number(json.removed_secret_total || 0);
                var activeRemoved = Number(json.removed_active_total || 0);
                var msg = 'Hapus secret selesai. User diproses: ' + ok + ', gagal: ' + failed +
                    ', secret terhapus: ' + secretRemoved + ', active terputus: ' + activeRemoved + '.';
                setResult(escapeHtml(msg), failed > 0);
                if (statusBox) statusBox.textContent = msg;
                if (ok > 0) {
                    items.forEach(function (it) {
                        if (it && it.row_key) {
                            selectedRows.delete(String(it.row_key));
                        }
                    });
                    selectedMatrixUsers.clear();
                }
                loadData();
            })
            .catch(function (err) {
                var msg = 'Gagal hapus secret: ' + err.message;
                setResult(escapeHtml(msg), true);
                if (statusBox) statusBox.textContent = msg;
            })
            .finally(function () {
                if (btnDelete) btnDelete.disabled = false;
                updateActionButtonLabel();
            });
    }

    function escapeHtml(str) {
        return String(str || '').replace(/[&<>"']/g, function (m) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;' })[m];
        });
    }

    function escapeAttr(str) {
        return escapeHtml(str).replace(/"/g, '&quot;');
    }

    if (btnRefresh) {
        btnRefresh.addEventListener('click', loadData);
    }
    if (btnMove) {
        btnMove.addEventListener('click', moveSelectedUsers);
    }
    if (btnDelete) {
        btnDelete.addEventListener('click', deleteSelectedSecrets);
    }
    if (matrixDeleteSelectedBtn) {
        matrixDeleteSelectedBtn.addEventListener('click', deleteSelectedInactiveDuplicates);
    }
    if (matrixDuplicateOnly) {
        matrixDuplicateOnly.addEventListener('change', function () {
            renderMatrix();
        });
    }
    if (matrixUserSearch) {
        matrixUserSearch.addEventListener('input', function () {
            if (searchTimer) clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                renderMatrix();
            }, 220);
        });
    }
    if (matrixUserFilter) {
        matrixUserFilter.addEventListener('change', function () {
            renderMatrix();
        });
    }
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            if (searchTimer) clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                currentPage = 1;
                applyFilter();
            }, 240);
        });
    }
    if (userSearchInput) {
        userSearchInput.addEventListener('input', function () {
            if (searchTimer) clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                currentPage = 1;
                applyFilter();
            }, 240);
        });
    }
    if (filterServer) {
        filterServer.addEventListener('change', function () {
            currentPage = 1;
            applyFilter();
        });
    }
    if (tableUserSearch) {
        tableUserSearch.addEventListener('input', function () {
            if (searchTimer) clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                currentPage = 1;
                applyFilter();
            }, 220);
        });
    }
    if (pageSizeSelect) {
        pageSizeSelect.addEventListener('change', function () {
            currentPage = 1;
            applyFilter();
        });
    }
    if (checkAll) {
        checkAll.addEventListener('change', function () {
            if (!tableBody) return;
            Array.prototype.forEach.call(tableBody.querySelectorAll('.row-check'), function (check) {
                var key = String(check.getAttribute('data-row-key') || '').trim();
                if (!key) return;
                check.checked = checkAll.checked;
                if (check.checked) {
                    var row = findRowByKey(key);
                    if (row) selectedRows.set(key, row);
                } else {
                    selectedRows.delete(key);
                }
            });
            updateActionButtonLabel();
            syncCheckAllState();
        });
    }
    if (pagePrev) {
        pagePrev.addEventListener('click', function () {
            if (currentPage > 1) {
                currentPage--;
                renderRows();
                updatePagination();
            }
        });
    }
    if (pageNext) {
        pageNext.addEventListener('click', function () {
            var totalPages = getTotalPages();
            if (currentPage < totalPages) {
                currentPage++;
                renderRows();
                updatePagination();
            }
        });
    }
    if (tabActive) {
        tabActive.addEventListener('click', function () { setTab('active'); });
    }
    if (tabInactive) {
        tabInactive.addEventListener('click', function () { setTab('inactive'); });
    }
    if (sortButtons && sortButtons.length > 0) {
        Array.prototype.forEach.call(sortButtons, function (btn) {
            btn.addEventListener('click', function () {
                setSort(btn.getAttribute('data-sort') || '');
            });
        });
    }

    updateActionButtonLabel();
    updateMatrixDeleteButtonLabel();
    updateSortButtons();
    loadData();
})();
</script>
