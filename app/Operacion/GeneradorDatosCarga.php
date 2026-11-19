<?php

declare(strict_types=1);

namespace App\Operacion;

use App\Nucleo\BaseDatos;
use App\Nucleo\GeneradorUlid;
use App\Servicios\ServicioAuditoria;
use RuntimeException;

/**
 * Dataset sintético para las pruebas de carga y el inventario de consultas (RNF-PERF-016; Anexo H).
 *
 * Escala «3a»: 3 vigencias del centro 9124 con unas 36.000 salidas, 9.000 recaudos, 9.000 recepciones y 750.000 eventos
 * de auditoría, con la distribución por unidad del piloto (Ganadería concentra el volumen), más un segundo centro
 * (código 9999) con el 10 % del volumen para verificar el aislamiento. «1a» es un tercio y «mini» el 1 % (pruebas y CI).
 *
 *   - Nunca se ejecuta con APP_ENTORNO=produccion (RDL-009) y exige las tablas de negocio vacías.
 *   - Es determinista en las cifras de negocio: la misma semilla da los mismos conteos y sumas, así que los informes
 *     de carga son comparables (los ULID y los hashes de la auditoría sí cambian).
 *   - Los eventos de auditoría se insertan ya sellados con la misma fórmula de ServicioAuditoria, así que la
 *     verificación de la cadena sobre el dataset también prueba el sellado a escala.
 *   - Sin datos personales: correos @carga.invalid, nombres «Usuario de carga N», sin identificaciones de pagadores.
 */
final class GeneradorDatosCarga
{
    public const ESCALAS = ['3a' => 1.0, '1a' => 0.3334, 'mini' => 0.01];

    /** Volumen anual del centro piloto a escala 1 (2.6 del SRS). */
    private const POR_ANIO = ['recepciones' => 3000, 'salidas_por_recepcion' => 4, 'recaudos' => 3000, 'gastos' => 800, 'auditoria' => 250000];

    /** Distribución del volumen por unidad, como en el piloto. */
    private const PESOS = ['GAN' => 35, 'GRA' => 15, 'AGR' => 10, 'HOR' => 10, 'IND' => 10, 'INV' => 5, 'GOU' => 5, 'CAF' => 4, 'API' => 3, 'LAM' => 2, 'COM' => 1];

    private const ACCIONES = ['tablero_central.consultar', 'salidas.crear', 'recepciones.crear', 'recaudos.clasificar', 'gastos.crear', 'sesion.ingresar', 'exportaciones.solicitar'];

    private const MEDIDAS = ['GAN' => 'L', 'GRA' => 'ud', 'AGR' => 'kg', 'HOR' => 'kg', 'IND' => 'ud', 'INV' => 'kg', 'GOU' => 'ud', 'CAF' => 'ud', 'API' => 'kg', 'LAM' => 'ud', 'COM' => 'ud'];

    /** @var callable(string):void */
    private $progreso;

    /** @param callable(string):void|null $progreso */
    public function __construct(private readonly BaseDatos $bd, private readonly string $entorno, ?callable $progreso = null)
    {
        $this->progreso = $progreso ?? static function (string $mensaje): void {
        };
    }

