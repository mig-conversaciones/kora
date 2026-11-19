<?php

declare(strict_types=1);

namespace App\Importacion\Fuentes;

use App\Importacion\ContextoImportacion;
use App\Importacion\FuenteImportacion;
use App\Importacion\Normalizador;
use App\Nucleo\BaseDatos;
use App\Servicios\ReglasHato;

/**
 * Fuente 7: estado reproductivo («ESTADO REP», G.3, RF-052 a RF-055). Un archivo es un corte: su fecha es la columna D,
 * que debe ser única, o la elegida al cargar. El IEP y los días abiertos se calculan; los importados se guardan aparte
 * para que la bandeja de calidad los contraste (±1 día). Clave natural: corte + animal.
 */
final class FuenteHato extends FuenteImportacion
{
    private const LOTES = ['vaca produccion' => 'PRODUCCION', 'produccion' => 'PRODUCCION', 'vaca horra' => 'HORRA', 'horra' => 'HORRA',
        'vaca practica' => 'PRACTICA', 'practica' => 'PRACTICA', 'practica academica' => 'PRACTICA'];

    public function clave(): string
    {
        return 'estado_reproductivo';
    }

    public function nombre(): string
    {
        return 'Estado reproductivo del hato (ESTADO REP)';
    }

    public function permiso(): string
    {
        return 'hato.importar';
    }

    public function patronHoja(): string
    {
        return '/^estado rep/';
    }

    public function tabla(): string
    {
        return 'registros_reproductivos';
    }

    /** @return list<string> */
    public function opcionesRequeridas(): array
    {
        return ['unidad_hato'];
    }

    public function columnas(): array
    {
        return [
            'vaca' => ['alias' => ['vaca', 'animal'], 'obligatoria' => true],
            'lote' => ['alias' => ['lote'], 'obligatoria' => true],
            'nacimiento' => ['alias' => ['fecha de nacimiento'], 'obligatoria' => false],
            'fecha_actual' => ['alias' => ['fecha actual'], 'obligatoria' => false],
            'penultimo' => ['alias' => ['penultimo parto'], 'obligatoria' => false],
            'ultimo' => ['alias' => ['ultimo parto'], 'obligatoria' => false],
            'iep' => ['alias' => ['iep'], 'obligatoria' => false],
            'servicios' => ['alias' => ['# servicios post parto', 'servicios post parto', 'servicios posparto'], 'obligatoria' => false],
            'servicio_efectivo' => ['alias' => ['fecha servicio efectivo'], 'obligatoria' => false],
            'palpacion' => ['alias' => ['ultimo palpacion', 'ultima palpacion'], 'obligatoria' => false],
            'estado' => ['alias' => ['estado reproductivo'], 'obligatoria' => false],
            'edad' => ['alias' => ['edad gestacion dias', 'edad gestacion'], 'obligatoria' => false],
            'abiertos' => ['alias' => ['dias abiertos'], 'obligatoria' => false],
        ];
    }

    public function camposComparables(): array
    {
        return ['lote_hato', 'penultimo_parto', 'ultimo_parto', 'iep_importado', 'servicios_posparto', 'servicio_efectivo', 'ultima_palpacion', 'estado_reproductivo', 'edad_gestacion', 'dias_abiertos_importado'];
    }

