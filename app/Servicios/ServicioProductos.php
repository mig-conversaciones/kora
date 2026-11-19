<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\Entrada;
use App\Nucleo\ErrorHttp;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\Solicitud;

/**
 * Catálogo de productos (RF-017) y derivados lácteos con factores (RF-018):
 *   - El código SGAC es único por centro y no cambia.
 *   - La medida no cambia si el producto tiene recepciones.
 *   - Precio mínimo ≤ precio de venta ≤ precio máximo, cuando los tres están.
 *   - Un derivado lácteo exige un factor > 0 y una meta mínima ≤ máxima. Un factor 0 o nulo lo excluye de IND_08 y
 *     se informa como incidencia de configuración.
 * Los factores solo se cambian con el permiso catalogo.gestionar_factores, y el cambio queda auditado (no altera lo
 * ya exportado: los indicadores guardan el factor usado, I6).
 */
final class ServicioProductos
{
    public const MEDIDAS = ['L' => 'Litro (L)', 'kg' => 'Kilogramo (kg)', 'ud' => 'Unidad (ud)', 'lb' => 'Libra (lb)', 'cubeta' => 'Cubeta de 30 unidades'];
    public const ROTACION = ['fresco' => 'Fresco', 'animal' => 'Animal', 'otro' => 'Otro'];
    /** Derivados que la semilla espera encontrar en el catálogo (S-12, Obs. 6). */
    public const DERIVADOS_ESPERADOS = ['queso cuajada', 'yogurt', 'manjar blanco', 'cortado'];

    private const CAMPOS_GENERALES = ['nombre', 'medida', 'clase', 'codigo_familia', 'nombre_familia', 'precio_minimo', 'precio_maximo', 'precio_venta', 'unidad_sugerida_id', 'categoria_rotacion'];
    private const CAMPOS_FACTORES = ['es_derivado_lacteo', 'factor_leche', 'meta_rendimiento_min', 'meta_rendimiento_max'];

    public function __construct(
        private readonly BaseDatos $bd,
        private readonly ServicioAuditoria $auditoria,
        private readonly ServicioVersionDatos $versiones,
        private readonly ReglasDependencias $dependencias,
    ) {
    }

    /**
     * @return array{filas:list<array<string,mixed>>,hayMas:bool}
     */
    public function listar(int $centroId, string $buscar, bool $soloDerivados, int $pagina, int $porPagina = 25): array
    {
        $parametros = ['centro' => $centroId];
        $filtros = '';
        if ($buscar !== '') {
            $filtros .= ' AND (p.nombre LIKE :buscar OR p.codigo_sgac LIKE :buscar2)';
            $parametros['buscar'] = $parametros['buscar2'] = '%' . addcslashes($buscar, '%_\\') . '%';
        }
        if ($soloDerivados) {
            $filtros .= ' AND p.es_derivado_lacteo = 1';
        }
        $filas = $this->bd->consultar(
            "SELECT p.*, u.nombre AS unidad_nombre FROM productos p LEFT JOIN unidades_productivas u ON u.id = p.unidad_sugerida_id
              WHERE p.centro_id = :centro AND p.archivado_en IS NULL{$filtros}
              ORDER BY p.nombre, p.id LIMIT " . ($porPagina + 1) . ' OFFSET ' . ((max(1, $pagina) - 1) * $porPagina),
            $parametros
        );
        return ['filas' => array_slice($filas, 0, $porPagina), 'hayMas' => count($filas) > $porPagina];
    }

    /** @return list<array<string,mixed>> productos activos para los desplegables */
    public function opciones(int $centroId): array
    {
        return $this->bd->consultar('SELECT id, codigo_sgac, nombre, medida FROM productos WHERE centro_id = :c AND archivado_en IS NULL ORDER BY nombre', ['c' => $centroId]);
    }