    /**
     * @param (callable(string):string)|null $hashear hash de contraseña para las cuentas de k6 (Argon2id de la app)
     * @return array{resumen:array<string,int>,usuarios:list<array<string,mixed>>}
     */
    public function generar(string $escala, int $anioFinal, int $semilla = 20261119, int $cuentas = 0, ?callable $hashear = null, string $contrasena = ''): array
    {
        if ($this->entorno === 'produccion') {
            throw new RuntimeException('El generador de datos sintéticos no se ejecuta en producción (RDL-009).');
        }
        if (!isset(self::ESCALAS[$escala])) {
            throw new RuntimeException('Escala no válida: usa 3a, 1a o mini.');
        }
        foreach (['salidas', 'recaudos', 'recepciones_lote'] as $tabla) {
            if ((int) $this->bd->valor("SELECT COUNT(*) FROM {$tabla}") > 0) {
                throw new RuntimeException("La tabla {$tabla} ya tiene datos: el dataset se genera sobre una base recién migrada y sembrada.");
            }
        }
        mt_srand($semilla);
        $factor = self::ESCALAS[$escala];
        $piloto = (int) ($this->bd->valor("SELECT id FROM centros WHERE codigo = '9124'") ?? throw new RuntimeException('Falta el centro 9124: ejecuta cli/sembrar.php.'));
        $verificacion = $this->segundoCentro($piloto);
        $resumen = ['salidas' => 0, 'recaudos' => 0, 'recepciones' => 0, 'gastos' => 0, 'asignaciones' => 0, 'procesos' => 0, 'auditoria' => 0, 'productos' => 0];
        $centros = [$piloto => $factor, $verificacion => $factor * 0.1];
        foreach ($centros as $centro => $escalaCentro) {
            $unidades = $this->unidades($centro);
            $productos = $this->productos($centro, $unidades);
            $resumen['productos'] += array_sum(array_map('count', $productos));
            for ($anio = $anioFinal - 2; $anio <= $anioFinal; $anio++) {
                ($this->progreso)("Centro {$centro}, vigencia {$anio}…");
                $resumen['asignaciones'] += $this->asignaciones($centro, $unidades, $anio);
                $procesos = $this->procesos($centro, $unidades, $productos, $anio);
                $resumen['procesos'] += count($procesos);
                [$recepciones, $salidas] = $this->recepcionesYSalidas($centro, $unidades, $productos, $procesos, $anio, $escalaCentro);
                $resumen['recepciones'] += $recepciones;
                $resumen['salidas'] += $salidas;
                $resumen['recaudos'] += $this->recaudos($centro, $unidades, $productos, $anio, $escalaCentro);
                $resumen['gastos'] += $this->gastos($centro, $unidades, $anio, $escalaCentro);
                $resumen['auditoria'] += $this->auditoria($centro, $unidades, $anio, $escalaCentro);
            }
            $this->bd->ejecutar('INSERT INTO versiones_datos (centro_id, version) VALUES (:c, 1) ON DUPLICATE KEY UPDATE version = version + 1', ['c' => $centro]);
        }
        $usuarios = $cuentas > 0 && $hashear !== null ? $this->cuentasDeCarga($piloto, $verificacion, $cuentas, $hashear, $contrasena) : [];
        return ['resumen' => $resumen, 'usuarios' => $usuarios];
    }

    private function segundoCentro(int $piloto): int
    {
        $this->bd->ejecutar("INSERT IGNORE INTO centros (codigo, nombre, regional, codigo_regional) VALUES ('9999', 'Centro de verificación de aislamiento', 'Valle', '76')");
        $centro = (int) $this->bd->valor("SELECT id FROM centros WHERE codigo = '9999'");
        foreach (['GAN' => ['Ganadería', 'pecuaria'], 'AGR' => ['Agrícola', 'agricola'], 'IND' => ['Agroindustria', 'agroindustrial']] as $codigo => [$nombre, $tipo]) {
            $this->bd->ejecutar(
                'INSERT IGNORE INTO unidades_productivas (centro_id, codigo, nombre, prefijo_lote, tipo) VALUES (:c, :codigo, :n, :p, :t)',
                ['c' => $centro, 'codigo' => $codigo, 'n' => $nombre, 'p' => $codigo, 't' => $tipo]
            );
        }
        $this->bd->ejecutar(
            'INSERT IGNORE INTO categorias_gasto (centro_id, codigo, nombre, linea_presupuestal, incluye_ind17)
             SELECT :destino, codigo, nombre, linea_presupuestal, incluye_ind17 FROM categorias_gasto WHERE centro_id = :origen',
            ['destino' => $centro, 'origen' => $piloto]
        );
        return $centro;
    }

