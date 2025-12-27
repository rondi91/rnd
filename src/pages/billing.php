<?php
$dataFile = __DIR__ . '/../../storage/billing.json';
$priceFile = __DIR__ . '/../../storage/pppoe_prices.json';
$billingData = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];
if (!is_array($billingData)) $billingData = [];
$priceMap = file_exists($priceFile) ? json_decode(file_get_contents($priceFile), true) : [];
if (!is_array($priceMap)) $priceMap = [];
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
    <h1>Billing PPPoE</h1>
    <p>Status pembayaran (lunas / belum bayar) per user PPPoE.</p>
</div>

<section class="card">
    <div style="display:flex; gap:0.75rem; flex-wrap:wrap; align-items:center; justify-content:space-between;">
        <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
            <div class="metric-box">
                <div class="label">Pembayaran bulan ini</div>
                <div class="value" id="bill-total-this-month">Rp 0</div>
            </div>
            <div class="metric-box">
                <div class="label">Lunas</div>
                <div class="value" id="bill-total-paid">0 user</div>
            </div>
            <div class="metric-box">
                <div class="label">Belum Bayar</div>
                <div class="value" id="bill-total-unpaid">0 user</div>
            </div>
            <div class="metric-box">
                <div class="label">Total User</div>
                <div class="value" id="bill-total-all">0 user</div>
            </div>
        </div>
        <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
            <label style="display:flex; align-items:center; gap:0.35rem;">
                <span>Bulan</span>
                <input type="month" id="bill-month" style="padding:0.45rem 0.6rem;">
            </label>
            <button type="button" class="ghost" id="bill-refresh">Refresh</button>
            <div style="display:flex; gap:0.35rem; flex-wrap:wrap;">
                <button type="button" class="ghost" id="bill-export-csv">Export CSV</button>
                <button type="button" class="ghost" id="bill-export-excel">Export Excel</button>
                <button type="button" class="ghost" id="bill-export-pdf">Export PDF</button>
            </div>
            <button type="button" class="ghost" id="bill-clear-month" style="display:none; border-color:#fca5a5; color:#b91c1c;">Hapus Pembayaran Bulan Ini</button>
        </div>
    </div>
    <div id="bill-admin-section" style="display:none; margin-top:0.75rem;">
        <div class="muted" style="margin-bottom:0.35rem;">Total admin bayar</div>
        <div id="bill-admin-totals" style="display:flex; gap:0.6rem; flex-wrap:wrap;"></div>
    </div>
    <div id="bill-backup-section" class="alert" data-admin="<?php echo $isAdminView ? '1' : '0'; ?>" style="display:<?php echo $isAdminView ? 'block' : 'none'; ?>; margin-top:0.75rem; background:#f8fafc; border-color:#e2e8f0; color:#0f172a;">
        <div style="font-weight:600; margin-bottom:0.4rem;">Backup Data Billing (Admin)</div>
        <div style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center;">
            <button type="button" class="ghost" id="bill-backup-export">Export Backup</button>
            <label class="ghost" style="display:inline-flex; align-items:center; gap:0.4rem; padding:0.45rem 0.75rem; cursor:pointer;">
                <span>Pilih File</span>
                <input type="file" id="bill-backup-file" accept="application/json" style="display:none;">
            </label>
            <button type="button" class="ghost" id="bill-backup-import">Import Backup</button>
            <label style="display:inline-flex; align-items:center; gap:0.35rem; padding-left:0.25rem;">
                <input type="checkbox" id="bill-backup-replace">
                <span>Replace backup terakhir</span>
            </label>
        </div>
        <div class="muted" id="bill-backup-status" style="margin-top:0.4rem;"></div>
            <div style="margin-top:0.6rem;">
                <div class="muted" style="margin-bottom:0.35rem;">File backup otomatis</div>
            <div style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center; margin-bottom:0.4rem;">
                <button type="button" class="ghost" id="bill-backup-refresh">Refresh List</button>
                <button type="button" class="ghost" id="bill-backup-download-latest">Download Terbaru</button>
                <button type="button" class="ghost" id="bill-backup-delete-latest" style="border-color:#fca5a5; color:#b91c1c;">Delete Terbaru</button>
            </div>
            <div class="table-wrapper">
                <table class="bill-table">
                    <thead>
                        <tr>
                            <th>Nama File</th>
                            <th>Ukuran</th>
                            <th>Waktu</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="bill-backup-list">
                        <tr><td colspan="4">Memuat...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<section class="card">
    <div class="bill-toolbar">
        <div class="bill-tabs">
            <button type="button" class="ghost tab-btn is-active" data-tab="unpaid">Belum Bayar</button>
            <button type="button" class="ghost tab-btn" data-tab="paid">Lunas</button>
        </div>
        <div class="bill-spacer"></div>
        <label class="bill-field">
            <span>Cari</span>
            <input type="search" id="bill-search" placeholder="user / profile / router / lokasi" style="padding:0.45rem 0.6rem;">
        </label>
        <label class="bill-field">
            <span>Alamat</span>
            <select id="bill-location" style="padding:0.45rem 0.6rem;">
                <option value="">Semua</option>
            </select>
        </label>
        <label class="bill-field">
            <span>Per halaman</span>
            <select id="bill-page-size" style="padding:0.45rem 0.6rem;">
                <option value="5">5</option>
                <option value="10" selected>10</option>
                <option value="20">20</option>
                <option value="50">50</option>
            </select>
        </label>
        <div class="bill-pager">
            <button type="button" class="ghost" id="bill-page-prev">&laquo;</button>
            <span id="bill-page-info" class="muted">Page 1/1</span>
            <button type="button" class="ghost" id="bill-page-next">&raquo;</button>
        </div>
    </div>
    <div id="bill-error" class="alert" style="display:none;"></div>
    <div id="tab-unpaid">
        <div class="table-wrapper">
            <table class="bill-table">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>User</th>
                        <th>Profile</th>
                        <th>Router</th>
                        <th>Harga</th>
                        <th>Bulan Tunggak</th>
                        <th>Tagihan</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody id="bill-unpaid-body">
                    <tr><td colspan="8">Memuat...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <div id="tab-paid" style="display:none;">
        <div class="table-wrapper">
            <table class="bill-table">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>User</th>
                        <th>Profile</th>
                        <th>Router</th>
                        <th>Harga</th>
                        <th>Terakhir Bayar</th>
                        <th>Bulan Dibayar</th>
                        <th>Admin Bayar</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody id="bill-paid-body">
                    <tr><td colspan="9">Memuat...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</section>

