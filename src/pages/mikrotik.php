<?php
$dataFile = __DIR__ . '/../../storage/mikrotik.json';
$routers = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];
if (!is_array($routers)) {
    $routers = [];
}
$categories = [];
foreach ($routers as $r) {
    if (!empty($r['category'])) {
        $categories[] = $r['category'];
    }
}
$categories = array_values(array_unique($categories));
?>

<div class="page-head">
    <h1>Router Mikrotik</h1>
    <p>CRUD router dengan dialog modal.</p>
</div>

<section class="card">
    <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem;">
        <div>
            <h2 style="margin:0;">Daftar Router</h2>
            <p class="muted" style="margin:0.25rem 0 0;">Kelola data dari storage/mikrotik.json</p>
        </div>
        <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
            <button type="button" class="ghost" id="sync-ip-pppoe">Sync IP dari PPPoE</button>
            <button type="button" id="open-router-modal">Tambah Router</button>
        </div>
    </div>
    <div id="add-router-result" class="alert" style="display:none; margin-top:0.75rem;"></div>
</section>

<section class="card">
    <div style="margin:0.4rem 0 0.75rem; display:flex; gap:0.6rem; flex-wrap:wrap; align-items:center;">
        <label style="display:flex; align-items:center; gap:0.35rem;">
            <span>Cari (live)</span>
            <input type="search" id="mikrotik-search" placeholder="nama / host / lokasi / kategori / pppoe / server">
        </label>
        <span class="muted" style="font-size:0.9rem;">Ketik untuk memfilter daftar tanpa reload.</span>
    </div>
    <div class="table-wrapper">
        <table class="table-responsive">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nama Router</th>
                    <th>IP / Host</th>
                    <th>Lokasi <button type="button" class="ghost" id="sort-location" style="padding:0.25rem 0.5rem; font-size:0.85rem;">up/down</button></th>
                    <th>Kategori</th>
                    <th>Akun PPPoE</th>
                    <th>Server Sumber</th>
                    <th>Username</th>
                    <th>Password</th>
                    <th>Catatan</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody id="mikrotik-table-body">
                <?php if (count($routers) === 0): ?>
                    <tr><td colspan="11">Belum ada data. Klik "Tambah Router".</td></tr>
                <?php else: ?>
                    <?php foreach ($routers as $row): ?>
                        <tr
                            data-id="<?php echo htmlspecialchars($row['id']); ?>"
                            data-name="<?php echo htmlspecialchars($row['name']); ?>"
                            data-host="<?php echo htmlspecialchars($row['host']); ?>"
                            data-location="<?php echo htmlspecialchars($row['location']); ?>"
                            data-category="<?php echo htmlspecialchars($row['category'] ?? ''); ?>"
                            data-pppoe-account="<?php echo htmlspecialchars($row['pppoe_account'] ?? ''); ?>"
                            data-source-server-id="<?php echo htmlspecialchars($row['source_server_id'] ?? ''); ?>"
                            data-source-server-name="<?php echo htmlspecialchars($row['source_server_name'] ?? ''); ?>"
                            data-username="<?php echo htmlspecialchars($row['username'] ?? ''); ?>"
                            data-password="<?php echo htmlspecialchars($row['password'] ?? ''); ?>"
                            data-notes="<?php echo htmlspecialchars($row['notes']); ?>"
                        >
                            <td data-label="ID"><?php echo htmlspecialchars($row['id']); ?></td>
                            <td data-label="Nama Router"><?php echo htmlspecialchars($row['name']); ?></td>
                            <td data-label="IP / Host">
                                <?php $host = $row['host'] ?? ''; ?>
                                <?php if ($host): ?>
                                    <?php $href = stripos($host, 'http') === 0 ? $host : 'http://' . $host; ?>
                                    <a href="<?php echo htmlspecialchars($href); ?>" target="_blank" rel="noreferrer">
                                        <?php echo htmlspecialchars($host); ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td data-label="Lokasi"><?php echo htmlspecialchars($row['location']); ?></td>
                            <td data-label="Kategori"><?php echo htmlspecialchars($row['category'] ?? ''); ?></td>
                            <td data-label="Akun PPPoE"><?php echo htmlspecialchars($row['pppoe_account'] ?? ''); ?></td>
                            <td data-label="Server Sumber">
                                <?php
                                    $sourceName = trim((string)($row['source_server_name'] ?? ''));
                                    $sourceId = trim((string)($row['source_server_id'] ?? ''));
                                    echo htmlspecialchars($sourceName !== '' ? $sourceName : $sourceId);
                                ?>
                            </td>
                            <td data-label="Username"><?php echo htmlspecialchars($row['username'] ?? ''); ?></td>
                            <td data-label="Password"><?php echo htmlspecialchars($row['password'] ?? ''); ?></td>
                            <td data-label="Catatan"><?php echo htmlspecialchars($row['notes']); ?></td>
                            <td data-label="Aksi" class="table-actions">
                                <button class="ghost" data-action="edit" data-id="<?php echo htmlspecialchars($row['id']); ?>">Edit</button>
                                <button class="ghost" data-action="delete" data-id="<?php echo htmlspecialchars($row['id']); ?>">Hapus</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                <tr id="mikrotik-no-match" style="display:none;"><td colspan="11">Tidak ada hasil untuk pencarian.</td></tr>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <h2>Endpoint CRUD</h2>
    <pre><code>GET    /public/api.php?resource=mikrotik