    /** @return array<string,int> código => id, sin la unidad técnica SIN */
    private function unidades(int $centro): array
    {
        $unidades = [];
        foreach ($this->bd->consultar("SELECT id, codigo FROM unidades_productivas WHERE centro_id = :c AND codigo <> 'SIN' ORDER BY id", ['c' => $centro]) as $fila) {
            $unidades[(string) $fila['codigo']] = (int) $fila['id'];
        }
        return $unidades;
    }

    /**
     * @param array<string,int> $unidades
     * @return array<string,list<array{id:int,medida:string}>> productos por unidad
     */
    private function productos(int $centro, array $unidades): array
    {
        $productos = [];
        $n = 0;
        foreach ($unidades as $codigo => $unidad) {
            $medida = self::MEDIDAS[$codigo] ?? 'ud';
            for ($i = 1; $i <= 3; $i++) {
                $n++;
                $sgac = sprintf('CG-%s-%02d', $codigo, $i);
                $this->bd->ejecutar(
                    'INSERT IGNORE INTO productos (centro_id, codigo_sgac, nombre, medida, unidad_sugerida_id, precio_venta, origen_tipo) VALUES (:c, :s, :n, :m, :u, :p, \'integracion\')',
                    ['c' => $centro, 's' => $sgac, 'n' => "Producto sintético {$codigo} {$i}", 'm' => $medida, 'u' => $unidad, 'p' => (string) (mt_rand(2, 40) * 500)]
                );
                $productos[$codigo][] = ['id' => (int) $this->bd->valor('SELECT id FROM productos WHERE centro_id = :c AND codigo_sgac = :s', ['c' => $centro, 's' => $sgac]), 'medida' => $medida];
            }
        }
        return $productos;
    }

    /** @param array<string,int> $unidades */
    private function asignaciones(int $centro, array $unidades, int $anio): int
    {
        $filas = [];
        foreach ($unidades as $unidad) {
            $total = mt_rand(20, 200) * 1000000;
            $filas[] = [$centro, $unidad, $anio, $total, intdiv($total * 3, 10), intdiv($total * 3, 10), intdiv($total * 2, 10), 'integracion'];
        }
        $this->insertarLote('asignaciones_presupuestales', ['centro_id', 'unidad_id', 'vigencia', 'valor_total', 'mantenimiento', 'alimentacion', 'materiales_formacion', 'origen_tipo'], $filas);
        return count($filas);
    }

    /**
     * @param array<string,int> $unidades
     * @param array<string,list<array{id:int,medida:string}>> $productos
     * @return array<string,int> código de unidad => id del proceso
     */
    private function procesos(int $centro, array $unidades, array $productos, int $anio): array
    {
        $procesos = [];
        $n = 0;
        foreach (array_slice($unidades, 0, 6, true) as $codigo => $unidad) {
            $n++;
            $producto = $productos[$codigo][0];
            $codigoProceso = sprintf('AUTO_%03d', $n);
            $this->bd->ejecutar(
                "INSERT INTO procesos_autoconsumo (centro_id, unidad_id, codigo, vigencia, destino_texto, producto_id, producto_texto, cupo_cantidad, medida, cupo_valor, origen_tipo)
                 VALUES (:c, :u, :codigo, :v, 'Bienestar al Aprendiz', :p, :pt, :cc, :m, :cv, 'integracion')",
                ['c' => $centro, 'u' => $unidad, 'codigo' => $codigoProceso, 'v' => $anio, 'p' => $producto['id'], 'pt' => "Producto sintético {$codigo} 1", 'cc' => '5000', 'm' => $producto['medida'], 'cv' => (string) (mt_rand(5, 40) * 1000000)]
            );
            $procesos[$codigo] = (int) $this->bd->valor('SELECT id FROM procesos_autoconsumo WHERE centro_id = :c AND codigo = :codigo AND vigencia = :v', ['c' => $centro, 'codigo' => $codigoProceso, 'v' => $anio]);
        }
        return $procesos;
    }