    /** @return array<string,mixed> */
    public function obtener(int $centroId, int $id): array
    {
        $fila = $this->bd->fila('SELECT * FROM productos WHERE id = :id AND centro_id = :c AND archivado_en IS NULL', ['id' => $id, 'c' => $centroId]);
        if ($fila === null) {
            throw new ErrorHttp(404);
        }
        return $fila;
    }

    /**
     * Incidencias de configuración de derivados (RF-018): esperados que no están en el catálogo y derivados sin
     * factor válido.
     *
     * @return list<string>
     */
    public function incidenciasDerivados(int $centroId): array
    {
        $incidencias = [];
        $presentes = array_map(static fn (array $f): string => mb_strtolower((string) $f['nombre']), $this->bd->consultar(
            'SELECT nombre FROM productos WHERE centro_id = :c AND archivado_en IS NULL AND LOWER(nombre) IN (\'queso cuajada\', \'yogurt\', \'manjar blanco\', \'cortado\')',
            ['c' => $centroId]
        ));
        foreach (self::DERIVADOS_ESPERADOS as $esperado) {
            if (!in_array($esperado, $presentes, true)) {
                $incidencias[] = 'El derivado «' . ucfirst($esperado) . '» no está en el catálogo: créalo con su código SGAC oficial para que aparezca en las transformaciones.';
            }
        }
        foreach ($this->bd->consultar('SELECT nombre FROM productos WHERE centro_id = :c AND archivado_en IS NULL AND es_derivado_lacteo = 1 AND (factor_leche IS NULL OR factor_leche = 0)', ['c' => $centroId]) as $fila) {
            $incidencias[] = 'El derivado «' . $fila['nombre'] . '» no tiene factor de leche: queda fuera de IND_08 hasta configurarlo.';
        }
        return $incidencias;
    }

