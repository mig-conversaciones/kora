<?php

declare(strict_types=1);

namespace App\Importacion\Fuentes;

use App\Importacion\ContextoImportacion;
use App\Importacion\FuenteImportacion;
use App\Importacion\Normalizador;
use App\Nucleo\BaseDatos;
use App\Nucleo\Entrada;

/**
 * Fuente 5: base de producción, es decir, recepciones de lotes («Base Produccion Centro», G.2, RF-039). Clave natural:
 * número interno + código SGAC (+ unidad). Acepta también el formato del contrato de integración (RF-096).
 */
final class FuenteProduccion extends FuenteImportacion
{
    public function clave(): string
    {
        return 'base_produccion';
    }

    public function nombre(): string
    {
        return 'Base de producción (recepciones)';
    }

    public function permiso(): string
    {
        return 'recepciones.importar';
    }

    public function patronHoja(): string
    {
        return '/^(base produccion|recepciones)/';
    }

    public function tabla(): string
    {
        return 'recepciones_lote';
    }

    public function campoSuma(): string
    {
        return 'cantidad';
    }

    public function columnas(): array
    {
        return [
            'numero' => ['alias' => ['num interno', 'numero interno'], 'obligatoria' => true],
            'fecha' => ['alias' => ['fecha recepcion', 'fecha'], 'obligatoria' => true],
            'unidad' => ['alias' => ['nombre proveedor', 'unidad'], 'obligatoria' => true],
            'nombre' => ['alias' => ['nombre producto'], 'obligatoria' => false],
            'codigo' => ['alias' => ['codigo sgarc', 'codigo sgac', 'codigo producto'], 'obligatoria' => true],
            'cantidad' => ['alias' => ['cantidad recibida', 'cantidad'], 'obligatoria' => true],
            'medida' => ['alias' => ['unidad de medida', 'medida'], 'obligatoria' => false],
            'precio' => ['alias' => ['precio de venta sugerido', 'precio sugerido'], 'obligatoria' => false],
            'responsable' => ['alias' => ['usuario que recibe', 'responsable'], 'obligatoria' => false],
        ];
    }

    public function camposComparables(): array
    {
        return ['fecha_recepcion', 'cantidad', 'medida', 'precio_sugerido', 'responsable_texto'];
    }

    public function validar(array $valores, ContextoImportacion $contexto): ?array
    {
        $numero = strtoupper((string) Normalizador::texto($valores['numero'] ?? null));
        if ($numero === '') {
            return null;
        }
        $errores = [];
        $advertencias = [];
        if (preg_match('/^[A-Z0-9]{2,8}-(REC|DEV)-\d{6}-\d{3,4}$/', $numero) !== 1) {
            $advertencias[] = "El número interno «{$numero}» no sigue el formato GAN-REC-210126-001.";
        }
        $fecha = Normalizador::fecha($valores['fecha'] ?? null);
        if ($fecha === null) {
            self::error($errores, 'fecha', 'La fecha de recepción no es válida.');
        }
        $unidad = $contexto->unidadId(Normalizador::unidad($valores['unidad'] ?? null));
        if ($unidad === null) {
            self::error($errores, 'unidad', 'La unidad (proveedor) no se reconoce.');
        }
        self::exigirUnidad($contexto, $unidad, 'unidad', $errores);
        $producto = $contexto->producto(Normalizador::soloDigitos($valores['codigo'] ?? ''));
        if ($producto === null) {
            self::error($errores, 'codigo', 'El código SGAC no está en el catálogo. Importa primero el catálogo.');
        }
        $nombre = Normalizador::texto($valores['nombre'] ?? null);
        if ($producto !== null && $nombre !== null && Normalizador::encabezado($nombre) !== Normalizador::encabezado($producto['nombre'])) {
            $advertencias[] = "El nombre «{$nombre}» difiere del catálogo («{$producto['nombre']}»).";
        }
        $cantidad = Normalizador::numero($valores['cantidad'] ?? null, 3);
        if ($cantidad === null || Entrada::comparar($cantidad, '0.000') <= 0) {
            self::error($errores, 'cantidad', 'La cantidad recibida debe ser mayor que 0.');
        }
        $medida = trim((string) ($valores['medida'] ?? '')) === '' ? ($producto['medida'] ?? null) : Normalizador::medida($valores['medida']);
        if ($producto !== null && $medida !== $producto['medida']) {
            self::error($errores, 'medida', 'La medida de la recepción debe coincidir con la del catálogo.');
        }
        $precio = trim((string) ($valores['precio'] ?? '')) === '' ? null : Normalizador::numero($valores['precio']);
        $responsable = Normalizador::texto($valores['responsable'] ?? null);
        $datos = [
            'unidad_id' => $unidad, 'numero_interno' => mb_substr($numero, 0, 40), 'fecha_recepcion' => $fecha,
            'producto_id' => $producto['id'] ?? null, 'cantidad' => $cantidad, 'medida' => $medida, 'precio_sugerido' => $precio,
            'responsable_texto' => $responsable === null ? null : mb_substr($responsable, 0, 150),
        ];
        $actual = $errores === [] ? $this->actual($contexto, $datos) : null;
        if ($actual !== null) {
            $entregado = (string) $contexto->bd->valor('SELECT COALESCE(SUM(cantidad), 0) FROM salidas WHERE recepcion_id = :r AND archivado_en IS NULL', ['r' => $actual['id']]);
            if (Entrada::comparar(Entrada::sumarCon(3, $entregado), (string) $cantidad) > 0) {
                self::error($errores, 'cantidad', 'La recepción no puede ser menor que las cantidades ya entregadas.');
            }
            if ((int) $actual['unidad_id'] !== $unidad) {
                self::error($errores, 'unidad', 'El lote ya existe con otra unidad.');
            }
        }
        return ['datos' => $datos, 'clave' => $numero . '|' . ($producto['codigo_sgac'] ?? '') . '|' . $unidad, 'errores' => $errores, 'advertencias' => $advertencias];
    }

    public function actual(ContextoImportacion $contexto, array $datos): ?array
    {
        return $contexto->bd->fila(
            'SELECT * FROM recepciones_lote WHERE centro_id = :c AND numero_interno = :n AND producto_id = :p AND archivado_en IS NULL',
            ['c' => $contexto->centroId, 'n' => $datos['numero_interno'], 'p' => $datos['producto_id']]
        );
    }

    public function aplicar(ContextoImportacion $contexto, array $datos, ?array $actual, array $procedencia): int
    {
        if ($actual === null) {
            return $this->insertarFila($contexto->bd, $datos + ['centro_id' => $contexto->centroId, 'tipo_movimiento' => 'REC'], $procedencia, $contexto->usuarioId);
        }
        $this->actualizarFila($contexto->bd, (int) $actual['id'], array_intersect_key($datos, array_flip($this->camposComparables())) + $procedencia, $contexto->usuarioId);
        return (int) $actual['id'];
    }

    public function dependencias(BaseDatos $bd, int $id, int $loteId): ?string
    {
        $otras = (int) $bd->valor(
            'SELECT COUNT(*) FROM salidas WHERE recepcion_id = :r AND archivado_en IS NULL AND (lote_importacion_id IS NULL OR lote_importacion_id <> :l)',
            ['r' => $id, 'l' => $loteId]
        );
        $transformaciones = (int) $bd->valor('SELECT COUNT(*) FROM transformaciones WHERE recepcion_derivado_id = :r AND archivado_en IS NULL', ['r' => $id]);
        return $otras + $transformaciones > 0 ? "La recepción {$id} ya tiene salidas o transformaciones registradas después." : null;
    }
}
