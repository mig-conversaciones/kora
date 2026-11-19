<?php

declare(strict_types=1);

namespace App\Importacion;

use App\Cola\ContextoTrabajo;
use App\Cola\DespachadorCola;
use App\Cola\ErrorDefinitivo;
use App\Nucleo\BaseDatos;
use App\Nucleo\Entorno;
use App\Nucleo\ErrorHttp;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\GeneradorUlid;
use App\Nucleo\Solicitud;
use App\Servicios\AutorizacionRecurso;
use App\Servicios\RecursoAuditado;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioVersionDatos;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PDOException;

/**
 * Importación asistida (RF-083 a RF-094):
 *   1. Carga: el libro se valida (ServicioCargaArchivos), se detectan sus fuentes y el usuario elige las que su rol puede
 *      importar. Cada fuente es un lote, identificado por el hash del archivo, la fuente y el centro (RF-089).
 *   2. Validación (trabajo importacion.validar): lectura en streaming, mapeo, reglas por fila y acción prevista (crear,
 *      actualizar, sin cambios). Nada de negocio cambia hasta aplicar (RF-087).
 *   3. Aplicación (importacion.aplicar): una transacción si hay hasta 5.000 filas, o bloques de 500. Cada fila aplicada
 *      queda marcada en la misma transacción, así que un reintento continúa sin duplicar (RF-088).
 *   4. Reversión (importacion.revertir) con motivo y reautenticación, si no hay dependencias posteriores (RF-090).
 */
final class ServicioImportaciones
{
    public const FILAS_UNA_TRANSACCION = 5000;
    public const BLOQUE = 500;
    private const VIGENCIA_TOKEN = 3600;

    public function __construct(
        private readonly BaseDatos $bd,
        private readonly RegistroFuentes $fuentes,
        private readonly ServicioCargaArchivos $carga,
        private readonly DespachadorCola $despachador,
        private readonly ServicioAuditoria $auditoria,
        private readonly ServicioVersionDatos $versiones,
    ) {
    }

    public function registro(): RegistroFuentes
    {
        return $this->fuentes;
    }

    /** ¿El usuario puede importar alguna fuente? */
    public static function puedeImportar(Solicitud $solicitud): bool
    {
        $usuario = AutorizacionRecurso::usuario($solicitud);
        foreach ((new RegistroFuentes())->todas() as $fuente) {
            if ($usuario->tienePermiso($fuente->permiso())) {
                return true;
            }
        }
        return false;
    }

    // ---------------------------------------------------------------- Carga y detección

    /**
     * Guarda el libro, detecta sus fuentes y devuelve lo necesario para elegirlas, con un token firmado del archivo.
     *
     * @param array{nombre:string,ruta:string,tamano:int,error:int}|null $archivo
     * @return array{token:string,nombre:string,detectadas:list<array<string,mixed>>}
     */
    public function cargar(Solicitud $solicitud, ?array $archivo): array
    {
        $guardado = $this->carga->guardar($archivo);
        try {
            $hojas = LectorLibro::hojas($guardado['ruta']);
        } catch (\Throwable) {
            @unlink($guardado['ruta']);
            throw new ErrorValidacion(['archivo' => 'El libro no se pudo leer; ábrelo en Excel y guárdalo de nuevo como .xlsx.']);
        }
        foreach ($hojas as $hoja) {
            if ($hoja['filas'] > ServicioCargaArchivos::FILAS_MAXIMAS + 10) {
                @unlink($guardado['ruta']);
                throw new ErrorValidacion(['archivo' => "La hoja «{$hoja['nombre']}» supera 20.000 filas; divídela en varios libros."]);
            }
        }
        $usuario = AutorizacionRecurso::usuario($solicitud);
        $detectadas = [];
        foreach ($this->fuentes->detectar($hojas) as $d) {
            $fuente = $d['fuente'];
            $previo = $this->bd->fila(
                "SELECT ulid FROM lotes_importacion WHERE centro_id = :c AND fuente = :f AND hash_archivo = :h AND estado = 'aplicado' ORDER BY id DESC LIMIT 1",
                ['c' => AutorizacionRecurso::alcance($solicitud)->centroId, 'f' => $fuente->clave(), 'h' => $guardado['hash']]
            );
            $detectadas[] = [
                'fuente' => $fuente->clave(), 'nombre' => $fuente->nombre(), 'hoja' => $d['hoja'], 'filas' => max(0, $d['filas'] - $d['fila_encabezado']),
                'permitida' => $usuario->tienePermiso($fuente->permiso()), 'opciones' => $fuente->opcionesRequeridas(),
                'vigencia_sugerida' => preg_match('/(20\d{2})/', $d['hoja'], $m) === 1 ? (int) $m[1] : null,
                'ya_aplicado' => $previo['ulid'] ?? null,
            ];
        }
        if ($detectadas === []) {
            @unlink($guardado['ruta']);
            throw new ErrorValidacion(['archivo' => 'No se reconoció ninguna fuente en el libro. Usa el libro original o una plantilla descargada de esta pantalla.']);
        }
        $carga = ['relativa' => $guardado['relativa'], 'nombre' => $guardado['nombre'], 'hash' => $guardado['hash'], 'tamano' => $guardado['tamano'], 'usuario' => $usuario->id, 't' => time()];
        $json = base64_encode(json_encode($carga, JSON_THROW_ON_ERROR));
        return ['token' => $json . '.' . self::firma($json), 'nombre' => $guardado['nombre'], 'detectadas' => $detectadas];
    }

