<?php
session_start();
require_once __DIR__ . '/auth_helpers.php';
forgetRememberToken();
$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
