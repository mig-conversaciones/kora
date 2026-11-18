<?php

declare(strict_types=1);

/**
 * Configuración de htmx que viaja en <meta name="htmx-config"> (04-03, 4.3.2). Vista agrega los nonces de la
 * respuesta. historyCacheSize = 0 evita guardar instantáneas con datos en localStorage (D-38, RS-024).
 */

return [
    'allowEval' => false,
    'allowScriptTags' => false,
    'includeIndicatorStyles' => false,
    'historyCacheSize' => 0,
    'refreshOnHistoryMiss' => true,
    'selfRequestsOnly' => true,
    'globalViewTransitions' => true,
    'timeout' => 20000,
    'responseHandling' => [
        ['code' => '204', 'swap' => false],
        ['code' => '[23]..', 'swap' => true],
        ['code' => '422', 'swap' => true, 'error' => false],
        ['code' => '409', 'swap' => true, 'error' => false],
        ['code' => '503', 'swap' => true, 'error' => true, 'target' => '#contenido-principal'],
        ['code' => '[45]..', 'swap' => true, 'error' => true, 'target' => '#contenedor-errores'],
        ['code' => '...', 'swap' => false],
    ],
];
