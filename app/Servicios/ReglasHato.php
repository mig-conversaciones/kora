<?php

declare(strict_types=1);

namespace App\Servicios;

/**
 * Reglas del hato (RF-054 a RF-056): cálculo del IEP y los días abiertos en el servidor, validación de las fechas
 * reproductivas con los mensajes del prototipo y semáforos con los límites exactos de 2.10. Son funciones puras.
 */
final class ReglasHato
{
    /** Tolerancia entre el valor importado y el calculado antes de abrir una incidencia (RF-054). */
    public const TOLERANCIA_DIAS = 1;

    /** IEP en días: último parto menos penúltimo parto. Null si falta alguno. */
    public static function iep(?string $penultimoParto, ?string $ultimoParto): ?int
    {
        return $penultimoParto === null || $ultimoParto === null ? null : self::dias($penultimoParto, $ultimoParto);
    }

    /** Días abiertos: servicio efectivo (o la fecha de corte si está vacío) menos el último parto. */
    public static function diasAbiertos(?string $ultimoParto, ?string $servicioEfectivo, string $fechaCorte): ?int
    {
        return $ultimoParto === null ? null : self::dias($ultimoParto, $servicioEfectivo ?? $fechaCorte);
    }

    /**
     * Errores por campo de un registro reproductivo (RF-055).
     *
     * @param array<string,?string> $fechas penultimo_parto, ultimo_parto, servicio_efectivo, ultima_palpacion
     * @return array<string,string>
     */
    public static function validarFechas(?string $nacimiento, array $fechas, string $fechaCorte): array
    {
        $errores = [];
        foreach (['penultimo_parto', 'ultimo_parto', 'servicio_efectivo', 'ultima_palpacion'] as $campo) {
            $fecha = $fechas[$campo] ?? null;
            if ($fecha === null) {
                continue;
            }
            if ($nacimiento !== null && $fecha < $nacimiento) {
                $errores[$campo] = 'Los eventos reproductivos deben ser posteriores al nacimiento.';
            } elseif ($fecha > $fechaCorte) {
                $errores[$campo] = 'La fecha no puede ser posterior a la fecha del corte.';
            }
        }
        $penultimo = $fechas['penultimo_parto'] ?? null;
        $ultimo = $fechas['ultimo_parto'] ?? null;
        if ($penultimo !== null && $ultimo !== null && $penultimo >= $ultimo && !isset($errores['penultimo_parto'])) {
            $errores['penultimo_parto'] = 'El penúltimo parto debe ser anterior al último.';
        }
        return $errores;
    }

    /**
     * Semáforo del IEP: verde hasta 365 días, ámbar de 366 a 400 y rojo desde 401 (RF-056).
     *
     * @return array{tono:string,texto:string,icono:string}
     */
    public static function semaforoIep(?int $dias): array
    {
        return match (true) {
            $dias === null => self::estado('neutra', 'Sin dato'),
            $dias <= 365 => self::estado('exito', 'En meta'),
            $dias <= 400 => self::estado('advertencia', 'Vigilar'),
            default => self::estado('error', 'Fuera de meta'),
        };
    }

    /**
     * Semáforo de los días abiertos: neutral («sin umbral») por debajo de 91, verde de 91 a 120, ámbar de 121 a 135
     * y rojo desde 136 (RF-056).
     *
     * @return array{tono:string,texto:string,icono:string}
     */
    public static function semaforoDiasAbiertos(?int $dias): array
    {
        return match (true) {
            $dias === null => self::estado('neutra', 'Sin dato'),
            $dias < 91 => self::estado('neutra', 'Sin umbral'),
            $dias <= 120 => self::estado('exito', 'En meta'),
            $dias <= 135 => self::estado('advertencia', 'Vigilar'),
            default => self::estado('error', 'Fuera de meta'),
        };
    }

    /** Estado reproductivo normalizado: «preñada » → «PREÑADA». */
    public static function normalizarEstado(string $estado): ?string
    {
        $texto = mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $estado)));
        return $texto === '' ? null : mb_substr($texto, 0, 30);
    }

    /** ¿El valor importado difiere del calculado más que la tolerancia? */
    public static function difiere(?int $importado, ?int $calculado): bool
    {
        return $importado !== null && $calculado !== null && abs($importado - $calculado) > self::TOLERANCIA_DIAS;
    }

    private static function dias(string $desde, string $hasta): int
    {
        $inicio = new \DateTimeImmutable($desde, new \DateTimeZone('UTC'));
        $fin = new \DateTimeImmutable($hasta, new \DateTimeZone('UTC'));
        return (int) $inicio->diff($fin)->format('%r%a');
    }

    /** @return array{tono:string,texto:string,icono:string} */
    private static function estado(string $tono, string $texto): array
    {
        $iconos = ['exito' => 'ok', 'advertencia' => 'alerta', 'error' => 'error', 'neutra' => 'info'];
        return ['tono' => $tono, 'texto' => $texto, 'icono' => $iconos[$tono]];
    }
}
