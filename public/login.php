<?php
declare(strict_types=1);

session_start();
if (isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}
require_once __DIR__ . '/auth_helpers.php';

$error = '';
$redirect = isset($_GET['redirect']) ? $_GET['redirect'] : 'index.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim((string) ($_POST['identifier'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $remember = isset($_POST['remember']);
    if ($identifier === '' || $password === '') {
        $error = 'Email/nama dan password wajib diisi.';
    } else {
        $file = __DIR__ . '/../storage/users.json';
        $users = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
        if (!is_array($users)) $users = [];
        $found = null;
        foreach ($users as $u) {
            if (strcasecmp((string) ($u['email'] ?? ''), $identifier) === 0 ||
                strcasecmp((string) ($u['name'] ?? ''), $identifier) === 0) {
                $found = $u;
                break;
            }
        }
        if (!$found) {
            $error = 'User tidak ditemukan.';
        } else {
            $stored = (string) ($found['password'] ?? '');
            $ok = false;
            if ($stored !== '' && (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon2'))) {
                $ok = password_verify($password, $stored);
            } else {
                $ok = hash_equals($stored, $password);
            }
            if ($ok) {
                session_regenerate_id(true);
                unset($found['password']);
                $_SESSION['user'] = $found;
                if ($remember && isset($found['id'])) {
                    $token = issueRememberToken((int) $found['id']);
                    if ($token) {
                        setRememberCookie($token, 30);
                    }
                } else {
                    forgetRememberToken();
                }
                header('Location: ' . $redirect);
                exit;
            }
            $error = 'Password salah.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Project Admin</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="app-shell" style="align-items:center; justify-content:center; padding:1.5rem;">
        <div class="card" style="max-width:420px; width:100%;">
            <h2 style="margin-top:0;">Login</h2>
            <p class="muted">Masuk untuk mengakses aplikasi.</p>
            <?php if ($error): ?>
                <div class="alert" style="background:#ffe4e4; border-color:#fca5a5; color:#b91c1c;">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            <form method="post" style="display:grid; gap:0.75rem;">
                <label>
                    <span>Email atau Nama</span>
                    <input type="text" name="identifier" placeholder="admin@local atau admin" required>
                </label>
                <label>
                    <span>Password</span>
                    <input type="password" name="password" placeholder="password" required>
                </label>
                <label style="display:flex; align-items:center; gap:0.5rem;">
                    <input type="checkbox" name="remember" value="1">
                    <span>Ingat saya</span>
                </label>
                <button type="submit">Login</button>
            </form>
        </div>
    </div>
</body>
</html>
