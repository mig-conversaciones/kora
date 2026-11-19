<?php

declare(strict_types=1);

namespace App\Importacion;

use App\Nucleo\BaseDatos;
use App\Nucleo\Entrada;
use App\Nucleo\ErrorHttp;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\GeneradorUlid;
use App\Nucleo\Solicitud;
use App\Operacion\ServicioRespaldo;
use App\Servicios\AutorizacionRecurso;
use App\Servicios\RecursoAuditado;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioVersionDatos;

/**
 * Carga inicial guiada del piloto (RF-094, RDL-011). Ordena las fuentes, toma la copia previa y compara las cifras de
 * control calculadas del archivo (conteo y suma, en total y por unidad) con las que hay en la base en el momento de
 * verificar. Si hay cualquier diferencia, la carga no queda «verificada» y el acta las lista. Solo una carga
 * verificada se puede firmar, y al firmar se verifica de nuevo: si algo cambió desde la verificación, no se firma.
 *
 * La importación de cada fuente es la de siempre (RF-083 a RF-093). Aquí solo se lleva el orden, el estado y el acta.
 * Para cada fuente vale el último lote aplicado desde que empezó la carga: si un archivo tenía errores, se corrige y
 * se vuelve a cargar.
 */
final class ServicioCargaInicial
{
    /**
     * Orden de la carga: el catálogo antes que la producción y las ventas, que lo referencian; el presupuesto antes
     * que los cupos; el hato al final, con su fecha de corte.
     *
     * @var array<string,array{obligatoria:bool,libro:string}>
     */
    public const PASOS = [
        'catalogo_productos' => ['obligatoria' => true, 'libro' => 'BBDD_Produccion de Centro (hoja de productos)'],
        'presupuesto' => ['obligatoria' => true, 'libro' => 'BBDD_DATOS ADMON (Ingresos - Gastos)'],
        'cupos_autoconsumo' => ['obligatoria' => true, 'libro' => 'BBDD_DATOS ADMON (cupos de autoconsumo)'],
        'recaudos_dg' => ['obligatoria' => true, 'libro' => 'BBDD_DATOS ADMON (Ingresos_Centro Agropecuario)'],
        'gastos_ejecutados' => ['obligatoria' => false, 'libro' => 'Plantilla de gastos ejecutados, si la coordinación la tiene'],
        'base_produccion' => ['obligatoria' => true, 'libro' => 'BBDD_Produccion de Centro (Base Producción)'],
        'base_ventas' => ['obligatoria' => true, 'libro' => 'BBDD_Produccion de Centro (Base Ventas)'],
        'estado_reproductivo' => ['obligatoria' => true, 'libro' => 'INFO_Hato (ESTADO REP), con su fecha de corte'],
    ];

    /** Tablas en las que un registro que no viene del último lote de la carga es una diferencia (RDL-011). */
    private const TABLAS_EXCLUSIVAS = ['productos', 'asignaciones_presupuestales', 'procesos_autoconsumo', 'recaudos', 'gastos_ejecutados', 'recepciones_lote', 'salidas'];

    public function __construct(
        private readonly BaseDatos $bd,
        private readonly ServicioImportaciones $importaciones,
        private readonly ServicioRespaldo $respaldo,
        private readonly ServicioAuditoria $auditoria,
        private readonly ServicioVersionDatos $versiones,
        /** @var array<string,array{obligatoria:bool,libro:string}> */
        private readonly array $pasosCarga = self::PASOS,
    ) {
    }

    /** @return array<string,mixed>|null carga vigente del centro (no anulada), la más reciente */
    public function vigente(int $centroId): ?array
    {
        return $this->bd->fila(
            "SELECT c.*, ui.nombre AS iniciada_por_nombre, uf.nombre AS firmada_por_nombre FROM cargas_iniciales c
               JOIN usuarios ui ON ui.id = c.iniciada_por LEFT JOIN usuarios uf ON uf.id = c.firmada_por
              WHERE c.centro_id = :c AND c.estado <> 'anulada' ORDER BY c.id DESC LIMIT 1",
            ['c' => $centroId]
        );
    }

    /** @return array<string,mixed> carga del centro del actor; 404 si no existe o es de otro centro */
    public function carga(Solicitud $solicitud, string $ulid): array
    {
        $fila = $this->bd->fila(
            'SELECT c.*, ui.nombre AS iniciada_por_nombre, uf.nombre AS firmada_por_nombre FROM cargas_iniciales c
               JOIN usuarios ui ON ui.id = c.iniciada_por LEFT JOIN usuarios uf ON uf.id = c.firmada_por
              WHERE c.ulid = :u AND c.centro_id = :c',
            ['u' => $ulid, 'c' => AutorizacionRecurso::alcance($solicitud)->centroId]
        );
        return $fila ?? throw new ErrorHttp(404);
    }

