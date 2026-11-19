<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\ErrorHttp;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\Solicitud;

/**
 * Cierre de periodo mensual (RF-028). En un mes cerrado no se crean, editan, archivan ni importan registros con
 * fecha en ese mes: el caso de uso llama a exigirAbierto() dentro de su transacción y recibe un 409 «Periodo
 * cerrado». La reapertura exige motivo y reautenticación (la ruta la declara) y queda auditada.
 */
final class ServicioPeriodos
{
    public function __construct(private readonly BaseDatos $bd, private readonly ServicioAuditoria $auditoria)
    {
    }

    public function estaCerrado(int $centroId, string $fecha): bool
    {
        [$anio, $mes] = array_map('intval', explode('-', substr($fecha, 0, 7)));
        return (string) ($this->bd->valor(
            'SELECT estado FROM periodos_cierre WHERE centro_id = :centro AND anio = :anio AND mes = :mes',
            ['centro' => $centroId, 'anio' => $anio, 'mes' => $mes]
        ) ?? '') === 'cerrado';
    }

    /** 409 si alguna de las fechas (la anterior y la nueva del registro) cae en un mes cerrado. */
    public function exigirAbierto(int $centroId, string ...$fechas): void
    {
        foreach (array_unique($fechas) as $fecha) {
            if ($fecha !== '' && $this->estaCerrado($centroId, $fecha)) {
                throw new ErrorHttp(409, 'El mes ' . substr($fecha, 5, 2) . '/' . substr($fecha, 0, 4) . ' está cerrado y no admite cambios. Pide su reapertura a la Coordinación administrativa.', 'Periodo cerrado');
            }
        }
    }

    /**
     * Los 12 meses del año con su estado.
     *
     * @return list<array{mes:int,estado:string,cerrado_en:?string,reabierto_en:?string,motivo_reapertura:?string}>
     */
    public function delAnio(int $centroId, int $anio): array
    {
        $filas = [];
        foreach ($this->bd->consultar('SELECT mes, estado, cerrado_en, reabierto_en, motivo_reapertura FROM periodos_cierre WHERE centro_id = :centro AND anio = :anio', ['centro' => $centroId, 'anio' => $anio]) as $fila) {
            $filas[(int) $fila['mes']] = $fila;
        }
        $meses = [];
        for ($mes = 1; $mes <= 12; $mes++) {
            $fila = $filas[$mes] ?? null;
            $meses[] = [
                'mes' => $mes,
                'estado' => (string) ($fila['estado'] ?? 'abierto'),
                'cerrado_en' => $fila['cerrado_en'] ?? null,
                'reabierto_en' => $fila['reabierto_en'] ?? null,
                'motivo_reapertura' => $fila['motivo_reapertura'] ?? null,
            ];
        }
        return $meses;
    }

    public function cerrar(Solicitud $solicitud, int $centroId, int $anio, int $mes): void
    {
        $this->validarMes($anio, $mes);
        $hoy = new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota'));
        if (sprintf('%04d-%02d', $anio, $mes) > $hoy->format('Y-m')) {
            throw new ErrorValidacion(['periodo' => 'Un mes que aún no comienza no se puede cerrar.']);
        }
        $actor = AutorizacionRecurso::usuario($solicitud)->id;
        $this->bd->transaccion(function (BaseDatos $bd) use ($solicitud, $centroId, $anio, $mes, $actor): void {
            $actual = $bd->fila('SELECT id, estado FROM periodos_cierre WHERE centro_id = :centro AND anio = :anio AND mes = :mes FOR UPDATE', ['centro' => $centroId, 'anio' => $anio, 'mes' => $mes]);
            if ($actual !== null && $actual['estado'] === 'cerrado') {
                throw new ErrorHttp(409, 'El periodo ya está cerrado.');
            }
            if ($actual === null) {
                $bd->ejecutar(
                    'INSERT INTO periodos_cierre (centro_id, anio, mes, estado, cerrado_por, cerrado_en) VALUES (:centro, :anio, :mes, \'cerrado\', :actor, UTC_TIMESTAMP(3))',
                    ['centro' => $centroId, 'anio' => $anio, 'mes' => $mes, 'actor' => $actor]
                );
            } else {
                $bd->ejecutar(
                    "UPDATE periodos_cierre SET estado = 'cerrado', cerrado_por = :actor, cerrado_en = UTC_TIMESTAMP(3) WHERE id = :id",
                    ['actor' => $actor, 'id' => $actual['id']]
                );
            }
            $this->auditoria->registrar($solicitud, 'periodos.cerrar', 'exito', null, ['periodo' => sprintf('%04d-%02d', $anio, $mes)], ['estado' => $actual['estado'] ?? 'abierto'], ['estado' => 'cerrado']);
        });
    }

    public function reabrir(Solicitud $solicitud, int $centroId, int $anio, int $mes, string $motivo): void
    {
        $this->validarMes($anio, $mes);
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 10 || mb_strlen($motivo) > 500) {
            throw new ErrorValidacion(['motivo' => 'Explica el motivo de la reapertura (entre 10 y 500 caracteres).']);
        }
        $actor = AutorizacionRecurso::usuario($solicitud)->id;
        $this->bd->transaccion(function (BaseDatos $bd) use ($solicitud, $centroId, $anio, $mes, $motivo, $actor): void {
            $filas = $bd->ejecutar(
                "UPDATE periodos_cierre SET estado = 'reabierto', reabierto_por = :actor, reabierto_en = UTC_TIMESTAMP(3), motivo_reapertura = :motivo
                  WHERE centro_id = :centro AND anio = :anio AND mes = :mes AND estado = 'cerrado'",
                ['actor' => $actor, 'motivo' => $motivo, 'centro' => $centroId, 'anio' => $anio, 'mes' => $mes]
            );
            if ($filas === 0) {
                throw new ErrorHttp(409, 'El periodo no está cerrado.');
            }
            $this->auditoria->registrar($solicitud, 'periodos.reabrir', 'exito', null, ['periodo' => sprintf('%04d-%02d', $anio, $mes)], ['estado' => 'cerrado'], ['estado' => 'reabierto'], $motivo);
        });
    }

    private function validarMes(int $anio, int $mes): void
    {
        if ($anio < 2020 || $anio > 2100 || $mes < 1 || $mes > 12) {
            throw new ErrorValidacion(['periodo' => 'El periodo no es válido.']);
        }
    }
}