    public function validar(array $valores, ContextoImportacion $contexto): ?array
    {
        $vaca = Normalizador::texto($valores['vaca'] ?? null);
        if ($vaca === null) {
            return null;
        }
        $vaca = is_float($valores['vaca']) ? (string) (int) $valores['vaca'] : mb_substr($vaca, 0, 30);
        $errores = [];
        $unidad = (int) $contexto->opcion('unidad_hato');
        self::exigirUnidad($contexto, $unidad, 'vaca', $errores);
        $corte = Normalizador::fecha($contexto->opcion('fecha_corte')) ?? Normalizador::fecha($valores['fecha_actual'] ?? null);
        if ($corte === null) {
            self::error($errores, 'fecha_actual', 'Falta la fecha del corte: elígela al cargar o complétala en la columna «Fecha actual».');
        } else {
            $contexto->memoria['corte_detectado'] ??= $corte;
            if ($contexto->opcion('fecha_corte') === null && $contexto->memoria['corte_detectado'] !== $corte) {
                self::error($errores, 'fecha_actual', 'Las fechas de la columna «Fecha actual» no son iguales en todo el archivo: elige la fecha del corte al cargar.');
            }
        }
        $lote = self::LOTES[Normalizador::encabezado($valores['lote'] ?? '')] ?? null;
        if ($lote === null) {
            self::error($errores, 'lote', 'El lote no se reconoce (VACA PRODUCCION, VACA HORRA o VACA PRACTICA).');
        }
        $fechas = [];
        $advertenciasFechas = [];
        foreach (['nacimiento' => 'nacimiento', 'penultimo' => 'penultimo_parto', 'ultimo' => 'ultimo_parto', 'servicio_efectivo' => 'servicio_efectivo', 'palpacion' => 'ultima_palpacion'] as $columna => $campo) {
            $texto = $valores[$columna] ?? null;
            $fechas[$campo] = Normalizador::fecha($texto);
            $escrito = is_scalar($texto) ? trim((string) $texto) : '';
            if ($fechas[$campo] !== null || $texto === null || ($escrito === '' && !$texto instanceof \DateTimeInterface)) {
                continue;
            }
            // Las fuentes anotan el servicio o la palpación con texto («25/10/2026 MONTA NATURAL (ARGOS)»): se toma la
            // fecha inicial si la hay y, si no, el campo queda vacío con una advertencia, sin rechazar al animal.
            if (in_array($campo, ['servicio_efectivo', 'ultima_palpacion'], true)) {
                $inicial = preg_match('#^(\d{1,2}/\d{1,2}/\d{4})\b#', $escrito, $m) === 1 ? Normalizador::fecha($m[1]) : null;
                $fechas[$campo] = $inicial;
                $advertenciasFechas[] = $inicial === null
                    ? "«{$escrito}» en {$columna} no es una fecha: el campo queda vacío."
                    : "«{$escrito}» en {$columna}: se toma la fecha {$inicial}.";
                continue;
            }
            self::error($errores, $columna, 'La fecha no es válida.');
        }
        $nacimiento = $fechas['nacimiento'];
        unset($fechas['nacimiento']);
        foreach (ReglasHato::validarFechas($nacimiento, $fechas, $corte ?? '9999-12-31') as $campo => $mensaje) {
            self::error($errores, $campo, $mensaje);
        }
        $iep = ReglasHato::iep($fechas['penultimo_parto'], $fechas['ultimo_parto']);
        $abiertos = $corte === null ? null : ReglasHato::diasAbiertos($fechas['ultimo_parto'], $fechas['servicio_efectivo'], $corte);
        $datos = [
            'identificador' => $vaca, 'fecha_nacimiento' => $nacimiento, 'unidad_id' => $unidad, 'fecha_corte' => $corte, 'lote_hato' => $lote,
            'iep_dias' => $iep, 'iep_importado' => self::dias($valores['iep'] ?? null), 'servicios_posparto' => Normalizador::entero($valores['servicios'] ?? null),
            'estado_reproductivo' => ReglasHato::normalizarEstado((string) ($valores['estado'] ?? '')),
            'edad_gestacion' => ($edad = Normalizador::texto($valores['edad'] ?? null)) === null ? null : mb_substr($edad, 0, 30),
            'dias_abiertos' => $abiertos, 'dias_abiertos_importado' => self::dias($valores['abiertos'] ?? null),
        ] + $fechas;
        $advertencias = $advertenciasFechas;
        if (ReglasHato::difiere($datos['iep_importado'], $iep) || ReglasHato::difiere($datos['dias_abiertos_importado'], $abiertos)) {
            $advertencias[] = 'El IEP o los días abiertos importados difieren del cálculo en más de 1 día; se abrirá una incidencia de calidad.';
        }
        return ['datos' => $datos, 'clave' => $corte . '|' . $vaca, 'errores' => $errores, 'advertencias' => $advertencias];
    }

    public function actual(ContextoImportacion $contexto, array $datos): ?array
    {
        return $contexto->bd->fila(
            'SELECT r.* FROM registros_reproductivos r JOIN cortes_hato c ON c.id = r.corte_id JOIN animales a ON a.id = r.animal_id
              WHERE c.centro_id = :c AND c.unidad_id = :u AND c.fecha_corte = :f AND a.identificador = :i',
            ['c' => $contexto->centroId, 'u' => $datos['unidad_id'], 'f' => $datos['fecha_corte'], 'i' => $datos['identificador']]
        );
    }