    /**
     * @param array<string,int> $unidades
     * @param array<string,list<array{id:int,medida:string}>> $productos
     * @param array<string,int> $procesos
     * @return array{0:int,1:int}
     */
    private function recepcionesYSalidas(int $centro, array $unidades, array $productos, array $procesos, int $anio, float $escala): array
    {
        $total = max(1, (int) round(self::POR_ANIO['recepciones'] * $escala));
        $totalRecaudos = max(1, (int) round(self::POR_ANIO['recaudos'] * $escala));
        $siguiente = (int) $this->bd->valor('SELECT COALESCE(MAX(id), 0) FROM recepciones_lote') + 1;
        $recepciones = [];
        $salidas = [];
        $contadorSalidas = 0;
        for ($i = 0; $i < $total; $i++) {
            $codigo = $this->unidadAlAzar($unidades);
            $producto = $productos[$codigo][mt_rand(0, count($productos[$codigo]) - 1)];
            $dia = mt_rand(0, 330);
            $fecha = date('Y-m-d', (int) strtotime("{$anio}-01-01 +{$dia} days"));
            $cantidad = mt_rand(20, 400);
            $id = $siguiente++;
            $recepciones[] = [$id, $centro, $unidades[$codigo], sprintf('%s-REC-%d-%05d', $codigo, $anio, $i + 1), $fecha, $producto['id'], $cantidad, $producto['medida'], 'integracion'];
            $restante = $cantidad;
            $numeroSalidas = mt_rand(2, 2 * self::POR_ANIO['salidas_por_recepcion'] - 2);
            for ($s = 0; $s < $numeroSalidas && $restante > 0; $s++) {
                $cantidadSalida = min($restante, max(1, intdiv($cantidad, $numeroSalidas)));
                $restante -= $cantidadSalida;
                $precio = mt_rand(2, 40) * 500;
                $autoconsumo = isset($procesos[$codigo]) && mt_rand(1, 100) <= 15;
                $fechaSalida = date('Y-m-d', (int) strtotime("{$fecha} +" . mt_rand(0, 25) . ' days'));
                $fechaSalida = min($fechaSalida, "{$anio}-12-31");
                $contadorSalidas++;
                $salidas[] = [
                    $centro, $unidades[$codigo], $id, $fechaSalida, $producto['id'], $cantidadSalida, $producto['medida'], $precio, $cantidadSalida * $precio,
                    $autoconsumo ? 'AUTOCONSUMO' : 'VENTA_DIRECTA', $autoconsumo ? $procesos[$codigo] : null,
                    // El 30 % de las ventas lleva la prefactura de un recaudo del año: conciliación y bandeja representativas.
                    !$autoconsumo && mt_rand(1, 100) <= 30 ? sprintf('PF-%d-%06d', $anio, mt_rand(1, $totalRecaudos)) : null,
                    hash('sha256', "carga|salida|{$centro}|{$anio}|{$contadorSalidas}"), 'integracion',
                ];
            }
            if (count($recepciones) >= 1000) {
                $this->volcarRecepcionesYSalidas($recepciones, $salidas);
            }
        }
        $this->volcarRecepcionesYSalidas($recepciones, $salidas);
        return [$total, $contadorSalidas];
    }

    /**
     * @param list<list<scalar|null>> $recepciones
     * @param list<list<scalar|null>> $salidas
     */
    private function volcarRecepcionesYSalidas(array &$recepciones, array &$salidas): void
    {
        $this->bd->transaccion(function () use ($recepciones, $salidas): void {
            $this->insertarLote('recepciones_lote', ['id', 'centro_id', 'unidad_id', 'numero_interno', 'fecha_recepcion', 'producto_id', 'cantidad', 'medida', 'origen_tipo'], $recepciones);
            foreach (array_chunk($salidas, 1000) as $bloque) {
                $this->insertarLote('salidas', ['centro_id', 'unidad_id', 'recepcion_id', 'fecha', 'producto_id', 'cantidad', 'medida', 'precio_unitario', 'valor', 'canal', 'proceso_id', 'factura', 'clave_natural', 'origen_tipo'], $bloque);
            }
        });
        $recepciones = [];
        $salidas = [];
    }