GET    /public/api.php?resource=mikrotik&id=1
POST   /public/api.php?resource=mikrotik
PUT    /public/api.php?resource=mikrotik&id=1
DELETE /public/api.php?resource=mikrotik&id=1</code></pre>
    <p class="muted">Body JSON: {"name": "Core Router", "host": "10.0.0.1", "location": "DC-1", "category": "core", "pppoe_account": "user@lokasi", "source_server_id": "4", "source_server_name": "SERVER 2 40.1", "username": "admin", "password": "secret", "notes": "Main uplink"}</p>
</section>

<style>
.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.35);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 60;
}
.modal {
    background: #fff;
    border-radius: 14px;
    padding: 1.25rem;
    width: 95%;
    max-width: 640px;
    box-shadow: 0 18px 40px rgba(0,0,0,0.25);
}
.modal header {
    font-weight: 700;
    font-size: 1.1rem;
    margin-bottom: 0.4rem;
}
.modal .muted {
    color: #6b7280;
    margin: 0 0 0.8rem;
}
.modal .field {
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
}
.modal footer {
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
    margin-top: 1rem;
}
.modal .status {
    min-height: 1.2rem;
    font-size: 0.9rem;
    color: #6b7280;
}
</style>

<div id="router-modal" class="modal-backdrop" role="dialog" aria-modal="true">
    <div class="modal">
        <header id="router-modal-title">Tambah Router</header>
        <p class="muted">Isi detail router Mikrotik untuk disimpan ke storage/mikrotik.json</p>
        <div class="form-grid" style="grid-template-columns: repeat(auto-fit,minmax(240px,1fr));">
            <label class="field">
                <span>Nama Router</span>
                <input type="text" id="router-name" placeholder="Core Router">
            </label>
            <label class="field">
                <span>IP / Host</span>
                <input type="text" id="router-host" placeholder="10.0.0.1">
            </label>
            <label class="field">
                <span>Lokasi</span>
                <input type="text" id="router-location" placeholder="Data Center">
            </label>
            <label class="field">
                <span>Kategori</span>
                <select id="category-select">
                    <option value="">-- Pilih kategori --</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?php echo htmlspecialchars($category); ?>"><?php echo htmlspecialchars($category); ?></option>
                    <?php endforeach; ?>
                    <option value="__new">+ Tambah kategori baru</option>
                </select>
            </label>
            <label class="field" id="category-new-wrapper" style="display:none;">
                <span>Kategori baru</span>
                <input type="text" id="category-new" placeholder="Mis. core / isp / branch">
            </label>
            <label class="field">
                <span>Username</span>
                <input type="text" id="router-username" placeholder="admin">
            </label>
            <label class="field">
                <span>Password</span>
                <input type="password" id="router-password" placeholder="********">
            </label>
            <label class="field">
                <span>Akun PPPoE</span>
                <input type="text" id="router-pppoe-account" placeholder="contoh: user@lokasi">
            </label>
            <label class="field">
                <span>Server Sumber (ID)</span>
                <input type="text" id="router-source-server-id" placeholder="contoh: 4">
            </label>
            <label class="field" style="grid-column:1/-1;">
                <span>Server Sumber (Nama)</span>
                <input type="text" id="router-source-server-name" placeholder="contoh: SERVER 2 40.1">
            </label>
            <label class="field" style="grid-column:1/-1;">
                <span>Catatan</span>
                <input type="text" id="router-notes" placeholder="Uplink utama">
            </label>
        </div>
        <div class="status" id="router-modal-status"></div>
        <footer>
            <button type="button" class="ghost" id="router-modal-cancel">Batal</button>
            <button type="button" id="router-modal-save">Simpan</button>
        </footer>
    </div>
