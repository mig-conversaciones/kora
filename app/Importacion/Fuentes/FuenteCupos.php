<?php

declare(strict_types=1);

namespace App\Importacion\Fuentes;

use App\Importacion\ContextoImportacion;
use App\Importacion\FuenteImportacion;
use App\Importacion\Normalizador;
use App\Nucleo\BaseDatos;

/** Fuente 3: cupos de autoconsumo («Autoconsumo_2026», G.1). Clave natural: código + vigencia. */
final class FuenteCupos extends FuenteImportacion
{
    public function clave(): string
    {
        return 'cupos_autoconsumo';
    }

    public function nombre(): string
    {
        return 'Cupos de autoconsumo (Autoconsumo_AAAA)';
    }

    public function permiso(): string
    {
        return 'procesos.importar';
    }

    public function patronHoja(): string
    {
        return '/^autoconsumo \d{4}/';
    }

    public function tabla(): string
    {
        return 'procesos_autoconsumo';
    }

    /** @return list<string> */
    public function opcionesRequeridas(): array
    {
        return ['vigencia'];
    }

    public function campoSuma(): string
    {
        return 'cupo_valor';
    }

    public function columnas(): array
    {
        return [
            'codigo' => ['alias' => ['id proceso'], 'obligatoria' => true],
            'origen' => ['alias' => ['up origen'], 'obligatoria' => true],
            'destino' => ['alias' => ['up destino y o cliente', 'up destino', 'destino'], 'obligatoria' => true],
            'producto' => ['alias' => ['producto'], 'obligatoria' => true],
            'cantidad' => ['alias' => ['cant autoconsumo', 'cantidad'], 'obligatoria' => true],
            'medida' => ['alias' => ['unidad de medida', 'medida'], 'obligatoria' => true],
            'valor' => ['alias' => ['valor'], 'obligatoria' => true],
        ];
    }

    public function camposComparables(): array
    {
        return ['unidad_id', 'destino_id', 'destino_texto', 'producto_id', 'producto_texto', 'cupo_cantidad', 'medida', 'cupo_valor'];
    }

    public function validar(array $valores, ContextoImportacion $contexto): ?array
    {
        $codigo = strtoupper((string) Normalizador::texto($valores['codigo'] ?? null));
        if ($codigo === '') {
            return null;
        }
        $errores = [];
        if (preg_match('/^AUTO_\d{3,6}$/', $codigo) !== 1) {
            self::error($errores, 'codigo', 'El código del proceso tiene el formato AUTO_001.');
        }
        $unidad = $contexto->unidadId(Normalizador::unidad($valores['origen'] ?? null));
        if ($unidad === null) {
            self::error($errores, 'origen', 'La unidad de origen no se reconoce.');
        }
        self::exigirUnidad($contexto, $unidad, 'origen', $errores);
        $destinoTexto = Normalizador::texto($valores['destino'] ?? null);
        $destino = null;
        if ($destinoTexto !== null) {
            foreach ($contexto->bd->consultar('SELECT id, nombre FROM destinos_internos WHERE centro_id = :c AND activo = 1', ['c' => $contexto->centroId]) as $d) {
                if (Normalizador::encabezado($d['nombre']) === Normalizador::encabezado($destinoTexto)) {
                    $destino = (int) $d['id'];
                }
            }
        } else {
            self::error($errores, 'destino', 'Falta el destino o cliente.');
        }
        $productoTexto = (string) Normalizador::texto($valores['producto'] ?? null);
        $cantidad = Normalizador::numero($valores['cantidad'] ?? null, 3);
        if ($cantidad === null || (float) $cantidad <= 0) {
            self::error($errores, 'cantidad', 'El cupo físico debe ser mayor que 0.');
        }
        $medida = Normalizador::medida($valores['medida'] ?? null);
        if ($medida === null) {
            self::error($errores, 'medida', 'La unidad de medida no se reconoce (Litros, Kilos, Unidad, Libra, Cubeta).');
        }
        $valor = Normalizador::numero($valores['valor'] ?? 0);
        if ($valor === null) {
            self::error($errores, 'valor', 'El valor del cupo no es un número mayor o igual que 0.');
        }
        $datos = [
            'codigo' => $codigo, 'vigencia' => (int) $contexto->opcion('vigencia'), 'unidad_id' => $unidad,
            'destino_id' => $destino, 'destino_texto' => $destino === null ? $destinoTexto : null,
            'producto_id' => $contexto->homologar($productoTexto), 'producto_texto' => mb_substr($productoTexto, 0, 200),
            'cupo_cantidad' => $cantidad, 'medida' => $medida, 'cupo_valor' => $valor,
        ];
        $actual = $errores === [] ? $this->actual($contexto, $datos) : null;
        if ($actual !== null && (int) $contexto->bd->valor('SELECT COUNT(*) FROM salidas WHERE proceso_id = :p AND archivado_en IS NULL', ['p' => $actual['id']]) > 0) {
            foreach (['unidad_id' => 'origen', 'medida' => 'medida', 'producto_texto' => 'producto'] as $campo => $columna) {
                if ((string) $datos[$campo] !== (string) $actual[$campo]) {
                    self::error($errores, $columna, 'El proceso tiene entregas. Conserva su unidad, medida, producto y vigencia.');
                }
            }
        }
        return ['datos' => $datos, 'clave' => $codigo . '|' . $datos['vigencia'], 'errores' => $errores, 'advertencias' => $datos['producto_id'] === null ? ["El producto «{$productoTexto}» no está homologado."] : []];
    }

    public function actual(ContextoImportacion $contexto, array $datos): ?array
    {
        return $contexto->bd->fila(
            'SELECT * FROM procesos_autoconsumo WHERE centro_id = :c AND codigo = :k AND vigencia = :v AND archivado_en IS NULL',
            ['c' => $contexto->centroId, 'k' => $datos['codigo'], 'v' => $datos['vigencia']]
        );
    }

    public function aplicar(ContextoImportacion $contexto, array $datos, ?array $actual, array $procedencia): int
    {
        $procedencia = array_intersect_key($procedencia, array_flip(['origen_tipo', 'lote_importacion_id', 'origen_fila']));
        if ($actual === null) {
            return $this->insertarFila($contexto->bd, $datos + ['centro_id' => $contexto->centroId], $procedencia, $contexto->usuarioId);
        }
        $this->actualizarFila($contexto->bd, (int) $actual['id'], array_intersect_key($datos, array_flip($this->camposComparables())) + $procedencia, $contexto->usuarioId);
        return (int) $actual['id'];
    }

    public function dependencias(BaseDatos $bd, int $id, int $loteId): ?string
    {
        return (int) $bd->valor('SELECT COUNT(*) FROM salidas WHERE proceso_id = :p AND archivado_en IS NULL', ['p' => $id]) > 0 ? "El proceso {$id} ya tiene entregas." : null;
    }
}
