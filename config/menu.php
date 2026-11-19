<?php

declare(strict_types=1);

/**
 * Menú principal (RI-002, RF-002). Vista lo filtra en el servidor: una entrada con «permiso» solo aparece si el
 * usuario tiene ese permiso o, si es una lista, alguno de ellos. Las entradas sin ruta son módulos aún no entregados y
 * se muestran deshabilitadas con su iteración (05-planificacion). Los grupos siguen RF-002: «Vista general»
 * (tableros), «Mi gestión» (lo que cada rol registra), «Catálogos» (consulta y maestros) y «Sistema».
 */

return [
    [
        'grupo' => 'Vista general',
        'entradas' => [
            ['texto' => 'Inicio', 'ruta' => '/', 'icono' => 'inicio'],
            ['texto' => 'Tablero central', 'icono' => 'tablero', 'iteracion' => 'I6'],
            ['texto' => 'Ganadería — Hato El Milagroso', 'icono' => 'hoja', 'iteracion' => 'I6'],
        ],
    ],
    [
        'grupo' => 'Mi gestión',
        'entradas' => [
            ['texto' => 'Presupuesto y gastos', 'ruta' => '/finanzas/gastos', 'icono' => 'presupuesto', 'permiso' => ['gastos.consultar', 'asignaciones.consultar']],
            ['texto' => 'Procesos de autoconsumo', 'ruta' => '/finanzas/procesos', 'icono' => 'caja', 'permiso' => 'procesos.consultar'],
            ['texto' => 'Cierres de periodo', 'ruta' => '/finanzas/periodos', 'icono' => 'candado', 'permiso' => 'periodos.cerrar'],
            ['texto' => 'Recaudos', 'icono' => 'recaudo', 'iteracion' => 'I5'],
            ['texto' => 'Producción y lotes', 'icono' => 'caja', 'iteracion' => 'I4'],
            ['texto' => 'Hato', 'icono' => 'hoja', 'iteracion' => 'I4'],
            ['texto' => 'Importación de BBDD', 'icono' => 'carga', 'iteracion' => 'I5'],
        ],
    ],
    [
        'grupo' => 'Catálogos',
        'entradas' => [
            ['texto' => 'Unidades y productos', 'ruta' => '/catalogos/unidades', 'icono' => 'unidad', 'permiso' => 'catalogo.consultar'],
            ['texto' => 'Parámetros', 'ruta' => '/catalogos/parametros', 'icono' => 'ajustes', 'permiso' => 'parametros.consultar'],
            ['texto' => 'Roles y permisos', 'ruta' => '/sistema/roles', 'icono' => 'candado', 'autenticada' => true],
        ],
    ],
    [
        'grupo' => 'Sistema',
        'entradas' => [
            ['texto' => 'Usuarios', 'ruta' => '/sistema/usuarios', 'icono' => 'usuarios', 'permiso' => 'usuarios.gestionar'],
            ['texto' => 'Auditoría', 'ruta' => '/sistema/auditoria', 'icono' => 'candado', 'permiso' => 'auditoria.consultar'],
            ['texto' => 'Mantenimiento', 'ruta' => '/sistema/mantenimiento', 'icono' => 'ajustes', 'permiso' => 'mantenimiento.gestionar'],
            ['texto' => 'Colas de trabajos', 'ruta' => '/sistema/colas', 'icono' => 'reloj', 'permiso' => 'sistema.colas'],
            ['texto' => 'Sistema de diseño', 'ruta' => '/sistema-diseno', 'icono' => 'paleta'],
        ],
    ],
];