<div id="bill-modal" class="modal-backdrop" style="display:none;">
    <div class="modal" style="max-width:420px;">
        <header id="bill-modal-title">Konfirmasi Pembayaran</header>
        <div class="muted" id="bill-modal-desc"></div>
        <div style="display:grid; gap:0.6rem; margin-top:0.5rem;">
            <label style="display:flex; flex-direction:column; gap:0.3rem;">
                <span>Nama User</span>
                <input type="text" id="bill-modal-user" class="input" readonly>
            </label>
            <label style="display:flex; flex-direction:column; gap:0.3rem;">
                <span>Profile</span>
                <input type="text" id="bill-modal-profile" class="input" readonly>
            </label>
            <label style="display:flex; flex-direction:column; gap:0.3rem;">
                <span>Bulan pembayaran</span>
                <input type="month" id="bill-modal-month">
            </label>
            <label style="display:flex; flex-direction:column; gap:0.3rem;">
                <span>Bulan dibayar</span>
                <input type="number" id="bill-modal-months" class="input" min="1" step="1" value="1">
            </label>
            <label style="display:flex; flex-direction:column; gap:0.3rem;">
                <span>Total dibayar (optional, override)</span>
                <input type="number" id="bill-modal-amount" class="input" min="0" step="1000" placeholder="mis. 150000">
            </label>
            <div class="status" id="bill-modal-status"></div>
        </div>
        <footer>
            <button type="button" class="ghost" id="bill-modal-cancel">Batal</button>
            <button type="button" id="bill-modal-pay">Tandai Lunas</button>
        </footer>
    </div>
</div>

<div id="bill-wa-modal" class="modal-backdrop" style="display:none;">
    <div class="modal" style="max-width:420px;">
        <header>Set Nomor WA</header>
        <div class="muted" id="bill-wa-desc"></div>
        <div style="display:grid; gap:0.6rem; margin-top:0.5rem;">
            <label style="display:flex; flex-direction:column; gap:0.3rem;">
                <span>Nomor WA</span>
                <input type="text" id="bill-wa-input" class="input" placeholder="contoh: 628123456789">
            </label>
            <div class="status" id="bill-wa-status"></div>
        </div>
        <footer>
            <button type="button" class="ghost" id="bill-wa-cancel">Batal</button>
            <button type="button" id="bill-wa-save">Simpan</button>
        </footer>
    </div>
</div>

<div id="bill-wa-message-modal" class="modal-backdrop" style="display:none;">
    <div class="modal" style="max-width:520px;">
        <header>Kirim Tagihan WA</header>
        <div class="muted" id="bill-wa-message-desc"></div>
        <div style="display:grid; gap:0.6rem; margin-top:0.5rem;">
            <label style="display:flex; flex-direction:column; gap:0.3rem;">
                <span>Pesan</span>
                <textarea id="bill-wa-message-text" class="input textarea" rows="8"></textarea>
            </label>
            <div class="status" id="bill-wa-message-status"></div>
        </div>
        <footer>
            <button type="button" class="ghost" id="bill-wa-message-cancel">Batal</button>
            <button type="button" id="bill-wa-message-send">Kirim WA</button>
        </footer>
    </div>
</div>

