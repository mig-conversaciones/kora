<?php

declare(strict_types=1);

namespace App\Importacion\Fuentes;

use App\Importacion\ContextoImportacion;
use App\Importacion\FuenteImportacion;
use App\Importacion\Normalizador;
use App\Nucleo\BaseDatos;
use App\Nucleo\Entrada;

/**
 * Fuente 6: base de ventas, es decir, salidas («Base Ventas», G.2, RF-043, RF-045). La fuente no tiene identificador:
 * la clave natural combina los campos de la fila con su número de ocurrencia en el archivo (RF-089). Una salida cuyo
 * número interno no corresponde a ninguna recepción conserva sus datos con recepcion_id NULL y queda para la bandeja de
 * calidad. Se relaciona solo por claves (número, código, proceso), nunca por nombre ni por fecha (RF-097). En el
 * formato del contrato de integración (columna id_externo) la clave es ese identificador y es obligatorio (RF-096).
 */
final class FuenteVentas extends FuenteImportacion
{
    public function clave(): string
    {
        return 'base_ventas';
    }

    public function nombre(): string
    {
        return 'Base de ventas (salidas)';
    }

    public function permiso(): string
    {
        return 'salidas.importar';
    }

    public function patronHoja(): string
    {
        return '/^(base ventas|salidas)/';
    }

    public function tabla(): string
    {
        return 'salidas';
    }

    public function campoSuma(): string
    {
        return 'valor';
    }

    /** @return list<string> */
    public function camposSensibles(): array
    {
        return ['cliente'];
    }

    public function columnas(): array
    {
        return [
            'numero' => ['alias' => ['num interno compra', 'numero interno'], 'obligatoria' => true],
            'factura' => ['alias' => ['no factura didactica', 'factura'], 'obligatoria' => false],
            'fecha' => ['alias' => ['fecha venta', 'fecha'], 'obligatoria' => true],
            'codigo' => ['alias' => ['codigo sgarc', 'codigo sgac', 'codigo producto'], 'obligatoria' => false],
            'nombre' => ['alias' => ['nombre del producto', 'nombre producto'], 'obligatoria' => false],
            'medida' => ['alias' => ['unidad de medida', 'medida'], 'obligatoria' => true],
            'cantidad' => ['alias' => ['cantidad vendida', 'cantidad'], 'obligatoria' => true],
            'precio' => ['alias' => ['precio unitario venta', 'precio unitario'], 'obligatoria' => true],
            'subtotal' => ['alias' => ['subtotal', 'valor'], 'obligatoria' => false],
            'metodo' => ['alias' => ['metodo de pago', 'canal'], 'obligatoria' => true],
            'origen' => ['alias' => ['origen', 'proceso'], 'obligatoria' => false],
            'transaccion' => ['alias' => ['codigo transaccion', 'referencia transaccion'], 'obligatoria' => false],
            'cliente' => ['alias' => ['id cliente', 'destino'], 'obligatoria' => false],
            'id_externo' => ['alias' => ['id externo'], 'obligatoria' => false],
        ];
    }

    public function camposComparables(): array
    {
        return [];
    }

