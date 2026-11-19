<?php

declare(strict_types=1);

namespace App\Operacion;

use App\Nucleo\BaseDatos;
use App\Nucleo\Entrada;
use RuntimeException;

/**
 * Escenario sintético de capacitación (RF-100, D-19): el mismo escenario demostrativo del prototipo, reproducible y
 * separado de los datos reales. En la vigencia 2027 deja en Ganadería, entre otros:
 *   - AUTO_001 con 3.200 L entregados de 4.000 (80 %);
 *   - cuatro transformaciones lácteas con un aprovechamiento global del 97 % (IND_08);
 *   - IND_17 de 1.337,5 COP/L: 16.000 L, gasto de 24.400.000 COP y componente académico de 3.000.000 COP;
 *   - la asignación de 185.000.000 COP.
 * Las cifras son los oráculos de 2.10 («Escenario sintético de capacitación»); OraculosIndicadoresTest las verifica
 * sobre este mismo generador.
 *
 * Nunca se ejecuta con APP_ENTORNO=produccion y exige que el centro no tenga recepciones ni salidas.
 */
final class GeneradorCapacitacion
{
    /** clave => [código, nombre, medida, rotación, factor de leche, máximo de la meta] */
    private const PRODUCTOS = [
        'leche' => ['5013170101', 'Leche de vaca', 'L', 'fresco', null, null],
        'bovino' => ['1010150100', 'Bovino en pie', 'kg', 'animal', null, null],
        'queso' => ['DEMO-QUESO', 'Queso cuajada DEMO', 'kg', 'fresco', 8.0, 8.5],
        'yogurt' => ['DEMO-YOGURT', 'Yogurt DEMO', 'L', 'fresco', 1.0, 1.05],
        'manjar' => ['DEMO-MANJAR', 'Manjar blanco DEMO', 'kg', 'fresco', 3.4, 3.6],
        'cortado' => ['DEMO-CORTADO', 'Cortado DEMO', 'kg', 'fresco', 4.0, 4.2],
    ];

    private int $secuencia = 0;
    /** @var array<string,int> */
    private array $productos = [];
    /** @var array<string,int> */
    private array $unidades = [];

    public function __construct(private readonly BaseDatos $bd, private readonly string $entorno)
    {
    }

    /**
     * @return array{productos:array<string,int>,auto_001:int,lote_leche_1:int,salida_auto_1:int,salida_venta_1:int}
     */
    public function generar(int $centroId, int $vigencia = 2027): array
    {
        if ($this->entorno === 'produccion') {
            throw new RuntimeException('El escenario de capacitación nunca se genera en producción (D-19).');
        }
        if ((int) $this->bd->valor('SELECT COUNT(*) FROM recepciones_lote WHERE centro_id = :c', ['c' => $centroId]) + (int) $this->bd->valor('SELECT COUNT(*) FROM salidas WHERE centro_id = :c', ['c' => $centroId]) > 0) {
            throw new RuntimeException('El centro ya tiene recepciones o salidas: el escenario se genera sobre una base de capacitación vacía.');
        }
        foreach ($this->bd->consultar('SELECT id, codigo FROM unidades_productivas WHERE centro_id = :c', ['c' => $centroId]) as $u) {
            $this->unidades[(string) $u['codigo']] = (int) $u['id'];
        }
        return $this->bd->transaccion(function () use ($centroId, $vigencia): array {
            $this->crearProductos($centroId);
            $gan = $this->unidad('GAN');
            foreach ([['AUTO_001', 'leche', 4000, 'L', 7200000], ['AUTO_002', 'bovino', 1000, 'kg', 8000000]] as [$codigo, $producto, $cupo, $medida, $valor]) {
                $this->bd->ejecutar(
                    "INSERT INTO procesos_autoconsumo (centro_id, unidad_id, codigo, vigencia, producto_id, producto_texto, cupo_cantidad, medida, cupo_valor, destino_texto)
                     VALUES (:c, :u, :cod, :vig, :p, :t, :q, :m, :v, 'Agroindustria')",
                    ['c' => $centroId, 'u' => $gan, 'cod' => $codigo, 'vig' => $vigencia, 'p' => $this->productos[$producto], 't' => $producto, 'q' => $cupo, 'm' => $medida, 'v' => $valor]
                );
            }
            $ids = ['productos' => $this->productos];
            $ids['auto_001'] = (int) $this->bd->valor("SELECT id FROM procesos_autoconsumo WHERE centro_id = :c AND codigo = 'AUTO_001' AND vigencia = :v", ['c' => $centroId, 'v' => $vigencia]);
            $auto002 = (int) $this->bd->valor("SELECT id FROM procesos_autoconsumo WHERE centro_id = :c AND codigo = 'AUTO_002' AND vigencia = :v", ['c' => $centroId, 'v' => $vigencia]);
            $derivados = [['queso', 8.0], ['yogurt', 1.0], ['manjar', 3.4], ['cortado', 4.0]];
            for ($i = 1; $i <= 4; $i++) {
                $fecha = sprintf('%d-04-%02d', $vigencia, $i * 5);
                $siguiente = sprintf('%d-04-%02d', $vigencia, $i * 5 + 1);
                $lote = $this->recepcion($centroId, 'GAN', 'GAN-REC-DEMO-00' . $i, $fecha, 'leche', '4000', 'L');
                $auto = $this->salida($centroId, 'GAN', $fecha, 'leche', '800', '1800', 'AUTOCONSUMO', $lote, $ids['auto_001']);
                $venta = $this->salida($centroId, 'GAN', $siguiente, 'leche', '3200', '2200', 'VENTA_DIRECTA', $lote);
                if ($i === 1) {
                    $ids += ['lote_leche_1' => $lote, 'salida_auto_1' => $auto, 'salida_venta_1' => $venta];
                }
                [$producto, $factor] = $derivados[$i - 1];
                $cantidad = number_format(800 * 0.97 / $factor, 3, '.', '');
                $derivado = $this->recepcion($centroId, 'IND', 'IND-REC-DEMO-00' . $i, $siguiente, $producto, $cantidad, $producto === 'yogurt' ? 'L' : 'kg');
                $this->bd->ejecutar(
                    'INSERT INTO transformaciones (centro_id, unidad_id, fecha, salida_leche_id, recepcion_derivado_id, litros_asignados) VALUES (:c, :u, :f, :s, :r, 800)',
                    ['c' => $centroId, 'u' => $gan, 'f' => $siguiente, 's' => $auto, 'r' => $derivado]
                );
            }
            $bovino = $this->recepcion($centroId, 'GAN', 'GAN-REC-DEMO-BOV', "{$vigencia}-04-01", 'bovino', '650', 'kg');
            $this->salida($centroId, 'GAN', "{$vigencia}-04-19", 'bovino', '650', '8000', 'AUTOCONSUMO', $bovino, $auto002);
            $categorias = array_column($this->bd->consultar('SELECT id, codigo FROM categorias_gasto WHERE centro_id = :c', ['c' => $centroId]), 'id', 'codigo');
            foreach ([['ALIMENTACION', 18000000, 2200000], ['MANTENIMIENTO', 6400000, 800000], ['MATERIALES_FORMACION', 1000000, 0]] as [$categoria, $valor, $academico]) {
                if (!isset($categorias[$categoria])) {
                    throw new RuntimeException("Falta la categoría de gasto {$categoria}: aplica las semillas antes del escenario.");
                }
                $this->bd->ejecutar(
                    'INSERT INTO gastos_ejecutados (centro_id, unidad_id, fecha, categoria_id, valor, valor_componente_academico) VALUES (:c, :u, :f, :cat, :v, :a)',
                    ['c' => $centroId, 'u' => $gan, 'f' => "{$vigencia}-04-30", 'cat' => $categorias[$categoria], 'v' => $valor, 'a' => $academico]
                );
            }
            $this->bd->ejecutar(
                'INSERT INTO asignaciones_presupuestales (centro_id, unidad_id, vigencia, valor_total, mantenimiento, alimentacion, materiales_formacion) VALUES (:c, :u, :v, 185000000, 45000000, 80000000, 60000000)',
                ['c' => $centroId, 'u' => $gan, 'v' => $vigencia]
            );
            return $ids;
        });
    }

