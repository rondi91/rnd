<?php
$dataFile = __DIR__ . '/../../storage/users.json';
$users = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];
if (!is_array($users)) $users = [];
$statuses = ['aktif', 'menunggu', 'diblokir'];
$routes = require __DIR__ . '/../routes.php';
$menuPermissions = [];
foreach ($routes as $slug => $cfg) {
    $menuPermissions[] = [
        'slug' => $slug,
        'title' => $cfg['title'] ?? $slug,
    ];
}
$locFile = __DIR__ . '/../../storage/alamat.json';
$locations = file_exists($locFile) ? json_decode(file_get_contents($locFile), true) : [];
if (!is_array($locations)) $locations = [];
?>
<div class="page-head">
    <h1>Users</h1>
    <p>Kelola user, password, role, dan permissions.</p>
</div>

<section class="card">
    <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem;">
        <div>
            <h2 style="margin:0;">Daftar User</h2>
            <p class="muted" style="margin:0.25rem 0 0;">Data tersimpan di storage/users.json</p>
        </div>
        <button type="button" id="open-user-modal">Tambah User</button>
    </div>
    <div id="user-result" class="alert" style="display:none; margin-top:0.75rem;"></div>
</section>

<section class="card">
    <div class="table-wrapper">
        <table class="table-responsive">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nama</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Permissions</th>
                    <th>Status</th>
                    <th>Password</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody id="users-table-body">
                <?php if (count($users) === 0): ?>
                    <tr><td colspan="8">Belum ada data user.</td></tr>
                <?php else: ?>
                    <?php foreach ($users as $row): ?>
                        <?php
                            $perms = $row['permissions'] ?? [];
                            $permText = is_array($perms) ? implode(', ', $perms) : (string) $perms;
                            $password = (string) ($row['password'] ?? '');
                            $mask = $password === '' ? '-' : str_repeat('*', min(10, max(6, strlen($password))));
                            $status = strtolower($row['status'] ?? 'aktif');
                            $badge = $status === 'aktif' ? 'badge-success' : ($status === 'menunggu' ? 'badge-warning' : 'badge-danger');
                        ?>
                        <tr
                            data-id="<?php echo htmlspecialchars($row['id']); ?>"
                            data-name="<?php echo htmlspecialchars($row['name'] ?? ''); ?>"
                            data-email="<?php echo htmlspecialchars($row['email'] ?? ''); ?>"
                            data-status="<?php echo htmlspecialchars($row['status'] ?? 'aktif'); ?>"
                            data-password="<?php echo htmlspecialchars($password); ?>"
                            data-role="<?php echo htmlspecialchars($row['role'] ?? ''); ?>"
                            data-permissions="<?php echo htmlspecialchars($permText); ?>"
                        >
                            <td data-label="ID"><?php echo htmlspecialchars($row['id']); ?></td>
                            <td data-label="Nama"><?php echo htmlspecialchars($row['name'] ?? ''); ?></td>
                            <td data-label="Email"><?php echo htmlspecialchars($row['email'] ?? ''); ?></td>
                            <td data-label="Role"><?php echo htmlspecialchars($row['role'] ?? '-'); ?></td>
                            <td data-label="Permissions"><?php echo htmlspecialchars($permText !== '' ? $permText : '-'); ?></td>
                            <td data-label="Status"><span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span></td>
                            <td data-label="Password"><?php echo $mask; ?></td>
                            <td data-label="Aksi" class="table-actions">
                                <button class="ghost" data-action="edit" data-id="<?php echo htmlspecialchars($row['id']); ?>">Edit</button>
                                <button class="ghost" data-action="delete" data-id="<?php echo htmlspecialchars($row['id']); ?>">Hapus</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div id="user-modal" class="modal-backdrop" role="dialog" aria-modal="true" style="display:none;">
    <div class="modal">
        <header id="user-modal-title">Tambah User</header>
        <p class="muted">Isi data user, role, dan permissions.</p>
        <div class="form-grid" style="grid-template-columns: repeat(auto-fit,minmax(220px,1fr));">
            <label class="field">
                <span>Nama</span>
                <input type="text" id="user-name" placeholder="Nama user">
            </label>
            <label class="field">
                <span>Email</span>
                <input type="email" id="user-email" placeholder="email@domain.com">
            </label>
            <label class="field">
                <span>Password</span>
                <input type="text" id="user-password" placeholder="password">
            </label>
            <label class="field">
                <span>Role</span>
                <input type="text" id="user-role" placeholder="admin / staff">
            </label>
            <label class="field" style="grid-column:1/-1;">
                <span>Permissions Menu</span>
                <div class="perm-grid" id="perm-menu-list">
                    <?php foreach ($menuPermissions as $perm): ?>
                        <label class="perm-item">
                            <input type="checkbox" class="perm-menu" value="<?php echo htmlspecialchars($perm['slug']); ?>">
                            <span><?php echo htmlspecialchars($perm['title']); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </label>
            <label class="field" style="grid-column:1/-1;">
                <span>Alamat Billing</span>
                <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.4rem;">
                    <button type="button" class="ghost" id="sync-location">Sync alamat dari PPPoE</button>
                    <span class="muted" id="sync-location-status"></span>
                </div>
                <div class="perm-grid" id="perm-location-list">
                    <?php if (count($locations) === 0): ?>
                        <div class="muted">Belum ada alamat di storage/alamat.json</div>
                    <?php else: ?>
                        <?php foreach ($locations as $loc): ?>
                            <label class="perm-item">
                                <input type="checkbox" class="perm-location" value="<?php echo htmlspecialchars(strtoupper($loc)); ?>">
                                <span><?php echo htmlspecialchars($loc); ?></span>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </label>
            <label class="field">
                <span>Status</span>
                <select id="user-status">
                    <?php foreach ($statuses as $st): ?>
                        <option value="<?php echo htmlspecialchars($st); ?>"><?php echo htmlspecialchars(ucfirst($st)); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <div class="status" id="user-modal-status"></div>
        <footer>
            <button type="button" class="ghost" id="user-modal-cancel">Batal</button>
            <button type="button" id="user-modal-save">Simpan</button>
        </footer>
    </div>