    /** @param array<string,mixed> $datos */
    public function crear(Solicitud $solicitud, array $datos, bool $puedeFactores): int
    {
        $centro = ServicioCatalogos::centro($solicitud);
        $codigo = trim((string) ($datos['codigo_sgac'] ?? ''));
        $errores = [];
        if (preg_match('/^\d{4,20}$/', $codigo) !== 1) {
            $errores['codigo_sgac'] = 'El código SGAC tiene solo dígitos (4 a 20).';
        } elseif ((int) $this->bd->valor('SELECT COUNT(*) FROM productos WHERE centro_id = :c AND codigo_sgac = :codigo', ['c' => $centro, 'codigo' => $codigo]) > 0) {
            $errores['codigo_sgac'] = 'Ya existe un producto con ese código SGAC.';
        }
        [$valores, $erroresCampos] = $this->validar($centro, $datos, $puedeFactores, null);
        $errores += $erroresCampos;
        if ($errores !== []) {
            throw new ErrorValidacion($errores);
        }
        $actor = AutorizacionRecurso::usuario($solicitud)->id;
        return $this->bd->transaccion(function (BaseDatos $bd) use ($solicitud, $centro, $codigo, $valores, $actor): int {
            $columnas = array_keys($valores);
            $id = $bd->insertar(
                'INSERT INTO productos (centro_id, codigo_sgac, ' . implode(', ', $columnas) . ', origen_tipo, creado_por, actualizado_por)
                 VALUES (:centro_id, :codigo_sgac, :' . implode(', :', $columnas) . ", 'manual', :creado_por, :actualizado_por)",
                $valores + ['centro_id' => $centro, 'codigo_sgac' => $codigo, 'creado_por' => $actor, 'actualizado_por' => $actor]
            );
            $this->auditoria->registrar($solicitud, 'productos.crear', 'exito', new RecursoAuditado('productos', $id), [], null, ['codigo_sgac' => $codigo] + $valores);
            $this->versiones->incrementar($centro);
            return $id;
        });
    }

    /** @param array<string,mixed> $datos */
    public function actualizar(Solicitud $solicitud, int $id, array $datos, bool $puedeGenerales, bool $puedeFactores): void
    {
        $centro = ServicioCatalogos::centro($solicitud);
        $actual = $this->obtener($centro, $id);
        $version = Entrada::entero($datos['version'] ?? '', 1);
        if ($version === null) {
            throw new ErrorHttp(409, 'Falta la versión del registro; recarga la página.');
        }
        if (!$puedeGenerales) {
            // Quien solo gestiona factores conserva los datos generales tal como están.
            $datos = array_merge($datos, ServicioCatalogos::extraer($actual, self::CAMPOS_GENERALES));
        }
        [$valores, $errores] = $this->validar($centro, $datos, $puedeFactores, $actual);
        if ($errores !== []) {
            throw new ErrorValidacion($errores);
        }
        $cambios = array_filter($valores, static fn ($v, string $k): bool => (string) $v !== (string) ($actual[$k] ?? ''), ARRAY_FILTER_USE_BOTH);
        if ($cambios === []) {
            return;
        }
        $actor = AutorizacionRecurso::usuario($solicitud)->id;
        $this->bd->transaccion(function (BaseDatos $bd) use ($solicitud, $centro, $id, $version, $cambios, $actual, $actor): void {
            $asignaciones = implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", array_keys($cambios)));
            $filas = $bd->ejecutar(
                "UPDATE productos SET {$asignaciones}, version = version + 1, actualizado_por = :actor, actualizado_en = UTC_TIMESTAMP(3) WHERE id = :id AND version = :version",
                $cambios + ['actor' => $actor, 'id' => $id, 'version' => $version]
            );
            if ($filas === 0) {
                throw new ErrorHttp(409, 'El producto cambió mientras lo editabas; recarga para ver la versión actual.');
            }
            $accion = array_intersect_key($cambios, array_flip(self::CAMPOS_FACTORES)) !== [] ? 'productos.actualizar_factores' : 'productos.actualizar';
            $this->auditoria->registrar($solicitud, $accion, 'exito', new RecursoAuditado('productos', $id), [], ServicioCatalogos::extraer($actual, array_keys($cambios)), $cambios);
            $this->versiones->incrementar($centro);
        });
    }

    /**
     * @param array<string,mixed> $datos
     * @param array<string,mixed>|null $actual
     * @return array{0:array<string,string|int|null>,1:array<string,string>}
     */
    private function validar(int $centro, array $datos, bool $puedeFactores, ?array $actual): array
    {
        $errores = [];
        $nombre = Entrada::texto($datos['nombre'] ?? '', 200);
        if ($nombre === null || $nombre === '') {
            $errores['nombre'] = 'Escribe el nombre del producto (máximo 200 caracteres).';
        }
        $medida = (string) ($datos['medida'] ?? '');
        if (!isset(self::MEDIDAS[$medida])) {
            $errores['medida'] = 'Elige la medida.';
        } elseif ($actual !== null && $medida !== $actual['medida'] && ($recepciones = $this->dependencias->recepcionesDeProducto((int) $actual['id'])) > 0) {
            $errores['medida'] = "La medida no puede cambiar: el producto tiene {$recepciones} recepciones registradas en «{$actual['medida']}».";
        }
        $opcional = static fn (string $campo, int $max): ?string => ($t = Entrada::texto($datos[$campo] ?? '', $max)) === '' ? null : $t;
        $clase = $opcional('clase', 150);
        $codigoFamilia = $opcional('codigo_familia', 10);
        $nombreFamilia = $opcional('nombre_familia', 150);
        foreach (['clase' => [$clase, 150], 'codigo_familia' => [$codigoFamilia, 10], 'nombre_familia' => [$nombreFamilia, 150]] as $campo => [$valor, $max]) {
            if ($valor === null && trim((string) ($datos[$campo] ?? '')) !== '') {
                $errores[$campo] = "Máximo {$max} caracteres.";
            }
        }
        $precios = [];
        foreach (['precio_minimo', 'precio_maximo', 'precio_venta'] as $campo) {
            $texto = trim((string) ($datos[$campo] ?? ''));
            $precios[$campo] = $texto === '' ? null : Entrada::decimal($texto);
            if ($texto !== '' && $precios[$campo] === null) {
                $errores[$campo] = 'Escribe un valor en pesos, sin signo (por ejemplo 2.200).';
            }
        }
        if ($precios['precio_minimo'] !== null && $precios['precio_maximo'] !== null && Entrada::comparar($precios['precio_minimo'], $precios['precio_maximo']) > 0) {
            $errores['precio_maximo'] = 'El precio máximo no puede ser menor que el mínimo.';
        }
        if ($precios['precio_venta'] !== null && !isset($errores['precio_maximo'])) {
            if (($precios['precio_minimo'] !== null && Entrada::comparar($precios['precio_venta'], $precios['precio_minimo']) < 0)
                || ($precios['precio_maximo'] !== null && Entrada::comparar($precios['precio_venta'], $precios['precio_maximo']) > 0)) {
                $errores['precio_venta'] = 'El precio de venta debe estar entre el mínimo y el máximo.';
            }
        }
        $unidad = trim((string) ($datos['unidad_sugerida_id'] ?? '')) === '' ? null : Entrada::entero($datos['unidad_sugerida_id'], 1);
        if ($unidad !== null && (int) $this->bd->valor('SELECT COUNT(*) FROM unidades_productivas WHERE id = :u AND centro_id = :c', ['u' => $unidad, 'c' => $centro]) === 0) {
            $errores['unidad_sugerida_id'] = 'Elige una unidad del centro.';
        }
        $rotacion = (string) ($datos['categoria_rotacion'] ?? 'fresco');
        if (!isset(self::ROTACION[$rotacion])) {
            $errores['categoria_rotacion'] = 'Elige la categoría de rotación.';
        }
        $valores = [
            'nombre' => $nombre,
            'medida' => $medida,
            'clase' => $clase,
            'codigo_familia' => $codigoFamilia,
            'nombre_familia' => $nombreFamilia,
            'precio_minimo' => $precios['precio_minimo'],
            'precio_maximo' => $precios['precio_maximo'],
            'precio_venta' => $precios['precio_venta'],
            'unidad_sugerida_id' => $unidad,
            'categoria_rotacion' => $rotacion,
        ];
        if ($puedeFactores) {
            [$factores, $erroresFactores] = $this->validarFactores($datos);
            $valores += $factores;
            $errores += $erroresFactores;
        }
        return [$valores, $errores];
    }

    /**
     * @param array<string,mixed> $datos
     * @return array{0:array<string,string|int|null>,1:array<string,string>}
     */
    private function validarFactores(array $datos): array
    {
        $derivado = !empty($datos['es_derivado_lacteo']) ? 1 : 0;
        if ($derivado === 0) {
            return [['es_derivado_lacteo' => 0, 'factor_leche' => null, 'meta_rendimiento_min' => null, 'meta_rendimiento_max' => null], []];
        }
        $errores = [];
        $valores = ['es_derivado_lacteo' => 1];
        foreach (['factor_leche' => 'el factor de leche', 'meta_rendimiento_min' => 'la meta mínima', 'meta_rendimiento_max' => 'la meta máxima'] as $campo => $texto) {
            $valores[$campo] = Entrada::decimal($datos[$campo] ?? '', 4);
            if ($valores[$campo] === null || Entrada::comparar($valores[$campo], '0.0000') <= 0) {
                $errores[$campo] = "Escribe {$texto} en litros por unidad, mayor que 0 (hasta 4 decimales).";
            }
        }
        if (!isset($errores['meta_rendimiento_min']) && !isset($errores['meta_rendimiento_max'])
            && Entrada::comparar((string) $valores['meta_rendimiento_min'], (string) $valores['meta_rendimiento_max']) > 0) {
            $errores['meta_rendimiento_max'] = 'La meta máxima no puede ser menor que la mínima.';
        }
        return [$valores, $errores];
    }
}