    /**
     * @param array<string,int> $unidades
     * @param array<string,list<array{id:int,medida:string}>> $productos
     */
    private function recaudos(int $centro, array $unidades, array $productos, int $anio, float $escala): int
    {
        $total = max(1, (int) round(self::POR_ANIO['recaudos'] * $escala));
        $filas = [];
        for ($i = 1; $i <= $total; $i++) {
            $codigo = $this->unidadAlAzar($unidades);
            $sinUnidad = mt_rand(1, 100) <= 3;
            $fecha = date('Y-m-d', (int) strtotime("{$anio}-01-01 +" . mt_rand(0, 364) . ' days'));
            $filas[] = [
                $centro, $sinUnidad ? null : $unidades[$codigo], $sinUnidad ? null : $productos[$codigo][0]['id'], $fecha, mt_rand(4, 400) * 500,
                sprintf('CUP-%d-%06d', $anio, $i), sprintf('PF-%d-%06d', $anio, $i), mt_rand(1, 100) <= 92 ? 'Producción' : 'Servicios',
                $sinUnidad ? 'pendiente' : 'clasificado', 'Wompi', hash('sha256', "carga|recaudo|{$centro}|{$anio}|{$i}"), 'integracion',
            ];
            if (count($filas) === 1000) {
                $this->insertarRecaudos($filas);
            }
        }
        $this->insertarRecaudos($filas);
        return $total;
    }

    /** @param list<list<scalar|null>> $filas */
    private function insertarRecaudos(array &$filas): void
    {
        $this->insertarLote('recaudos', ['centro_id', 'unidad_id', 'producto_id', 'fecha_pago', 'valor', 'cupon_pago', 'prefactura', 'tipo_ingreso', 'estado_clasificacion', 'pasarela', 'clave_natural', 'origen_tipo'], $filas);
        $filas = [];
    }

    /** @param array<string,int> $unidades */
    private function gastos(int $centro, array $unidades, int $anio, float $escala): int
    {
        $categorias = array_map('intval', array_column($this->bd->consultar('SELECT id FROM categorias_gasto WHERE centro_id = :c', ['c' => $centro]), 'id'));
        if ($categorias === []) {
            return 0;
        }
        $total = max(1, (int) round(self::POR_ANIO['gastos'] * $escala));
        $filas = [];
        for ($i = 1; $i <= $total; $i++) {
            $valor = mt_rand(1, 300) * 10000;
            $filas[] = [$centro, $unidades[$this->unidadAlAzar($unidades)], date('Y-m-d', (int) strtotime("{$anio}-01-01 +" . mt_rand(0, 364) . ' days')), $categorias[mt_rand(0, count($categorias) - 1)], $valor, 0, 'Gasto sintético de carga', 'integracion'];
        }
        foreach (array_chunk($filas, 1000) as $bloque) {
            $this->insertarLote('gastos_ejecutados', ['centro_id', 'unidad_id', 'fecha', 'categoria_id', 'valor', 'valor_componente_academico', 'descripcion', 'origen_tipo'], $bloque);
        }
        return $total;
    }