    /** @return list<array<string,mixed>> cargas anteriores, también las anuladas */
    public function historial(int $centroId): array
    {
        return $this->bd->consultar(
            'SELECT c.ulid, c.estado, c.vigencia, c.iniciada_en, c.firmada_en, c.anulada_motivo, u.nombre AS iniciada_por_nombre
               FROM cargas_iniciales c JOIN usuarios u ON u.id = c.iniciada_por WHERE c.centro_id = :c ORDER BY c.id DESC LIMIT 20',
            ['c' => $centroId]
        );
    }

    /** @return array<string,mixed> carga creada */
    public function iniciar(Solicitud $solicitud, string $vigencia): array
    {
        $alcance = AutorizacionRecurso::alcance($solicitud);
        $usuario = AutorizacionRecurso::usuario($solicitud);
        $anio = Entrada::entero($vigencia, 2020, 2100);
        if ($anio === null) {
            throw new ErrorValidacion(['vigencia' => 'Escribe la vigencia de la carga, por ejemplo 2027.']);
        }
        $vigente = $this->vigente($alcance->centroId);
        if ($vigente !== null) {
            throw new ErrorValidacion(['carga' => $vigente['estado'] === 'firmada'
                ? 'La carga inicial de este centro ya está firmada: la producción empezó con ella.'
                : 'Ya hay una carga inicial en curso. Continúala o anúlala con su motivo.']);
        }
        $respaldo = $this->respaldo->copiaPrevia('preimportacion', 'Antes de la carga inicial del piloto (RF-094)');
        $ulid = GeneradorUlid::generar();
        $this->bd->ejecutar(
            'INSERT INTO cargas_iniciales (ulid, centro_id, vigencia, respaldo_previo, iniciada_por) VALUES (:u, :c, :v, :r, :p)',
            ['u' => $ulid, 'c' => $alcance->centroId, 'v' => $anio, 'r' => $respaldo, 'p' => $usuario->id]
        );
        $carga = $this->carga($solicitud, $ulid);
        $this->auditoria->registrar($solicitud, 'carga_inicial.iniciar', 'exito', new RecursoAuditado('cargas_iniciales', (int) $carga['id']), ['vigencia' => $anio, 'respaldo' => $respaldo]);
        return $carga;
    }

    /**
     * Estado de cada paso para la pantalla guiada.
     *
     * @param array<string,mixed> $carga
     * @return list<array<string,mixed>>
     */
    public function pasos(array $carga): array
    {
        $registro = $this->importaciones->registro()->todas();
        $lotes = $this->lotesDesde($carga);
        $pasos = [];
        foreach ($this->pasosCarga as $clave => $paso) {
            $deFuente = array_values(array_filter($lotes, static fn (array $l): bool => $l['fuente'] === $clave));
            $aplicado = array_values(array_filter($deFuente, static fn (array $l): bool => $l['estado'] === 'aplicado'))[0] ?? null;
            // Un lote sin aplicar anterior al último aplicado quedó reemplazado; uno posterior deja la fuente en duda.
            $desde = $aplicado === null ? 0 : (int) $aplicado['id'];
            $pasos[] = [
                'clave' => $clave,
                'nombre' => isset($registro[$clave]) ? $registro[$clave]->nombre() : $clave,
                'libro' => $paso['libro'],
                'obligatoria' => $paso['obligatoria'],
                'ultimo' => $deFuente[0] ?? null,
                'aplicado' => $aplicado,
                'pendientes' => $aplicado === null ? 0 : count(array_filter($deFuente, static fn (array $l): bool => (int) $l['id'] > $desde
                    && in_array($l['estado'], ['cargado', 'validando', 'validado', 'con_errores', 'aplicando', 'revirtiendo'], true))),
            ];
        }
        return $pasos;
    }

