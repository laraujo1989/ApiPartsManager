<?php

/**
 * Router solo para el servidor integrado de PHP (desarrollo):
 *   php -S 127.0.0.1:8000 -t public dev-router.php
 * Sin él, las URLs que terminan en extensión (p. ej. /api/files/part/abc.pdf) devuelven 404 en vez de llegar a Symfony.
 * En producción esto lo hace la configuración de nginx/Apache.
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH) ?: '/';
if ($path !== '/' && !str_starts_with($path, '/api') && is_file(__DIR__.'/public'.$path)) {
    return false;
}

$_SERVER['SCRIPT_FILENAME'] = __DIR__.'/public/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__.'/public/index.php';
