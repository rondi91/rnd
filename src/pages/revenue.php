<?php
$routersFile = __DIR__ . '/../../storage/mikrotik.json';
$priceFile = __DIR__ . '/../../storage/pppoe_prices.json';

$routers = file_exists($routersFile) ? json_decode(file_get_contents($routersFile), true) : [];
if (!is_array($routers)) {
    $routers = [];
}
$servers = array_values(array_filter($routers, function ($r) {
    return isset($r['category']) && strtolower(trim($r['category'])) === 'server';
}));

$priceMap = [];
if (file_exists($priceFile)) {
    $decoded = json_decode(file_get_contents($priceFile), true);
    if (is_array($decoded)) {
        $priceMap = $decoded;
    }
}
?>
<div class="page-head">
    <h1>Revenue PPPoE</h1>
    <p>Profil PPPoE per server, total user, harga, dan estimasi pendapatan.</p>
</div>

<section class="card">
    <div style="display:flex; gap:1rem; align-items:center; justify-content:space-between; flex-wrap:wrap;">
        <div>
            <h2 style="margin:0;">Ringkasan</h2>
            <p class="muted" style="margin:0;">Data diambil dari PPPoE (secret/active) per server.</p>
        </div>
        <div style="display:flex; gap:0.5rem; align-items:center;">
            <button type="button" class="ghost" id="rev-refresh">Refresh Data</button>
            <button type="button" class="ghost" id="rev-reset-filter">Reset Filter</button>
        </div>
    </div>
    <div style="margin-top:0.75rem; display:flex; gap:0.75rem; flex-wrap:wrap;">
        <div class="metric-box">
            <div class="label">Total User</div>
            <div class="value" id="rev-total-users">0</div>
        </div>
        <div class="metric-box">
            <div class="label">User Berharga</div>
            <div class="value" id="rev-total-priced">0</div>
        </div>
    </div>
    <div style="margin-top:0.75rem; display:flex; gap:0.75rem; flex-wrap:wrap;">
        <label style="display:flex; align-items:center; gap:0.35rem;">
            <span>Filter Server</span>
            <select id="rev-router-filter">
                <option value="">Semua</option>
                <?php foreach ($servers as $srv): ?>
                    <option value="<?php echo htmlspecialchars($srv['id'] ?? ''); ?>">
                        <?php echo htmlspecialchars($srv['name'] ?? ('Router #' . ($srv['id'] ?? ''))); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label style="display:flex; align-items:center; gap:0.35rem;">
            <span>Filter Profile</span>
            <input type="search" id="rev-profile-filter" placeholder="nama profile">
        </label>
    </div>
</section>

<section class="card">
    <h2>Detail Per Server</h2>
    <div id="rev-errors" class="alert" style="display:none;"></div>
    <div id="rev-list" class="rev-grid"></div>
    <div id="rev-total" class="rev-total"></div>
</section>

<div id="rev-modal" class="modal-backdrop" style="display:none;">
    <div class="modal" style="max-width:420px;">
        <header>Atur Harga Profile</header>
        <div class="muted">Tetapkan harga per profile PPPoE. Perubahan disimpan ke file konfigurasi.</div>
        <div style="display:grid; gap:0.65rem;">
            <label style="display:flex; flex-direction:column; gap:0.3rem;">
                <span>Server</span>
                <input type="text" id="rev-modal-router" class="input" readonly>
            </label>
            <label style="display:flex; flex-direction:column; gap:0.3rem;">
                <span>Profile</span>
                <input type="text" id="rev-modal-profile" class="input" readonly>
            </label>
            <label style="display:flex; flex-direction:column; gap:0.3rem;">
                <span>Harga</span>
                <input type="number" min="0" step="100" id="rev-modal-price" class="input" placeholder="mis. 150000">
            </label>
            <div class="status" id="rev-modal-status"></div>
        </div>
        <footer>
            <button type="button" class="ghost" id="rev-modal-cancel">Batal</button>
            <button type="button" id="rev-modal-save">Simpan</button>
        </footer>
    </div>
</div>