    /**
     * Compara archivo y base para cada fuente y guarda el resultado. Devuelve la carga actualizada.
     *
     * @return array<string,mixed>
     */
    public function verificar(Solicitud $solicitud, string $ulid): array
    {
        $carga = $this->carga($solicitud, $ulid);
        if (!in_array($carga['estado'], ['en_curso', 'con_diferencias', 'verificada'], true)) {
            throw new ErrorValidacion(['carga' => 'Esta carga ya no se puede verificar.']);
        }
        $resultado = $this->calcular($carga);
        $estado = $resultado['diferencias'] === 0 ? 'verificada' : 'con_diferencias';
        $this->bd->ejecutar(
            'UPDATE cargas_iniciales SET estado = :e, resultado = :r, huella = :h, verificada_en = UTC_TIMESTAMP(3) WHERE id = :id',
            ['e' => $estado, 'r' => self::json($resultado), 'h' => self::huella($resultado), 'id' => $carga['id']]
        );
        $this->auditoria->registrar($solicitud, 'carga_inicial.verificar', $estado === 'verificada' ? 'exito' : 'fallo', new RecursoAuditado('cargas_iniciales', (int) $carga['id']), ['diferencias' => $resultado['diferencias']]);
        return $this->carga($solicitud, $ulid);
    }

    /** @return array<string,mixed> carga firmada */
    public function firmar(Solicitud $solicitud, string $ulid, string $observaciones, bool $declaracion): array
    {
        $carga = $this->carga($solicitud, $ulid);
        if ($carga['estado'] !== 'verificada') {
            throw new ErrorValidacion(['carga' => 'Solo se firma una carga verificada, sin diferencias en las cifras de control.']);
        }
        $observaciones = trim($observaciones);
        $errores = [];
        if (!$declaracion) {
            $errores['declaracion'] = 'Confirma que revisaste las cifras del acta con los archivos fuente.';
        }
        if (mb_strlen($observaciones) > 1000) {
            $errores['observaciones'] = 'Las observaciones admiten hasta 1.000 caracteres.';
        }
        if ($errores !== []) {
            throw new ErrorValidacion($errores);
        }
        // Se verifica de nuevo: si algo cambió desde la verificación (un lote revertido, un registro editado), no se firma.
        $resultado = $this->calcular($carga);
        if ($resultado['diferencias'] > 0 || self::huella($resultado) !== $carga['huella']) {
            $this->bd->ejecutar(
                'UPDATE cargas_iniciales SET estado = :e, resultado = :r, huella = :h, verificada_en = UTC_TIMESTAMP(3) WHERE id = :id',
                ['e' => $resultado['diferencias'] > 0 ? 'con_diferencias' : 'verificada', 'r' => self::json($resultado), 'h' => self::huella($resultado), 'id' => $carga['id']]
            );
            throw new ErrorValidacion(['carga' => 'Los datos cambiaron desde la verificación. Revisa el acta actualizada antes de firmar.']);
        }
        $usuario = AutorizacionRecurso::usuario($solicitud);
        $this->bd->ejecutar(
            "UPDATE cargas_iniciales SET estado = 'firmada', firmada_por = :u, firmada_en = UTC_TIMESTAMP(3), observaciones = :o WHERE id = :id AND estado = 'verificada'",
            ['u' => $usuario->id, 'o' => $observaciones === '' ? null : $observaciones, 'id' => $carga['id']]
        );
        $this->auditoria->registrar($solicitud, 'carga_inicial.firmar', 'exito', new RecursoAuditado('cargas_iniciales', (int) $carga['id']), ['huella' => $carga['huella']]);
        $this->versiones->incrementar((int) $carga['centro_id']);
        return $this->carga($solicitud, $ulid);
    }

    public function anular(Solicitud $solicitud, string $ulid, string $motivo): void
    {
        $carga = $this->carga($solicitud, $ulid);
        $motivo = trim($motivo);
        if ($carga['estado'] === 'firmada' || $carga['estado'] === 'anulada') {
            throw new ErrorValidacion(['motivo' => 'Una carga firmada o anulada no se anula.']);
        }
        if (mb_strlen($motivo) < 10 || mb_strlen($motivo) > 500) {
            throw new ErrorValidacion(['motivo' => 'Explica el motivo en 10 a 500 caracteres.']);
        }
        $this->bd->ejecutar("UPDATE cargas_iniciales SET estado = 'anulada', anulada_motivo = :m WHERE id = :id", ['m' => $motivo, 'id' => $carga['id']]);
        $this->auditoria->registrar($solicitud, 'carga_inicial.anular', 'exito', new RecursoAuditado('cargas_iniciales', (int) $carga['id']), [], null, null, $motivo);
    }

