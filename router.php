<?php
/*
 * Router for PHP's built-in server, which ignores .htaccess:
 *   php -S 0.0.0.0:8000 router.php
 * Blocks data, ML, config and repository files that Apache would deny.
 */
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (preg_match('#(^|/)(data|ml|output|\.venv|\.git)(/|$)|\.(ini|db|tmp|py|h|ino|md)$|^/(config|config\.local|database|file_functions|layout|session|router|satellite_engine|prediction_engine)\.php$#i', $path)) {
    http_response_code(403);
    exit('Forbidden');
}
if ($path === '/') { require __DIR__ . '/index.php'; return true; }
return false;