<style>
.metric-box {
    background: #eef0f3;
    border: 1px solid #d0d5dd;
    border-radius: 12px;
    padding: 0.75rem 1rem;
    min-width: 180px;
}
.metric-box .label { color: #6b7280; font-weight: 600; font-size: 0.9rem; }
.metric-box .value { font-weight: 800; font-size: 1.2rem; }
.tab-btn.is-active {
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
}
.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.45);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 140;
}
.modal {
    background: #fff;
    border-radius: 12px;
    padding: 1rem;
    box-shadow: 0 14px 40px rgba(0,0,0,0.25);
}
.modal header { font-weight: 700; margin-bottom: 0.35rem; }
.modal footer { display: flex; gap: 0.5rem; justify-content: flex-end; margin-top: 0.8rem; }
.modal .status { font-size: 0.9rem; color: #666; min-height: 1.1rem; }
.modal .input.textarea {
    min-height: 140px;
    resize: vertical;
}
.bill-toolbar {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 0.75rem;
    flex-wrap: wrap;
    align-items: center;
}
.bill-tabs {
    display: flex;
    gap: 0.5rem;
}
.bill-spacer {
    flex: 1;
}
.bill-field {
    display: flex;
    align-items: center;
    gap: 0.35rem;
}
.bill-pager {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.bill-table th,
.bill-table td {
    white-space: nowrap;
}
.bill-table tbody tr {
    background: #f3f4f6;
}
.btn-pay {
    background: #22c55e;
    border: 1px solid #22c55e;
    color: #fff;
}
#bill-modal-pay {
    background: #22c55e;
    border: 1px solid #22c55e;
    color: #fff;
}
.cell-mobile {
    display: none;
}
.action-group {
    display: inline-flex;
    gap: 0.4rem;
    flex-wrap: wrap;
}
@media (max-width: 720px) {
    .bill-tabs {
        width: 100%;
    }
    .bill-tabs .tab-btn {
        flex: 1;
    }
    .bill-field {
        width: 100%;
        justify-content: space-between;
    }
    .bill-field input,
    .bill-field select {
        width: 100%;
        max-width: 220px;
    }
    .bill-pager {
        width: 100%;
        justify-content: space-between;
    }
    .bill-table thead {
        display: none;
    }
    .bill-table,
    .bill-table tbody,
    .bill-table tr,
    .bill-table td {
        display: block;
        width: 100%;
    }
    .bill-table tr {
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 0.6rem 0.7rem;
        margin-bottom: 0.7rem;
        background: #f3f4f6;
    }
    .bill-table td {
        border: 0;
        padding: 0.35rem 0;
        display: flex;
        justify-content: space-between;
        gap: 0.75rem;
        white-space: normal;
    }
    .bill-table td::before {
        content: attr(data-label);
        font-weight: 600;
        color: var(--muted);
        flex: 0 0 auto;
    }
    .bill-table td[data-label="Aksi"] {
        justify-content: flex-start;
    }
    .bill-table td[data-label]:not([data-label="No"]):not([data-label="User"]):not([data-label="Tagihan"]):not([data-label="Aksi"]) {
        display: none;
    }
    .cell-desktop {
        display: none;
    }
    .cell-mobile {
        display: inline;
    }
}
</style>

<script>
(function(){
    var unpaidBody = document.getElementById('bill-unpaid-body');
    var paidBody = document.getElementById('bill-paid-body');
    var totalThisMonth = document.getElementById('bill-total-this-month');
    var totalPaid = document.getElementById('bill-total-paid');
    var totalUnpaid = document.getElementById('bill-total-unpaid');
    var monthInput = document.getElementById('bill-month');
    var searchInput = document.getElementById('bill-search');
    var locationSelect = document.getElementById('bill-location');
    var refreshBtn = document.getElementById('bill-refresh');
    var exportCsvBtn = document.getElementById('bill-export-csv');
    var exportExcelBtn = document.getElementById('bill-export-excel');
    var exportPdfBtn = document.getElementById('bill-export-pdf');
    var clearMonthBtn = document.getElementById('bill-clear-month');
    var tabButtons = document.querySelectorAll('.tab-btn');
    var tabUnpaid = document.getElementById('tab-unpaid');
    var tabPaid = document.getElementById('tab-paid');
    var errBox = document.getElementById('bill-error');
    var lastData = null;
    var pageSizeSelect = document.getElementById('bill-page-size');
    var pagePrev = document.getElementById('bill-page-prev');
    var pageNext = document.getElementById('bill-page-next');
    var pageInfo = document.getElementById('bill-page-info');
    var currentPage = { unpaid: 1, paid: 1 };
    var adminSection = document.getElementById('bill-admin-section');
    var adminTotals = document.getElementById('bill-admin-totals');
    var backupSection = document.getElementById('bill-backup-section');
    var backupExportBtn = document.getElementById('bill-backup-export');
    var backupImportBtn = document.getElementById('bill-backup-import');
    var backupFile = document.getElementById('bill-backup-file');
    var backupStatus = document.getElementById('bill-backup-status');
    var backupReplace = document.getElementById('bill-backup-replace');
    var backupList = document.getElementById('bill-backup-list');
    var backupRefreshBtn = document.getElementById('bill-backup-refresh');
    var backupDownloadLatest = document.getElementById('bill-backup-download-latest');
    var backupDeleteLatest = document.getElementById('bill-backup-delete-latest');
    var isAdminView = backupSection ? backupSection.dataset.admin === '1' : false;

    var modal = document.getElementById('bill-modal');
    var modalUser = document.getElementById('bill-modal-user');
    var modalProfile = document.getElementById('bill-modal-profile');
    var modalMonth = document.getElementById('bill-modal-month');
    var modalMonths = document.getElementById('bill-modal-months');
    var modalAmount = document.getElementById('bill-modal-amount');
    var modalDesc = document.getElementById('bill-modal-desc');
    var modalStatus = document.getElementById('bill-modal-status');
    var modalPay = document.getElementById('bill-modal-pay');
    var modalCancel = document.getElementById('bill-modal-cancel');
    var modalTitle = document.getElementById('bill-modal-title');

    var current = { username: '', profile: '', router_id: '', price: 0, months_due: 0, mode: 'pay' };
    var waModal = null;
    var waInput = null;
    var waStatus = null;
    var waSave = null;
    var waCancel = null;
    var waDesc = null;
    var waCurrent = { username: '', profile: '', router_id: '', wa: '' };
    var waMessageModal = null;
    var waMessageText = null;
    var waMessageStatus = null;
    var waMessageSend = null;
    var waMessageCancel = null;
    var waMessageDesc = null;
    var waMessageCurrent = { username: '', profile: '', router_id: '', router_name: '', wa: '', period: '', total: 0 };
    var pendingWaMessage = null;

    function fmt(num) {
        return 'Rp ' + Number(num || 0).toLocaleString('id-ID');
    }

    function fmtMonth(value) {
        if (!value) return '-';
        var v = String(value);
        var match = v.match(/\d{4}-\d{2}/);
        if (!match) return v;
        var ym = match[0];
        var d = new Date(ym + '-01T00:00:00');
        if (!isNaN(d.getTime())) {
            return d.toLocaleDateString('id-ID', { month: 'long', year: 'numeric' });
        }
        return ym;
    }

    function setError(msg) {
        if (!errBox) return;
        if (!msg) {
            errBox.style.display = 'none';
            errBox.textContent = '';
            return;
        }
        errBox.style.display = 'block';
        errBox.textContent = msg;
    }

    function setBackupStatus(msg, isError) {
        if (!backupStatus) return;
        backupStatus.textContent = msg || '';
        backupStatus.style.color = isError ? '#b91c1c' : '#0f172a';
    }

    function loadBackupReplaceState() {
        if (!backupReplace) return;
        try {
            var saved = localStorage.getItem('billing_backup_replace');
            backupReplace.checked = saved === '1';
        } catch (e) {
            backupReplace.checked = false;
        }
    }

    function saveBackupReplaceState() {
        if (!backupReplace) return;
        try {
            localStorage.setItem('billing_backup_replace', backupReplace.checked ? '1' : '0');
        } catch (e) {
            // ignore storage errors
        }
    }

    function formatSize(bytes) {
        var b = Number(bytes || 0);
        if (b >= 1024 * 1024) return (b / (1024 * 1024)).toFixed(2) + ' MB';
        if (b >= 1024) return (b / 1024).toFixed(2) + ' KB';
        return b + ' B';
    }

    function loadBackupList() {
        if (!backupList) return;
        backupList.innerHTML = '<tr><td colspan="4">Memuat...</td></tr>';
        fetch('payment_backup_list.php')
            .then(function(res){ return res.json(); })
            .then(function(json){
                if (json.error) throw new Error(json.error);
                var files = Array.isArray(json.files) ? json.files : [];
                if (files.length === 0) {
                    backupList.innerHTML = '<tr><td colspan="4">Belum ada backup.</td></tr>';
                    return;
                }
                backupList.innerHTML = '';
                files.forEach(function(item){
                    var tr = document.createElement('tr');
                    var name = item.name || '-';
                    var time = item.mtime ? new Date(item.mtime * 1000).toLocaleString('id-ID') : '-';
                    tr.innerHTML =
                        '<td data-label="Nama File">' + name + '</td>' +
                        '<td data-label="Ukuran">' + formatSize(item.size || 0) + '</td>' +
                        '<td data-label="Waktu">' + time + '</td>' +
                        '<td data-label="Aksi"><a class="ghost" href="payment_backup_download.php?file=' + encodeURIComponent(name) + '">Download</a></td>';
                    backupList.appendChild(tr);
                });
            })
            .catch(function(err){
                backupList.innerHTML = '<tr><td colspan="4">Gagal memuat: ' + err.message + '</td></tr>';
            });
    }

    function switchTab(name) {
        tabButtons.forEach(function(btn){
            if (btn.dataset.tab === name) btn.classList.add('is-active');
            else btn.classList.remove('is-active');
        });
        if (name === 'unpaid') currentPage.unpaid = 1;
        if (name === 'paid') currentPage.paid = 1;
        if (tabUnpaid) tabUnpaid.style.display = name === 'unpaid' ? '' : 'none';
        if (tabPaid) tabPaid.style.display = name === 'paid' ? '' : 'none';
        if (lastData) render(lastData);
    }

    tabButtons.forEach(function(btn){
        btn.addEventListener('click', function(){
            switchTab(btn.dataset.tab);
        });
    });

    function fetchData() {
        setError('');
        if (unpaidBody) unpaidBody.innerHTML = '<tr><td colspan="8">Memuat...</td></tr>';
        if (paidBody) paidBody.innerHTML = '<tr><td colspan="9">Memuat...</td></tr>';
        var params = [];
        if (monthInput && monthInput.value) {
            params.push('month=' + encodeURIComponent(monthInput.value));
        }
        var url = 'billing_data.php' + (params.length ? ('?' + params.join('&')) : '');
        fetch(url)
            .then(function(res){ return res.json(); })
            .then(function(json){
                if (json.error) throw new Error(json.error);
                render(json);
                lastData = json;
                populateLocations(json.locations || []);
            })
            .catch(function(err){
                setError('Gagal memuat: ' + err.message);
            });
    }

    function populateLocations(list) {
        if (!locationSelect) return;
        var cur = locationSelect.value;
        locationSelect.innerHTML = '<option value=\"\">Semua</option>';
        (list || []).sort().forEach(function(loc){
            var opt = document.createElement('option');
            opt.value = loc;
            opt.textContent = loc;
            locationSelect.appendChild(opt);
        });
        if (cur) locationSelect.value = cur;
    }

    function applyFilters(unpaid, paid) {
        var q = (searchInput && searchInput.value ? searchInput.value.toLowerCase().trim() : '');
        var loc = (locationSelect && locationSelect.value ? locationSelect.value.toLowerCase().trim() : '');
        function match(item) {
            var hay = (
                (item.username || '') + ' ' +
                (item.profile || '') + ' ' +
                (item.router_name || '') + ' ' +
                (item.location || '')
            ).toLowerCase();
            var okQ = !q || hay.indexOf(q) !== -1;
            var okLoc = !loc || ((item.location || '').toLowerCase() === loc);
            return okQ && okLoc;
        }
        return {
            unpaid: (unpaid || []).filter(match),
            paid: (paid || []).filter(match)
        };
    }

    function getPageSize() {
        var v = pageSizeSelect ? parseInt(pageSizeSelect.value, 10) : 10;
        if (!v || v < 1) v = 10;
        return v;
    }

    function openExport(format) {
        var targetMonth = monthInput && monthInput.value ? monthInput.value : '';
        var url = 'billing_export.php?format=' + encodeURIComponent(format || 'csv');
        if (targetMonth) {
            url += '&month=' + encodeURIComponent(targetMonth);
        }
        window.open(url, '_blank');
    }

    function normalizeWa(value) {
        var digits = String(value || '').replace(/\D+/g, '');
        if (!digits) return '';
        if (digits[0] === '0') return '62' + digits.slice(1);
        if (digits[0] === '8') return '62' + digits;
        return digits;
    }

    function openWaModal(payload) {
        if (!waModal) return;
        waCurrent.username = payload.username || '';
        waCurrent.profile = payload.profile || '';
        waCurrent.router_id = payload.router_id || '';
        waCurrent.wa = payload.wa || '';
        if (waInput) waInput.value = waCurrent.wa;
        if (waDesc) waDesc.textContent = (waCurrent.username || '-') + ' | ' + (waCurrent.profile || '-') + ' | ' + (payload.router_name || '');
        if (waStatus) waStatus.textContent = '';
        waModal.style.display = 'flex';
    }

    function buildUnpaidMessage(data) {
        var period = data.period || '-';
        var total = fmt(data.total || 0);
        var lines = [
            'Yth. ' + (data.username || 'Pelanggan') + ',',
            'Tagihan PPPoE Anda belum dibayar.',
            'User: ' + (data.username || '-'),
            'Profile: ' + (data.profile || '-'),
            'Router: ' + (data.router_name || '-'),
            'Periode: ' + period,
            'Total: ' + total,
            '',
            'Mohon lakukan pembayaran. Terima kasih.'
        ];
        return lines.join('\n');
    }

    function openWaMessageModal(payload) {
        if (!waMessageModal) return;
        if (!payload.wa) {
            pendingWaMessage = payload;
            openWaModal(payload);
            return;
        }
        waMessageCurrent = {
            username: payload.username || '',
            profile: payload.profile || '',
            router_id: payload.router_id || '',
            router_name: payload.router_name || '',
            wa: payload.wa || '',
            period: payload.period || '',
            total: payload.total || 0
        };
        if (waMessageDesc) {
            waMessageDesc.textContent = (waMessageCurrent.username || '-') + ' | ' + (waMessageCurrent.profile || '-') + ' | ' + (waMessageCurrent.router_name || '');
        }
        if (waMessageText) {
            waMessageText.value = buildUnpaidMessage(waMessageCurrent);
        }
        if (waMessageStatus) waMessageStatus.textContent = '';
        waMessageModal.style.display = 'flex';
    }

    function closeWaMessageModal() {
        if (!waMessageModal) return;
        waMessageModal.style.display = 'none';
    }

    function sendWaMessage() {
        if (!waMessageText || !waMessageSend) return;
        var msg = waMessageText.value.trim();
        if (!msg) {
            if (waMessageStatus) waMessageStatus.textContent = 'Pesan tidak boleh kosong.';
            return;
        }
        var wa = normalizeWa(waMessageCurrent.wa || '');
        if (!wa) {
            if (waMessageStatus) waMessageStatus.textContent = 'Nomor WA belum diisi.';
            return;
        }
        var urlSend = 'https://wa.me/' + wa + '?text=' + encodeURIComponent(msg);
        window.open(urlSend, '_blank');
        closeWaMessageModal();
    }

    function closeWaModal() {
        if (!waModal) return;
        waModal.style.display = 'none';
    }

    function saveWaContact() {
        if (!waSave) return;
        var raw = waInput ? waInput.value : '';
        var wa = normalizeWa(raw);
        waSave.disabled = true;
        if (waStatus) waStatus.textContent = 'Menyimpan...';
        fetch('pppoe_contacts.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                username: waCurrent.username,
                profile: waCurrent.profile,
                router_id: waCurrent.router_id,
                wa: wa
            })
        })
        .then(function(res){ return res.json(); })
        .then(function(json){
            if (json.error) throw new Error(json.error);
            closeWaModal();
            if (pendingWaMessage) {
                pendingWaMessage.wa = wa;
                var payload = pendingWaMessage;
                pendingWaMessage = null;
                openWaMessageModal(payload);
            }
            fetchData();
        })
        .catch(function(err){
            if (waStatus) waStatus.textContent = 'Gagal: ' + err.message;
        })
        .finally(function(){
            waSave.disabled = false;
        });
    }

    function render(json) {
        var filtered = applyFilters(json.unpaid || [], json.paid || []);
        var unpaid = filtered.unpaid;
        var paid = filtered.paid;
        var pageSize = getPageSize();
        var totalUnpaidPages = Math.max(1, Math.ceil(unpaid.length / pageSize));
        var totalPaidPages = Math.max(1, Math.ceil(paid.length / pageSize));
        if (currentPage.unpaid > totalUnpaidPages) currentPage.unpaid = totalUnpaidPages;
        if (currentPage.paid > totalPaidPages) currentPage.paid = totalPaidPages;
        var startUnpaid = (currentPage.unpaid - 1) * pageSize;
        var startPaid = (currentPage.paid - 1) * pageSize;
        var unpaidSlice = unpaid.slice(startUnpaid, startUnpaid + pageSize);
        var paidSlice = paid.slice(startPaid, startPaid + pageSize);
        if (unpaidBody) {
            unpaidBody.innerHTML = '';
            if (unpaidSlice.length === 0) {
                unpaidBody.innerHTML = '<tr><td colspan="8">Semua user sudah lunas.</td></tr>';
            } else {
                unpaidSlice.forEach(function(item, idx){
                    var no = startUnpaid + idx + 1;
                    var totalBill = (item.price || 0) * (item.months_due || 0);
                    var tr = document.createElement('tr');
                    tr.innerHTML =
                        '<td data-label="No">' + no + '</td>' +
                        '<td data-label="User">' + (item.username || '') + '</td>' +
                        '<td data-label="Profile">' + (item.profile || '') + '</td>' +
                        '<td data-label="Router">' + (item.router_name || '') + '</td>' +
                        '<td data-label="Harga">' + fmt(item.price || 0) + '</td>' +
                        '<td data-label="Bulan Tunggak">' + (item.month_names || '-') + '</td>' +
                        '<td data-label="Tagihan">' + fmt(totalBill) + '</td>' +
                        '<td data-label="Aksi">' +
                            '<div class="action-group">' +
                                '<button type="button" class="pay-btn btn-pay" data-username="' + (item.username || '') + '" data-profile="' + (item.profile || '') + '" data-router="' + (item.router_id || '') + '" data-price="' + (item.price || 0) + '" data-months="' + (item.months_due || 0) + '">Bayar</button>' +
                                '<button type="button" class="ghost wa-message-btn" data-username="' + (item.username || '') + '" data-profile="' + (item.profile || '') + '" data-router="' + (item.router_id || '') + '" data-router-name="' + (item.router_name || '') + '" data-wa="' + (item.wa || '') + '" data-period="' + (item.month_names || '') + '" data-total="' + totalBill + '">Tagih WA</button>' +
                                '<button type="button" class="ghost wa-btn" data-username="' + (item.username || '') + '" data-profile="' + (item.profile || '') + '" data-router="' + (item.router_id || '') + '" data-router-name="' + (item.router_name || '') + '" data-wa="' + (item.wa || '') + '">Set WA</button>' +
                            '</div>' +
                        '</td>';
                    unpaidBody.appendChild(tr);
                });
            }
        }
        if (paidBody) {
            paidBody.innerHTML = '';
            if (paidSlice.length === 0) {
                paidBody.innerHTML = '<tr><td colspan="9">Belum ada data lunas.</td></tr>';
            } else {
                paidSlice.forEach(function(item, idx){
                    var no = startPaid + idx + 1;
                    var paidMonth = fmtMonth(item.paid_month || item.last_payment || '');
                    var paidMonthsLabel = (item.last_paid_months && item.last_paid_months > 1)
                        ? ' (' + item.last_paid_months + ' bln)'
                        : '';
                    var paidTotal = (item.price || 0) * (item.last_paid_months || 1);
                    var trp = document.createElement('tr');
                    trp.innerHTML =
                        '<td data-label="No">' + no + '</td>' +
                        '<td data-label="User">' + (item.username || '') + '</td>' +
                        '<td data-label="Profile">' + (item.profile || '') + '</td>' +
                        '<td data-label="Router">' + (item.router_name || '') + '</td>' +
                        '<td data-label="Tagihan"><span class="cell-desktop">' + fmt(item.price || 0) + '</span><span class="cell-mobile">' + fmt(paidTotal) + '</span></td>' +
                        '<td data-label="Terakhir Bayar">' + (item.last_payment || '-') + '</td>' +
                        '<td data-label="Bulan Dibayar">' + paidMonth + paidMonthsLabel + '</td>' +
                        '<td data-label="Admin Bayar">' + (item.paid_by || '-') + '</td>' +
                        '<td data-label="Aksi">' +
                            '<div class="action-group">' +
                                '<button type="button" class="ghost pay-btn" data-username="' + (item.username || '') + '" data-profile="' + (item.profile || '') + '" data-router="' + (item.router_id || '') + '" data-price="' + (item.price || 0) + '" data-months="1">Edit Pembayaran</button> ' +
                                '<button type="button" class="ghost print-btn" data-username="' + (item.username || '') + '" data-profile="' + (item.profile || '') + '" data-router="' + (item.router_id || '') + '" data-month="' + (item.paid_month || '') + '">Cetak Nota</button> ' +
                                '<button type="button" class="ghost wa-send-btn" data-username="' + (item.username || '') + '" data-profile="' + (item.profile || '') + '" data-router="' + (item.router_id || '') + '" data-router-name="' + (item.router_name || '') + '" data-month="' + (item.paid_month || '') + '" data-months="' + (item.last_paid_months || 1) + '" data-total="' + paidTotal + '" data-wa="' + (item.wa || '') + '">Kirim WA</button> ' +
                                '<button type="button" class="ghost wa-btn" data-username="' + (item.username || '') + '" data-profile="' + (item.profile || '') + '" data-router="' + (item.router_id || '') + '" data-router-name="' + (item.router_name || '') + '" data-wa="' + (item.wa || '') + '">Set WA</button> ' +
                                '<button type="button" class="ghost unpay-btn" data-username="' + (item.username || '') + '" data-profile="' + (item.profile || '') + '" data-router="' + (item.router_id || '') + '" data-price="' + (item.price || 0) + '" data-months="1">Tandai Belum Bayar</button>' +
                            '</div>' +
                        '</td>';
                    paidBody.appendChild(trp);
                });
            }
        }
        if (totalThisMonth) totalThisMonth.textContent = fmt(json.total_this_month || 0);
        if (totalPaid) totalPaid.textContent = (paid.length || 0) + ' user';
        if (totalUnpaid) totalUnpaid.textContent = (unpaid.length || 0) + ' user';
        var totalAllBox = document.getElementById('bill-total-all');
        if (totalAllBox) totalAllBox.textContent = (paid.length + unpaid.length) + ' user';
        if (pageInfo) {
            var activeTab = document.querySelector('.tab-btn.is-active')?.dataset.tab || 'unpaid';
            var totalPages = activeTab === 'paid' ? totalPaidPages : totalUnpaidPages;
            var curPage = activeTab === 'paid' ? currentPage.paid : currentPage.unpaid;
            pageInfo.textContent = 'Page ' + curPage + ' / ' + totalPages;
        }
        if (clearMonthBtn) {
            clearMonthBtn.style.display = json && json.is_admin ? 'inline-flex' : 'none';
        }
        if (backupSection) {
            var allow = (json && json.is_admin) || isAdminView;
            backupSection.style.display = allow ? 'block' : 'none';
        }
        renderAdminTotals(json);
    }

    function renderAdminTotals(json) {
        if (!adminSection || !adminTotals) return;
        var list = json.paid_by_totals || [];
        if (!Array.isArray(list) || list.length === 0) {
            adminSection.style.display = 'none';
            adminTotals.innerHTML = '';
            return;
        }
        adminSection.style.display = 'block';
        adminTotals.innerHTML = '';
        list.forEach(function(item){
            var box = document.createElement('div');
            box.className = 'metric-box';
            box.innerHTML = '<div class="label">' + (item.name || '-') + '</div>' +
                            '<div class="value">' + fmt(item.total || 0) + '</div>';
            adminTotals.appendChild(box);
        });
    }

    document.addEventListener('click', function(e){
        var btn = e.target.closest('.pay-btn');
        var btnUnpay = e.target.closest('.unpay-btn');
        var btnPrint = e.target.closest('.print-btn');
        var btnWa = e.target.closest('.wa-btn');
        var btnWaSend = e.target.closest('.wa-send-btn');
        var btnWaMessage = e.target.closest('.wa-message-btn');
        if (btnPrint) {
            var monthValue = btnPrint.dataset.month || (monthInput ? monthInput.value : '');
            var url = 'billing_receipt.php' +
                '?username=' + encodeURIComponent(btnPrint.dataset.username || '') +
                '&profile=' + encodeURIComponent(btnPrint.dataset.profile || '') +
                '&router_id=' + encodeURIComponent(btnPrint.dataset.router || '');
            if (monthValue) {
                url += '&month=' + encodeURIComponent(monthValue);
            }
            window.open(url, '_blank');
            return;
        }
        if (btnWa) {
            openWaModal({
                username: btnWa.dataset.username || '',
                profile: btnWa.dataset.profile || '',
                router_id: btnWa.dataset.router || '',
                router_name: btnWa.dataset.routerName || '',
                wa: btnWa.dataset.wa || ''
            });
            return;
        }
        if (btnWaSend) {
            var wa = normalizeWa(btnWaSend.dataset.wa || '');
            if (!wa) {
                openWaModal({
                    username: btnWaSend.dataset.username || '',
                    profile: btnWaSend.dataset.profile || '',
                    router_id: btnWaSend.dataset.router || '',
                    router_name: btnWaSend.dataset.routerName || '',
                    wa: ''
                });
                return;
            }
            var monthValueSend = btnWaSend.dataset.month || (monthInput ? monthInput.value : '');
            var receiptUrl = buildReceiptUrl({
                username: btnWaSend.dataset.username || '',
                profile: btnWaSend.dataset.profile || '',
                router_id: btnWaSend.dataset.router || '',
                month: monthValueSend
            });
            var text = [
                'Pembayaran PPPoE berhasil.',
                'User: ' + (btnWaSend.dataset.username || ''),
                'Profile: ' + (btnWaSend.dataset.profile || ''),
                'Router: ' + (btnWaSend.dataset.routerName || ''),
                'Bulan: ' + (monthValueSend ? fmtMonth(monthValueSend) : '-'),
                'Total: ' + fmt(btnWaSend.dataset.total || 0),
                'Nota: ' + receiptUrl
            ].join('\n');
            var urlSend = 'https://wa.me/' + wa + '?text=' + encodeURIComponent(text);
            window.open(urlSend, '_blank');
            return;
        }
        if (btnWaMessage) {
            openWaMessageModal({
                username: btnWaMessage.dataset.username || '',
                profile: btnWaMessage.dataset.profile || '',
                router_id: btnWaMessage.dataset.router || '',
                router_name: btnWaMessage.dataset.routerName || '',
                wa: btnWaMessage.dataset.wa || '',
                period: btnWaMessage.dataset.period || '',
                total: Number(btnWaMessage.dataset.total || 0)
            });
            return;
        }
        if (btn || btnUnpay) {
            current.username = btn ? (btn.dataset.username || '') : (btnUnpay.dataset.username || '');
            current.profile = btn ? (btn.dataset.profile || '') : (btnUnpay.dataset.profile || '');
            current.router_id = btn ? (btn.dataset.router || '') : (btnUnpay.dataset.router || '');
            current.price = Number((btn ? btn.dataset.price : btnUnpay.dataset.price) || 0);
            current.months_due = Number((btn ? btn.dataset.months : btnUnpay.dataset.months) || 0);
            current.mode = btn ? 'pay' : 'unpay';
            if (modalUser) modalUser.value = current.username;
            if (modalProfile) modalProfile.value = current.profile;
            if (modalMonths) modalMonths.value = current.months_due > 0 ? current.months_due : 1;
            if (modalMonth) {
                if (monthInput && monthInput.value) {
                    modalMonth.value = monthInput.value;
                } else {
                    var now = new Date();
                    modalMonth.value = now.toISOString().slice(0,7);
                }
            }
            if (modalAmount) modalAmount.value = '';
            if (modalTitle) modalTitle.textContent = current.mode === 'pay' ? 'Konfirmasi Pembayaran' : 'Set Belum Bayar';
            if (modalPay) modalPay.textContent = current.mode === 'pay' ? 'Tandai Lunas' : 'Set Belum Bayar';
            if (modalDesc) {
                if (current.mode === 'pay') {
                    modalDesc.textContent = 'Tagihan: ' + fmt(current.price * (current.months_due || 1));
                } else {
                    modalDesc.textContent = 'Set status belum bayar. Isi bulan tunggakan jika perlu.';
                }
            }
            if (modalStatus) modalStatus.textContent = '';
            if (modal) modal.style.display = 'flex';
        }
        if (e.target === modal) {
            modal.style.display = 'none';
        }
    });

    modalCancel && modalCancel.addEventListener('click', function(){
        if (modal) modal.style.display = 'none';
    });

    waModal = document.getElementById('bill-wa-modal');
    waInput = document.getElementById('bill-wa-input');
    waStatus = document.getElementById('bill-wa-status');
    waSave = document.getElementById('bill-wa-save');
    waCancel = document.getElementById('bill-wa-cancel');
    waDesc = document.getElementById('bill-wa-desc');

    waCancel && waCancel.addEventListener('click', closeWaModal);
    waSave && waSave.addEventListener('click', saveWaContact);
    waModal && waModal.addEventListener('click', function(e){
        if (e.target === waModal) closeWaModal();
    });

    waMessageModal = document.getElementById('bill-wa-message-modal');
    waMessageText = document.getElementById('bill-wa-message-text');
    waMessageStatus = document.getElementById('bill-wa-message-status');
    waMessageSend = document.getElementById('bill-wa-message-send');
    waMessageCancel = document.getElementById('bill-wa-message-cancel');
    waMessageDesc = document.getElementById('bill-wa-message-desc');

    waMessageCancel && waMessageCancel.addEventListener('click', closeWaMessageModal);
    waMessageSend && waMessageSend.addEventListener('click', sendWaMessage);
    waMessageModal && waMessageModal.addEventListener('click', function(e){
        if (e.target === waMessageModal) closeWaMessageModal();
    });

    modalPay && modalPay.addEventListener('click', function(){
        var months = modalMonths ? parseInt(modalMonths.value, 10) : 0;
        if (!months || months < 1) {
            if (modalStatus) modalStatus.textContent = 'Bulan dibayar minimal 1.';
            return;
        }
        var overrideAmount = modalAmount ? Number(modalAmount.value || 0) : 0;
        if (modalAmount && modalAmount.value && (isNaN(overrideAmount) || overrideAmount < 0)) {
            modalStatus.textContent = 'Nilai pembayaran tidak valid.';
            return;
        }
        modalPay.disabled = true;
        if (modalStatus) modalStatus.textContent = 'Menyimpan...';
        var payload = {
            action: current.mode === 'pay' ? 'pay' : 'unpay',
            username: current.username,
            profile: current.profile,
            router_id: current.router_id,
            months: months,
            month: modalMonth && modalMonth.value ? modalMonth.value : '',
            amount: overrideAmount > 0 ? overrideAmount : null,
            backup_mode: backupReplace && backupReplace.checked ? 'replace' : 'new'
        };
        fetch('billing_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function(res){ return res.json(); })
        .then(function(json){
            if (json.error) throw new Error(json.error);
            if (modal) modal.style.display = 'none';
            fetchData();
        })
        .catch(function(err){
            if (modalStatus) modalStatus.textContent = 'Gagal: ' + err.message;
        })
        .finally(function(){
            modalPay.disabled = false;
        });
    });

    refreshBtn && refreshBtn.addEventListener('click', fetchData);
    exportCsvBtn && exportCsvBtn.addEventListener('click', function(){ openExport('csv'); });
    exportExcelBtn && exportExcelBtn.addEventListener('click', function(){ openExport('excel'); });
    exportPdfBtn && exportPdfBtn.addEventListener('click', function(){ openExport('pdf'); });
    backupExportBtn && backupExportBtn.addEventListener('click', function(){
        window.location.href = 'billing_backup.php?action=export';
    });
    backupImportBtn && backupImportBtn.addEventListener('click', function(){
        if (!backupFile || !backupFile.files || backupFile.files.length === 0) {
            setBackupStatus('Pilih file backup terlebih dahulu.', true);
            return;
        }
        if (!confirm('Import backup akan menimpa data billing. Lanjutkan?')) return;
        setBackupStatus('Mengimpor backup...', false);
        var formData = new FormData();
        formData.append('backup_file', backupFile.files[0]);
        fetch('billing_backup.php', {
            method: 'POST',
            body: formData
        })
            .then(function(res){ return res.json(); })
            .then(function(json){
                if (json.error) throw new Error(json.error);
                setBackupStatus(json.message || 'Import selesai', false);
                if (backupFile) backupFile.value = '';
                fetchData();
            })
            .catch(function(err){
                setBackupStatus('Gagal import: ' + err.message, true);
            });
    });
    backupRefreshBtn && backupRefreshBtn.addEventListener('click', loadBackupList);
    backupDownloadLatest && backupDownloadLatest.addEventListener('click', function(){
        if (!backupList) return;
        var first = backupList.querySelector('tr td a');
        if (first && first.href) {
            window.location.href = first.href;
        }
    });
    backupDeleteLatest && backupDeleteLatest.addEventListener('click', function(){
        if (!backupList) return;
        var first = backupList.querySelector('tr td a');
        if (!first || !first.href) {
            setBackupStatus('Tidak ada backup untuk dihapus.', true);
            return;
        }
        if (!confirm('Hapus file backup terbaru?')) return;
        setBackupStatus('Menghapus backup terbaru...', false);
        fetch('payment_backup_delete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ file: 'latest' })
        })
            .then(function(res){ return res.json(); })
            .then(function(json){
                if (json.error) throw new Error(json.error);
                setBackupStatus('Backup dihapus: ' + (json.deleted || ''), false);
                loadBackupList();
            })
            .catch(function(err){
                setBackupStatus('Gagal hapus: ' + err.message, true);
            });
    });
    backupReplace && backupReplace.addEventListener('change', saveBackupReplaceState);
    clearMonthBtn && clearMonthBtn.addEventListener('click', function(){
        var targetMonth = monthInput && monthInput.value ? monthInput.value : '';
        if (!targetMonth) {
            alert('Pilih bulan terlebih dahulu.');
            return;
        }
        if (!confirm('Hapus semua pembayaran untuk bulan ' + targetMonth + '?')) return;
        clearMonthBtn.disabled = true;
        fetch('billing_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'clear_month', month: targetMonth })
        })
        .then(function(res){ return res.json(); })
        .then(function(json){
            if (json.error) throw new Error(json.error);
            fetchData();
        })
        .catch(function(err){
            setError('Gagal menghapus pembayaran: ' + err.message);
        })
        .finally(function(){
            clearMonthBtn.disabled = false;
        });
    });
    searchInput && searchInput.addEventListener('input', function(){
        if (!lastData) return;
        render(lastData);
    });
    locationSelect && locationSelect.addEventListener('change', function(){
        if (!lastData) return;
        render(lastData);
    });
    pageSizeSelect && pageSizeSelect.addEventListener('change', function(){
        currentPage.unpaid = 1;
        currentPage.paid = 1;
        if (lastData) render(lastData);
    });
    pagePrev && pagePrev.addEventListener('click', function(){
        var activeTab = document.querySelector('.tab-btn.is-active')?.dataset.tab || 'unpaid';
        if (activeTab === 'unpaid' && currentPage.unpaid > 1) {
            currentPage.unpaid--;
            if (lastData) render(lastData);
        }
        if (activeTab === 'paid' && currentPage.paid > 1) {
            currentPage.paid--;
            if (lastData) render(lastData);
        }
    });
    pageNext && pageNext.addEventListener('click', function(){
        if (!lastData) return;
        var filtered = applyFilters(lastData.unpaid || [], lastData.paid || []);
        var pageSize = getPageSize();
        var totalUnpaidPages = Math.max(1, Math.ceil(filtered.unpaid.length / pageSize));
        var totalPaidPages = Math.max(1, Math.ceil(filtered.paid.length / pageSize));
        var activeTab = document.querySelector('.tab-btn.is-active')?.dataset.tab || 'unpaid';
        if (activeTab === 'unpaid' && currentPage.unpaid < totalUnpaidPages) {
            currentPage.unpaid++;
            render(lastData);
        }
        if (activeTab === 'paid' && currentPage.paid < totalPaidPages) {
            currentPage.paid++;
            render(lastData);
        }
    });

    switchTab('unpaid');
    // set default bulan = sekarang
    if (monthInput && !monthInput.value) {
        var now = new Date();
        monthInput.value = now.toISOString().slice(0,7);
        monthInput.addEventListener('change', fetchData);
    }
    fetchData();
    if (backupSection && backupSection.dataset.admin === '1') {
        loadBackupReplaceState();
        loadBackupList();
    }

    function buildReceiptUrl(payload) {
        var base = window.location.origin + window.location.pathname;
        if (!base.endsWith('/')) {
            base = base.replace(/\/[^\/]*$/, '/') || base + '/';
        }
        var url = base + 'billing_receipt.php' +
            '?username=' + encodeURIComponent(payload.username || '') +
            '&profile=' + encodeURIComponent(payload.profile || '') +
            '&router_id=' + encodeURIComponent(payload.router_id || '');
        if (payload.month) {
            url += '&month=' + encodeURIComponent(payload.month);
        }
        return url;
    }
})();
</script>