    public function aplicar(ContextoImportacion $contexto, array $datos, ?array $actual, array $procedencia): int
    {
        $bd = $contexto->bd;
        $registro = array_intersect_key($datos, array_flip(array_merge($this->camposComparables(), ['iep_dias', 'dias_abiertos'])));
        if ($actual !== null) {
            $this->actualizarFila($bd, (int) $actual['id'], $registro + ['origen_fila' => $procedencia['origen_fila']], $contexto->usuarioId);
            return (int) $actual['id'];
        }
        $corte = $this->corte($contexto, (int) $datos['unidad_id'], (string) $datos['fecha_corte']);
        $animal = $bd->fila('SELECT id FROM animales WHERE centro_id = :c AND unidad_id = :u AND identificador = :i', ['c' => $contexto->centroId, 'u' => $datos['unidad_id'], 'i' => $datos['identificador']]);
        if ($animal === null) {
            $animalId = $bd->insertar(
                "INSERT INTO animales (centro_id, unidad_id, identificador, fecha_nacimiento, origen_tipo, lote_importacion_id, creado_por, actualizado_por)
                 VALUES (:c, :u, :i, :n, 'importacion', :l, :p1, :p2)",
                ['c' => $contexto->centroId, 'u' => $datos['unidad_id'], 'i' => $datos['identificador'], 'n' => $datos['fecha_nacimiento'], 'l' => $contexto->loteId, 'p1' => $contexto->usuarioId, 'p2' => $contexto->usuarioId]
            );
        } else {
            $animalId = (int) $animal['id'];
            if ($datos['fecha_nacimiento'] !== null) {
                $bd->ejecutar('UPDATE animales SET fecha_nacimiento = :n, archivado_en = NULL WHERE id = :id', ['n' => $datos['fecha_nacimiento'], 'id' => $animalId]);
            }
        }
        return $this->insertarFila($bd, $registro + ['centro_id' => $contexto->centroId, 'unidad_id' => $datos['unidad_id'], 'corte_id' => $corte, 'animal_id' => $animalId], ['origen_fila' => $procedencia['origen_fila']], $contexto->usuarioId);
    }

    /** Un registro creado por el lote se elimina; si el corte queda vacío, también, y el vigente pasa al último. */
    public function revertirCreado(BaseDatos $bd, int $id, int $usuario, string $motivo): void
    {
        $registro = $bd->fila('SELECT corte_id, unidad_id, centro_id FROM registros_reproductivos WHERE id = :id', ['id' => $id]);
        if ($registro === null) {
            return;
        }
        $bd->ejecutar('DELETE FROM registros_reproductivos WHERE id = :id', ['id' => $id]);
        if ((int) $bd->valor('SELECT COUNT(*) FROM registros_reproductivos WHERE corte_id = :c', ['c' => $registro['corte_id']]) === 0) {
            $bd->ejecutar('DELETE FROM cortes_hato WHERE id = :c', ['c' => $registro['corte_id']]);
            $ultimo = $bd->valor('SELECT id FROM cortes_hato WHERE centro_id = :c AND unidad_id = :u ORDER BY fecha_corte DESC LIMIT 1', ['c' => $registro['centro_id'], 'u' => $registro['unidad_id']]);
            if ($ultimo !== null) {
                $bd->ejecutar('UPDATE cortes_hato SET vigente = (id = :id) WHERE centro_id = :c AND unidad_id = :u', ['id' => $ultimo, 'c' => $registro['centro_id'], 'u' => $registro['unidad_id']]);
            }
        }
    }

    private function corte(ContextoImportacion $contexto, int $unidad, string $fecha): int
    {
        $clave = "corte|{$unidad}|{$fecha}";
        if (isset($contexto->memoria[$clave])) {
            return (int) $contexto->memoria[$clave];
        }
        $bd = $contexto->bd;
        $id = $bd->valor('SELECT id FROM cortes_hato WHERE centro_id = :c AND unidad_id = :u AND fecha_corte = :f', ['c' => $contexto->centroId, 'u' => $unidad, 'f' => $fecha]);
        if ($id === null) {
            $id = $bd->insertar(
                'INSERT INTO cortes_hato (centro_id, unidad_id, fecha_corte, vigente, lote_importacion_id, creado_por) VALUES (:c, :u, :f, 0, :l, :p)',
                ['c' => $contexto->centroId, 'u' => $unidad, 'f' => $fecha, 'l' => $contexto->loteId, 'p' => $contexto->usuarioId]
            );
            $ultimo = $bd->valor('SELECT id FROM cortes_hato WHERE centro_id = :c AND unidad_id = :u ORDER BY fecha_corte DESC LIMIT 1', ['c' => $contexto->centroId, 'u' => $unidad]);
            $bd->ejecutar('UPDATE cortes_hato SET vigente = (id = :id) WHERE centro_id = :c AND unidad_id = :u', ['id' => $ultimo, 'c' => $contexto->centroId, 'u' => $unidad]);
        }
        return $contexto->memoria[$clave] = (int) $id;
    }

    private static function dias(mixed $valor): ?int
    {
        if ($valor === null || (is_string($valor) && trim($valor) === '')) {
            return null;
        }
        return is_numeric($valor) ? (int) round((float) $valor) : null;
    }
}