    /**
     * Crea un lote por fuente elegida y encola su validación (RF-085). Devuelve los ULID de los lotes.
     *
     * @param array<string,mixed> $datos token, fuentes[], vigencia, fecha_corte, unidad_hato
     * @return list<string>
     */
    public function crearLotes(Solicitud $solicitud, array $datos): array
    {
        $carga = self::leerToken((string) ($datos['token'] ?? ''), AutorizacionRecurso::usuario($solicitud)->id);
        $ruta = $this->carga->rutaAbsoluta((string) $carga['relativa']);
        $elegidas = array_values(array_filter(array_map('strval', (array) ($datos['fuentes'] ?? []))));
        if ($elegidas === []) {
            throw new ErrorValidacion(['fuentes' => 'Elige al menos una fuente para importar.']);
        }
        $detectadas = [];
        foreach ($this->fuentes->detectar(LectorLibro::hojas($ruta)) as $d) {
            $detectadas[$d['fuente']->clave()] = $d;
        }
        $alcance = AutorizacionRecurso::alcance($solicitud);
        $usuario = AutorizacionRecurso::usuario($solicitud);
        $errores = [];
        $opciones = [];
        foreach ($elegidas as $clave) {
            if (!isset($detectadas[$clave])) {
                throw new ErrorValidacion(['fuentes' => 'Una de las fuentes elegidas no está en el libro.']);
            }
            $fuente = $detectadas[$clave]['fuente'];
            if (!$usuario->tienePermiso($fuente->permiso())) {
                $this->auditoria->registrar($solicitud, $fuente->permiso(), 'denegado', null, ['fuente' => $clave]);
                throw new ErrorHttp(403);
            }
            $opciones[$clave] = [];
            foreach ($fuente->opcionesRequeridas() as $opcion) {
                $opciones[$clave][$opcion] = match ($opcion) {
                    'vigencia' => \App\Nucleo\Entrada::entero($datos['vigencia_' . $clave] ?? ($datos['vigencia'] ?? ''), 2020, 2100),
                    'unidad_hato' => \App\Nucleo\Entrada::entero($datos['unidad_hato'] ?? '', 1),
                    default => null,
                };
                if ($opciones[$clave][$opcion] === null) {
                    $errores[$opcion === 'vigencia' ? 'vigencia_' . $clave : $opcion] = $opcion === 'vigencia' ? 'Escribe la vigencia (año) de la fuente.' : 'Elige la unidad del hato.';
                }
            }
            if ($clave === 'estado_reproductivo') {
                $unidad = (int) ($opciones[$clave]['unidad_hato'] ?? 0);
                if ($unidad > 0 && !$alcance->permiteUnidad($unidad)) {
                    $errores['unidad_hato'] = 'Unidad fuera de su alcance.';
                }
                $opciones[$clave]['fecha_corte'] = \App\Nucleo\Entrada::fecha($datos['fecha_corte'] ?? '');
            }
        }
        if ($errores !== []) {
            throw new ErrorValidacion($errores);
        }
        $ulids = [];
        foreach ($elegidas as $clave) {
            $d = $detectadas[$clave];
            $ulid = GeneradorUlid::generar();
            $this->bd->transaccion(function () use ($solicitud, $alcance, $usuario, $carga, $clave, $d, $opciones, $ulid): void {
                $id = $this->bd->insertar(
                    "INSERT INTO lotes_importacion (ulid, centro_id, fuente, nombre_archivo, ruta_privada, hash_archivo, tamano, estado, resumen, creado_por)
                     VALUES (:ulid, :c, :f, :n, :r, :h, :t, 'validando', :resumen, :u)",
                    [
                        'ulid' => $ulid, 'c' => $alcance->centroId, 'f' => $clave, 'n' => $carga['nombre'], 'r' => $carga['relativa'], 'h' => $carga['hash'],
                        't' => $carga['tamano'], 'u' => $usuario->id,
                        'resumen' => json_encode(['hoja' => $d['hoja'], 'fila_encabezado' => $d['fila_encabezado'], 'filas_hoja' => $d['filas'], 'opciones' => $opciones[$clave]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    ]
                );
                $trabajo = $this->despachador->encolar('importacion.validar', ['lote_id' => $id], [
                    'actor_id' => $usuario->id, 'centro_id' => $alcance->centroId, 'clave_idempotencia' => 'importacion-validar-' . $ulid,
                ]);
                $this->bd->ejecutar('UPDATE lotes_importacion SET trabajo_id = :t WHERE id = :id', ['t' => $trabajo['id'], 'id' => $id]);
                $this->auditoria->registrar($solicitud, 'importaciones.cargar', 'exito', new RecursoAuditado('lotes_importacion', $id), ['fuente' => $clave, 'archivo' => $carga['nombre'], 'hash' => $carga['hash']]);
            });
            $ulids[] = $ulid;
        }
        return $ulids;
    }

    // ---------------------------------------------------------------- Validación (trabajo importacion.validar)

    public function validar(int $loteId, ?ContextoTrabajo $trabajo = null): void
    {
        $lote = $this->loteBloqueable($loteId);
        $fuente = $this->fuentes->fuente((string) $lote['fuente']);
        $contexto = ContextoImportacion::delLote($this->bd, $lote);
        if (!$contexto->tienePermiso($fuente->permiso())) {
            $this->terminar($loteId, 'fallido', ['error' => 'El usuario ya no tiene permiso para importar esta fuente.']);
            return;
        }
        $resumen = self::resumen($lote);
        $this->bd->ejecutar('DELETE FROM filas_importacion WHERE lote_id = :l', ['l' => $loteId]);
        $ruta = $this->carga->rutaAbsoluta((string) $lote['ruta_privada']);
        $hoja = (string) $resumen['hoja'];
        $filaEncabezado = (int) ($fuente->filaEncabezado() ?? $resumen['fila_encabezado'] ?? 1);
        $total = max(1, (int) ($resumen['filas_hoja'] ?? 1));
        $mapa = null;
        $pendientes = [];
        $claves = [];
        $conteo = ['total' => 0, 'validas' => 0, 'error' => 0, 'crear' => 0, 'actualizar' => 0, 'sin_cambios' => 0, 'advertencias' => 0];
        $cifras = ['filas' => 0, 'suma' => '0', 'por_unidad' => []];
        $muestra = [];
        foreach (LectorLibro::filas($ruta, $hoja) as $numero => $celdas) {
            if ($numero < $filaEncabezado) {
                continue;
            }
            if ($numero === $filaEncabezado) {
                $resultado = RegistroFuentes::mapear($fuente, array_map(static fn (mixed $v): string => Normalizador::encabezado(is_scalar($v) ? $v : ''), $celdas));
                if ($resultado['faltantes'] !== []) {
                    $this->terminar($loteId, 'con_errores', ['error' => 'Faltan columnas obligatorias: ' . implode(', ', $resultado['faltantes']) . '.']);
                    return;
                }
                $mapa = $resultado['mapa'];
                continue;
            }
            if ($mapa === null) {
                continue;
            }
            $valores = [];
            foreach ($mapa as $campo => $indice) {
                $valores[$campo] = $celdas[$indice] ?? null;
            }
            $validacion = $fuente->validar($valores, $contexto);
            if ($validacion === null) {
                continue;
            }
            $conteo['total']++;
            if ($conteo['total'] > ServicioCargaArchivos::FILAS_MAXIMAS) {
                $this->terminar($loteId, 'con_errores', ['error' => 'La hoja supera 20.000 filas de datos.']);
                return;
            }
            $errores = $validacion['errores'];
            $clave = $validacion['clave'];
            if ($clave !== '' && isset($claves[$clave])) {
                $errores[] = ['columna' => '', 'mensaje' => "Registro repetido en el archivo (igual que la fila {$claves[$clave]})."];
            }
            if ($clave !== '') {
                $claves[$clave] ??= $numero;
            }
            self::sumarCifras($cifras, $fuente, $validacion['datos'], $contexto);
            $originales = [];
            foreach ($valores as $campo => $valor) {
                if (!in_array($campo, $fuente->camposSensibles(), true)) {
                    $originales[$campo] = $valor instanceof \DateTimeInterface ? $valor->format('Y-m-d') : (is_scalar($valor) ? (string) $valor : null);
                }
            }
            if ($errores !== []) {
                $conteo['error']++;
                $pendientes[] = [$numero, $clave, ['originales' => $originales], 'error', null, ['errores' => $errores, 'advertencias' => $validacion['advertencias']], null];
            } else {
                $actual = $fuente->actual($contexto, $validacion['datos']);
                $accion = $fuente->accion($validacion['datos'], $actual);
                $conteo['validas']++;
                $conteo[$accion]++;
                $conteo['advertencias'] += count($validacion['advertencias']) > 0 ? 1 : 0;
                $pendientes[] = [$numero, $clave, ['datos' => $validacion['datos'], 'originales' => $originales], 'valida', $accion, $validacion['advertencias'] === [] ? null : ['errores' => [], 'advertencias' => $validacion['advertencias']], $actual === null ? null : (int) $actual['id']];
                if ($accion === 'actualizar' && count($muestra) < 50 && $actual !== null) {
                    $diferencias = [];
                    foreach ($fuente->camposComparables() as $campo) {
                        if (FuenteImportacion::comparable($validacion['datos'][$campo] ?? null) !== FuenteImportacion::comparable($actual[$campo] ?? null)) {
                            $diferencias[$campo] = ['actual' => $actual[$campo] ?? null, 'nuevo' => $validacion['datos'][$campo] ?? null];
                        }
                    }
                    $muestra[] = ['fila' => $numero, 'clave' => $clave, 'diferencias' => $diferencias];
                }
            }
            if (count($pendientes) >= 200) {
                $this->guardarFilas($loteId, $hoja, $pendientes);
                $pendientes = [];
                $trabajo?->latido(min(95, (int) ($numero / $total * 100)), "Validadas {$conteo['total']} filas.");
            }
        }
        $this->guardarFilas($loteId, $hoja, $pendientes);
        if ($mapa === null) {
            $this->terminar($loteId, 'con_errores', ['error' => 'No se encontró la fila de encabezados.']);
            return;
        }
        $resumen['conteo'] = $conteo;
        $resumen['muestra'] = $muestra;
        $this->bd->ejecutar(
            'UPDATE lotes_importacion SET estado = :e, filas_total = :t, filas_validas = :v, filas_error = :er, filas_nuevas = :n, filas_actualizadas = :a, filas_sin_cambios = :s,
                    resumen = :r, cifras_control = :cifras WHERE id = :id',
            [
                'e' => $conteo['error'] > 0 ? 'con_errores' : 'validado', 't' => $conteo['total'], 'v' => $conteo['validas'], 'er' => $conteo['error'],
                'n' => $conteo['crear'], 'a' => $conteo['actualizar'], 's' => $conteo['sin_cambios'],
                'r' => json_encode($resumen, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'cifras' => json_encode(['archivo' => $cifras], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'id' => $loteId,
            ]
        );
        $this->notificar($lote, 'Importación validada', "{$fuente->nombre()}: {$conteo['validas']} filas válidas y {$conteo['error']} con error. Revisa la vista previa antes de aplicar.");
    }

    // ---------------------------------------------------------------- Aplicación (trabajo importacion.aplicar)

    /** Encola la aplicación tras la confirmación del usuario (RF-087). */
    public function solicitarAplicacion(Solicitud $solicitud, string $ulid, bool $soloValidas): void
    {
        $lote = $this->lote($solicitud, $ulid);
        $fuente = $this->fuentes->fuente((string) $lote['fuente']);
        $this->exigirPermiso($solicitud, $fuente);
        if (!in_array($lote['estado'], ['validado', 'con_errores'], true)) {
            throw new ErrorHttp(409, 'El lote no está listo para aplicarse.');
        }
        if ((int) $lote['filas_validas'] === 0) {
            throw new ErrorHttp(409, 'El lote no tiene filas válidas para aplicar.');
        }
        if ($lote['estado'] === 'con_errores' && (!$soloValidas || !$fuente->permiteParcial())) {
            throw new ErrorValidacion(['solo_validas' => 'El lote tiene filas con error: confirma que aplicas solo las válidas o corrige el archivo y vuelve a cargarlo.']);
        }
        $previo = $this->bd->valor(
            "SELECT ulid FROM lotes_importacion WHERE centro_id = :c AND fuente = :f AND hash_archivo = :h AND estado = 'aplicado' AND id <> :id LIMIT 1",
            ['c' => $lote['centro_id'], 'f' => $lote['fuente'], 'h' => $lote['hash_archivo'], 'id' => $lote['id']]
        );
        if ($previo !== null) {
            throw new ErrorHttp(409, 'Este mismo archivo ya se aplicó para esta fuente (lote ' . $previo . ').');
        }
        $filas = $this->bd->ejecutar("UPDATE lotes_importacion SET estado = 'aplicando' WHERE id = :id AND estado IN ('validado', 'con_errores')", ['id' => $lote['id']]);
        if ($filas === 0) {
            throw new ErrorHttp(409, 'El lote cambió; recarga la página.');
        }
        $usuario = AutorizacionRecurso::usuario($solicitud);
        $trabajo = $this->despachador->encolar('importacion.aplicar', ['lote_id' => (int) $lote['id']], [
            'actor_id' => $usuario->id, 'centro_id' => (int) $lote['centro_id'], 'clave_idempotencia' => 'importacion-aplicar-' . $ulid,
        ]);
        $this->bd->ejecutar('UPDATE lotes_importacion SET trabajo_id = :t, aplicado_por = :u WHERE id = :id', ['t' => $trabajo['id'], 'u' => $usuario->id, 'id' => $lote['id']]);
        $this->auditoria->registrar($solicitud, 'importaciones.aplicar', 'exito', new RecursoAuditado('lotes_importacion', (int) $lote['id']), ['fuente' => $lote['fuente'], 'solo_validas' => $soloValidas]);
    }

    public function aplicar(int $loteId, ?ContextoTrabajo $trabajo = null): void
    {
        $lote = $this->loteBloqueable($loteId);
        if ($lote['estado'] !== 'aplicando') {
            return;
        }
        $fuente = $this->fuentes->fuente((string) $lote['fuente']);
        $contexto = ContextoImportacion::delLote($this->bd, $lote);
        if (!$contexto->tienePermiso($fuente->permiso())) {
            $this->terminar($loteId, 'fallido', ['error' => 'El usuario ya no tiene permiso para importar esta fuente.']);
            return;
        }
        $pendientes = (int) $this->bd->valor("SELECT COUNT(*) FROM filas_importacion WHERE lote_id = :l AND estado = 'valida'", ['l' => $loteId]);
        $bloque = $pendientes <= self::FILAS_UNA_TRANSACCION ? max(1, $pendientes) : self::BLOQUE;
        $hechas = 0;
        do {
            $filas = $this->bd->consultar("SELECT * FROM filas_importacion WHERE lote_id = :l AND estado = 'valida' ORDER BY fila LIMIT {$bloque}", ['l' => $loteId]);
            if ($filas === []) {
                break;
            }
            $this->bd->transaccion(function (BaseDatos $bd) use ($filas, $fuente, $contexto, $lote): void {
                foreach ($filas as $fila) {
                    $this->aplicarFila($bd, $fila, $fuente, $contexto, $lote);
                }
            });
            $hechas += count($filas);
            $trabajo?->latido(min(95, (int) ($hechas / max(1, $pendientes) * 100)), "Aplicadas {$hechas} de {$pendientes} filas.");
        } while (count($filas) === $bloque);

        $conteo = [];
        foreach ($this->bd->consultar("SELECT accion, COUNT(*) AS n FROM filas_importacion WHERE lote_id = :l AND estado = 'aplicada' GROUP BY accion", ['l' => $loteId]) as $c) {
            $conteo[(string) $c['accion']] = (int) $c['n'];
        }
        $cifras = json_decode((string) ($lote['cifras_control'] ?? '{}'), true) ?: [];
        $cifras['aplicado'] = $this->cifrasAplicadas($fuente, $loteId);
        $cifras['verificada'] = ($cifras['archivo']['filas'] ?? -1) === $cifras['aplicado']['filas']
            && FuenteImportacion::comparable($cifras['archivo']['suma'] ?? '0') === FuenteImportacion::comparable($cifras['aplicado']['suma']);
        $this->bd->ejecutar(
            "UPDATE lotes_importacion SET estado = 'aplicado', aplicado_en = UTC_TIMESTAMP(3), filas_nuevas = :n, filas_actualizadas = :a, filas_sin_cambios = :s,
                    cifras_control = :c, respaldo_previo = :r WHERE id = :id",
            [
                'n' => $conteo['crear'] ?? 0, 'a' => $conteo['actualizar'] ?? 0, 's' => $conteo['sin_cambios'] ?? 0,
                'c' => json_encode($cifras, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'r' => 'valores-anteriores-por-fila', 'id' => $loteId,
            ]
        );
        $this->versiones->incrementar((int) $lote['centro_id']);
        $this->despachador->encolar('calidad.recalcular', [], ['actor_id' => (int) $lote['creado_por'], 'centro_id' => (int) $lote['centro_id'], 'clave_idempotencia' => 'calidad-tras-' . $lote['ulid']]);
        $this->notificar($lote, 'Importación aplicada', "{$fuente->nombre()}: " . ($conteo['crear'] ?? 0) . ' nuevos, ' . ($conteo['actualizar'] ?? 0) . ' actualizados y ' . ($conteo['sin_cambios'] ?? 0) . ' sin cambios.' . ($cifras['verificada'] ? '' : ' Las cifras de control no coinciden con el archivo.'));
    }

    /**
     * @param array<string,mixed> $fila
     * @param array<string,mixed> $lote
     */
    private function aplicarFila(BaseDatos $bd, array $fila, FuenteImportacion $fuente, ContextoImportacion $contexto, array $lote): void
    {
        $contenido = json_decode((string) $fila['datos'], true);
        $datos = is_array($contenido['datos'] ?? null) ? $contenido['datos'] : [];
        $actual = $fuente->actual($contexto, $datos);
        $accion = $fuente->accion($datos, $actual);
        $anteriores = null;
        try {
            if ($accion === 'sin_cambios' && $actual !== null) {
                $id = (int) $actual['id'];
            } else {
                if ($actual !== null) {
                    $anteriores = array_intersect_key($actual, array_flip($fuente->camposComparables()));
                }
                $id = $fuente->aplicar($contexto, $datos, $actual, [
                    'origen_tipo' => 'importacion', 'lote_importacion_id' => (int) $lote['id'], 'origen_archivo' => mb_substr((string) $lote['nombre_archivo'], 0, 255),
                    'origen_hoja' => mb_substr((string) $fila['hoja'], 0, 80), 'origen_fila' => (int) $fila['fila'],
                ]);
            }
        } catch (PDOException $error) {
            if (($error->errorInfo[0] ?? '') !== '23000') {
                throw $error;
            }
            $bd->ejecutar(
                "UPDATE filas_importacion SET estado = 'error', errores = :e WHERE id = :id",
                ['e' => json_encode(['errores' => [['columna' => '', 'mensaje' => 'No se pudo aplicar: entra en conflicto con un registro existente.']], 'advertencias' => []], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'id' => $fila['id']]
            );
            return;
        }
        $bd->ejecutar(
            "UPDATE filas_importacion SET estado = 'aplicada', accion = :a, entidad = :t, entidad_id = :e, valores_anteriores = :v WHERE id = :id",
            ['a' => $accion, 't' => $fuente->tabla(), 'e' => $id, 'v' => $anteriores === null ? null : json_encode($anteriores, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'id' => $fila['id']]
        );
    }

    // ---------------------------------------------------------------- Reversión (RF-090)

    /**
     * @param array<string,mixed> $lote
     * @return list<string> registros que impiden revertir (vacío si procede)
     */
    public function bloqueosReversion(array $lote): array
    {
        $fuente = $this->fuentes->fuente((string) $lote['fuente']);
        $bloqueos = [];
        foreach ($this->bd->consultar("SELECT entidad_id FROM filas_importacion WHERE lote_id = :l AND estado = 'aplicada' AND accion = 'crear'", ['l' => $lote['id']]) as $f) {
            $motivo = $fuente->dependencias($this->bd, (int) $f['entidad_id'], (int) $lote['id']);
            if ($motivo !== null) {
                $bloqueos[] = $motivo;
                if (count($bloqueos) >= 20) {
                    break;
                }
            }
        }
        return $bloqueos;
    }

    public function solicitarReversion(Solicitud $solicitud, string $ulid, string $motivo): void
    {
        $lote = $this->lote($solicitud, $ulid);
        $this->exigirPermiso($solicitud, $this->fuentes->fuente((string) $lote['fuente']));
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 10 || mb_strlen($motivo) > 500) {
            throw new ErrorValidacion(['motivo' => 'Explica el motivo de la reversión (entre 10 y 500 caracteres).']);
        }
        if ($lote['estado'] !== 'aplicado') {
            throw new ErrorHttp(409, 'Solo se revierte un lote aplicado.');
        }
        $bloqueos = $this->bloqueosReversion($lote);
        if ($bloqueos !== []) {
            throw new ErrorValidacion(['motivo' => 'No se puede revertir: ' . implode(' ', array_slice($bloqueos, 0, 5))]);
        }
        $this->bd->ejecutar("UPDATE lotes_importacion SET estado = 'revirtiendo', motivo_reversion = :m, revertido_por = :u WHERE id = :id", ['m' => $motivo, 'u' => AutorizacionRecurso::usuario($solicitud)->id, 'id' => $lote['id']]);
        $this->despachador->encolar('importacion.revertir', ['lote_id' => (int) $lote['id']], [
            'actor_id' => AutorizacionRecurso::usuario($solicitud)->id, 'centro_id' => (int) $lote['centro_id'], 'clave_idempotencia' => 'importacion-revertir-' . $ulid,
        ]);
        $this->auditoria->registrar($solicitud, 'importaciones.revertir', 'exito', new RecursoAuditado('lotes_importacion', (int) $lote['id']), ['fuente' => $lote['fuente']], ['estado' => 'aplicado'], ['estado' => 'revirtiendo'], $motivo);
    }

    public function revertir(int $loteId): void
    {
        $lote = $this->loteBloqueable($loteId);
        if ($lote['estado'] !== 'revirtiendo') {
            return;
        }
        $fuente = $this->fuentes->fuente((string) $lote['fuente']);
        if ($this->bloqueosReversion($lote) !== []) {
            $this->bd->ejecutar("UPDATE lotes_importacion SET estado = 'aplicado' WHERE id = :id", ['id' => $loteId]);
            throw new ErrorDefinitivo('Aparecieron dependencias después de solicitar la reversión.');
        }
        $usuario = (int) ($lote['revertido_por'] ?? $lote['creado_por']);
        $this->bd->transaccion(function (BaseDatos $bd) use ($loteId, $fuente, $usuario, $lote): void {
            foreach ($bd->consultar("SELECT * FROM filas_importacion WHERE lote_id = :l AND estado = 'aplicada' AND accion IN ('crear', 'actualizar') ORDER BY fila DESC", ['l' => $loteId]) as $fila) {
                if ($fila['accion'] === 'crear') {
                    $fuente->revertirCreado($bd, (int) $fila['entidad_id'], $usuario, (string) $lote['motivo_reversion']);
                } else {
                    $anteriores = json_decode((string) ($fila['valores_anteriores'] ?? '[]'), true);
                    $fuente->restaurar($bd, (int) $fila['entidad_id'], is_array($anteriores) ? $anteriores : [], $usuario);
                }
            }
            $bd->ejecutar("UPDATE lotes_importacion SET estado = 'revertido', revertido_en = UTC_TIMESTAMP(3) WHERE id = :id", ['id' => $loteId]);
        });
        $this->versiones->incrementar((int) $lote['centro_id']);
        $this->despachador->encolar('calidad.recalcular', [], ['actor_id' => $usuario, 'centro_id' => (int) $lote['centro_id'], 'clave_idempotencia' => 'calidad-tras-reversion-' . $lote['ulid']]);
        $this->notificar($lote, 'Importación revertida', "{$fuente->nombre()}: los registros del lote se archivaron y los actualizados recuperaron sus valores anteriores.");
    }

    // ---------------------------------------------------------------- Consulta

    /**
     * Historial dentro del alcance (RF-093): las fuentes que el rol puede importar; un rol de unidad solo ve sus lotes.
     *
     * @return list<array<string,mixed>>
     */
    public function historial(Solicitud $solicitud): array
    {
        $usuario = AutorizacionRecurso::usuario($solicitud);
        $alcance = AutorizacionRecurso::alcance($solicitud);
        $permitidas = [];
        foreach ($this->fuentes->todas() as $fuente) {
            if ($usuario->tienePermiso($fuente->permiso())) {
                $permitidas[] = $fuente->clave();
            }
        }
        if ($permitidas === []) {
            return [];
        }
        $filas = $this->bd->consultar(
            'SELECT l.*, u.nombre AS usuario_nombre FROM lotes_importacion l JOIN usuarios u ON u.id = l.creado_por
              WHERE l.centro_id = :c ORDER BY l.id DESC LIMIT 100',
            ['c' => $alcance->centroId]
        );
        return array_values(array_filter($filas, static fn (array $l): bool => in_array($l['fuente'], $permitidas, true) && ($alcance->todoElCentro || (int) $l['creado_por'] === $usuario->id)));
    }

    /** @return array<string,mixed> lote dentro del alcance; 404 si no existe o es ajeno */
    public function lote(Solicitud $solicitud, string $ulid): array
    {
        foreach ($this->historialCompleto($solicitud, $ulid) as $lote) {
            return $lote;
        }
        throw new ErrorHttp(404);
    }

    /** @return list<array<string,mixed>> filas con error o advertencia para la vista previa */
    public function filasConObservaciones(int $loteId, int $limite = 100): array
    {
        return $this->bd->consultar(
            "SELECT fila, hoja, estado, errores, datos FROM filas_importacion WHERE lote_id = :l AND (estado = 'error' OR errores IS NOT NULL) ORDER BY estado = 'error' DESC, fila LIMIT " . max(1, $limite),
            ['l' => $loteId]
        );
    }

    /**
     * Reporte XLSX de las filas rechazadas (RF-091), una fila por fila con error.
     *
     * @param array<string,mixed> $lote
     */
    public function reporteErrores(array $lote, string $destino): void
    {
        $fuente = $this->fuentes->fuente((string) $lote['fuente']);
        $campos = array_values(array_diff(array_keys($fuente->columnas()), $fuente->camposSensibles()));
        $escritor = new Writer();
        $escritor->openToFile($destino);
        $escritor->addRow(Row::fromValues(array_merge(['Hoja', 'Fila', 'Columna', 'Mensaje'], $campos)));
        $desde = 0;
        do {
            $filas = $this->bd->consultar("SELECT id, hoja, fila, datos, errores FROM filas_importacion WHERE lote_id = :l AND estado = 'error' AND id > :d ORDER BY id LIMIT 500", ['l' => $lote['id'], 'd' => $desde]);
            foreach ($filas as $f) {
                $desde = (int) $f['id'];
                $errores = json_decode((string) $f['errores'], true)['errores'] ?? [];
                $originales = json_decode((string) $f['datos'], true)['originales'] ?? [];
                $escritor->addRow(Row::fromValues(array_merge(
                    [(string) $f['hoja'], (int) $f['fila'], implode(', ', array_unique(array_filter(array_column($errores, 'columna')))), implode(' ', array_column($errores, 'mensaje'))],
                    array_map(static fn (string $c): string => (string) ($originales[$c] ?? ''), $campos)
                )));
            }
        } while (count($filas) === 500);
        $escritor->close();
    }

    /** Plantilla descargable de una fuente (RF-083): la hoja con sus encabezados. */
    public function plantilla(string $clave, string $destino): void
    {
        $fuente = $this->fuentes->fuente($clave);
        $escritor = new Writer();
        $escritor->openToFile($destino);
        $escritor->getCurrentSheet()->setName(RegistroFuentes::HOJAS_PLANTILLA[$clave]);
        $escritor->addRow(Row::fromValues(array_map(static fn (array $d): string => ucfirst($d['alias'][0]), array_values($fuente->columnas()))));
        $escritor->close();
    }

    /** @param array<string,mixed> $lote */
    public function rutaOriginal(array $lote): string
    {
        return $this->carga->rutaAbsoluta((string) $lote['ruta_privada']);
    }

    /** @return array{estado:string,progreso:int,mensaje:?string}|null progreso del trabajo de la cola del lote */
    public function progreso(int $trabajoId): ?array
    {
        $fila = $this->bd->fila('SELECT estado, progreso, mensaje_progreso FROM trabajos WHERE id = :id', ['id' => $trabajoId]);
        return $fila === null ? null : ['estado' => (string) $fila['estado'], 'progreso' => (int) $fila['progreso'], 'mensaje' => $fila['mensaje_progreso'] === null ? null : (string) $fila['mensaje_progreso']];
    }

    // ---------------------------------------------------------------- Internas

    /** @return list<array<string,mixed>> */
    private function historialCompleto(Solicitud $solicitud, string $ulid): array
    {
        return array_values(array_filter($this->historial($solicitud), static fn (array $l): bool => $l['ulid'] === $ulid));
    }

    private function exigirPermiso(Solicitud $solicitud, FuenteImportacion $fuente): void
    {
        if (!AutorizacionRecurso::usuario($solicitud)->tienePermiso($fuente->permiso())) {
            $this->auditoria->registrar($solicitud, $fuente->permiso(), 'denegado', null, ['fuente' => $fuente->clave()]);
            throw new ErrorHttp(403);
        }
    }

    /** @return array<string,mixed> */
    private function loteBloqueable(int $loteId): array
    {
        $lote = $this->bd->fila('SELECT * FROM lotes_importacion WHERE id = :id', ['id' => $loteId]);
        if ($lote === null) {
            throw new ErrorDefinitivo("El lote {$loteId} no existe.");
        }
        return $lote;
    }

    /**
     * @param array<string,mixed> $lote
     * @return array<string,mixed>
     */
    private static function resumen(array $lote): array
    {
        $resumen = json_decode((string) ($lote['resumen'] ?? '{}'), true);
        return is_array($resumen) ? $resumen : [];
    }

    /** @param array<string,mixed> $extra */
    private function terminar(int $loteId, string $estado, array $extra): void
    {
        $lote = $this->loteBloqueable($loteId);
        $this->bd->ejecutar('UPDATE lotes_importacion SET estado = :e, resumen = :r WHERE id = :id', [
            'e' => $estado, 'r' => json_encode($extra + self::resumen($lote), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'id' => $loteId,
        ]);
        $this->notificar($lote, 'Importación con problemas', (string) ($extra['error'] ?? 'Revisa el lote.'));
    }

    /** @param list<array{0:int,1:string,2:array<string,mixed>,3:string,4:?string,5:?array<string,mixed>,6:?int}> $filas */
    private function guardarFilas(int $loteId, string $hoja, array $filas): void
    {
        if ($filas === []) {
            return;
        }
        $valores = [];
        $parametros = [];
        foreach ($filas as $i => [$numero, $clave, $datos, $estado, $accion, $errores, $entidad]) {
            $valores[] = "(:l{$i}, :h{$i}, :f{$i}, :k{$i}, :d{$i}, :e{$i}, :a{$i}, :er{$i}, :en{$i})";
            $parametros += [
                "l{$i}" => $loteId, "h{$i}" => mb_substr($hoja, 0, 80), "f{$i}" => $numero, "k{$i}" => $clave === '' ? null : mb_substr($clave, 0, 255),
                "d{$i}" => json_encode($datos, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), "e{$i}" => $estado, "a{$i}" => $accion,
                "er{$i}" => $errores === null ? null : json_encode($errores, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), "en{$i}" => $entidad,
            ];
        }
        $this->bd->ejecutar('INSERT INTO filas_importacion (lote_id, hoja, fila, clave_natural, datos, estado, accion, errores, entidad_id) VALUES ' . implode(', ', $valores), $parametros);
    }

    /**
     * Cifras de control del archivo (RF-094): filas de datos, suma del campo de la fuente y desglose por unidad.
     *
     * @param array{filas:int,suma:string,por_unidad:array<string,array{filas:int,suma:string}>} $cifras
     * @param array<string,mixed> $datos
     */
    private static function sumarCifras(array &$cifras, FuenteImportacion $fuente, array $datos, ContextoImportacion $contexto): void
    {
        $cifras['filas']++;
        $campo = $fuente->campoSuma();
        $valor = $campo === null ? '0' : (string) ($datos[$campo] ?? '0');
        $valor = $valor === '' ? '0' : $valor;
        $cifras['suma'] = \App\Nucleo\Entrada::sumarCon(3, $cifras['suma'], $valor);
        $unidad = isset($datos['unidad_id']) ? ($contexto->codigoUnidad((int) $datos['unidad_id']) ?? 'SIN') : 'SIN';
        $cifras['por_unidad'][$unidad] ??= ['filas' => 0, 'suma' => '0'];
        $cifras['por_unidad'][$unidad]['filas']++;
        $cifras['por_unidad'][$unidad]['suma'] = \App\Nucleo\Entrada::sumarCon(3, $cifras['por_unidad'][$unidad]['suma'], $valor);
    }

    /** @return array{filas:int,suma:string} cifras de los registros que el lote dejó en la base */
    private function cifrasAplicadas(FuenteImportacion $fuente, int $loteId): array
    {
        $campo = $fuente->campoSuma();
        $archivado = $fuente->tabla() === 'registros_reproductivos' ? '' : ' AND t.archivado_en IS NULL';
        $fila = $this->bd->fila(
            'SELECT COUNT(*) AS filas, COALESCE(SUM(' . ($campo === null ? '0' : 't.' . $campo) . "), 0) AS suma FROM {$fuente->tabla()} t
              WHERE t.id IN (SELECT entidad_id FROM filas_importacion WHERE lote_id = :l AND estado = 'aplicada'){$archivado}",
            ['l' => $loteId]
        ) ?? [];
        return ['filas' => (int) ($fila['filas'] ?? 0), 'suma' => \App\Nucleo\Entrada::sumarCon(3, (string) ($fila['suma'] ?? '0'))];
    }

    /** @param array<string,mixed> $lote */
    private function notificar(array $lote, string $titulo, string $mensaje): void
    {
        $this->bd->ejecutar(
            "INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, enlace) VALUES (:u, 'importacion', :t, :m, :e)",
            ['u' => $lote['creado_por'], 't' => $titulo, 'm' => mb_substr($mensaje, 0, 500), 'e' => '/importaciones/' . $lote['ulid']]
        );
    }

    private static function firma(string $datos): string
    {
        $clave = base64_decode(Entorno::texto('CLAVE_HMAC_PII', ''), true) ?: 'kora-sin-clave';
        return hash_hmac('sha256', 'importacion|' . $datos, $clave);
    }

    /** @return array<string,mixed> */
    private static function leerToken(string $token, int $usuario): array
    {
        [$json, $firma] = array_pad(explode('.', $token, 2), 2, '');
        if ($json === '' || !hash_equals(self::firma($json), $firma)) {
            throw new ErrorValidacion(['fuentes' => 'La carga no es válida o venció; vuelve a subir el libro.']);
        }
        $carga = json_decode((string) base64_decode($json, true), true);
        if (!is_array($carga) || (int) ($carga['usuario'] ?? 0) !== $usuario || time() - (int) ($carga['t'] ?? 0) > self::VIGENCIA_TOKEN) {
            throw new ErrorValidacion(['fuentes' => 'La carga venció; vuelve a subir el libro.']);
        }
        return $carga;
    }
}
