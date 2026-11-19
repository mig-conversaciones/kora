<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\Entrada;
use App\Nucleo\ErrorHttp;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\Solicitud;

/**
 * Maestros del centro (RF-016, RF-019, RF-020, RF-023): unidades productivas, categorías de gasto, destinos internos
 * y homologación de conceptos. Todos se limitan al centro del usuario; los cambios quedan auditados con antes y
 * después, e incrementan la versión de datos (ADR-010).
 */
final class ServicioCatalogos
{
    public const TIPOS_UNIDAD = ['pecuaria' => 'Pecuaria', 'agricola' => 'Agrícola', 'agroindustrial' => 'Agroindustrial', 'servicios' => 'Servicios', 'tecnica' => 'Técnica (solo heredados)'];
    public const LINEAS = ['mantenimiento' => 'Mantenimiento', 'alimentacion' => 'Alimentación', 'materiales_formacion' => 'Materiales de formación', 'otra' => 'Otra'];

    public function __construct(
        private readonly BaseDatos $bd,
        private readonly ServicioAuditoria $auditoria,
        private readonly ServicioVersionDatos $versiones,
        private readonly ReglasDependencias $dependencias,
    ) {
    }

    /** @var array<int,array{filas:list<array<string,mixed>>,leida:float}> */
    private array $unidadesLeidas = [];

    public static function centro(Solicitud $solicitud): int
    {
        $centro = AutorizacionRecurso::alcance($solicitud)->centroId;
        if ($centro <= 0) {
            throw new ErrorHttp(403);
        }
        return $centro;
    }

    /** Centro activo de la v1, que opera uno solo (S-19); 0 si aún no se sembró. */
    public function centroPrincipal(): int
    {
        return (int) ($this->bd->valor('SELECT MIN(id) FROM centros WHERE activo = 1') ?? 0);
    }

    // ---------------------------------------------------------------- Unidades productivas (RF-016)

    /**
     * Unidades del centro. Una solicitud suele pedirlas para el filtro y para la tabla: se leen una vez (válidas 1 s,
     * de modo que el trabajador, que vive más, no conserva un catálogo viejo) y las activas se filtran en PHP.
     *
     * @return list<array<string,mixed>>
     */
    public function unidades(int $centroId, bool $soloActivas = false): array
    {
        $memoria = $this->unidadesLeidas[$centroId] ?? null;
        if ($memoria === null || microtime(true) - $memoria['leida'] >= 1.0) {
            $memoria = ['filas' => $this->bd->consultar(
                "SELECT * FROM unidades_productivas WHERE centro_id = :centro ORDER BY codigo = 'SIN', nombre",
                ['centro' => $centroId]
            ), 'leida' => microtime(true)];
            $this->unidadesLeidas[$centroId] = $memoria;
        }
        return $soloActivas ? array_values(array_filter($memoria['filas'], static fn (array $u): bool => (int) $u['activa'] === 1)) : $memoria['filas'];
    }

    /**
     * Unidades activas que el usuario puede usar (para los formularios de negocio).
     *
     * @return list<array<string,mixed>>
     */
    public function unidadesDelAlcance(Solicitud $solicitud): array
    {
        $alcance = AutorizacionRecurso::alcance($solicitud);
        return array_values(array_filter(
            $this->unidades($alcance->centroId, true),
            static fn (array $u): bool => $u['codigo'] !== 'SIN' && $alcance->permiteUnidad((int) $u['id'])
        ));
    }

    /** @return array<string,mixed> */
    public function unidad(int $centroId, int $id): array
    {
        $fila = $this->bd->fila('SELECT * FROM unidades_productivas WHERE id = :id AND centro_id = :centro', ['id' => $id, 'centro' => $centroId]);
        if ($fila === null) {
            throw new ErrorHttp(404);
        }
        return $fila;
    }

    /** @return array<string,int> */
    public function bloqueosUnidad(int $centroId, int $id): array
    {
        return $this->dependencias->activosDeUnidad($centroId, $id);
    }