    /**
     * Eventos de auditoría ya sellados, encadenados tras el último sello existente.
     *
     * @param array<string,int> $unidades
     */
    private function auditoria(int $centro, array $unidades, int $anio, float $escala): int
    {
        $total = max(1, (int) round(self::POR_ANIO['auditoria'] * $escala));
        $columnas = ['id', 'evento_id', 'ocurrido_en', 'actor_rol', 'centro_id', 'unidad_id', 'accion', 'recurso', 'recurso_id', 'resultado', 'origen', 'secuencia_sello', 'hash_anterior', 'hash_evento'];
        $hechos = 0;
        $inicioAnio = (int) strtotime("{$anio}-01-01 00:00:00 UTC");
        while ($hechos < $total) {
            $bloque = min(2000, $total - $hechos);
            $this->bd->transaccion(function (BaseDatos $bd) use ($bloque, $centro, $unidades, $inicioAnio, $columnas, $hechos, $total): void {
                $control = $bd->fila('SELECT ultima_secuencia, ultimo_hash FROM cadena_auditoria WHERE id = 1 FOR UPDATE') ?? throw new RuntimeException('Falta el control de la cadena.');
                $secuencia = (int) $control['ultima_secuencia'];
                $hash = (string) $control['ultimo_hash'];
                $id = (int) $bd->valor('SELECT COALESCE(MAX(id), 0) FROM eventos_auditoria');
                $filas = [];
                for ($i = 0; $i < $bloque; $i++) {
                    $id++;
                    $secuencia++;
                    $segundo = $inicioAnio + (int) (($hechos + $i) / $total * 364 * 86400) + mt_rand(0, 59);
                    $evento = [
                        'id' => $id, 'evento_id' => GeneradorUlid::generar(), 'ocurrido_en' => gmdate('Y-m-d H:i:s', $segundo) . '.000', 'request_id' => null,
                        'actor_id' => null, 'actor_rol' => 'lider_unidad', 'centro_id' => $centro, 'unidad_id' => $unidades[$this->unidadAlAzar($unidades)],
                        'accion' => self::ACCIONES[mt_rand(0, count(self::ACCIONES) - 1)], 'recurso' => 'carga', 'recurso_id' => (string) mt_rand(1, 50000),
                        'resultado' => 'exito', 'origen' => 'web', 'ip_tratada' => null, 'ip_hmac' => null, 'agente' => null, 'valores_antes' => null,
                        'valores_despues' => null, 'motivo' => null, 'metadatos' => null,
                    ];
                    $nuevo = hash('sha256', $hash . '|' . $secuencia . '|' . ServicioAuditoria::canonico($evento));
                    $filas[] = [$id, $evento['evento_id'], $evento['ocurrido_en'], 'lider_unidad', $centro, $evento['unidad_id'], $evento['accion'], 'carga', $evento['recurso_id'], 'exito', 'web', $secuencia, $hash, $nuevo];
                    $hash = $nuevo;
                }
                $this->insertarLote('eventos_auditoria', $columnas, $filas);
                $bd->ejecutar('UPDATE cadena_auditoria SET ultima_secuencia = :s, ultimo_hash = :h WHERE id = 1', ['s' => $secuencia, 'h' => $hash]);
            });
            $hechos += $bloque;
        }
        return $total;
    }