    /**
     * Cifras de control de la carga: por fuente, archivo frente a base, en total y por unidad, más los registros que
     * hay en la base sin venir de la carga y las filas rechazadas.
     *
     * @param array<string,mixed> $carga
     * @return array{fuentes:list<array<string,mixed>>,diferencias:int,calculado_en:string}
     */
    public function calcular(array $carga): array
    {
        $registro = $this->importaciones->registro()->todas();
        $unidades = [];
        foreach ($this->bd->consultar('SELECT id, codigo FROM unidades_productivas WHERE centro_id = :c', ['c' => $carga['centro_id']]) as $u) {
            $unidades[(int) $u['id']] = (string) $u['codigo'];
        }
        $fuentes = [];
        $total = 0;
        foreach ($this->pasos($carga) as $paso) {
            $clave = (string) $paso['clave'];
            $fuente = $registro[$clave] ?? null;
            $item = ['fuente' => $clave, 'nombre' => $paso['nombre'], 'obligatoria' => $paso['obligatoria'], 'lote' => null, 'archivo' => null, 'base' => null, 'diferencias' => [], 'rechazadas' => []];
            if ($paso['pendientes'] > 0) {
                $item['diferencias'][] = "{$paso['pendientes']} lote(s) cargado(s) sin aplicar ni descartar.";
            }
            $lote = $paso['aplicado'];
            if ($lote === null || $fuente === null) {
                if ($paso['obligatoria']) {
                    $item['diferencias'][] = 'Falta cargar y aplicar esta fuente.';
                } else {
                    $item['omitida'] = true;
                }
                $total += count($item['diferencias']);
                $fuentes[] = $item;
                continue;
            }
            $cifras = json_decode((string) ($lote['cifras_control'] ?? '{}'), true) ?: [];
            $archivo = is_array($cifras['archivo'] ?? null) ? $cifras['archivo'] : ['filas' => 0, 'suma' => '0', 'por_unidad' => []];
            $base = $this->cifrasBase($fuente, (int) $lote['id'], $unidades);
            $item['lote'] = ['ulid' => $lote['ulid'], 'archivo' => $lote['nombre_archivo'], 'hash' => $lote['hash_archivo'] ?? null, 'aplicado_en' => $lote['aplicado_en']];
            $item['archivo'] = ['filas' => (int) ($archivo['filas'] ?? 0), 'suma' => Entrada::sumarCon(3, (string) ($archivo['suma'] ?? '0')), 'por_unidad' => self::normalizarUnidades($archivo['por_unidad'] ?? [])];
            $item['base'] = $base;
            if ($item['archivo']['filas'] !== $base['filas']) {
                $item['diferencias'][] = "Filas: {$item['archivo']['filas']} en el archivo y {$base['filas']} en la base.";
            }
            if ($item['archivo']['suma'] !== $base['suma']) {
                $item['diferencias'][] = "Suma: {$item['archivo']['suma']} en el archivo y {$base['suma']} en la base.";
            }
            foreach (array_unique(array_merge(array_keys($item['archivo']['por_unidad']), array_keys($base['por_unidad']))) as $unidad) {
                $a = $item['archivo']['por_unidad'][$unidad] ?? ['filas' => 0, 'suma' => '0.000'];
                $b = $base['por_unidad'][$unidad] ?? ['filas' => 0, 'suma' => '0.000'];
                if ($a !== $b && ($item['archivo']['filas'] === $base['filas'] && $item['archivo']['suma'] === $base['suma'])) {
                    $item['diferencias'][] = "Unidad {$unidad}: {$a['filas']} filas ({$a['suma']}) en el archivo y {$b['filas']} ({$b['suma']}) en la base.";
                }
            }
            if ($base['fuera_de_carga'] > 0) {
                $item['diferencias'][] = "{$base['fuera_de_carga']} registro(s) en la base que no vienen de esta carga (RDL-011).";
            }
            $item['rechazadas'] = $this->rechazadas((int) $lote['id']);
            $total += count($item['diferencias']);
            $fuentes[] = $item;
        }
        return ['fuentes' => $fuentes, 'diferencias' => $total, 'calculado_en' => gmdate('Y-m-d\TH:i:s\Z')];
    }

    /**
     * @param array<string,mixed> $carga
     * @return list<array<string,mixed>> lotes del centro creados desde el inicio de la carga, del más reciente al más antiguo
     */
    private function lotesDesde(array $carga): array
    {
        return $this->bd->consultar(
            "SELECT id, ulid, fuente, estado, nombre_archivo, hash_archivo, aplicado_en, cifras_control, filas_total, filas_error, creado_en
               FROM lotes_importacion WHERE centro_id = :c AND creado_en >= :desde AND estado <> 'fallido' ORDER BY id DESC",
            ['c' => $carga['centro_id'], 'desde' => $carga['iniciada_en']]
        );
    }

