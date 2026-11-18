<?php

declare(strict_types=1);

/**
 * Simulador del cron de hPanel para el desarrollo en MAMP (Windows no tiene cron). Reproduce la tabla D.4 del
 * Anexo D: el trabajador cada 30 s (entrada normal más la desfasada) y el programador cada minuto, cada uno como
 * proceso independiente, igual que en producción. Detener con Ctrl+C.
 *
 *   php cli/cron_local.php [--ciclos=N]   (N = número de medios minutos; por defecto, indefinido)
 *
 * No se usa en producción: allí el cron de hPanel ejecuta trabajador_cron.php y programador.php.
 */

require __DIR__ . '/arranque.php';

$opciones = opcionesCli($argv);
$ciclos = isset($opciones['ciclos']) ? max(1, (int) $opciones['ciclos']) : PHP_INT_MAX;
$php = escapeshellarg(PHP_BINARY);
$ini = php_ini_loaded_file();
$argumentosIni = $ini !== false ? ' -c ' . escapeshellarg($ini) : '';
$lanzar = static function (string $script) use ($php, $argumentosIni): void {
    $comando = $php . $argumentosIni . ' ' . escapeshellarg(RAIZ . "/cli/{$script}");
    // Proceso en segundo plano, como lo haría el cron: no se espera a que termine.
    if (PHP_OS_FAMILY === 'Windows') {
        pclose(popen('start /B "" ' . $comando . ' > NUL 2>&1', 'r'));
    } else {
        exec($comando . ' > /dev/null 2>&1 &');
    }
};

escribirLinea('Simulando el cron de KORA (trabajador cada 30 s, programador cada minuto). Ctrl+C para detener.');
for ($ciclo = 0; $ciclo < $ciclos; $ciclo++) {
    $lanzar('trabajador_cron.php');
    if ($ciclo % 2 === 0) {
        $lanzar('programador.php');
    }
    escribirLinea(gmdate('H:i:s') . ' UTC · trabajador' . ($ciclo % 2 === 0 ? ' y programador' : '') . ' lanzados');
    if ($ciclo + 1 < $ciclos) {
        sleep(30);
    }
}
exit(0);
