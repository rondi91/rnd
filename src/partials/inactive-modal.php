<?php /** Modal daftar user tidak aktif (secret tanpa sesi aktif) */ ?>
<div id="inactive-modal" class="modal-backdrop" style="display:none;">
    <div class="modal" style="max-width: 900px; width: 95%; background:#fff; color:#1f2933; border:1px solid #e5e7eb; border-radius:18px; padding:0; box-shadow:0 20px 45px rgba(0,0,0,0.2);">
        <div style="padding:16px 18px; background:#f7f9fb; color:#0f172a; border-top-left-radius:18px; border-top-right-radius:18px; border-bottom:1px solid #e5e7eb;">
            <div style="font-size:18px; font-weight:700;">User Tidak Aktif</div>
            <div style="font-size:14px; color:#4b5563;">PPP Secret tanpa sesi aktif</div>
        </div>
        <div style="padding:12px 18px; font-size:14px; color:#4b5563;">Klik Edit/Hapus pada kolom Aksi.</div>
        <div style="max-height: 440px; overflow:auto; padding:0 18px 12px;">
            <?php if (count($inactiveUsers) === 0): ?>
                <div style="padding:0.5rem;">Tidak ada user tidak aktif.</div>
            <?php else: ?>
                <table class="table-responsive" style="width:100%; border-collapse:collapse;">
                    <thead>
                        <tr style="color:#6b7280; font-size:13px; text-transform:uppercase; letter-spacing:0.02em;">
                            <th style="text-align:left; padding:10px 8px; border-bottom:1px solid #e5e7eb;">Router</th>
                            <th style="text-align:left; padding:10px 8px; border-bottom:1px solid #e5e7eb;">User</th>
                            <th style="text-align:left; padding:10px 8px; border-bottom:1px solid #e5e7eb;">Profile</th>
                            <th style="text-align:right; padding:10px 8px; border-bottom:1px solid #e5e7eb;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="inactive-table-body">
                        <?php foreach ($inactiveUsers as $u): ?>
                            <tr data-username="<?php echo htmlspecialchars($u['username']); ?>"
                                data-profile="<?php echo htmlspecialchars($u['profile']); ?>"
                                data-router-id="<?php echo htmlspecialchars($u['router_id']); ?>"
                                data-router-name="<?php echo htmlspecialchars($u['router_name']); ?>">
                                <td data-label="Router" style="padding:10px 8px; font-weight:600; color:#111827;"><?php echo htmlspecialchars($u['router_name']); ?></td>
                                <td data-label="User" style="padding:10px 8px; font-weight:700; color:#1f2937;"><?php echo htmlspecialchars($u['username']); ?></td>
                                <td data-label="Profile" style="padding:10px 8px; color:#374151;"><?php echo htmlspecialchars($u['profile']); ?></td>
                                <td data-label="Aksi" style="padding:10px 8px; text-align:right;">
                                    <button type="button" class="pill-btn inactive-edit-btn" style="padding:8px 12px; background:linear-gradient(135deg,#2563eb,#3b82f6); border:none; color:#fff; border-radius:10px; margin-right:8px;">Edit</button>
                                    <button type="button" class="pill-btn ghost inactive-delete-btn" style="padding:8px 12px; border:1px solid #e11d48; color:#b91c1c;">Hapus</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <div style="padding:12px 18px; border-top:1px solid #e5e7eb; text-align:right; background:#f7f9fb; border-bottom-left-radius:18px; border-bottom-right-radius:18px;">
            <button type="button" class="ghost" id="inactive-close">Tutup</button>
        </div>
    </div>
</div>