    private function crearProductos(int $centroId): void
    {
        foreach (self::PRODUCTOS as $clave => [$codigo, $nombre, $medida, $rotacion, $factor, $maximo]) {
            $existente = $this->bd->valor('SELECT id FROM productos WHERE centro_id = :c AND codigo_sgac = :s', ['c' => $centroId, 's' => $codigo]);
            if ($existente === null) {
                $this->bd->ejecutar(
                    'INSERT INTO productos (centro_id, codigo_sgac, nombre, medida, categoria_rotacion, es_derivado_lacteo, factor_leche, meta_rendimiento_min, meta_rendimiento_max)
                     VALUES (:c, :s, :n, :m, :r, :d, :f, :min, :max)',
                    ['c' => $centroId, 's' => $codigo, 'n' => $nombre, 'm' => $medida, 'r' => $rotacion, 'd' => $factor === null ? 0 : 1, 'f' => $factor, 'min' => $factor, 'max' => $maximo]
                );
                $existente = $this->bd->valor('SELECT id FROM productos WHERE centro_id = :c AND codigo_sgac = :s', ['c' => $centroId, 's' => $codigo]);
            }
            $this->productos[$clave] = (int) $existente;
        }
    }

    private function unidad(string $codigo): int
    {
        return $this->unidades[$codigo] ?? throw new RuntimeException("Falta la unidad {$codigo}: aplica las semillas antes del escenario.");
    }

    private function recepcion(int $centroId, string $unidad, string $numero, string $fecha, string $producto, string $cantidad, string $medida): int
    {
        return $this->bd->insertar(
            'INSERT INTO recepciones_lote (centro_id, unidad_id, numero_interno, fecha_recepcion, producto_id, cantidad, medida) VALUES (:c, :u, :n, :f, :p, :q, :m)',
            ['c' => $centroId, 'u' => $this->unidad($unidad), 'n' => $numero, 'f' => $fecha, 'p' => $this->productos[$producto], 'q' => $cantidad, 'm' => $medida]
        );
    }

    private function salida(int $centroId, string $unidad, string $fecha, string $producto, string $cantidad, string $precio, string $canal, ?int $recepcion = null, ?int $proceso = null): int
    {
        $this->secuencia++;
        $medida = (string) $this->bd->valor('SELECT medida FROM productos WHERE id = :p', ['p' => $this->productos[$producto]]);
        return $this->bd->insertar(
            'INSERT INTO salidas (centro_id, unidad_id, recepcion_id, fecha, producto_id, cantidad, medida, precio_unitario, valor, canal, proceso_id, clave_natural)
             VALUES (:c, :u, :r, :f, :p, :q, :m, :pu, :v, :canal, :proceso, :k)',
            ['c' => $centroId, 'u' => $this->unidad($unidad), 'r' => $recepcion, 'f' => $fecha, 'p' => $this->productos[$producto], 'q' => $cantidad, 'm' => $medida,
                'pu' => $precio, 'v' => Entrada::valorSalida($cantidad, $precio), 'canal' => $canal, 'proceso' => $proceso, 'k' => hash('sha256', "capacitacion-salida-{$centroId}-{$this->secuencia}")]
        );
    }
}
