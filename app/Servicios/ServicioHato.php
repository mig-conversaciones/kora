<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\Entrada;
use App\Nucleo\ErrorHttp;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\Solicitud;
use App\Politicas\RecursoUnidad;
use App\Repositorios\RepositorioAnimales;
use PDOException;

/**
 * Hato por cortes fechados (RF-052 a RF-057).
 *   - Cada corte es una fotografía completa del estado reproductivo. El vigente es el último; los tableros muestran
 *     su fecha y filtrar por fechas no simula historia (RF-053).
 *   - El IEP y los días abiertos se calculan en el servidor (RF-054). Si llegan importados y difieren más de un día,
 *     se abre una incidencia de calidad sin bloquear (lo hace ServicioCalidad).
 *   - El líder de la unidad pecuaria gestiona todos los datos (hato.gestionar). La Coordinación académica solo cambia
 *     el lote de práctica y sus observaciones, con motivo (hato.gestionar_practica, RF-057).
 */
final class ServicioHato
{
    public const LOTES = ['PRODUCCION' => 'Producción', 'HORRA' => 'Horra', 'PRACTICA' => 'Práctica académica'];

    public function __construct(
        private readonly BaseDatos $bd,
        private readonly RepositorioAnimales $animales,
        private readonly AutorizacionRecurso $autorizacion,
        private readonly ServicioAuditoria $auditoria,
        private readonly ServicioVersionDatos $versiones,
    ) {
    }

    /**
     * Unidades pecuarias del alcance (las que tienen hato).
     *
     * @return list<array<string,mixed>>
     */
    public function unidadesPecuarias(Solicitud $solicitud): array
    {
        $alcance = AutorizacionRecurso::alcance($solicitud);
        $filas = $this->bd->consultar("SELECT id, codigo, nombre FROM unidades_productivas WHERE centro_id = :c AND tipo = 'pecuaria' AND activa = 1 ORDER BY codigo = 'GAN' DESC, nombre", ['c' => $alcance->centroId]);
        return array_values(array_filter($filas, static fn (array $u): bool => $alcance->permiteUnidad((int) $u['id'])));
    }

    /** @return list<array<string,mixed>> cortes de la unidad, del más reciente al más antiguo */
    public function cortes(int $centroId, int $unidadId): array
    {
        return $this->bd->consultar(
            'SELECT c.id, c.fecha_corte, c.vigente, (SELECT COUNT(*) FROM registros_reproductivos r WHERE r.corte_id = c.id) AS registros
               FROM cortes_hato c WHERE c.centro_id = :c AND c.unidad_id = :u ORDER BY c.fecha_corte DESC',
            ['c' => $centroId, 'u' => $unidadId]
        );
    }

    /** @return array<string,mixed>|null el corte pedido o, si no se pide, el vigente */
    public function corte(int $centroId, int $unidadId, ?int $corteId = null): ?array
    {
        return $corteId === null
            ? $this->bd->fila('SELECT * FROM cortes_hato WHERE centro_id = :c AND unidad_id = :u AND vigente = 1', ['c' => $centroId, 'u' => $unidadId])
            : $this->bd->fila('SELECT * FROM cortes_hato WHERE centro_id = :c AND unidad_id = :u AND id = :id', ['c' => $centroId, 'u' => $unidadId, 'id' => $corteId]);
    }

    /**
     * Inventario del corte con semáforos y totales por lote (RF-052, RF-056).
     *
     * @return array{animales:list<array<string,mixed>>,totales:array<string,int>}
     */
    public function inventario(Solicitud $solicitud, int $unidadId, int $corteId): array
    {
        $alcance = AutorizacionRecurso::alcance($solicitud);
        $this->autorizacion->exigir($solicitud, 'hato.consultar', new RecursoUnidad($alcance->centroId, $unidadId));
        $filas = $this->bd->consultar(
            'SELECT a.id, a.identificador, a.fecha_nacimiento, a.version AS version_animal, r.id AS registro_id, r.version, r.lote_hato,
                    r.penultimo_parto, r.ultimo_parto, r.iep_dias, r.servicios_posparto, r.servicio_efectivo, r.ultima_palpacion,
                    r.estado_reproductivo, r.edad_gestacion, r.dias_abiertos, r.observaciones_academicas
               FROM registros_reproductivos r JOIN animales a ON a.id = r.animal_id
              WHERE r.corte_id = :corte AND r.centro_id = :c AND r.unidad_id = :u AND a.archivado_en IS NULL
              ORDER BY r.lote_hato, CAST(a.identificador AS UNSIGNED), a.identificador',
            ['corte' => $corteId, 'c' => $alcance->centroId, 'u' => $unidadId]
        );
        $totales = array_fill_keys(array_keys(self::LOTES), 0);
        foreach ($filas as &$fila) {
            $fila['semaforo_iep'] = ReglasHato::semaforoIep($fila['iep_dias'] === null ? null : (int) $fila['iep_dias']);
            $fila['semaforo_abiertos'] = ReglasHato::semaforoDiasAbiertos($fila['dias_abiertos'] === null ? null : (int) $fila['dias_abiertos']);
            $totales[(string) $fila['lote_hato']]++;
        }
        return ['animales' => $filas, 'totales' => $totales];
    }

