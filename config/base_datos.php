<?php

declare(strict_types=1);

/**
 * Conexión a MariaDB (Hostinger Cloud), MySQL 8 o, solo en desarrollo local, MySQL 5.7.24 de MAMP (ADR-002).
 * Hostinger indica conectar desde la aplicación a 127.0.0.1:3306 o al socket local; el host srvNNNN.hstgr.io
 * es solo para conexiones remotas autorizadas (RBD-017).
 *
 * El SQL del proyecto se escribe en el mínimo común de MySQL 5.7.24 y MariaDB 10.6: sin CTE, sin funciones de
 * ventana, sin SKIP LOCKED obligatorio y sin DEFAULT en columnas JSON o TEXT. Las restricciones CHECK se declaran
 * (MariaDB las aplica; MySQL 5.7 las ignora) y sus reglas se validan también en PHP.
 */

use App\Nucleo\Entorno;

return [
    'dsn' => sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        Entorno::texto('BD_HOST', '127.0.0.1'),
        Entorno::entero('BD_PUERTO', 3306),
        Entorno::texto('BD_NOMBRE')
    ),
    'usuario' => Entorno::texto('BD_USUARIO'),
    'contrasena' => Entorno::texto('BD_CONTRASENA'),
    'opciones' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_PERSISTENT => false,
        PDO::ATTR_TIMEOUT => 5,
    ],
    // Se ejecutan al abrir cada conexión: UTC y modo estricto (RBD-002, RBD-003).
    'inicializacion' => [
        "SET time_zone = '+00:00'",
        "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION'",
        'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
    ],
    // verificar_entorno.php compara estas versiones con SELECT VERSION() (RBD-001).
    'version_minima' => ['mariadb' => '10.6.0', 'mysql' => '8.0.0'],
    // Excepción de desarrollo (ADR-002): el MySQL de MAMP solo se acepta con APP_ENTORNO=local.
    'version_minima_local' => ['mysql' => '5.7.24'],
    // Estrategia de reclamo de la cola: SKIP LOCKED si el motor lo soporta; si no, UPDATE condicional (RF-Q-006).
    'soporta_skip_locked' => Entorno::booleano('BD_SKIP_LOCKED', true),
];
