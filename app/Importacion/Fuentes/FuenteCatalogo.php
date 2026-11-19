<?php

declare(strict_types=1);

namespace App\Importacion\Fuentes;

use App\Importacion\ContextoImportacion;
use App\Importacion\FuenteImportacion;
use App\Importacion\Normalizador;
use App\Nucleo\BaseDatos;

/** Fuente 4: catálogo de productos («CatalogoProductos_CAB», G.2). Clave natural: código SGAC. */
final class FuenteCatalogo extends FuenteImportacion
{
    public function clave(): string
    {
        return 'catalogo_productos';
    }

    public function nombre(): string
    {
        return 'Catálogo de productos (CatalogoProductos_CAB)';
    }

    public function permiso(): string
    {
        return 'catalogo.importar';
    }

    public function patronHoja(): string
    {
        return '/^catalogoproductos/';
    }

    public function tabla(): string
    {
        return 'productos';
    }

    public function columnas(): array
    {
        return [
            'nombre' => ['alias' => ['nombre'], 'obligatoria' => true],
            'codigo' => ['alias' => ['codigo sgac', 'codigo sgarc'], 'obligatoria' => true],
            'unidad' => ['alias' => ['unidad productiva'], 'obligatoria' => false],
            'precio_minimo' => ['alias' => ['precio minimo'], 'obligatoria' => false],
            'precio_maximo' => ['alias' => ['precio maximo'], 'obligatoria' => false],
            'precio_venta' => ['alias' => ['precio de venta'], 'obligatoria' => false],
            'medida' => ['alias' => ['medida'], 'obligatoria' => true],
            'clase' => ['alias' => ['clase'], 'obligatoria' => false],
            'codigo_familia' => ['alias' => ['codigofamilia', 'codigo familia'], 'obligatoria' => false],
            'nombre_familia' => ['alias' => ['nombrefamilia', 'nombre familia'], 'obligatoria' => false],
        ];
    }

    public function camposComparables(): array
    {
        return ['nombre', 'unidad_sugerida_id', 'precio_minimo', 'precio_maximo', 'precio_venta', 'medida', 'clase', 'codigo_familia', 'nombre_familia', 'categoria_rotacion'];
    }

    public function validar(array $valores, ContextoImportacion $contexto): ?array
    {
        $codigo = Normalizador::soloDigitos($valores['codigo'] ?? '');
        $nombre = Normalizador::texto($valores['nombre'] ?? null);
        if ($codigo === '' && $nombre === null) {
            return null;
        }
        $errores = [];
        if (preg_match('/^\d{6,20}$/', $codigo) !== 1) {
            self::error($errores, 'codigo', 'El código SGAC debe tener entre 6 y 20 dígitos.');
        }
        if ($nombre === null) {
            self::error($errores, 'nombre', 'Falta el nombre del producto.');
        }
        $medida = Normalizador::medida($valores['medida'] ?? null);
        if ($medida === null) {
            self::error($errores, 'medida', 'La medida no se reconoce (Litros, Kilos, Unidad, Libra, Cubeta).');
        }
        $precios = [];
        foreach (['precio_minimo', 'precio_maximo', 'precio_venta'] as $campo) {
            $texto = trim((string) ($valores[$campo] ?? ''));
            $precios[$campo] = $texto === '' ? null : Normalizador::numero($valores[$campo]);
            if ($texto !== '' && $precios[$campo] === null) {
                self::error($errores, $campo, 'El precio no es un número mayor o igual que 0.');
            }
        }
        if ($precios['precio_minimo'] !== null && $precios['precio_maximo'] !== null && (float) $precios['precio_minimo'] > (float) $precios['precio_maximo']) {
            self::error($errores, 'precio_minimo', 'El precio mínimo supera el máximo.');
        }
        $unidadTexto = Normalizador::texto($valores['unidad'] ?? null);
        $unidad = $unidadTexto === null ? null : $contexto->unidadId(Normalizador::unidad($unidadTexto));
        $familia = Normalizador::texto($valores['nombre_familia'] ?? null);
        $datos = [
            'codigo_sgac' => $codigo, 'nombre' => $nombre === null ? '' : mb_substr($nombre, 0, 200), 'medida' => $medida,
            'unidad_sugerida_id' => $unidad, 'clase' => Normalizador::texto($valores['clase'] ?? null),
            'codigo_familia' => Normalizador::texto($valores['codigo_familia'] ?? null), 'nombre_familia' => $familia,
            'categoria_rotacion' => $familia !== null && Normalizador::encabezado($familia) === 'animales vivos' ? 'animal' : 'fresco',
        ] + $precios;
        $actual = $errores === [] ? $this->actual($contexto, $datos) : null;
        if ($actual !== null && $actual['medida'] !== $medida && (int) $contexto->bd->valor('SELECT COUNT(*) FROM recepciones_lote WHERE producto_id = :p AND archivado_en IS NULL', ['p' => $actual['id']]) > 0) {
            self::error($errores, 'medida', 'El producto tiene recepciones. Conserva la medida registrada.');
        }
        return ['datos' => $datos, 'clave' => $codigo, 'errores' => $errores, 'advertencias' => $unidadTexto !== null && $unidad === null ? ["La unidad «{$unidadTexto}» no se reconoce; queda sin unidad sugerida."] : []];
    }

    public function actual(ContextoImportacion $contexto, array $datos): ?array
    {
        return $contexto->bd->fila('SELECT * FROM productos WHERE centro_id = :c AND codigo_sgac = :s', ['c' => $contexto->centroId, 's' => $datos['codigo_sgac']]);
    }

    public function aplicar(ContextoImportacion $contexto, array $datos, ?array $actual, array $procedencia): int
    {
        $procedencia = array_intersect_key($procedencia, array_flip(['origen_tipo', 'lote_importacion_id', 'origen_fila']));
        $contexto->olvidarProducto((string) $datos['codigo_sgac']);
        if ($actual === null) {
            return $this->insertarFila($contexto->bd, $datos + ['centro_id' => $contexto->centroId], $procedencia, $contexto->usuarioId);
        }
        $this->actualizarFila($contexto->bd, (int) $actual['id'], array_intersect_key($datos, array_flip($this->camposComparables())) + $procedencia + ['archivado_en' => null], $contexto->usuarioId);
        return (int) $actual['id'];
    }

    public function dependencias(BaseDatos $bd, int $id, int $loteId): ?string
    {
        return (int) $bd->valor('SELECT COUNT(*) FROM recepciones_lote WHERE producto_id = :p AND archivado_en IS NULL', ['p' => $id]) > 0 ? "El producto {$id} ya tiene recepciones." : null;
    }
}