    /** @param array<string,mixed> $datos */
    public function actualizarUnidad(Solicitud $solicitud, int $id, array $datos): void
    {
        $centro = self::centro($solicitud);
        $actual = $this->unidad($centro, $id);
        $nombre = Entrada::texto($datos['nombre'] ?? '', 100);
        $prefijo = strtoupper(trim((string) ($datos['prefijo_lote'] ?? '')));
        $tipo = (string) ($datos['tipo'] ?? '');
        $activa = !empty($datos['activa']) ? 1 : 0;
        $errores = [];
        if ($nombre === null || $nombre === '') {
            $errores['nombre'] = 'Escribe el nombre (máximo 100 caracteres).';
        }
        if (preg_match('/^[A-Z]{2,5}$/', $prefijo) !== 1) {
            $errores['prefijo_lote'] = 'El prefijo de lote tiene de 2 a 5 letras mayúsculas.';
        } elseif ((int) $this->bd->valor('SELECT COUNT(*) FROM unidades_productivas WHERE centro_id = :c AND prefijo_lote = :p AND id <> :id', ['c' => $centro, 'p' => $prefijo, 'id' => $id]) > 0) {
            $errores['prefijo_lote'] = 'Otra unidad ya usa ese prefijo.';
        }
        if (!isset(self::TIPOS_UNIDAD[$tipo]) || ($tipo === 'tecnica') !== ($actual['codigo'] === 'SIN')) {
            $errores['tipo'] = 'Elige un tipo válido; «Técnica» es exclusivo de la unidad SIN.';
        }
        if ($activa === 0 && (int) $actual['activa'] === 1 && ($bloqueos = $this->bloqueosUnidad($centro, $id)) !== []) {
            $errores['activa'] = 'No se puede inactivar: tiene ' . ReglasDependencias::describir($bloqueos) . ' activos. Archívalos o reasígnalos primero.';
        }
        if ($errores !== []) {
            throw new ErrorValidacion($errores);
        }
        $nuevos = ['nombre' => $nombre, 'prefijo_lote' => $prefijo, 'tipo' => $tipo, 'activa' => $activa];
        $this->bd->transaccion(function (BaseDatos $bd) use ($solicitud, $id, $centro, $actual, $nuevos): void {
            $this->unidadesLeidas = [];
            $bd->ejecutar('UPDATE unidades_productivas SET nombre = :nombre, prefijo_lote = :prefijo_lote, tipo = :tipo, activa = :activa WHERE id = :id', $nuevos + ['id' => $id]);
            $this->auditoria->registrar($solicitud, 'unidades.actualizar', 'exito', new RecursoAuditado('unidades_productivas', $id, $id), [], self::extraer($actual, array_keys($nuevos)), $nuevos);
            $this->versiones->incrementar($centro);
        });
    }

    // ---------------------------------------------------------------- Categorías de gasto (RF-020)

    /** @return list<array<string,mixed>> */
    public function categorias(int $centroId, bool $soloActivas = false): array
    {
        return $this->bd->consultar(
            'SELECT * FROM categorias_gasto WHERE centro_id = :centro' . ($soloActivas ? ' AND activa = 1' : '') . ' ORDER BY id',
            ['centro' => $centroId]
        );
    }

    /** @param array<string,mixed> $datos */
    public function crearCategoria(Solicitud $solicitud, array $datos): int
    {
        $centro = self::centro($solicitud);
        $nombre = Entrada::texto($datos['nombre'] ?? '', 80);
        $codigo = strtoupper(trim((string) ($datos['codigo'] ?? '')));
        $linea = (string) ($datos['linea_presupuestal'] ?? '');
        $errores = [];
        if ($nombre === null || $nombre === '') {
            $errores['nombre'] = 'Escribe el nombre (máximo 80 caracteres).';
        }
        if (preg_match('/^[A-Z][A-Z0-9_]{1,29}$/', $codigo) !== 1) {
            $errores['codigo'] = 'El código usa mayúsculas, números y guion bajo (2 a 30 caracteres).';
        } elseif ((int) $this->bd->valor('SELECT COUNT(*) FROM categorias_gasto WHERE centro_id = :c AND codigo = :codigo', ['c' => $centro, 'codigo' => $codigo]) > 0) {
            $errores['codigo'] = 'Ya existe una categoría con ese código.';
        }
        if (!isset(self::LINEAS[$linea])) {
            $errores['linea_presupuestal'] = 'Elige la línea presupuestal.';
        }
        if ($errores !== []) {
            throw new ErrorValidacion($errores);
        }
        // «Incluye en IND_17» solo puede activarse en las líneas de Mantenimiento y Alimentación (S-14).
        $ind17 = !empty($datos['incluye_ind17']) && in_array($linea, ['mantenimiento', 'alimentacion'], true) ? 1 : 0;
        return $this->bd->transaccion(function (BaseDatos $bd) use ($solicitud, $centro, $codigo, $nombre, $linea, $ind17): int {
            $id = $bd->insertar(
                'INSERT INTO categorias_gasto (centro_id, codigo, nombre, linea_presupuestal, incluye_ind17) VALUES (:c, :codigo, :nombre, :linea, :ind17)',
                ['c' => $centro, 'codigo' => $codigo, 'nombre' => $nombre, 'linea' => $linea, 'ind17' => $ind17]
            );
            $this->auditoria->registrar($solicitud, 'categorias_gasto.crear', 'exito', new RecursoAuditado('categorias_gasto', $id), [], null, ['codigo' => $codigo, 'nombre' => $nombre, 'linea_presupuestal' => $linea, 'incluye_ind17' => $ind17]);
            $this->versiones->incrementar($centro);
            return $id;
        });
    }

