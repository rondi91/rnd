<?php
// Front controller for the admin template.
$sessionStarted = session_status() === PHP_SESSION_NONE;
if ($sessionStarted) {
    session_start();
}
require_once __DIR__ . '/auth_helpers.php';

$pageRoutes = require __DIR__ . '/../src/routes.php';
$allRoutes = $pageRoutes;

$currentUser = $_SESSION['user'] ?? null;
if (!$currentUser) {
    $remembered = tryRememberLogin();
    if ($remembered) {
        $_SESSION['user'] = $remembered;
        $currentUser = $remembered;
    }
}
if (!$currentUser) {
    $qs = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    $redirect = $qs ? '?redirect=' . urlencode($qs) : '';
    header('Location: login.php' . $redirect);
    exit;
}

$currentUser = normalizeUser($currentUser);

$requestedPage = isset($_GET['page']) ? trim($_GET['page']) : 'dashboard';
$notFound = false;
$accessDenied = false;

$filteredRoutes = array_filter($pageRoutes, function ($config, $slug) use ($currentUser) {
    return userHasAccess($currentUser, $slug);
}, ARRAY_FILTER_USE_BOTH);
if (!isset($filteredRoutes['dashboard']) && isset($pageRoutes['dashboard']) && userHasAccess($currentUser, 'dashboard')) {
    $filteredRoutes = ['dashboard' => $pageRoutes['dashboard']] + $filteredRoutes;
}
$pageRoutes = $filteredRoutes;
$defaultPage = array_key_first($pageRoutes) ?: '';
if ($defaultPage === '' || $defaultPage === 'dashboard') {
    $defaultPage = isset($pageRoutes['billing']) ? 'billing' : $defaultPage;
}

if (array_key_exists($requestedPage, $allRoutes)) {
    if (!userHasAccess($currentUser, $requestedPage)) {
        $page = $defaultPage;
        $accessDenied = ($page === '' || !isset($pageRoutes[$page]));
    } else {
        $page = $requestedPage;
    }
} else {
    $page = $defaultPage;
    $notFound = $requestedPage !== '';
}

if ($page === '' || !isset($pageRoutes[$page])) {
    if (isset($pageRoutes['billing'])) {
        $route = $pageRoutes['billing'];
        $page = 'billing';
        $contentFile = $route['file'];
    } else {
        $accessDenied = true;
        $pageTitle = 'Access Denied';
        $contentFile = __DIR__ . '/../src/pages/access_denied.php';
        $route = ['title' => $pageTitle];
    }
} else {
    $route = $pageRoutes[$page];
    $contentFile = $route['file'];
}

if (!file_exists($contentFile)) {
    if (isset($pageRoutes['billing'])) {
        $route = $pageRoutes['billing'];
        $page = 'billing';
        $contentFile = $route['file'];
    } else {
        $contentFile = __DIR__ . '/../src/pages/dashboard.php';
        $notFound = true;
    }
}

$pageTitle = $route['title'] ?? 'Project Admin';

require __DIR__ . '/../src/layout.php';

function normalizeUser(array $user): array
{
    $perms = $user['permissions'] ?? [];
    if (is_string($perms)) {
        $perms = array_values(array_filter(array_map('trim', explode(',', $perms))));
    }
    if (!is_array($perms)) {
        $perms = [];
    }
    $user['permissions'] = $perms;
    $user['role'] = $user['role'] ?? '';
    return $user;
}

function userHasAccess(array $user, string $slug): bool
{
    $role = strtolower((string) ($user['role'] ?? ''));
    if ($slug === 'dashboard') {
        return $role === 'admin';
    }
    if ($role === 'admin') {
        return true;
    }
    $perms = $user['permissions'] ?? [];
    if (in_array('*', $perms, true) || in_array('all', $perms, true)) {
        return true;
    }
    foreach ($perms as $perm) {
        if ($perm === $slug) {
            return true;
        }
        if (strpos($perm, ':') !== false) {
            [$base] = array_map('trim', explode(':', $perm, 2));
            if ($base === $slug) {
                return true;
            }
            if ($slug === 'billing' && in_array($base, ['billing', 'billing_location', 'location'], true)) {
                return true;
            }
        }
    }
    return false;
}
