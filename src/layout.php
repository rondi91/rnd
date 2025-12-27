<?php
// Shared layout for the admin template.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> | Project Admin</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="app-shell">
        <?php include __DIR__ . '/partials/header.php'; ?>
        <div class="app-body">
            <?php include __DIR__ . '/partials/sidebar.php'; ?>
            <div class="sidebar-backdrop" aria-hidden="true"></div>
            <main class="content" role="main">
                <?php if (isset($notFound) && $notFound): ?>
                    <div class="alert">Halaman tidak ditemukan, menampilkan beranda.</div>
                <?php endif; ?>
                <?php if (isset($accessDenied) && $accessDenied): ?>
                    <div class="alert" style="background:#ffe4e4; border-color:#fca5a5; color:#b91c1c;">
                        Akses ditolak untuk halaman tersebut.
                    </div>
                <?php endif; ?>
                <?php include $contentFile; ?>
            </main>
        </div>
    </div>
    <script src="assets/js/app.js"></script>
</body>
</html>