    public function cambiarEstadoCategoria(Solicitud $solicitud, int $id, bool $activa): void
    {
        $centro = self::centro($solicitud);
        $this->bd->transaccion(function (BaseDatos $bd) use ($solicitud, $centro, $id, $activa): void {
            $filas = $bd->ejecutar('UPDATE categorias_gasto SET activa = :a WHERE id = :id AND centro_id = :c AND activa <> :a2', ['a' => (int) $activa, 'a2' => (int) $activa, 'id' => $id, 'c' => $centro]);
            if ($filas === 0) {
                throw new ErrorHttp(404);
            }
            $this->auditoria->registrar($solicitud, 'categorias_gasto.' . ($activa ? 'activar' : 'inactivar'), 'exito', new RecursoAuditado('categorias_gasto', $id), [], ['activa' => (int) !$activa], ['activa' => (int) $activa]);
            $this->versiones->incrementar($centro);
        });
    }

    // ---------------------------------------------------------------- Destinos internos (RF-023)

    /** @return list<array<string,mixed>> */
    public function destinos(int $centroId, bool $soloActivos = false): array
    {
        return $this->bd->consultar(
            'SELECT d.*, u.nombre AS unidad_nombre FROM destinos_internos d LEFT JOIN unidades_productivas u ON u.id = d.unidad_id
              WHERE d.centro_id = :centro' . ($soloActivos ? ' AND d.activo = 1' : '') . ' ORDER BY d.nombre',
            ['centro' => $centroId]
        );
    }

    /** @param array<string,mixed> $datos */
    public function guardarDestino(Solicitud $solicitud, ?int $id, array $datos): int
    {
        $centro = self::centro($solicitud);
        $nombre = Entrada::texto($datos['nombre'] ?? '', 120);
        $unidad = trim((string) ($datos['unidad_id'] ?? '')) === '' ? null : Entrada::entero($datos['unidad_id'], 1);
        $activo = $id === null || !empty($datos['activo']) ? 1 : 0;
        $errores = [];
        if ($nombre === null || $nombre === '') {
            $errores['nombre'] = 'Escribe el nombre del destino (máximo 120 caracteres).';
        } elseif ((int) $this->bd->valor('SELECT COUNT(*) FROM destinos_internos WHERE centro_id = :c AND nombre = :n AND id <> :id', ['c' => $centro, 'n' => $nombre, 'id' => $id ?? 0]) > 0) {
            $errores['nombre'] = 'Ya existe un destino con ese nombre.';
        }
        if ($unidad !== null && (int) $this->bd->valor('SELECT COUNT(*) FROM unidades_productivas WHERE id = :u AND centro_id = :c AND activa = 1', ['u' => $unidad, 'c' => $centro]) === 0) {
            $errores['unidad_id'] = 'Elige una unidad receptora activa.';
        }
        if ($errores !== []) {
            throw new ErrorValidacion($errores);
        }
        $nuevos = ['nombre' => $nombre, 'unidad_id' => $unidad, 'activo' => $activo];
        return $this->bd->transaccion(function (BaseDatos $bd) use ($solicitud, $centro, $id, $nuevos): int {
            if ($id === null) {
                $id = $bd->insertar('INSERT INTO destinos_internos (centro_id, nombre, unidad_id, activo) VALUES (:c, :nombre, :unidad_id, :activo)', $nuevos + ['c' => $centro]);
                $antes = null;
            } else {
                $antes = $bd->fila('SELECT nombre, unidad_id, activo FROM destinos_internos WHERE id = :id AND centro_id = :c FOR UPDATE', ['id' => $id, 'c' => $centro]);
                if ($antes === null) {
                    throw new ErrorHttp(404);
                }
                $bd->ejecutar('UPDATE destinos_internos SET nombre = :nombre, unidad_id = :unidad_id, activo = :activo WHERE id = :id', $nuevos + ['id' => $id]);
            }
            $this->auditoria->registrar($solicitud, $antes === null ? 'destinos.crear' : 'destinos.actualizar', 'exito', new RecursoAuditado('destinos_internos', $id), [], $antes, $nuevos);
            $this->versiones->incrementar($centro);
            return $id;
        });
    }