</div>

<script>
(function() {
    var resultBox = document.getElementById('add-router-result');
    var tableBody = document.getElementById('mikrotik-table-body');
    var categorySelect = document.getElementById('category-select');
    var categoryNew = document.getElementById('category-new');
    var categoryNewWrapper = document.getElementById('category-new-wrapper');
    var openModalBtn = document.getElementById('open-router-modal');
    var syncIpBtn = document.getElementById('sync-ip-pppoe');
    var modal = document.getElementById('router-modal');
    var modalTitle = document.getElementById('router-modal-title');
    var modalSave = document.getElementById('router-modal-save');
    var modalCancel = document.getElementById('router-modal-cancel');
    var modalStatus = document.getElementById('router-modal-status');
    var inputName = document.getElementById('router-name');
    var inputHost = document.getElementById('router-host');
    var inputLocation = document.getElementById('router-location');
    var inputUsername = document.getElementById('router-username');
    var inputPassword = document.getElementById('router-password');
    var inputPppoeAccount = document.getElementById('router-pppoe-account');
    var inputSourceServerId = document.getElementById('router-source-server-id');
    var inputSourceServerName = document.getElementById('router-source-server-name');
    var inputNotes = document.getElementById('router-notes');
    var sortLocationBtn = document.getElementById('sort-location');
    var searchInput = document.getElementById('mikrotik-search');
    var noMatchRow = document.getElementById('mikrotik-no-match');
    var sortDir = 'asc';
    var editingId = null;
    if (!modal) return;

    function showResult(msg, isError) {
        if (!resultBox) return;
        resultBox.style.display = 'block';
        resultBox.textContent = msg || '';
        resultBox.style.background = isError ? '#ffe4e4' : '#e6ffed';
        resultBox.style.color = isError ? '#b91c1c' : '#166534';
        resultBox.style.borderColor = isError ? '#fca5a5' : '#86efac';
    }

    categorySelect.addEventListener('change', function () {
        toggleNewCategory(categorySelect.value === '__new');
    });

    openModalBtn && openModalBtn.addEventListener('click', function(){
        openModal();
    });
    syncIpBtn && syncIpBtn.addEventListener('click', function(){
        syncIpFromPppoe();
    });

    modalCancel && modalCancel.addEventListener('click', closeModal);
    modal.addEventListener('click', function(e){
        if (e.target === modal) closeModal();
    });

    modalSave && modalSave.addEventListener('click', function(){
        saveRouter();
    });

    sortLocationBtn && sortLocationBtn.addEventListener('click', function(){
        sortByLocation();
    });

    if (searchInput) {
        searchInput.addEventListener('input', applyFilter);
    }

    tableBody.addEventListener('click', function (e) {
        var btn = e.target.closest('button');
        if (!btn) return;
        var id = btn.dataset.id;
        if (!id) return;

        if (btn.dataset.action === 'edit') {
            startEdit(id);
        } else if (btn.dataset.action === 'delete') {
            deleteRouter(id);
        }
    });

    function openModal() {
        editingId = null;
        modalTitle.textContent = 'Tambah Router';
        modalStatus.textContent = '';
        fillForm({});
        modal.style.display = 'flex';
    }

    function closeModal() {
        modal.style.display = 'none';
        editingId = null;
        modalStatus.textContent = '';
    }

    function saveRouter() {
        var categoryValue = categorySelect.value === '__new'
            ? (categoryNew.value || '')
            : (categorySelect.value || '');
        var payload = {
            name: inputName.value || '',
            host: inputHost.value || '',
            location: inputLocation.value || '',
            category: categoryValue,
            username: inputUsername.value || '',
            password: inputPassword.value || '',
            pppoe_account: inputPppoeAccount.value || '',
            source_server_id: inputSourceServerId.value || '',
            source_server_name: inputSourceServerName.value || '',
            notes: inputNotes.value || ''
        };
        if (!payload.name || !payload.host) {
            modalStatus.textContent = 'Nama dan host wajib diisi.';
            return;
        }
        var method = editingId ? 'PUT' : 'POST';
        var url = editingId ? 'api.php?resource=mikrotik&id=' + encodeURIComponent(editingId) : 'api.php?resource=mikrotik';
        modalSave.disabled = true;
        modalStatus.textContent = 'Menyimpan...';

        fetch(url, {
            method: method,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function(res){ return res.json(); })
        .then(function(json){
            if (editingId) {
                showResult('Berhasil mengubah router #' + editingId, false);
                updateRow(json.data || json);
            } else {
                showResult('Berhasil menambah router dengan ID #' + (json.id || ''), false);
                appendRow(json);
            }
            maybeAddCategoryOption(categoryValue);
            closeModal();
        })
        .catch(function(err){
            modalStatus.textContent = 'Gagal: ' + err;
        })
        .finally(function(){
            modalSave.disabled = false;
        });
    }

    function startEdit(id) {
        var row = tableBody.querySelector('tr[data-id="' + id + '"]');
        if (!row) return;
        editingId = id;
        modalTitle.textContent = 'Edit Router #' + id;
        modalStatus.textContent = '';
        fillForm({
            name: row.dataset.name || '',
            host: row.dataset.host || '',
            location: row.dataset.location || '',
            category: row.dataset.category || '',
            pppoe_account: row.dataset.pppoeAccount || '',
            source_server_id: row.dataset.sourceServerId || '',
            source_server_name: row.dataset.sourceServerName || '',
            username: row.dataset.username || '',
            password: row.dataset.password || '',
            notes: row.dataset.notes || ''
        });
        modal.style.display = 'flex';
    }

    function deleteRouter(id) {
        if (!confirm('Hapus router #' + id + '?')) return;
        fetch('api.php?resource=mikrotik&id=' + encodeURIComponent(id), { method: 'DELETE' })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                removeRow(id);
                showResult('Router #' + id + ' telah dihapus.', false);
            })
            .catch(function (err) {
                showResult('Gagal menghapus router: ' + err, true);
            });
    }

    function syncIpFromPppoe() {
        if (!confirm('Sinkronkan IP host router berdasarkan akun PPPoE sekarang?')) return;
        showResult('Sinkronisasi IP dari PPPoE sedang berjalan...', false);
        fetch('mikrotik_sync_ip.php', { method: 'POST' })
            .then(function(res){ return res.json(); })
            .then(function(json){
                if (json.error) throw new Error(json.error);
                var notFound = Array.isArray(json.not_found) ? json.not_found : [];
                var notMatchedServer = Array.isArray(json.not_matched_server) ? json.not_matched_server : [];
                var msg = 'Sinkron selesai. Dicek: ' + (json.checked || 0) + ', Diupdate: ' + (json.updated || 0);
                if (notFound.length > 0) {
                    msg += '. Tidak ditemukan: ' + notFound.slice(0, 5).join(', ');
                    if (notFound.length > 5) msg += ' +' + (notFound.length - 5) + ' lagi';
                }
                if (notMatchedServer.length > 0) {
                    msg += '. Server tidak cocok: ' + notMatchedServer.slice(0, 5).join(', ');
                    if (notMatchedServer.length > 5) msg += ' +' + (notMatchedServer.length - 5) + ' lagi';
                }
                showResult(msg, false);
                setTimeout(function(){ window.location.reload(); }, 350);
            })
            .catch(function(err){
                showResult('Gagal sinkron: ' + err.message, true);
            });
    }

    function fillForm(data) {
        inputName.value = data.name || '';
        inputHost.value = data.host || '';
        inputLocation.value = data.location || '';
        setCategoryValue(data.category || '');
        inputPppoeAccount.value = data.pppoe_account || '';
        inputSourceServerId.value = data.source_server_id || '';
        inputSourceServerName.value = data.source_server_name || '';
        inputUsername.value = data.username || '';
        inputPassword.value = data.password || '';
        inputNotes.value = data.notes || '';
    }

    function sortByLocation() {
        var rows = Array.prototype.slice.call(tableBody.querySelectorAll('tr[data-id]'));
        if (rows.length === 0) return;
        rows.sort(function(a, b){
            var la = (a.dataset.location || '').toLowerCase();
            var lb = (b.dataset.location || '').toLowerCase();
            if (la === lb) return 0;
            return sortDir === 'asc' ? la.localeCompare(lb) : lb.localeCompare(la);
        });
        rows.forEach(function(r){ tableBody.appendChild(r); });
        sortDir = sortDir === 'asc' ? 'desc' : 'asc';
        if (sortLocationBtn) sortLocationBtn.textContent = 'Sort Lokasi (' + sortDir + ')';
    }

    function appendRow(item) {
        if (!tableBody || !item) return;
        ensureNoMatchRow();
        var hasData = tableBody.querySelectorAll('tr[data-id]').length > 0;
        if (!hasData) {
            tableBody.innerHTML = '';
            ensureNoMatchRow();
        }
        var tr = document.createElement('tr');
        var labelMap = {
            id: 'ID',
            name: 'Nama Router',
            host: 'IP / Host',
            location: 'Lokasi',
            category: 'Kategori',
            pppoe_account: 'Akun PPPoE',
            source_server: 'Server Sumber',
            username: 'Username',
            password: 'Password',
            notes: 'Catatan'
        };
        ['id', 'name', 'host', 'location', 'category', 'pppoe_account', 'source_server', 'username', 'password', 'notes'].forEach(function (key) {
            var td = document.createElement('td');
            td.setAttribute('data-label', labelMap[key] || key);
            var value = key === 'source_server'
                ? (item.source_server_name || item.source_server_id || '')
                : (item[key] || '');
            if (key === 'host' && value) {
                var a = document.createElement('a');
                var href = value.toLowerCase().startsWith('http') ? value : 'http://' + value;
                a.href = href;
                a.target = '_blank';
                a.rel = 'noreferrer';
                a.textContent = value;
                td.appendChild(a);
            } else {
                td.textContent = value;
            }
            tr.appendChild(td);
        });
        var actionsTd = document.createElement('td');
        actionsTd.className = 'table-actions';
        actionsTd.setAttribute('data-label', 'Aksi');
        actionsTd.innerHTML = '<button class="ghost" data-action="edit" data-id="' + (item.id || '') + '">Edit</button> ' +
            '<button class="ghost" data-action="delete" data-id="' + (item.id || '') + '">Hapus</button>';
        tr.appendChild(actionsTd);
        setRowData(tr, item);
        tableBody.insertBefore(tr, noMatchRow);
        applyFilter();
    }

    function updateRow(item) {
        if (!item || !item.id) return;
        var row = tableBody.querySelector('tr[data-id="' + item.id + '"]');
        if (!row) {
            appendRow(item);
            return;
        }
        var cells = row.querySelectorAll('td');
        cells[0].textContent = item.id || '';
        cells[1].textContent = item.name || '';
        cells[2].innerHTML = '';
        if (item.host) {
            var a = document.createElement('a');
            var href = item.host.toLowerCase().startsWith('http') ? item.host : 'http://' + item.host;
            a.href = href;
            a.target = '_blank';
            a.rel = 'noreferrer';
            a.textContent = item.host;
            cells[2].appendChild(a);
        }
        cells[3].textContent = item.location || '';
        cells[4].textContent = item.category || '';
        cells[5].textContent = item.pppoe_account || '';
        cells[6].textContent = item.source_server_name || item.source_server_id || '';
        cells[7].textContent = item.username || '';
        cells[8].textContent = item.password || '';
        cells[9].textContent = item.notes || '';
        setRowData(row, item);
        applyFilter();
    }

    function removeRow(id) {
        var row = tableBody.querySelector('tr[data-id="' + id + '"]');
        if (row) row.remove();
        var dataRows = tableBody.querySelectorAll('tr[data-id]');
        if (dataRows.length === 0) {
            tableBody.innerHTML = '<tr><td colspan="11">Belum ada data. Klik \"Tambah Router\".</td></tr>';
            ensureNoMatchRow();
        }
        applyFilter();
    }

    function setRowData(row, item) {
        row.dataset.id = item.id || '';
        row.dataset.name = item.name || '';
        row.dataset.host = item.host || '';
        row.dataset.location = item.location || '';
        row.dataset.category = item.category || '';
        row.dataset.pppoeAccount = item.pppoe_account || '';
        row.dataset.sourceServerId = item.source_server_id || '';
        row.dataset.sourceServerName = item.source_server_name || '';
        row.dataset.username = item.username || '';
        row.dataset.password = item.password || '';
        row.dataset.notes = item.notes || '';
    }

    function setCategoryValue(value) {
        if (!categorySelect) return;
        var option = categorySelect.querySelector('option[value="' + value + '"]');
        if (value && !option && value !== '__new') {
            var newOpt = document.createElement('option');
            newOpt.value = value;
            newOpt.textContent = value;
            categorySelect.insertBefore(newOpt, categorySelect.querySelector('option[value="__new"]'));
            categorySelect.value = value;
            toggleNewCategory(false);
            return;
        }
        if (option) {
            categorySelect.value = value;
            toggleNewCategory(false);
        } else if (value === '') {
            categorySelect.value = '';
            toggleNewCategory(false);
        } else {
            categorySelect.value = '__new';
            categoryNew.value = value;
            toggleNewCategory(true);
        }
    }

    function toggleNewCategory(show) {
        if (!categoryNewWrapper) return;
        categoryNewWrapper.style.display = show ? 'block' : 'none';
        if (!show && categoryNew) {
            categoryNew.value = '';
        }
    }

    function maybeAddCategoryOption(value) {
        if (!value || value === '__new') return;
        var existing = categorySelect.querySelector('option[value="' + value + '"]');
        if (!existing) {
            var opt = document.createElement('option');
            opt.value = value;
            opt.textContent = value;
            categorySelect.insertBefore(opt, categorySelect.querySelector('option[value="__new"]'));
        }
    }

    function ensureNoMatchRow() {
        if (!tableBody) return;
        if (!noMatchRow || !noMatchRow.parentNode) {
            noMatchRow = document.createElement('tr');
            noMatchRow.id = 'mikrotik-no-match';
            noMatchRow.style.display = 'none';
            var td = document.createElement('td');
            td.colSpan = 11;
            td.textContent = 'Tidak ada hasil untuk pencarian.';
            noMatchRow.appendChild(td);
            tableBody.appendChild(noMatchRow);
        }
    }

    function applyFilter() {
        if (!tableBody) return;
        ensureNoMatchRow();
        var q = (searchInput && searchInput.value ? searchInput.value.toLowerCase().trim() : '');
        var rows = Array.prototype.slice.call(tableBody.querySelectorAll('tr[data-id]'));
        var visible = 0;
        rows.forEach(function(row){
            var hay = (
                (row.dataset.id || '') + ' ' +
                (row.dataset.name || '') + ' ' +
                (row.dataset.host || '') + ' ' +
                (row.dataset.location || '') + ' ' +
                (row.dataset.category || '') + ' ' +
                (row.dataset.pppoeAccount || '') + ' ' +
                (row.dataset.sourceServerName || '') + ' ' +
                (row.dataset.sourceServerId || '') + ' ' +
                (row.dataset.username || '') + ' ' +
                (row.dataset.notes || '')
            ).toLowerCase();
            var show = !q || hay.indexOf(q) !== -1;
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        if (noMatchRow) {
            noMatchRow.style.display = rows.length > 0 && visible === 0 ? '' : 'none';
        }
    }

    // Init: ensure new category input is hidden
    toggleNewCategory(false);
    ensureNoMatchRow();
    applyFilter();
})();
</script>