    /**
     * Cifras recalculadas ahora desde la tabla de la fuente: lo que el lote dejó y sigue vigente, más los registros del
     * centro que no vienen de él.
     *
     * @param array<int,string> $unidades
     * @return array{filas:int,suma:string,por_unidad:array<string,array{filas:int,suma:string}>,fuera_de_carga:int}
     */
    private function cifrasBase(FuenteImportacion $fuente, int $loteId, array $unidades): array
    {
        $tabla = $fuente->tabla();
        $campo = $fuente->campoSuma();
        $suma = $campo === null ? '0' : 't.' . $campo;
        $archivado = $tabla === 'registros_reproductivos' ? '' : ' AND t.archivado_en IS NULL';
        $unidad = $tabla === 'productos' ? 'NULL' : 't.unidad_id';
        $filas = $this->bd->consultar(
            "SELECT {$unidad} AS unidad_id, COUNT(*) AS filas, COALESCE(SUM({$suma}), 0) AS suma FROM {$tabla} t
              WHERE t.id IN (SELECT entidad_id FROM filas_importacion WHERE lote_id = :l AND estado = 'aplicada'){$archivado}
              GROUP BY {$unidad}",
            ['l' => $loteId]
        );
        $resultado = ['filas' => 0, 'suma' => '0.000', 'por_unidad' => [], 'fuera_de_carga' => 0];
        foreach ($filas as $f) {
            $codigo = $f['unidad_id'] === null ? 'SIN' : ($unidades[(int) $f['unidad_id']] ?? 'SIN');
            $resultado['filas'] += (int) $f['filas'];
            $resultado['suma'] = Entrada::sumarCon(3, $resultado['suma'], (string) $f['suma']);
            $resultado['por_unidad'][$codigo] ??= ['filas' => 0, 'suma' => '0.000'];
            $resultado['por_unidad'][$codigo]['filas'] += (int) $f['filas'];
            $resultado['por_unidad'][$codigo]['suma'] = Entrada::sumarCon(3, $resultado['por_unidad'][$codigo]['suma'], (string) $f['suma']);
        }
        ksort($resultado['por_unidad']);
        if (in_array($tabla, self::TABLAS_EXCLUSIVAS, true)) {
            $resultado['fuera_de_carga'] = (int) $this->bd->valor(
                "SELECT COUNT(*) FROM {$tabla} t WHERE t.centro_id = (SELECT centro_id FROM lotes_importacion WHERE id = :l1) AND t.archivado_en IS NULL
                    AND t.id NOT IN (SELECT entidad_id FROM filas_importacion WHERE lote_id = :l2 AND estado = 'aplicada' AND entidad_id IS NOT NULL)",
                ['l1' => $loteId, 'l2' => $loteId]
            );
        }
        return $resultado;
    }

    /** @return list<array{fila:int,mensajes:list<string>}> filas rechazadas del lote (sin los valores, que pueden ser personales) */
    private function rechazadas(int $loteId): array
    {
        $salida = [];
        foreach ($this->bd->consultar("SELECT fila, errores FROM filas_importacion WHERE lote_id = :l AND estado = 'error' ORDER BY fila LIMIT 50", ['l' => $loteId]) as $f) {
            $errores = json_decode((string) ($f['errores'] ?? '{}'), true) ?: [];
            $mensajes = [];
            foreach (($errores['errores'] ?? []) as $e) {
                $mensajes[] = trim(($e['columna'] !== '' ? $e['columna'] . ': ' : '') . $e['mensaje']);
            }
            $salida[] = ['fila' => (int) $f['fila'], 'mensajes' => $mensajes];
        }
        return $salida;
    }

    /**
     * @param mixed $porUnidad
     * @return array<string,array{filas:int,suma:string}>
     */
    private static function normalizarUnidades(mixed $porUnidad): array
    {
        $salida = [];
        foreach (is_array($porUnidad) ? $porUnidad : [] as $codigo => $x) {
            $salida[(string) $codigo] = ['filas' => (int) ($x['filas'] ?? 0), 'suma' => Entrada::sumarCon(3, (string) ($x['suma'] ?? '0'))];
        }
        ksort($salida);
        return $salida;
    }

    /** @param array<string,mixed> $resultado */
    private static function huella(array $resultado): string
    {
        unset($resultado['calculado_en']);
        return hash('sha256', self::json($resultado));
    }

    /** @param array<string,mixed> $datos */
    private static function json(array $datos): string
    {
        return json_encode($datos, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
