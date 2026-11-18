<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;

/**
 * Límites de frecuencia con estado compartido en la base (tabla limites_tasa; RAC-013, RAC-029). Cada clave es
 * «tipo:dimension:HMAC(valor)», de modo que ni el correo ni la IP quedan en claro. Ventana fija por tipo, espera
 * progresiva de 1 a 30 s entre fallos y bloqueo temporal al superar el máximo; nunca un bloqueo permanente.
 */
final class ServicioLimiteTasa
{
    /** @param array<string,array{maximo:int,ventana:int,bloqueo:int}> $limites config/seguridad.php */
    public function __construct(private readonly BaseDatos $bd, private readonly array $limites, private readonly string $claveHmac)
    {
    }

    /** Segundos que faltan para poder intentar de nuevo; 0 si se permite. */
    public function espera(string $tipo, string $dimension, string $valor): int
    {
        $restante = $this->bd->valor(
            'SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(3), bloqueado_hasta) FROM limites_tasa
              WHERE clave = :clave AND bloqueado_hasta > UTC_TIMESTAMP(3)',
            ['clave' => $this->clave($tipo, $dimension, $valor)]
        );
        return $restante === null ? 0 : max(1, (int) $restante + 1);
    }

    /**
     * Registra un intento (fallido, o cualquiera en los límites por IP) y aplica la espera o el bloqueo.
     *
     * @param bool $progresivo espera creciente de 1 a 30 s entre fallos (inicio de sesión)
     * @return int contador en la ventana actual
     */
    public function registrar(string $tipo, string $dimension, string $valor, bool $progresivo = false): int
    {
        $limite = $this->limites[$tipo];
        $clave = $this->clave($tipo, $dimension, $valor);
        // En ON DUPLICATE KEY UPDATE las asignaciones se evalúan de izquierda a derecha: contador usa la ventana
        // anterior, y ventana_inicio se reinicia después si había vencido.
        $this->bd->ejecutar(
            'INSERT INTO limites_tasa (clave, ventana_inicio, contador) VALUES (:clave, UTC_TIMESTAMP(3), 1)
             ON DUPLICATE KEY UPDATE
                 contador = IF(ventana_inicio < UTC_TIMESTAMP(3) - INTERVAL :ventana SECOND, 1, contador + 1),
                 ventana_inicio = IF(contador = 1, UTC_TIMESTAMP(3), ventana_inicio)',
            ['clave' => $clave, 'ventana' => $limite['ventana']]
        );
        $contador = (int) $this->bd->valor('SELECT contador FROM limites_tasa WHERE clave = :clave', ['clave' => $clave]);
        $segundos = 0;
        if ($contador >= $limite['maximo']) {
            $segundos = $limite['bloqueo'];
        } elseif ($progresivo && $contador >= 2) {
            $segundos = min(30, 2 ** ($contador - 2));
        }
        if ($segundos > 0) {
            $this->bd->ejecutar(
                'UPDATE limites_tasa SET bloqueado_hasta = UTC_TIMESTAMP(3) + INTERVAL :segundos SECOND WHERE clave = :clave',
                ['segundos' => $segundos, 'clave' => $clave]
            );
        }
        return $contador;
    }

    public function reiniciar(string $tipo, string $dimension, string $valor): void
    {
        $this->bd->ejecutar('DELETE FROM limites_tasa WHERE clave = :clave', ['clave' => $this->clave($tipo, $dimension, $valor)]);
    }

    private function clave(string $tipo, string $dimension, string $valor): string
    {
        return $tipo . ':' . $dimension . ':' . hash_hmac('sha256', mb_strtolower($valor), $this->claveHmac);
    }
}