    /** @return array<string,mixed> animal y su registro en el corte vigente */
    public function animal(Solicitud $solicitud, int $id): array
    {
        $animal = $this->animales->obtener(AutorizacionRecurso::alcance($solicitud), $id);
        if ($animal['archivado_en'] !== null) {
            throw new ErrorHttp(404);
        }
        $corte = $this->corte((int) $animal['centro_id'], (int) $animal['unidad_id']);
        $registro = $corte === null ? null : $this->bd->fila('SELECT * FROM registros_reproductivos WHERE corte_id = :c AND animal_id = :a', ['c' => $corte['id'], 'a' => $id]);
        return ['animal' => $animal, 'registro' => $registro, 'corte' => $corte];
    }

    /**
     * Nuevo corte: copia el estado del corte vigente (recalculando los días abiertos con la nueva fecha) y pasa a ser
     * el vigente (RF-053).
     */
    public function crearCorte(Solicitud $solicitud, int $unidadId, string $fecha): int
    {
        $alcance = AutorizacionRecurso::alcance($solicitud);
        $this->autorizacion->exigir($solicitud, 'hato.gestionar', new RecursoUnidad($alcance->centroId, $unidadId));
        $vigente = $this->corte($alcance->centroId, $unidadId);
        if ($vigente !== null && $fecha <= (string) $vigente['fecha_corte']) {
            throw new ErrorValidacion(['fecha_corte' => 'El nuevo corte debe ser posterior al vigente (' . $vigente['fecha_corte'] . ').']);
        }
        if ($fecha > (new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota')))->format('Y-m-d')) {
            throw new ErrorValidacion(['fecha_corte' => 'La fecha del corte no puede ser futura.']);
        }
        $usuario = AutorizacionRecurso::usuario($solicitud);
        try {
            return $this->bd->transaccion(function (BaseDatos $bd) use ($solicitud, $alcance, $unidadId, $fecha, $vigente, $usuario): int {
                $bd->ejecutar('UPDATE cortes_hato SET vigente = 0 WHERE centro_id = :c AND unidad_id = :u', ['c' => $alcance->centroId, 'u' => $unidadId]);
                $corte = $bd->insertar(
                    'INSERT INTO cortes_hato (centro_id, unidad_id, fecha_corte, vigente, creado_por) VALUES (:c, :u, :f, 1, :p)',
                    ['c' => $alcance->centroId, 'u' => $unidadId, 'f' => $fecha, 'p' => $usuario->id]
                );
                if ($vigente !== null) {
                    foreach ($bd->consultar('SELECT * FROM registros_reproductivos WHERE corte_id = :c', ['c' => $vigente['id']]) as $r) {
                        $bd->insertar(
                            'INSERT INTO registros_reproductivos (centro_id, unidad_id, corte_id, animal_id, lote_hato, penultimo_parto, ultimo_parto, iep_dias,
                                    servicios_posparto, servicio_efectivo, ultima_palpacion, estado_reproductivo, edad_gestacion, dias_abiertos,
                                    observaciones_academicas, creado_por, actualizado_por)
                             VALUES (:c, :u, :corte, :a, :lote, :pp, :up, :iep, :sp, :se, :pal, :est, :edad, :abiertos, :obs, :p1, :p2)',
                            [
                                'c' => $r['centro_id'], 'u' => $r['unidad_id'], 'corte' => $corte, 'a' => $r['animal_id'], 'lote' => $r['lote_hato'],
                                'pp' => $r['penultimo_parto'], 'up' => $r['ultimo_parto'], 'iep' => $r['iep_dias'], 'sp' => $r['servicios_posparto'],
                                'se' => $r['servicio_efectivo'], 'pal' => $r['ultima_palpacion'], 'est' => $r['estado_reproductivo'], 'edad' => $r['edad_gestacion'],
                                'abiertos' => ReglasHato::diasAbiertos($r['ultimo_parto'] === null ? null : (string) $r['ultimo_parto'], $r['servicio_efectivo'] === null ? null : (string) $r['servicio_efectivo'], $fecha),
                                'obs' => $r['observaciones_academicas'], 'p1' => $usuario->id, 'p2' => $usuario->id,
                            ]
                        );
                    }
                }
                $this->auditoria->registrar($solicitud, 'hato.crear_corte', 'exito', new RecursoAuditado('cortes_hato', $corte, $unidadId), ['fecha_corte' => $fecha, 'desde_corte' => $vigente['id'] ?? null]);
                $this->versiones->incrementar($alcance->centroId);
                return $corte;
            });
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) === 1062) {
                throw new ErrorValidacion(['fecha_corte' => 'Ya existe un corte con esa fecha.']);
            }
            throw $error;
        }
    }

    /**
     * Alta o edición completa de un animal y su registro en el corte vigente (hato.gestionar).
     *
     * @param array<string,mixed> $datos
     */
    public function guardarAnimal(Solicitud $solicitud, ?int $id, array $datos): int
    {
        $alcance = AutorizacionRecurso::alcance($solicitud);
        $actual = $id === null ? null : $this->animal($solicitud, $id);
        $unidad = $actual === null ? Entrada::entero($datos['unidad_id'] ?? '', 1) : (int) $actual['animal']['unidad_id'];
        if ($unidad === null) {
            throw new ErrorValidacion(['unidad_id' => 'Elige la unidad pecuaria.']);
        }
        $this->autorizacion->exigir($solicitud, 'hato.gestionar', new RecursoUnidad($alcance->centroId, $unidad));
        $corte = $this->corte($alcance->centroId, $unidad);
        if ($corte === null) {
            throw new ErrorValidacion(['identificador' => 'Crea primero el corte del hato con su fecha.']);
        }
        $errores = [];
        $identificador = Entrada::texto($datos['identificador'] ?? '', 30);
        if ($identificador === null || preg_match('/^[A-Za-z0-9 ._-]{1,30}$/', $identificador) !== 1) {
            $errores['identificador'] = 'Escribe el número del animal (hasta 30 letras o cifras).';
        }
        $nacimiento = self::fechaOpcional($datos['fecha_nacimiento'] ?? '', 'fecha_nacimiento', $errores);
        $lote = (string) ($datos['lote_hato'] ?? '');
        if (!isset(self::LOTES[$lote])) {
            $errores['lote_hato'] = 'Elige el lote del hato.';
        }
        $fechas = [];
        foreach (['penultimo_parto', 'ultimo_parto', 'servicio_efectivo', 'ultima_palpacion'] as $campo) {
            $fechas[$campo] = self::fechaOpcional($datos[$campo] ?? '', $campo, $errores);
        }
        $servicios = trim((string) ($datos['servicios_posparto'] ?? '')) === '' ? null : Entrada::entero($datos['servicios_posparto'], 0, 99);
        if (trim((string) ($datos['servicios_posparto'] ?? '')) !== '' && $servicios === null) {
            $errores['servicios_posparto'] = 'Escribe un número entero entre 0 y 99.';
        }
        $estado = ReglasHato::normalizarEstado((string) ($datos['estado_reproductivo'] ?? ''));
        $edad = Entrada::texto($datos['edad_gestacion'] ?? '', 30);
        if ($edad === null) {
            $errores['edad_gestacion'] = 'Máximo 30 caracteres.';
        }
        $observaciones = Entrada::texto($datos['observaciones_academicas'] ?? '', 500);
        if ($observaciones === null) {
            $errores['observaciones_academicas'] = 'Máximo 500 caracteres.';
        }
        $errores += ReglasHato::validarFechas($nacimiento, $fechas, (string) $corte['fecha_corte']);
        if ($nacimiento !== null && $nacimiento > (string) $corte['fecha_corte']) {
            $errores['fecha_nacimiento'] = 'La fecha de nacimiento no puede ser posterior al corte.';
        }
        if ($errores === [] && (int) $this->bd->valor('SELECT COUNT(*) FROM animales WHERE centro_id = :c AND unidad_id = :u AND identificador = :i AND id <> :id', ['c' => $alcance->centroId, 'u' => $unidad, 'i' => $identificador, 'id' => $id ?? 0]) > 0) {
            $errores['identificador'] = "Ya existe el animal {$identificador} en la unidad.";
        }
        if ($errores !== []) {
            throw new ErrorValidacion($errores);
        }
        $registro = [
            'lote_hato' => $lote,
            'penultimo_parto' => $fechas['penultimo_parto'],
            'ultimo_parto' => $fechas['ultimo_parto'],
            'iep_dias' => ReglasHato::iep($fechas['penultimo_parto'], $fechas['ultimo_parto']),
            'servicios_posparto' => $servicios,
            'servicio_efectivo' => $fechas['servicio_efectivo'],
            'ultima_palpacion' => $fechas['ultima_palpacion'],
            'estado_reproductivo' => $estado,
            'edad_gestacion' => $edad === '' ? null : $edad,
            'dias_abiertos' => ReglasHato::diasAbiertos($fechas['ultimo_parto'], $fechas['servicio_efectivo'], (string) $corte['fecha_corte']),
            'observaciones_academicas' => $observaciones === '' ? null : $observaciones,
        ];
        $animal = ['identificador' => $identificador, 'fecha_nacimiento' => $nacimiento];
        $usuario = AutorizacionRecurso::usuario($solicitud);
        return $this->bd->transaccion(function (BaseDatos $bd) use ($solicitud, $alcance, $id, $actual, $unidad, $corte, $animal, $registro, $datos, $usuario): int {
            if ($actual === null) {
                $id = $this->animales->insertar($animal + ['centro_id' => $alcance->centroId, 'unidad_id' => $unidad, 'origen_tipo' => 'manual'], $usuario->id);
                $antes = null;
            } else {
                $this->animales->actualizar($alcance, $id, Entrada::entero($datos['version'] ?? '', 1) ?? 0, $animal, $usuario->id);
                $antes = ServicioCatalogos::extraer($actual['animal'], array_keys($animal)) + ($actual['registro'] === null ? [] : ServicioCatalogos::extraer($actual['registro'], array_keys($registro)));
            }
            $this->escribirRegistro($bd, $alcance->centroId, $unidad, (int) $corte['id'], $id, $registro, $actual['registro'] ?? null, $datos, $usuario->id);
            $this->auditoria->registrar($solicitud, $antes === null ? 'hato.crear_animal' : 'hato.actualizar_animal', 'exito', new RecursoAuditado('animales', $id, $unidad), ['corte' => $corte['fecha_corte']], $antes, $animal + $registro);
            $this->versiones->incrementar($alcance->centroId);
            return $id;
        });
    }

    /**
     * Lote de práctica académica y observaciones (RF-057). Exige motivo y queda auditado.
     *
     * @param array<string,mixed> $datos
     */
    public function cambiarPractica(Solicitud $solicitud, int $id, array $datos): void
    {
        $actual = $this->animal($solicitud, $id);
        $unidad = (int) $actual['animal']['unidad_id'];
        $this->autorizacion->exigir($solicitud, 'hato.gestionar_practica', new RecursoUnidad((int) $actual['animal']['centro_id'], $unidad));
        if ($actual['registro'] === null || $actual['corte'] === null) {
            throw new ErrorHttp(409, 'El animal no tiene registro en el corte vigente.');
        }
        $errores = [];
        $practica = ($datos['en_practica'] ?? '') === '1';
        $loteActual = (string) $actual['registro']['lote_hato'];
        // Al retirar de práctica, el animal vuelve a Producción salvo que se indique Horra.
        $nuevoLote = $practica ? 'PRACTICA' : (($datos['lote_retorno'] ?? '') === 'HORRA' ? 'HORRA' : ($loteActual === 'PRACTICA' ? 'PRODUCCION' : $loteActual));
        $observaciones = Entrada::texto($datos['observaciones_academicas'] ?? '', 500);
        if ($observaciones === null) {
            $errores['observaciones_academicas'] = 'Máximo 500 caracteres.';
        }
        $motivo = trim((string) ($datos['motivo'] ?? ''));
        if (mb_strlen($motivo) < 5 || mb_strlen($motivo) > 500) {
            $errores['motivo'] = 'Escribe el motivo del cambio (entre 5 y 500 caracteres).';
        }
        if ($errores !== []) {
            throw new ErrorValidacion($errores);
        }
        $cambios = ['lote_hato' => $nuevoLote, 'observaciones_academicas' => $observaciones === '' ? null : $observaciones];
        $antes = ['lote_hato' => $loteActual, 'observaciones_academicas' => $actual['registro']['observaciones_academicas']];
        $usuario = AutorizacionRecurso::usuario($solicitud);
        $this->bd->transaccion(function (BaseDatos $bd) use ($solicitud, $id, $unidad, $actual, $cambios, $antes, $datos, $motivo, $usuario): void {
            $filas = $bd->ejecutar(
                'UPDATE registros_reproductivos SET lote_hato = :lote, observaciones_academicas = :obs, version = version + 1, actualizado_por = :p
                  WHERE id = :id AND version = :v',
                ['lote' => $cambios['lote_hato'], 'obs' => $cambios['observaciones_academicas'], 'p' => $usuario->id, 'id' => $actual['registro']['id'], 'v' => Entrada::entero($datos['version'] ?? '', 1) ?? 0]
            );
            if ($filas === 0) {
                throw new ErrorHttp(409, 'El registro cambió; recarga para ver la versión actual.');
            }
            $this->auditoria->registrar($solicitud, 'hato.gestionar_practica', 'exito', new RecursoAuditado('animales', $id, $unidad), ['corte' => $actual['corte']['fecha_corte']], $antes, $cambios, $motivo);
            $this->versiones->incrementar((int) $actual['animal']['centro_id']);
        });
    }

    public function archivarAnimal(Solicitud $solicitud, int $id, int $version, string $motivo): void
    {
        $actual = $this->animal($solicitud, $id);
        $unidad = (int) $actual['animal']['unidad_id'];
        $this->autorizacion->exigir($solicitud, 'hato.gestionar', new RecursoUnidad((int) $actual['animal']['centro_id'], $unidad));
        $motivo = ServicioAsignaciones::motivo($motivo);
        $usuario = AutorizacionRecurso::usuario($solicitud);
        $this->bd->transaccion(function () use ($solicitud, $id, $version, $motivo, $actual, $unidad, $usuario): void {
            $this->animales->archivar(AutorizacionRecurso::alcance($solicitud), $id, $version, $usuario->id, $motivo);
            $this->auditoria->registrar($solicitud, 'hato.archivar_animal', 'exito', new RecursoAuditado('animales', $id, $unidad), [], ['identificador' => $actual['animal']['identificador']], null, $motivo);
            $this->versiones->incrementar((int) $actual['animal']['centro_id']);
        });
    }

    /**
     * @param array<string,scalar|null> $registro
     * @param array<string,mixed>|null $actual
     * @param array<string,mixed> $datos
     */
    private function escribirRegistro(BaseDatos $bd, int $centro, int $unidad, int $corte, int $animal, array $registro, ?array $actual, array $datos, int $usuario): void
    {
        $asignaciones = implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", array_keys($registro)));
        if ($actual === null) {
            $columnas = array_keys($registro);
            $bd->insertar(
                'INSERT INTO registros_reproductivos (centro_id, unidad_id, corte_id, animal_id, ' . implode(', ', $columnas) . ', creado_por, actualizado_por)
                 VALUES (:centro, :unidad, :corte, :animal, :' . implode(', :', $columnas) . ', :p1, :p2)',
                $registro + ['centro' => $centro, 'unidad' => $unidad, 'corte' => $corte, 'animal' => $animal, 'p1' => $usuario, 'p2' => $usuario]
            );
            return;
        }
        $filas = $bd->ejecutar(
            "UPDATE registros_reproductivos SET {$asignaciones}, version = version + 1, actualizado_por = :p WHERE id = :id AND version = :v",
            $registro + ['p' => $usuario, 'id' => $actual['id'], 'v' => Entrada::entero($datos['version_registro'] ?? '', 1) ?? 0]
        );
        if ($filas === 0) {
            throw new ErrorHttp(409, 'El registro cambió; recarga para ver la versión actual.');
        }
    }

    /** @param array<string,string> $errores */
    private static function fechaOpcional(mixed $valor, string $campo, array &$errores): ?string
    {
        if (trim((string) $valor) === '') {
            return null;
        }
        $fecha = Entrada::fecha($valor);
        if ($fecha === null) {
            $errores[$campo] = 'Revisa la fecha (AAAA-MM-DD).';
        }
        return $fecha;
    }
}
