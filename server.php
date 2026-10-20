<?php

/**
 * Laravel - A PHP Framework For Web Artisans
 *
 * @package  Laravel
 * @author   Taylor Otwell <taylor@laravel.com>
 */

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

@file_put_contents(
    __DIR__.'/storage/logs/requests.log',
    date('Y-m-d H:i:s').' | '
        .($_SERVER['REMOTE_ADDR'] ?? '?').' | '
        .($_SERVER['HTTP_HOST'] ?? '?').' | '
        .($_SERVER['REQUEST_METHOD'] ?? '?').' '
        .($_SERVER['REQUEST_URI'] ?? '?').' | '
        .'file_exists='.(file_exists(__DIR__.'/public'.$uri) ? '1' : '0').' | '
        .'path='.__DIR__.'/public'.$uri."\n",
    FILE_APPEND | LOCK_EX
);

// This file allows us to emulate Apache's "mod_rewrite" functionality from the
// built-in PHP web server. This provides a convenient way to test a Laravel
// application without having installed a "real" web server software here.
if ($uri !== '/' && file_exists(__DIR__.'/public'.$uri)) {
    return false;
}

require_once __DIR__.'/public/index.php';