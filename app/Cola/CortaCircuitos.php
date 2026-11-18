<?php

declare(strict_types=1);

namespace App\Cola;

use App\Nucleo\BaseDatos;

/**
 * Cortacircuitos con estado compartido en la base de datos, porque PHP no conserva estado entre procesos
 * (D-29, RNF-008): 3 fallos consecutivos abren el circuito 60 s; al vencer, una sola solicitud de prueba
 * (semiabierto) decide si se cierra o se vuelve a abrir.
 */
final class CortaCircuitos
{
    private const UMBRAL_FALLOS = 3;
    private const SEGUNDOS_ABIERTO = 60;

    public function __construct(private readonly BaseDatos $bd)
    {
    }

    public function permite(string $servicio): bool
    {
        $fila = $this->bd->fila('SELECT estado, abierto_hasta FROM estado_circuitos WHERE servicio = :servicio', ['servicio' => $servicio]);
        if ($fila === null || $fila['estado'] === 'cerrado') {
            return true;
        }
        if ($fila['estado'] === 'semiabierto') {
            return false;
        }
        // Abierto y vencido: solo la solicitud que gane la actualización condicional hace la prueba.
        return $this->bd->ejecutar(
            "UPDATE estado_circuitos SET estado = 'semiabierto'
              WHERE servicio = :servicio AND estado = 'abierto' AND abierto_hasta <= UTC_TIMESTAMP(3)",
            ['servicio' => $servicio]
        ) === 1;
    }

    public function registrarExito(string $servicio): void
    {
        $this->bd->ejecutar(
            "INSERT INTO estado_circuitos (servicio, estado, fallos_consecutivos) VALUES (:servicio, 'cerrado', 0)
             ON DUPLICATE KEY UPDATE estado = 'cerrado', fallos_consecutivos = 0, abierto_hasta = NULL",
            ['servicio' => $servicio]
        );
    }

    /**
     * Las asignaciones de ON DUPLICATE KEY UPDATE se evalúan de izquierda a derecha en MySQL y MariaDB
     * (sin el modo SIMULTANEOUS_ASSIGNMENT, que no se activa en config/base_datos.php): cada expresión ve
     * el valor ya actualizado de la anterior.
     */
    public function registrarFallo(string $servicio): void
    {
        $this->bd->ejecutar(
            "INSERT INTO estado_circuitos (servicio, estado, fallos_consecutivos) VALUES (:servicio, 'cerrado', 1)
             ON DUPLICATE KEY UPDATE
                 fallos_consecutivos = fallos_consecutivos + 1,
                 estado = IF(estado = 'semiabierto' OR fallos_consecutivos >= :umbral, 'abierto', estado),
                 abierto_hasta = IF(estado = 'abierto', UTC_TIMESTAMP(3) + INTERVAL :segundos SECOND, abierto_hasta)",
            ['servicio' => $servicio, 'umbral' => self::UMBRAL_FALLOS, 'segundos' => self::SEGUNDOS_ABIERTO]
        );
    }
}
