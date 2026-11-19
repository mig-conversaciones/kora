<?php

declare(strict_types=1);

namespace App\Tableros;

use App\Nucleo\Cache;
use App\Politicas\AlcanceDatos;
use App\Servicios\ServicioVersionDatos;

/**
 * Tableros con caché de aplicación (RNF-PERF-012, 4.6.2). Las claves llevan el centro, la huella del alcance, la
 * huella de los filtros, la versión de datos del centro y la versión del cálculo:
 *   - una escritura relevante incrementa la versión de datos en su transacción, así que la invalidación es inmediata;
 *   - dos alcances distintos nunca comparten entrada (un líder no lee el agregado del centro);
 *   - cambiar una fórmula exige subir CALCULO, que deja huérfanas las entradas anteriores.
 * La caché solo guarda agregados por alcance, sin datos personales, y nunca decide permisos: quien llama ya validó el
 * permiso y construyó el alcance en el servidor.
 */
final class ServicioTableros
{
    /** Versión de las fórmulas: súbela al cambiar cualquier cálculo de ServicioIndicadores. */
    public const CALCULO = 1;

    private const TTL_SEGUNDOS = 600;

    public function __construct(
        private readonly ServicioIndicadores $indicadores,
        private readonly Cache $cache,
        private readonly ServicioVersionDatos $versiones,
    ) {
    }

    /** @return array<string,mixed> IND_001, IND_002, estado de la información y aviso de conciliación */
    public function central(AlcanceDatos $alcance, FiltrosTablero $f): array
    {
        $clave = sprintf('tablero_central:%d:%s:%s:v%d:c%d', $alcance->centroId, $alcance->huella(), $f->huella(), $this->versiones->actual($alcance->centroId), self::CALCULO);
        return $this->cache->recordar($clave, self::TTL_SEGUNDOS, fn (): array => $this->indicadores->central($alcance, $f)
            + ['estado' => $this->indicadores->estadoInformacion($alcance)]);
    }

    /**
     * Opciones del filtro de producto del alcance y la vigencia, con la misma invalidación que los agregados.
     *
     * @return array<string,string>
     */
    public function opcionesProducto(AlcanceDatos $alcance, int $vigencia): array
    {
        $clave = sprintf('opciones_producto:%d:%s:%d:v%d:c%d', $alcance->centroId, $alcance->huella(), $vigencia, $this->versiones->actual($alcance->centroId), self::CALCULO);
        return $this->cache->recordar($clave, self::TTL_SEGUNDOS, fn (): array => $this->indicadores->opcionesProducto($alcance, $vigencia));
    }

    /** @return array<string,mixed> IND_003, IND_08, IND_12 e IND_17 de una unidad */
    public function unidad(AlcanceDatos $alcance, int $unidadId, FiltrosTablero $f, bool $incluirPractica): array
    {
        $clave = sprintf(
            'tablero_unidad:%d:%d:%s:%s:p%d:v%d:c%d',
            $alcance->centroId,
            $unidadId,
            $alcance->huella(),
            $f->huella(),
            $incluirPractica ? 1 : 0,
            $this->versiones->actual($alcance->centroId),
            self::CALCULO
        );
        return $this->cache->recordar($clave, self::TTL_SEGUNDOS, fn (): array => [
            'hato' => $this->indicadores->hato($alcance, $unidadId, $incluirPractica, $f->fin()),
            'transformacion' => $this->indicadores->transformacion($alcance, $unidadId, $f),
            'rotacion' => $this->indicadores->rotacion($alcance, $unidadId, $f),
            'costo' => $this->indicadores->costoLitro($alcance, $unidadId, $f),
        ]);
    }
}