</div>

<style>
.perm-grid {
    display: grid;
    gap: 0.4rem;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    padding: 0.4rem;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    background: #f9fafb;
}
.perm-item {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-weight: 600;
    color: #4b5563;
}
.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.45);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    overflow: auto;
    z-index: 50;
}
.modal {
    background: #fff;
    border-radius: 14px;
    padding: 1.2rem;
    min-width: 320px;
    max-width: 820px;
    max-height: calc(100vh - 2rem);
    width: 95%;
    overflow: auto;
    box-shadow: 0 18px 40px rgba(0,0,0,0.22);
}
.modal header {
    font-weight: 700;
    margin-bottom: 0.75rem;
    font-size: 1.1rem;
}
.modal .status {
    font-size: 0.9rem;
    color: #666;
    min-height: 1.2em;
}
</style>

<script>
(function(){
    var resultBox = document.getElementById('user-result');
    var tableBody = document.getElementById('users-table-body');
    var openModalBtn = document.getElementById('open-user-modal');
    var modal = document.getElementById('user-modal');
    var modalTitle = document.getElementById('user-modal-title');
    var modalSave = document.getElementById('user-modal-save');
    var modalCancel = document.getElementById('user-modal-cancel');
    var modalStatus = document.getElementById('user-modal-status');
    var inputName = document.getElementById('user-name');
    var inputEmail = document.getElementById('user-email');
    var inputPassword = document.getElementById('user-password');
    var inputRole = document.getElementById('user-role');
    var permMenuBoxes = document.querySelectorAll('.perm-menu');
    var permLocationBoxes = document.querySelectorAll('.perm-location');
    var permLocationList = document.getElementById('perm-location-list');
    var syncLocationBtn = document.getElementById('sync-location');
    var syncLocationStatus = document.getElementById('sync-location-status');
    var inputStatus = document.getElementById('user-status');
    var editingId = null;
    var extraPerms = [];

    function showResult(msg, isError) {
        if (!resultBox) return;
        resultBox.style.display = 'block';
        resultBox.textContent = msg || '';
        resultBox.style.background = isError ? '#ffe4e4' : '#e6ffed';
        resultBox.style.color = isError ? '#b91c1c' : '#166534';
        resultBox.style.borderColor = isError ? '#fca5a5' : '#86efac';
    }

    function openModal(data) {
        editingId = data && data.id ? data.id : null;
        modalTitle.textContent = editingId ? 'Edit User #' + editingId : 'Tambah User';
        modalStatus.textContent = '';
        inputName.value = data ? (data.name || '') : '';
        inputEmail.value = data ? (data.email || '') : '';
        inputPassword.value = data ? (data.password || '') : '';
        inputRole.value = data ? (data.role || '') : '';
        setPermissionChecks(data ? (data.permissions || '') : '');
        inputStatus.value = data ? (data.status || 'aktif') : 'aktif';
        modal.style.display = 'flex';
    }

    function closeModal() {
        modal.style.display = 'none';
        editingId = null;
    }

    function collectPayload() {
        return {
            name: inputName.value || '',
            email: inputEmail.value || '',
            password: inputPassword.value || '',
            role: inputRole.value || '',
            permissions: collectPermissions(),
            status: inputStatus.value || 'aktif'
        };
    }

    function statusBadge(status) {
        var st = (status || '').toLowerCase();
        if (st === 'aktif') return 'badge-success';
        if (st === 'menunggu') return 'badge-warning';
        return 'badge-danger';
    }

    function maskPassword(pwd) {
        if (!pwd) return '-';
        var len = pwd.length;
        var count = Math.min(10, Math.max(6, len));
        return Array(count + 1).join('*');
    }

    function setRowData(row, item) {
        row.dataset.id = item.id || '';
        row.dataset.name = item.name || '';
        row.dataset.email = item.email || '';
        row.dataset.status = item.status || 'aktif';
        row.dataset.password = item.password || '';
        row.dataset.role = item.role || '';
        row.dataset.permissions = Array.isArray(item.permissions) ? item.permissions.join(', ') : (item.permissions || '');
    }

    function appendRow(item) {
        if (!tableBody || !item) return;
        if (tableBody.children.length === 1 && tableBody.children[0].children.length === 1) {
            tableBody.innerHTML = '';
        }
        var tr = document.createElement('tr');
        var permText = Array.isArray(item.permissions) ? item.permissions.join(', ') : (item.permissions || '');
        tr.innerHTML =
            '<td data-label="ID">' + (item.id || '') + '</td>' +
            '<td data-label="Nama">' + (item.name || '') + '</td>' +
            '<td data-label="Email">' + (item.email || '') + '</td>' +
            '<td data-label="Role">' + (item.role || '-') + '</td>' +
            '<td data-label="Permissions">' + (permText || '-') + '</td>' +
            '<td data-label="Status"><span class="badge ' + statusBadge(item.status) + '">' + (item.status || 'aktif') + '</span></td>' +
            '<td data-label="Password">' + maskPassword(item.password) + '</td>' +
            '<td data-label="Aksi" class="table-actions">' +
                '<button class="ghost" data-action="edit" data-id="' + (item.id || '') + '">Edit</button> ' +
                '<button class="ghost" data-action="delete" data-id="' + (item.id || '') + '">Hapus</button>' +
            '</td>';
        setRowData(tr, item);
        tableBody.appendChild(tr);
    }

    function updateRow(item) {
        if (!item || !item.id) return;
        var row = tableBody.querySelector('tr[data-id="' + item.id + '"]');
        if (!row) {
            appendRow(item);
            return;
        }
        var cells = row.querySelectorAll('td');
        var permText = Array.isArray(item.permissions) ? item.permissions.join(', ') : (item.permissions || '');
        cells[0].textContent = item.id || '';
        cells[1].textContent = item.name || '';
        cells[2].textContent = item.email || '';
        cells[3].textContent = item.role || '-';
        cells[4].textContent = permText || '-';
        cells[5].innerHTML = '<span class="badge ' + statusBadge(item.status) + '">' + (item.status || 'aktif') + '</span>';
        cells[6].textContent = maskPassword(item.password);
        setRowData(row, item);
    }

    function collectPermissions() {
        var perms = [];
        permMenuBoxes.forEach(function(cb){
            if (cb.checked) perms.push(cb.value);
        });
        permLocationBoxes.forEach(function(cb){
            if (cb.checked) perms.push('billing:' + cb.value);
        });
        if (extraPerms.length) {
            extraPerms.forEach(function(p){ perms.push(p); });
        }
        return perms;
    }

    function setPermissionChecks(permText) {
        extraPerms = [];
        var list = [];
        if (Array.isArray(permText)) {
            list = permText;
        } else if (typeof permText === 'string') {
            list = permText.split(',').map(function(s){ return s.trim(); }).filter(Boolean);
        }
        var known = {};
        permMenuBoxes.forEach(function(cb){ known[cb.value] = true; });
        permLocationBoxes.forEach(function(cb){ known['billing:' + cb.value] = true; });

        var set = {};
        list.forEach(function(p){
            if (known[p]) {
                set[p] = true;
            } else {
                extraPerms.push(p);
            }
        });
        permMenuBoxes.forEach(function(cb){
            cb.checked = !!set[cb.value];
        });
        permLocationBoxes.forEach(function(cb){
            var key = 'billing:' + cb.value;
            cb.checked = !!set[key];
        });
    }

    function renderLocationOptions(list) {
        if (!permLocationList) return;
        permLocationList.innerHTML = '';
        if (!list || list.length === 0) {
            permLocationList.innerHTML = '<div class="muted">Belum ada alamat di storage/alamat.json</div>';
            permLocationBoxes = document.querySelectorAll('.perm-location');
            return;
        }
        list.forEach(function(loc){
            var label = document.createElement('label');
            label.className = 'perm-item';
            var input = document.createElement('input');
            input.type = 'checkbox';
            input.className = 'perm-location';
            input.value = String(loc || '').toUpperCase();
            var span = document.createElement('span');
            span.textContent = loc;
            label.appendChild(input);
            label.appendChild(span);
            permLocationList.appendChild(label);
        });
        permLocationBoxes = document.querySelectorAll('.perm-location');
        if (modal && modal.style.display === 'flex') {
            var row = editingId ? tableBody.querySelector('tr[data-id="' + editingId + '"]') : null;
            var perms = row ? (row.dataset.permissions || '') : collectPermissions();
            setPermissionChecks(perms);
        }
    }

    function removeRow(id) {
        var row = tableBody.querySelector('tr[data-id="' + id + '"]');
        if (row) row.remove();
        if (tableBody.children.length === 0) {
            tableBody.innerHTML = '<tr><td colspan="8">Belum ada data user.</td></tr>';
        }
    }

    function saveUser() {
        var payload = collectPayload();
        if (!payload.name || !payload.email) {
            modalStatus.textContent = 'Nama dan email wajib diisi.';
            return;
        }
        var method = editingId ? 'PUT' : 'POST';
        var url = editingId ? 'api.php?resource=users&id=' + encodeURIComponent(editingId) : 'api.php?resource=users';
        modalSave.disabled = true;
        modalStatus.textContent = 'Menyimpan...';
        fetch(url, {
            method: method,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function(res){ return res.json(); })
        .then(function(json){
            if (json.error) throw new Error(json.error);
            if (editingId) {
                showResult('Berhasil mengubah user #' + editingId, false);
                updateRow(json.data || json);
            } else {
                showResult('Berhasil menambah user #' + (json.id || ''), false);
                appendRow(json);
            }
            closeModal();
        })
        .catch(function(err){
            modalStatus.textContent = 'Gagal: ' + err.message;
        })
        .finally(function(){
            modalSave.disabled = false;
        });
    }

    function deleteUser(id) {
        if (!confirm('Hapus user #' + id + '?')) return;
        fetch('api.php?resource=users&id=' + encodeURIComponent(id), { method: 'DELETE' })
            .then(function(res){ return res.json(); })
            .then(function(json){
                if (json.error) throw new Error(json.error);
                removeRow(id);
                showResult('User #' + id + ' telah dihapus.', false);
            })
            .catch(function(err){
                showResult('Gagal menghapus user: ' + err.message, true);
            });
    }

    openModalBtn && openModalBtn.addEventListener('click', function(){
        openModal();
    });
    modalCancel && modalCancel.addEventListener('click', closeModal);
    modal && modal.addEventListener('click', function(e){
        if (e.target === modal) closeModal();
    });
    modalSave && modalSave.addEventListener('click', saveUser);
    syncLocationBtn && syncLocationBtn.addEventListener('click', function(){
        if (syncLocationStatus) syncLocationStatus.textContent = 'Menyinkron...';
        fetch('locations_sync.php', { method: 'POST' })
            .then(function(res){ return res.json(); })
            .then(function(json){
                if (json.error) throw new Error(json.error);
                renderLocationOptions(json.locations || []);
                if (syncLocationStatus) syncLocationStatus.textContent = 'Tersinkron.';
            })
            .catch(function(err){
                if (syncLocationStatus) syncLocationStatus.textContent = 'Gagal: ' + err.message;
            });
    });

    tableBody && tableBody.addEventListener('click', function(e){
        var btn = e.target.closest('button');
        if (!btn) return;
        var id = btn.dataset.id;
        if (!id) return;
        if (btn.dataset.action === 'edit') {
            var row = tableBody.querySelector('tr[data-id="' + id + '"]');
            if (!row) return;
            openModal({
                id: id,
                name: row.dataset.name || '',
                email: row.dataset.email || '',
                status: row.dataset.status || 'aktif',
                password: row.dataset.password || '',
                role: row.dataset.role || '',
                permissions: row.dataset.permissions || ''
            });
        }
        if (btn.dataset.action === 'delete') {
            deleteUser(id);
        }
    });
})();
</script>