    public function validar(array $valores, ContextoImportacion $contexto): ?array
    {
        $numero = strtoupper((string) Normalizador::texto($valores['numero'] ?? null));
        $fecha = Normalizador::fecha($valores['fecha'] ?? null);
        if ($numero === '' && $fecha === null && trim((string) ($valores['cantidad'] ?? '')) === '') {
            return null;
        }
        $errores = [];
        $advertencias = [];
        $contrato = array_key_exists('id_externo', $valores);
        $idExterno = Normalizador::texto($valores['id_externo'] ?? null);
        if ($contrato && $idExterno === null) {
            self::error($errores, 'id_externo', 'Una salida del contrato de integración exige id_externo.');
        }
        if ($fecha === null) {
            self::error($errores, 'fecha', 'La fecha de venta no es válida.');
        }
        $codigo = Normalizador::soloDigitos($valores['codigo'] ?? '');
        $recepcion = $this->recepcion($contexto, $numero, $codigo);
        $producto = $codigo !== '' ? $contexto->producto($codigo) : null;
        if ($producto === null && $recepcion !== null) {
            $producto = $contexto->bd->fila('SELECT id, codigo_sgac, nombre, medida FROM productos WHERE id = :p', ['p' => $recepcion['producto_id']]);
        }
        $prefijo = explode('-', $numero)[0];
        $unidad = $recepcion !== null ? (int) $recepcion['unidad_id'] : ($contexto->unidadPorPrefijo($prefijo) ?? $contexto->unidadId('SIN'));
        self::exigirUnidad($contexto, $unidad, 'numero', $errores);
        $medida = Normalizador::medida($valores['medida'] ?? null) ?? (Normalizador::texto($valores['medida'] ?? null) === null ? ($producto['medida'] ?? null) : null);
        if ($medida === null) {
            self::error($errores, 'medida', 'La unidad de medida no se reconoce (Litros, Kilos, Unidad, Libra, Cubeta).');
        }
        $textoMedida = Normalizador::texto($valores['medida'] ?? null);
        if ($textoMedida !== null && in_array(\App\Servicios\ServicioCatalogos::normalizarConcepto($textoMedida), Normalizador::PRESENTACIONES, true)) {
            $advertencias[] = "La medida «{$textoMedida}» se registra como unidad (ud).";
        }
        $cantidad = Normalizador::numero($valores['cantidad'] ?? null, 3);
        if ($cantidad === null || Entrada::comparar($cantidad, '0.000') <= 0) {
            self::error($errores, 'cantidad', 'La cantidad vendida debe ser mayor que 0.');
        }
        $precio = Normalizador::numero($valores['precio'] ?? null);
        if ($precio === null) {
            self::error($errores, 'precio', 'El precio unitario no es un número mayor o igual que 0.');
        }
        $valor = $cantidad !== null && $precio !== null ? Entrada::valorSalida($cantidad, $precio) : null;
        $subtotal = Normalizador::numero($valores['subtotal'] ?? null);
        if ($valor !== null && $subtotal !== null && Entrada::comparar($valor, $subtotal) !== 0) {
            $advertencias[] = "El subtotal de la fuente ({$subtotal}) difiere de cantidad × precio ({$valor}); se usa el calculado.";
        }
        $metodo = strtoupper((string) Normalizador::texto($valores['metodo'] ?? null));
        $canal = $metodo === 'AUTOCONSUMO' ? 'AUTOCONSUMO' : 'VENTA_DIRECTA';
        $origen = strtoupper((string) Normalizador::texto($valores['origen'] ?? null));
        $proceso = null;
        if (preg_match('/^AUTO_\d+$/', $origen) === 1 && $fecha !== null) {
            $proceso = $contexto->bd->valor(
                'SELECT id FROM procesos_autoconsumo WHERE centro_id = :c AND codigo = :k AND vigencia = :v AND archivado_en IS NULL',
                ['c' => $contexto->centroId, 'k' => $origen, 'v' => (int) substr($fecha, 0, 4)]
            );
            if ($proceso === null) {
                $advertencias[] = "El proceso {$origen} no existe en la vigencia; la entrega queda sin proceso.";
            }
        }
        $transaccion = Normalizador::texto($valores['transaccion'] ?? null);
        if ($transaccion !== null && Normalizador::encabezado($transaccion) === 'no aplica') {
            $transaccion = null;
        }
        $datos = [
            'unidad_id' => $unidad, 'recepcion_id' => $recepcion === null ? null : (int) $recepcion['id'],
            'numero_interno_texto' => $numero === '' ? null : mb_substr($numero, 0, 40), 'fecha' => $fecha,
            'producto_id' => $producto === null ? null : (int) $producto['id'],
            'nombre_producto_fuente' => ($n = Normalizador::texto($valores['nombre'] ?? null)) === null ? null : mb_substr($n, 0, 200),
            'cantidad' => $cantidad, 'medida' => $medida, 'precio_unitario' => $precio, 'valor' => $valor, 'canal' => $canal,
            'proceso_id' => $proceso === null ? null : (int) $proceso,
            'destino' => self::destino($valores['cliente'] ?? null),
            'factura' => ($f = Normalizador::texto($valores['factura'] ?? null)) === null ? null : mb_substr($f, 0, 40),
            'referencia_transaccion' => $transaccion === null ? null : mb_substr($transaccion, 0, 80),
            'id_externo' => $idExterno === null ? null : mb_substr($idExterno, 0, 80),
        ];
        if ($recepcion === null) {
            $advertencias[] = "El número interno «{$numero}» no corresponde a una recepción: la salida queda sin vínculo, para la bandeja de calidad.";
        }
        if ($contrato) {
            $clave = 'ext|' . $idExterno;
        } else {
            $base = hash('sha256', implode('|', [$numero, $fecha, $codigo, $cantidad, $precio, $metodo, $origen, $transaccion, $datos['factura']]));
            $contexto->ocurrencias[$base] = ($contexto->ocurrencias[$base] ?? 0) + 1;
            $clave = $base . '#' . $contexto->ocurrencias[$base];
        }
        $datos['clave_natural'] = hash('sha256', $clave);
        return ['datos' => $datos, 'clave' => $clave, 'errores' => $errores, 'advertencias' => $advertencias];
    }