<div id="rev-user-modal" class="modal-backdrop" style="display:none;">
    <div class="modal" style="max-width:520px; width:90%;">
        <header>Daftar User</header>
        <div class="muted" id="rev-user-subtitle"></div>
        <div id="rev-user-list" style="max-height:360px; overflow:auto; border:1px solid #e5e7eb; border-radius:10px; padding:0.5rem;"></div>
        <footer>
            <button type="button" class="ghost" id="rev-user-close">Tutup</button>
        </footer>
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
    z-index: 120;
}
.modal {
    background: #fff;
    border-radius: 12px;
    padding: 1rem;
    box-shadow: 0 14px 40px rgba(0,0,0,0.28);
}
.modal header { font-weight: 700; margin-bottom: 0.35rem; }
.modal footer { display: flex; gap: 0.5rem; justify-content: flex-end; margin-top: 0.8rem; }
.modal .status { font-size: 0.9rem; color: #666; min-height: 1.1rem; }

.rev-grid {
    display: grid;
    gap: 1rem;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
}
.rev-card {
    background: #0f1c2f;
    color: #e8f0ff;
    border: 1px solid #1f3558;
    border-radius: 12px;
    padding: 0.9rem;
}
.rev-card h3 {
    margin: 0 0 0.25rem;
}
.rev-card .muted {
    color: #9bb0d4;
}
.rev-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 0.35rem;
}
.rev-table th,
.rev-table td {
    padding: 0.4rem;
    border-bottom: 1px solid #1f3558;
    text-align: left;
}
.rev-table th {
    color: #9bb0d4;
    font-size: 0.9rem;
}
.rev-btn {
    background: transparent;
    color: #7bd6ff;
    border: 1px solid #7bd6ff;
    padding: 0.3rem 0.6rem;
    border-radius: 8px;
    font-size: 0.9rem;
}
.rev-btn.secondary {
    color: #ffe58f;
    border-color: #ffe58f;
    margin-left: 0.35rem;
}
.rev-actions {
    display: inline-flex;
    flex-wrap: wrap;
    gap: 0.35rem;
}
.rev-total {
    margin-top: 1rem;
    font-weight: 700;
    font-size: 1.2rem;
}
.rev-pill {
    display: inline-block;
    padding: 0.15rem 0.5rem;
    border-radius: 999px;
    background: #1f3558;
    color: #dbe8ff;
    font-weight: 700;
    font-size: 0.9rem;
}
.metric-box {
    background: #f1f5fb;
    border: 1px solid #d8deea;
    border-radius: 12px;
    padding: 0.75rem 1rem;
    min-width: 160px;
}
.metric-box .label { color: #6b7280; font-weight: 600; font-size: 0.9rem; }
.metric-box .value { font-weight: 800; font-size: 1.2rem; }
@media (max-width: 720px) {
    .rev-table thead {
        display: none;
    }
    .rev-table,
    .rev-table tbody,
    .rev-table tr,
    .rev-table td {
        display: block;
        width: 100%;
    }
    .rev-table tr {
        border: 1px solid #1f3558;
        border-radius: 10px;
        padding: 0.5rem 0.6rem;
        margin-bottom: 0.6rem;
        background: #132545;
    }
    .rev-table td {
        border: 0;
        padding: 0.3rem 0;
        display: flex;
        justify-content: space-between;
        gap: 0.75rem;
    }
    .rev-table td::before {
        content: attr(data-label);
        color: #9bb0d4;
        font-weight: 600;
        flex: 0 0 auto;
    }
    .rev-table td[data-label="Aksi"] {
        justify-content: flex-start;
    }
}
</style>

<script>
(function(){
    var revList = document.getElementById('rev-list');
    var revErrors = document.getElementById('rev-errors');
    var revTotal = document.getElementById('rev-total');
    var routerFilter = document.getElementById('rev-router-filter');
    var profileFilter = document.getElementById('rev-profile-filter');
    var refreshBtn = document.getElementById('rev-refresh');
    var resetFilterBtn = document.getElementById('rev-reset-filter');

    var modal = document.getElementById('rev-modal');
    var modalRouter = document.getElementById('rev-modal-router');
    var modalProfile = document.getElementById('rev-modal-profile');
    var modalPrice = document.getElementById('rev-modal-price');
    var modalStatus = document.getElementById('rev-modal-status');
    var modalSave = document.getElementById('rev-modal-save');
    var modalCancel = document.getElementById('rev-modal-cancel');
    var totalUsersBox = document.getElementById('rev-total-users');
    var totalPricedBox = document.getElementById('rev-total-priced');

    var userModal = document.getElementById('rev-user-modal');
    var userSubtitle = document.getElementById('rev-user-subtitle');
    var userList = document.getElementById('rev-user-list');
    var userClose = document.getElementById('rev-user-close');

    var priceMap = <?php echo json_encode($priceMap, JSON_UNESCAPED_SLASHES); ?> || {};
    var routerMap = <?php echo json_encode(array_column($servers, null, 'id'), JSON_UNESCAPED_SLASHES); ?> || {};
    var current = { routerId: '', profile: '' };
    var groupedData = {};

    function formatCurrency(num) {
        var n = Number(num || 0);
        return 'Rp ' + n.toLocaleString('id-ID');
    }

    function showError(msg) {
        if (!revErrors) return;
        if (!msg) {
            revErrors.style.display = 'none';
            revErrors.textContent = '';
            return;
        }
        revErrors.style.display = 'block';
        revErrors.textContent = msg;
    }

    function fetchData() {
        showError('');
        if (revList) revList.innerHTML = 'Memuat...';
        var params = [];
        var rf = routerFilter ? routerFilter.value.trim() : '';
        var pf = profileFilter ? profileFilter.value.trim() : '';
        if (rf) params.push('router_id=' + encodeURIComponent(rf));
        if (pf) params.push('profile=' + encodeURIComponent(pf));
        var url = 'pppoe_data.php' + (params.length ? ('?' + params.join('&')) : '');
        fetch(url)
            .then(function(res){ return res.json(); })
            .then(function(json){
                if (json.error) throw new Error(json.error);
                render(json);
            })
            .catch(function(err){
                showError('Gagal memuat data: ' + err.message);
                if (revList) revList.innerHTML = '';
            });
    }

    function groupData(json) {
        var grouped = {};
        var list = [];
        (json.active || []).forEach(function(item){
            list.push(item);
        });
        (json.inactive_users || []).forEach(function(item){
            list.push(item);
        });
        list.forEach(function(item){
            var rid = String(item.router_id || '');
            var rname = item.router_name || (routerMap[rid] ? (routerMap[rid].name || '') : '');
            var profile = item.profile || '';
            var uname = item.username || '';
            var ip = item.ip_address || item.last_ip || '';
            if (!rid || !profile) return;
            if (!grouped[rid]) grouped[rid] = { name: rname || ('Router #' + rid), profiles: {} };
            if (!grouped[rid].profiles[profile]) grouped[rid].profiles[profile] = { count: 0, users: [] };
            grouped[rid].profiles[profile].count += 1;
            if (uname) grouped[rid].profiles[profile].users.push({ username: uname, ip: ip });
        });
        return grouped;
    }

    function render(json) {
        if (!revList) return;
        groupedData = groupData(json);
        var grouped = groupedData;
        var totalAll = 0;
        var totalUsers = 0;
        var totalPriced = 0;
        revList.innerHTML = '';
        Object.keys(grouped).sort().forEach(function(rid){
            var card = document.createElement('div');
            card.className = 'rev-card';
            var header = document.createElement('div');
            header.style.display = 'flex';
            header.style.justifyContent = 'space-between';
            header.style.alignItems = 'center';
            var title = document.createElement('h3');
            title.textContent = grouped[rid].name || ('Router #' + rid);
            var pill = document.createElement('span');
            var profileCount = Object.keys(grouped[rid].profiles).length;
            pill.className = 'rev-pill';
            pill.textContent = profileCount + ' profile';
            header.appendChild(title);
            header.appendChild(pill);
            card.appendChild(header);

            var table = document.createElement('table');
            table.className = 'rev-table';
            table.innerHTML = '<thead><tr><th>Profile</th><th>User</th><th>Harga</th><th>Subtotal</th><th></th></tr></thead>';
            var tbody = document.createElement('tbody');
            var subtotalRouter = 0;
            Object.keys(grouped[rid].profiles).sort().forEach(function(pf){
                var info = grouped[rid].profiles[pf];
                var count = info.count;
                var price = 0;
                if (priceMap[rid] && priceMap[rid][pf]) {
                    price = Number(priceMap[rid][pf]) || 0;
                }
                var subtotal = price * count;
                subtotalRouter += subtotal;
                totalAll += subtotal;
                totalUsers += count;
                if (price > 0) {
                    totalPriced += count;
                }
                var tr = document.createElement('tr');
                tr.innerHTML =
                    '<td data-label="Profile">' + pf + '</td>' +
                    '<td data-label="User">' + count + '</td>' +
                    '<td data-label="Harga">' + formatCurrency(price) + '</td>' +
                    '<td data-label="Subtotal">' + formatCurrency(subtotal) + '</td>' +
                    '<td data-label="Aksi">' +
                        '<div class="rev-actions">' +
                            '<button type="button" class="rev-btn" data-router="' + rid + '" data-profile="' + pf + '">Set Harga</button>' +
                            '<button type="button" class="rev-btn secondary rev-users-btn" data-router="' + rid + '" data-profile="' + pf + '">Lihat User</button>' +
                        '</div>' +
                    '</td>';
                tbody.appendChild(tr);
            });
            table.appendChild(tbody);
            card.appendChild(table);

            var foot = document.createElement('div');
            foot.style.marginTop = '0.35rem';
            foot.style.fontWeight = '700';
            foot.textContent = 'Total Router: ' + formatCurrency(subtotalRouter);
            card.appendChild(foot);

            revList.appendChild(card);
        });

        if (revTotal) {
            revTotal.textContent = 'Total Semua: ' + formatCurrency(totalAll);
        }
        if (totalUsersBox) {
            totalUsersBox.textContent = totalUsers;
        }
        if (totalPricedBox) {
            totalPricedBox.textContent = totalPriced;
        }
    }

    function openModal(rid, profile) {
        if (!modal) {
            alert('Modal tidak tersedia di halaman ini.');
            return;
        }
        current.routerId = rid;
        current.profile = profile;
        if (modalRouter) modalRouter.value = (routerMap[rid] ? (routerMap[rid].name || ('Router #' + rid)) : ('Router #' + rid));
        if (modalProfile) modalProfile.value = profile;
        var price = '';
        if (priceMap[rid] && priceMap[rid][profile]) price = priceMap[rid][profile];
        if (modalPrice) modalPrice.value = price;
        if (modalStatus) modalStatus.textContent = '';
        if (modal) modal.style.display = 'flex';
    }

    function closeModal() {
        if (modal) modal.style.display = 'none';
        current = { routerId: '', profile: '' };
    }

    function savePrice() {
        if (!current.routerId || !current.profile) return;
        var val = modalPrice ? modalPrice.value.trim() : '';
        if (val === '') {
            modalStatus.textContent = 'Harga tidak boleh kosong.';
            return;
        }
        var num = Number(val);
        if (isNaN(num) || num < 0) {
            modalStatus.textContent = 'Harga harus berupa angka >= 0.';
            return;
        }
        modalSave.disabled = true;
        modalStatus.textContent = 'Menyimpan...';
        fetch('revenue_pppoe.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ router_id: current.routerId, profile: current.profile, price: num })
        })
        .then(function(res){ return res.json(); })
        .then(function(json){
            if (json.error) throw new Error(json.error);
            if (!priceMap[current.routerId]) priceMap[current.routerId] = {};
            priceMap[current.routerId][current.profile] = num;
            modalStatus.textContent = 'Harga tersimpan.';
            closeModal();
            fetchData();
        })
        .catch(function(err){
            modalStatus.textContent = 'Gagal: ' + err.message;
        })
        .finally(function(){
            modalSave.disabled = false;
        });
    }

    function handleSetPriceClick(e) {
        var btn = e.target.closest('.rev-btn');
        if (btn) {
            var rid = btn.dataset.router || '';
            var pf = btn.dataset.profile || '';
            if (btn.classList.contains('rev-users-btn')) {
                openUserModal(rid, pf);
            } else {
                openModal(rid, pf);
            }
        }
    }

    document.addEventListener('click', function(e){
        if (e.target === modal) closeModal();
    });
    if (revList) {
        revList.addEventListener('click', handleSetPriceClick);
    }

    modalCancel && modalCancel.addEventListener('click', closeModal);
    modalSave && modalSave.addEventListener('click', savePrice);
    userClose && userClose.addEventListener('click', function(){
        if (userModal) userModal.style.display = 'none';
    });

    refreshBtn && refreshBtn.addEventListener('click', fetchData);
    resetFilterBtn && resetFilterBtn.addEventListener('click', function(){
        if (routerFilter) routerFilter.value = '';
        if (profileFilter) profileFilter.value = '';
        fetchData();
    });
    profileFilter && profileFilter.addEventListener('input', function(){
        // only affects client filter; server filter still applied via URL
        // to limit request size, trigger fetch after small delay
        if (this.value.trim().length === 0) {
            // empty: reload all to clear server-side filter
            fetchData();
        }
    });

    fetchData();

    function openUserModal(rid, profile) {
        if (!userModal) return;
        var info = groupedData[rid] && groupedData[rid].profiles ? groupedData[rid].profiles[profile] : null;
        if (!info) {
            alert('Data user tidak ditemukan untuk profile ini.');
            return;
        }
        if (userSubtitle) {
            var rname = routerMap[rid] ? (routerMap[rid].name || ('Router #' + rid)) : ('Router #' + rid);
            userSubtitle.textContent = rname + ' • Profile: ' + profile + ' • ' + info.count + ' user';
        }
        if (userList) {
            userList.innerHTML = '';
            if (info.users.length === 0) {
                userList.textContent = 'Tidak ada data user.';
            } else {
                info.users.forEach(function(u){
                    var div = document.createElement('div');
                    div.style.padding = '0.35rem 0';
                    div.style.borderBottom = '1px solid #e5e7eb';
                    div.innerHTML = '<strong>' + (u.username || '') + '</strong>' + (u.ip ? ' <span style="color:#6b7280;">(' + u.ip + ')</span>' : '');
                    userList.appendChild(div);
                });
            }
        }
        userModal.style.display = 'flex';
    }
})();
</script>