    /**
     * Cuentas sintéticas para k6 (Anexo H.3): roles no privilegiados (sin 2FA), repartidas entre unidades; incluye
     * líderes del segundo centro para comprobar el aislamiento entre centros.
     *
     * @param callable(string):string $hashear
     * @return list<array<string,mixed>>
     */
    private function cuentasDeCarga(int $piloto, int $verificacion, int $cuentas, callable $hashear, string $contrasena): array
    {
        $roles = [];
        foreach ($this->bd->consultar('SELECT id, codigo FROM roles WHERE privilegiado = 0') as $rol) {
            $roles[(string) $rol['codigo']] = (int) $rol['id'];
        }
        $hash = $hashear($contrasena);
        $unidadesPiloto = $this->unidades($piloto);
        $unidadesVerificacion = $this->unidades($verificacion);
        $reparto = ['lider_unidad' => 0.6, 'lider_produccion_centro' => 0.15, 'grupo_recaudo' => 0.1, 'coordinacion_administrativa' => 0.1, 'coordinacion_academica' => 0.05];
        $usuarios = [];
        $n = 0;
        foreach ($reparto as $rol => $proporcion) {
            $cantidad = max(1, (int) round($cuentas * $proporcion));
            for ($i = 0; $i < $cantidad && $n < $cuentas; $i++) {
                $n++;
                $deVerificacion = $rol === 'lider_unidad' && $i % 6 === 5;
                $centro = $deVerificacion ? $verificacion : $piloto;
                $unidades = $deVerificacion ? $unidadesVerificacion : $unidadesPiloto;
                $codigo = array_keys($unidades)[$i % count($unidades)];
                $correo = sprintf('carga%02d@carga.invalid', $n);
                $this->bd->ejecutar(
                    "INSERT INTO usuarios (ulid, centro_id, correo, nombre, hash_contrasena, estado, contrasena_cambiada_en) VALUES (:u, :c, :correo, :n, :h, 'activo', UTC_TIMESTAMP(3))",
                    ['u' => GeneradorUlid::generar(), 'c' => $centro, 'correo' => $correo, 'n' => "Usuario de carga {$n}", 'h' => $hash]
                );
                $usuario = (int) $this->bd->valor('SELECT id FROM usuarios WHERE correo = :c', ['c' => $correo]);
                $this->bd->ejecutar('INSERT INTO usuarios_roles (usuario_id, rol_id, centro_id) VALUES (:u, :r, :c)', ['u' => $usuario, 'r' => $roles[$rol], 'c' => $centro]);
                if ($rol === 'lider_unidad') {
                    $this->bd->ejecutar('INSERT INTO usuarios_unidades (usuario_id, unidad_id) VALUES (:u, :unidad)', ['u' => $usuario, 'unidad' => $unidades[$codigo]]);
                }
                $usuarios[] = [
                    'alias' => sprintf('carga%02d', $n), 'correo' => $correo, 'contrasena' => $contrasena, 'rol' => $rol,
                    'centro' => $deVerificacion ? '9999' : '9124',
                    'unidadPropia' => $rol === 'lider_unidad' ? $codigo : null,
                    'unidadPropiaId' => $rol === 'lider_unidad' ? $unidades[$codigo] : null,
                    'categoriaGastoId' => (int) $this->bd->valor('SELECT MIN(id) FROM categorias_gasto WHERE centro_id = :c', ['c' => $centro]),
                    'unidadesAjenas' => $rol === 'lider_unidad' ? array_values(array_diff(array_keys($unidadesPiloto), [$codigo])) : [],
                ];
            }
        }
        return $usuarios;
    }

    /** @param array<string,int> $unidades */
    private function unidadAlAzar(array $unidades): string
    {
        $pesos = array_intersect_key(self::PESOS, $unidades) ?: array_fill_keys(array_keys($unidades), 1);
        $tiro = mt_rand(1, array_sum($pesos));
        foreach ($pesos as $codigo => $peso) {
            $tiro -= $peso;
            if ($tiro <= 0) {
                return $codigo;
            }
        }
        return (string) array_key_first($pesos);
    }

    /**
     * INSERT de varias filas con marcadores posicionales (una sola ida y vuelta por bloque).
     *
     * @param list<string> $columnas
     * @param list<list<scalar|null>> $filas
     */
    private function insertarLote(string $tabla, array $columnas, array $filas): void
    {
        if ($filas === []) {
            return;
        }
        $marcadores = '(' . implode(', ', array_fill(0, count($columnas), '?')) . ')';
        $parametros = [];
        foreach ($filas as $fila) {
            foreach ($fila as $valor) {
                $parametros[] = $valor;
            }
        }
        $this->bd->ejecutar(
            "INSERT INTO {$tabla} (" . implode(', ', $columnas) . ') VALUES ' . implode(', ', array_fill(0, count($filas), $marcadores)),
            $parametros
        );
    }
}
