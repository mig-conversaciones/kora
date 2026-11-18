<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Nucleo\BaseDatos;
use App\Nucleo\ErrorHttp;
use App\Politicas\AlcanceDatos;
use InvalidArgumentException;

/**
 * Base de los repositorios de negocio. Todo método público exige un AlcanceDatos y antepone su condición a
 * cualquier filtro, de modo que ninguna consulta de negocio puede omitirlo (RDI-003).
 */
abstract class RepositorioConAlcance
{
    /** Tabla y alias: constantes de código, nunca valores de la entrada. */
    protected const TABLA = '';
    protected const ALIAS = 't';
    /** Columnas ordenables (lista blanca, RS-003). */
    protected const ORDENABLES = ['id' => 't.id'];

    public function __construct(protected readonly BaseDatos $bd)
    {
    }

    /**
     * Listado paginado. $filtrosSql lo construye la subclase con marcadores; nunca interpola valores.
     * Se pide una fila extra para calcular X-Has-More sin un COUNT costoso (RNF-PERF-010).
     *
     * @param array<string,scalar|null> $parametros
     * @return array{filas:list<array<string,mixed>>,hayMas:bool}
     */
    public function listar(AlcanceDatos $alcance, string $filtrosSql, array $parametros, string $orden, int $pagina, int $porPagina = 25): array
    {
        [$condicion, $parametrosAlcance] = $alcance->condicionSql(static::ALIAS);
        $porPagina = max(1, min(100, $porPagina));
        $columna = static::ORDENABLES[ltrim($orden, '-')] ?? static::ALIAS . '.id';
        $direccion = str_starts_with($orden, '-') ? 'DESC' : 'ASC';
        $sql = sprintf(
            'SELECT %1$s.* FROM %2$s %1$s WHERE %3$s AND %1$s.archivado_en IS NULL %4$s ORDER BY %5$s %6$s, %1$s.id %6$s LIMIT %7$d OFFSET %8$d',
            static::ALIAS,
            static::TABLA,
            $condicion,
            $filtrosSql === '' ? '' : 'AND ' . $filtrosSql,
            $columna,
            $direccion,
            $porPagina + 1,
            (max(1, $pagina) - 1) * $porPagina
        );
        $filas = $this->bd->consultar($sql, $parametrosAlcance + $parametros);
        return ['filas' => array_slice($filas, 0, $porPagina), 'hayMas' => count($filas) > $porPagina];
    }

    /**
     * Registro por id dentro del alcance; fuera del alcance responde 404 para no revelar su existencia (RAC-028).
     *
     * @return array<string,mixed>
     */
    public function obtener(AlcanceDatos $alcance, int $id): array
    {
        [$condicion, $parametros] = $alcance->condicionSql(static::ALIAS);
        $fila = $this->bd->fila(
            sprintf('SELECT %1$s.* FROM %2$s %1$s WHERE %1$s.id = :id AND %3$s', static::ALIAS, static::TABLA, $condicion),
            $parametros + ['id' => $id]
        );
        if ($fila === null) {
            throw new ErrorHttp(404);
        }
        return $fila;
    }

    /**
     * Actualización con bloqueo optimista (RBD-009). $cambios ya pasó por la lista blanca del caso de uso.
     *
     * @param array<string,scalar|null> $cambios
     */
    public function actualizar(AlcanceDatos $alcance, int $id, int $version, array $cambios, int $usuarioId): void
    {
        $this->obtener($alcance, $id);
        $asignaciones = [];
        foreach (array_keys($cambios) as $columna) {
            if (preg_match('/^[a-z_]+$/', $columna) !== 1) {
                throw new InvalidArgumentException('Columna no permitida.');
            }
            $asignaciones[] = "{$columna} = :{$columna}";
        }
        $filas = $this->bd->ejecutar(
            sprintf('UPDATE %s SET %s, version = version + 1, actualizado_por = :usuario_actor, actualizado_en = UTC_TIMESTAMP(3) WHERE id = :id_registro AND version = :version_esperada', static::TABLA, implode(', ', $asignaciones)),
            $cambios + ['usuario_actor' => $usuarioId, 'id_registro' => $id, 'version_esperada' => $version]
        );
        if ($filas === 0) {
            throw new ErrorHttp(409, 'El registro cambió; recarga para ver la versión actual.');
        }
    }
}
