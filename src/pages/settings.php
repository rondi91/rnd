<?php
$isAdminView = false;
if (isset($currentUser) && is_array($currentUser)) {
    $role = strtolower((string) ($currentUser['role'] ?? ''));
    $perms = $currentUser['permissions'] ?? [];
    if (is_string($perms)) {
        $perms = array_values(array_filter(array_map('trim', explode(',', $perms))));
    }
    if (!is_array($perms)) {
        $perms = [];
    }
    $isAdminView = ($role === 'admin') || in_array('*', $perms, true) || in_array('all', $perms, true);
}
?>
<div class="page-head">
    <h1>Settings</h1>
    <p>Form contoh untuk konfigurasi.</p>
</div>

<section class="card">
    <form class="form-grid">
        <label>
            <span>Nama Aplikasi</span>
            <input type="text" name="app_name" value="Project Admin">
        </label>
        <label>
            <span>Email Notifikasi</span>
            <input type="email" name="notif_email" value="admin@example.com">
        </label>
        <label>
            <span>Bahasa</span>
            <select name="language">
                <option>Indonesia</option>
                <option>English</option>
            </select>
        </label>
        <label>
            <span>Dark Mode</span>
            <input type="checkbox" name="dark_mode">
        </label>
        <button type="submit">Simpan</button>
    </form>
</section>

<section class="card">
    <h2 style="margin-top:0;">Backup Data</h2>
    <p class="muted">Export/import semua data storage (JSON). Hanya admin yang dapat mengakses.</p>
    <div id="system-backup" data-admin="<?php echo $isAdminView ? '1' : '0'; ?>" style="display:<?php echo $isAdminView ? 'block' : 'none'; ?>;">
        <div style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center;">
            <button type="button" class="ghost" id="system-backup-export">Export Backup</button>
            <label class="ghost" style="display:inline-flex; align-items:center; gap:0.4rem; padding:0.45rem 0.75rem; cursor:pointer;">
                <span>Pilih File</span>
                <input type="file" id="system-backup-file" accept="application/json" style="display:none;">
            </label>
            <button type="button" class="ghost" id="system-backup-import">Import Backup</button>
        </div>
        <div class="muted" id="system-backup-status" style="margin-top:0.4rem;"></div>
    </div>
    <?php if (!$isAdminView): ?>
        <div class="alert" style="margin-top:0.75rem;">Fitur backup hanya tersedia untuk admin.</div>
    <?php endif; ?>
</section>

<script>
(function(){
    var wrap = document.getElementById('system-backup');
    var exportBtn = document.getElementById('system-backup-export');
    var importBtn = document.getElementById('system-backup-import');
    var fileInput = document.getElementById('system-backup-file');
    var statusBox = document.getElementById('system-backup-status');
    var isAdmin = wrap ? wrap.dataset.admin === '1' : false;

    function setStatus(msg, isError) {
        if (!statusBox) return;
        statusBox.textContent = msg || '';
        statusBox.style.color = isError ? '#b91c1c' : '#0f172a';
    }

    if (!isAdmin) return;

    exportBtn && exportBtn.addEventListener('click', function(){
        window.location.href = 'system_backup.php?action=export';
    });

    importBtn && importBtn.addEventListener('click', function(){
        if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
            setStatus('Pilih file backup terlebih dahulu.', true);
            return;
        }
        if (!confirm('Import backup akan menimpa semua data. Lanjutkan?')) return;
        setStatus('Mengimpor backup...', false);
        var formData = new FormData();
        formData.append('backup_file', fileInput.files[0]);
        fetch('system_backup.php', {
            method: 'POST',
            body: formData
        })
            .then(function(res){ return res.json(); })
            .then(function(json){
                if (json.error) throw new Error(json.error);
                setStatus(json.message || 'Import selesai', false);
                if (fileInput) fileInput.value = '';
            })
            .catch(function(err){
                setStatus('Gagal import: ' + err.message, true);
            });
    });
})();
</script>
