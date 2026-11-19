<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\Entrada;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\Solicitud;

/**
 * Parámetros de los indicadores por centro y vigencia (RF-021). Nunca se sobrescribe un valor: cada cambio es una
 * fila nueva con su fecha de inicio de vigencia, y el valor aplicable a una fecha es el de la vigencia más reciente
 * que no la supera. Así los indicadores de periodos pasados se recalculan con el parámetro que regía entonces.
 */
final class ServicioParametros
{
    /** clave => [etiqueta, unidad, tipo de validación] */
    public const DEFINICIONES = [
        'meta_vacas_ordeno' => ['Meta de vacas en ordeño', '%', 'porcentaje'],
        'precio_referencia_leche' => ['Precio de referencia de la leche', 'COP/L', 'precio'],
        'costo_maximo_precio' => ['Costo máximo frente al precio', '%', 'porcentaje'],
        'rotacion_frescos' => ['Rotación de frescos', 'días', 'dias'],
        'rotacion_animales' => ['Rotación de animales', 'días', 'dias'],
        'meta_aprovechamiento' => ['Meta de aprovechamiento global', '%', 'porcentaje'],
    ];

    public function __construct(
        private readonly BaseDatos $bd,
        private readonly ServicioAuditoria $auditoria,
        private readonly ServicioVersionDatos $versiones,
    ) {
    }

    /**
     * Valor vigente a una fecha (por defecto hoy) de cada parámetro.
     *
     * @return array<string,array{valor:?string,vigente_desde:?string}>
     */
    public function vigentes(int $centroId, ?string $fecha = null): array
    {
        $fecha ??= (new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota')))->format('Y-m-d');
        $filas = $this->bd->consultar(
            'SELECT p.clave, p.valor, p.vigente_desde FROM parametros_indicadores p
               JOIN (SELECT clave, MAX(vigente_desde) AS desde FROM parametros_indicadores
                      WHERE centro_id = :centro AND vigente_desde <= :fecha GROUP BY clave) v
                 ON v.clave = p.clave AND v.desde = p.vigente_desde
              WHERE p.centro_id = :centro2',
            ['centro' => $centroId, 'fecha' => $fecha, 'centro2' => $centroId]
        );
        $resultado = array_fill_keys(array_keys(self::DEFINICIONES), ['valor' => null, 'vigente_desde' => null]);
        foreach ($filas as $fila) {
            $resultado[(string) $fila['clave']] = ['valor' => (string) $fila['valor'], 'vigente_desde' => (string) $fila['vigente_desde']];
        }
        return $resultado;
    }

    /** @return list<array<string,mixed>> */
    public function historial(int $centroId): array
    {
        return $this->bd->consultar(
            'SELECT p.*, u.nombre AS creado_por_nombre FROM parametros_indicadores p LEFT JOIN usuarios u ON u.id = p.creado_por
              WHERE p.centro_id = :centro ORDER BY p.clave, p.vigente_desde DESC',
            ['centro' => $centroId]
        );
    }

    /**
     * Registra un valor nuevo con su inicio de vigencia. Si ya hay un valor para esa misma fecha se reemplaza (es
     * una corrección del mismo día de vigencia), y el valor anterior queda en la auditoría.
     *
     * @param array<string,mixed> $datos
     */
    public function registrar(Solicitud $solicitud, array $datos): void
    {
        $centro = ServicioCatalogos::centro($solicitud);
        $clave = (string) ($datos['clave'] ?? '');
        $errores = [];
        if (!isset(self::DEFINICIONES[$clave])) {
            throw new ErrorValidacion(['clave' => 'Elige el parámetro.']);
        }
        [, $unidad, $tipo] = self::DEFINICIONES[$clave];
        $valor = Entrada::decimal($datos['valor'] ?? '', $tipo === 'dias' ? 0 : 2);
        if ($valor === null) {
            $errores['valor'] = $tipo === 'dias' ? 'Escribe un número entero de días.' : 'Escribe un número (hasta 2 decimales).';
        } elseif ($tipo === 'porcentaje' && Entrada::comparar($valor, '100.00') > 0) {
            $errores['valor'] = 'El porcentaje debe estar entre 0 y 100.';
        } elseif ($tipo === 'precio' && Entrada::comparar($valor, '0.00') <= 0) {
            $errores['valor'] = "El precio debe ser mayor que 0 ({$unidad}).";
        } elseif ($tipo === 'dias' && ((int) $valor < 1 || (int) $valor > 3650)) {
            $errores['valor'] = 'Los días deben estar entre 1 y 3.650.';
        }
        $desde = Entrada::fecha($datos['vigente_desde'] ?? '');
        if ($desde === null) {
            $errores['vigente_desde'] = 'Indica la fecha de inicio de vigencia (AAAA-MM-DD).';
        }
        if ($errores !== []) {
            throw new ErrorValidacion($errores);
        }
        $actor = AutorizacionRecurso::usuario($solicitud)->id;
        $this->bd->transaccion(function (BaseDatos $bd) use ($solicitud, $centro, $clave, $valor, $desde, $actor): void {
            $anterior = $bd->fila(
                'SELECT valor, vigente_desde FROM parametros_indicadores WHERE centro_id = :c AND clave = :k AND vigente_desde <= :d ORDER BY vigente_desde DESC LIMIT 1',
                ['c' => $centro, 'k' => $clave, 'd' => $desde]
            );
            $bd->ejecutar(
                'INSERT INTO parametros_indicadores (centro_id, clave, valor, vigente_desde, creado_por) VALUES (:c, :k, :v, :d, :actor)
                 ON DUPLICATE KEY UPDATE valor = VALUES(valor), creado_por = VALUES(creado_por), creado_en = UTC_TIMESTAMP(3)',
                ['c' => $centro, 'k' => $clave, 'v' => $valor, 'd' => $desde, 'actor' => $actor]
            );
            $this->auditoria->registrar($solicitud, 'parametros.registrar', 'exito', new RecursoAuditado('parametros_indicadores', $clave), [], $anterior, ['valor' => $valor, 'vigente_desde' => $desde]);
            $this->versiones->incrementar($centro);
        });
    }
}
