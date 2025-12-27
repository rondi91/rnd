<?php
// Sidebar navigation with active state.
if (!isset($pageRoutes) || !is_array($pageRoutes)) {
    // Fallback to load routes if sidebar is rendered in isolation.
    $pageRoutes = require __DIR__ . '/../routes.php';
}
?>
<aside class="sidebar" aria-label="Sidebar navigation">
    <div class="sidebar-header">
        <button class="sidebar-close" type="button" aria-label="Tutup sidebar">X</button>
        <div class="sidebar-brand">
            <span class="brand-icon">PA</span>
            <span>Project Admin</span>
        </div>
        <span></span>
    </div>
    <div class="nav-section-title">MENU</div>
    <nav>
        <ul class="nav-list">
            <?php foreach ($pageRoutes as $slug => $config): ?>
                <?php $isActive = $slug === $page; ?>
                <li class="nav-item<?php echo $isActive ? ' is-active' : ''; ?>">
                    <a href="?page=<?php echo urlencode($slug); ?>">
                        <?php echo htmlspecialchars($config['title']); ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>
</aside>
