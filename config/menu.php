<?php

declare(strict_types=1);

/**
 * Menú principal (RI-002, RF-002). Vista lo filtra en el servidor: una entrada con «permiso» solo aparece si el
 * usuario lo tiene. Las entradas sin ruta son módulos aún no entregados y se muestran deshabilitadas con su
 * iteración (05-planificacion). En la I3 el menú se reorganiza en «Vista general», «Mi gestión» y «Consulta».
 */

return [
    [
        'grupo' => 'Plataforma',
        'entradas' => [
            ['texto' => 'Inicio', 'ruta' => '/', 'icono' => 'inicio'],
            ['texto' => 'Sistema de diseño', 'ruta' => '/sistema-diseno', 'icono' => 'paleta'],
        ],
    ],
    [
        'grupo' => 'Tableros',
        'entradas' => [
            ['texto' => 'Tablero central', 'icono' => 'tablero', 'iteracion' => 'I6'],
            ['texto' => 'Ganadería — Hato El Milagroso', 'icono' => 'hoja', 'iteracion' => 'I6'],
        ],
    ],
    [
        'grupo' => 'Gestión',
        'entradas' => [
            ['texto' => 'Presupuesto y gastos', 'icono' => 'presupuesto', 'iteracion' => 'I3'],
            ['texto' => 'Recaudos', 'icono' => 'recaudo', 'iteracion' => 'I5'],
            ['texto' => 'Producción y lotes', 'icono' => 'caja', 'iteracion' => 'I4'],
            ['texto' => 'Hato', 'icono' => 'hoja', 'iteracion' => 'I4'],
            ['texto' => 'Importación de BBDD', 'icono' => 'carga', 'iteracion' => 'I5'],
        ],
    ],
    [
        'grupo' => 'Sistema',
        'entradas' => [
            ['texto' => 'Mantenimiento', 'ruta' => '/sistema/mantenimiento', 'icono' => 'ajustes', 'permiso' => 'mantenimiento.gestionar'],
            ['texto' => 'Colas de trabajos', 'ruta' => '/sistema/colas', 'icono' => 'reloj', 'permiso' => 'sistema.colas'],
            ['texto' => 'Usuarios y roles', 'icono' => 'usuarios', 'iteracion' => 'I3'],
            ['texto' => 'Auditoría', 'icono' => 'candado', 'iteracion' => 'I3'],
            ['texto' => 'Catálogos y parámetros', 'icono' => 'ajustes', 'iteracion' => 'I3'],
        ],
    ],
];
