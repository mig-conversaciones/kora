<?php

declare(strict_types=1);

/**
 * Router del servidor embebido de PHP para el escaneo DAST de la CI (RS-016): sirve los archivos de public/ tal cual
 * y lo demás por el controlador frontal, como hace el .htaccess en Apache o LiteSpeed. Nunca se usa en un servidor.
 *
 *   php -S 127.0.0.1:8080 -t public tests/seguridad/servidor.php
 */

$ruta = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$archivo = realpath(__DIR__ . '/../../public' . $ruta);
$publico = realpath(__DIR__ . '/../../public');
if ($ruta !== '/' && $archivo !== false && $publico !== false && str_starts_with($archivo, $publico) && is_file($archivo) && !str_ends_with($archivo, '.php')) {
    return false;
}
require __DIR__ . '/../../public/index.php';
