<?php
declare(strict_types=1);

function rememberTokensFile(): string
{
    return __DIR__ . '/../storage/remember_tokens.json';
}

function usersFile(): string
{
    return __DIR__ . '/../storage/users.json';
}

function loadJsonArray(string $file): array
{
    $data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    return is_array($data) ? $data : [];
}

function saveJsonArray(string $file, array $data): bool
{
    return false !== file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

function setRememberCookie(string $token, int $days = 30): void
{
    $expire = time() + ($days * 86400);
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    setcookie('remember_token', $token, [
        'expires' => $expire,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clearRememberCookie(): void
{
    setcookie('remember_token', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function issueRememberToken(int $userId): ?string
{
    try {
        $raw = bin2hex(random_bytes(32));
    } catch (\Throwable $e) {
        return null;
    }
    $hash = hash('sha256', $raw);
    $now = time();
    $expires = $now + (30 * 86400);
    $tokens = loadJsonArray(rememberTokensFile());
    $tokens = array_values(array_filter($tokens, function ($t) use ($now) {
        return isset($t['expires_at']) && (int) $t['expires_at'] > $now;
    }));
    $tokens[] = [
        'token_hash' => $hash,
        'user_id' => $userId,
        'created_at' => $now,
        'expires_at' => $expires,
    ];
    saveJsonArray(rememberTokensFile(), $tokens);
    return $raw;
}

function forgetRememberToken(?string $rawToken = null): void
{
    $rawToken = $rawToken ?? ($_COOKIE['remember_token'] ?? '');
    if ($rawToken === '') {
        clearRememberCookie();
        return;
    }
    $hash = hash('sha256', $rawToken);
    $tokens = loadJsonArray(rememberTokensFile());
    $tokens = array_values(array_filter($tokens, function ($t) use ($hash) {
        $stored = (string) ($t['token_hash'] ?? '');
        return $stored === '' || !hash_equals($stored, $hash);
    }));
    saveJsonArray(rememberTokensFile(), $tokens);
    clearRememberCookie();
}

function tryRememberLogin(): ?array
{
    $raw = $_COOKIE['remember_token'] ?? '';
    if ($raw === '') {
        return null;
    }
    $hash = hash('sha256', $raw);
    $tokens = loadJsonArray(rememberTokensFile());
    $now = time();
    $match = null;
    $updated = [];
    foreach ($tokens as $t) {
        $exp = (int) ($t['expires_at'] ?? 0);
        if ($exp && $exp < $now) {
            continue;
        }
        $stored = (string) ($t['token_hash'] ?? '');
        if ($stored !== '' && hash_equals($stored, $hash)) {
            $match = $t;
            $t['expires_at'] = $now + (30 * 86400);
        }
        $updated[] = $t;
    }

    if ($match) {
        saveJsonArray(rememberTokensFile(), $updated);
        setRememberCookie($raw, 30);
        $users = loadJsonArray(usersFile());
        foreach ($users as $u) {
            if ((string) ($u['id'] ?? '') === (string) ($match['user_id'] ?? '')) {
                unset($u['password']);
                return $u;
            }
        }
        $updated = array_values(array_filter($updated, function ($t) use ($hash) {
            $stored = (string) ($t['token_hash'] ?? '');
            return $stored === '' || !hash_equals($stored, $hash);
        }));
        saveJsonArray(rememberTokensFile(), $updated);
    } else {
        saveJsonArray(rememberTokensFile(), $updated);
    }

    clearRememberCookie();
    return null;
}
