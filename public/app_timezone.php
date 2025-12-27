<?php
declare(strict_types=1);

if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Asia/Jakarta');
}

@date_default_timezone_set(APP_TIMEZONE);