    // ---------------------------------------------------------------- Homologación de conceptos (RF-019)

    /** Sin tildes, minúsculas y espacios simples: «  Leche de VACA » → «leche de vaca». */
    public static function normalizarConcepto(string $texto): string
    {
        $descompuesto = \Normalizer::normalize(trim($texto), \Normalizer::FORM_D) ?: $texto;
        $sinTildes = (string) preg_replace('/\p{Mn}+/u', '', $descompuesto);
        return (string) preg_replace('/\s+/u', ' ', mb_strtolower(\Normalizer::normalize($sinTildes, \Normalizer::FORM_C) ?: $sinTildes));
    }

    /** @return list<array<string,mixed>> */
    public function homologaciones(int $centroId, string $buscar = ''): array
    {
        $parametros = ['centro' => $centroId];
        $filtro = '';
        if ($buscar !== '') {
            $filtro = ' AND h.texto_normalizado LIKE :buscar';
            $parametros['buscar'] = '%' . addcslashes(self::normalizarConcepto($buscar), '%_\\') . '%';
        }
        return $this->bd->consultar(
            "SELECT h.*, p.codigo_sgac, p.nombre AS producto_nombre FROM homologaciones_producto h
               LEFT JOIN productos p ON p.id = h.producto_id
              WHERE h.centro_id = :centro{$filtro} ORDER BY h.producto_id IS NOT NULL, h.texto_normalizado LIMIT 200",
            $parametros
        );
    }

    /**
     * Crea o reasigna una equivalencia. Producto vacío = «concepto fuente» sin homologar: los genéricos («Queso»)
     * nunca se asignan solos a una variedad (RF-019).
     *
     * @param array<string,mixed> $datos
     */
    public function guardarHomologacion(Solicitud $solicitud, array $datos): int
    {
        $centro = self::centro($solicitud);
        $texto = self::normalizarConcepto((string) ($datos['texto'] ?? ''));
        $producto = trim((string) ($datos['producto_id'] ?? '')) === '' ? null : Entrada::entero($datos['producto_id'], 1);
        $errores = [];
        if ($texto === '' || mb_strlen($texto) > 200) {
            $errores['texto'] = 'Escribe el concepto tal como aparece en la fuente (máximo 200 caracteres).';
        }
        if ($producto !== null && (int) $this->bd->valor('SELECT COUNT(*) FROM productos WHERE id = :p AND centro_id = :c AND archivado_en IS NULL', ['p' => $producto, 'c' => $centro]) === 0) {
            $errores['producto_id'] = 'Elige un producto activo del catálogo.';
        }
        if ($errores !== []) {
            throw new ErrorValidacion($errores);
        }
        $actor = AutorizacionRecurso::usuario($solicitud)->id;
        return $this->bd->transaccion(function (BaseDatos $bd) use ($solicitud, $centro, $texto, $producto, $actor): int {
            $antes = $bd->fila('SELECT id, producto_id FROM homologaciones_producto WHERE centro_id = :c AND texto_normalizado = :t FOR UPDATE', ['c' => $centro, 't' => $texto]);
            if ($antes === null) {
                $id = $bd->insertar(
                    'INSERT INTO homologaciones_producto (centro_id, texto_normalizado, producto_id, origen, creado_por) VALUES (:c, :t, :p, \'manual\', :actor)',
                    ['c' => $centro, 't' => $texto, 'p' => $producto, 'actor' => $actor]
                );
            } else {
                $id = (int) $antes['id'];
                $bd->ejecutar('UPDATE homologaciones_producto SET producto_id = :p WHERE id = :id', ['p' => $producto, 'id' => $id]);
            }
            $this->auditoria->registrar($solicitud, 'homologaciones.guardar', 'exito', new RecursoAuditado('homologaciones_producto', $id), ['texto' => $texto], $antes === null ? null : ['producto_id' => $antes['producto_id']], ['producto_id' => $producto]);
            $this->versiones->incrementar($centro);
            return $id;
        });
    }

    /**
     * @param array<string,mixed> $fila
     * @param list<string> $claves
     * @return array<string,mixed>
     */
    public static function extraer(array $fila, array $claves): array
    {
        return array_intersect_key($fila, array_flip($claves));
    }
}