    public function actual(ContextoImportacion $contexto, array $datos): ?array
    {
        return $contexto->bd->fila('SELECT id FROM salidas WHERE centro_id = :c AND clave_natural = :k', ['c' => $contexto->centroId, 'k' => $datos['clave_natural']]);
    }

    public function aplicar(ContextoImportacion $contexto, array $datos, ?array $actual, array $procedencia): int
    {
        if ($actual !== null) {
            return (int) $actual['id'];
        }
        // El vínculo se resuelve otra vez al aplicar: la base de producción pudo aplicarse después de validar esta.
        if ($datos['recepcion_id'] === null && $datos['numero_interno_texto'] !== null) {
            $recepcion = $this->recepcion($contexto, (string) $datos['numero_interno_texto'], '');
            if ($recepcion !== null && (int) $recepcion['unidad_id'] === (int) $datos['unidad_id']) {
                $datos['recepcion_id'] = (int) $recepcion['id'];
                $datos['producto_id'] ??= (int) $recepcion['producto_id'];
            }
        }
        return $this->insertarFila($contexto->bd, $datos + ['centro_id' => $contexto->centroId], $procedencia, $contexto->usuarioId);
    }

    public function dependencias(BaseDatos $bd, int $id, int $loteId): ?string
    {
        $transformaciones = (int) $bd->valor('SELECT COUNT(*) FROM transformaciones WHERE salida_leche_id = :s AND archivado_en IS NULL', ['s' => $id]);
        $conciliaciones = (int) $bd->valor('SELECT COUNT(*) FROM conciliaciones_recaudo WHERE salida_id = :s AND archivado_en IS NULL', ['s' => $id]);
        return $transformaciones + $conciliaciones > 0 ? "La salida {$id} ya alimenta transformaciones o conciliaciones." : null;
    }

    /**
     * Vínculo único por número interno y código; sin código, solo si el número identifica un único lote.
     *
     * @return array<string,mixed>|null
     */
    private function recepcion(ContextoImportacion $contexto, string $numero, string $codigo): ?array
    {
        if ($numero === '') {
            return null;
        }
        $filas = $contexto->bd->consultar(
            'SELECT r.id, r.unidad_id, r.producto_id, p.codigo_sgac FROM recepciones_lote r JOIN productos p ON p.id = r.producto_id
              WHERE r.centro_id = :c AND r.numero_interno = :n AND r.archivado_en IS NULL',
            ['c' => $contexto->centroId, 'n' => $numero]
        );
        if ($codigo !== '') {
            $filas = array_values(array_filter($filas, static fn (array $f): bool => (string) $f['codigo_sgac'] === $codigo));
        }
        return count($filas) === 1 ? $filas[0] : null;
    }

    /** El destino nunca guarda una identificación personal en claro: solo sus 4 últimos dígitos. */
    private static function destino(mixed $valor): ?string
    {
        $texto = Normalizador::texto($valor);
        if ($texto === null || Normalizador::encabezado($texto) === 'up destino y o cliente') {
            return null;
        }
        $digitos = Normalizador::soloDigitos($texto);
        if ($digitos !== '' && strlen($digitos) >= 5 && strlen($digitos) <= 15 && preg_match('/^[\d .\-]+$/', $texto) === 1) {
            return 'Cliente ****' . substr($digitos, -4);
        }
        return mb_substr($texto, 0, 150);
    }
}
