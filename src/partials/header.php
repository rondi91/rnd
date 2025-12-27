<?php
// Header bar with brand and quick actions.
?>
<header class="header">
    <button class="menu-toggle" type="button" aria-label="Toggle sidebar">
        <span class="menu-icon" aria-hidden="true"><span></span></span>
        <span class="menu-label">Menu</span>
    </button>
    <div class="brand">
        <span class="brand-title">Project Admin</span>
        <small class="brand-subtitle">Starter Template</small>
    </div>
    <div class="header-actions">
        <?php if (isset($currentUser)): ?>
            <span class="user-chip"><?php echo htmlspecialchars($currentUser['name'] ?? 'User'); ?></span>
            <a class="ghost" href="logout.php" style="padding:0.45rem 0.7rem; border-radius:8px;">Logout</a>
        <?php else: ?>
            <span class="user-chip">Guest</span>
        <?php endif; ?>
    </div>
</header>
