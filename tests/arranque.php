<?php

declare(strict_types=1);

/**
 * Arranque de las pruebas. Carga el .env local (o el que genera la CI) y redirige la base de datos a la de
 * pruebas: ninguna prueba toca la base de desarrollo.
 */

use App\Nucleo\Entorno;

define('RAIZ', dirname(__DIR__));

require RAIZ . '/vendor/autoload.php';

Entorno::cargar(is_file(RAIZ . '/.env') ? RAIZ . '/.env' : RAIZ . '/.env.example');
Entorno::establecer('BD_NOMBRE', (string) (getenv('BD_NOMBRE_PRUEBAS') ?: 'kora_pruebas'));
date_default_timezone_set('UTC');
