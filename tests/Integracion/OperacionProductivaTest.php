<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Cola\Trabajador;
use App\Nucleo\Aplicacion;
use App\Nucleo\BaseDatos;
use App\Nucleo\Contenedor;
use App\Nucleo\Entorno;
use App\Nucleo\Migrador;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioCalidad;
use App\Servicios\ServicioMantenimiento;
use PHPUnit\Framework\TestCase;

/**
 * Iteración 4 «Operación productiva» de extremo a extremo: recepciones con existencias, salidas con sus reglas,
 * transformación láctea con la recepción del derivado en el mismo flujo (Obs. 6), hato por cortes con el lote de
 * práctica, bandeja de calidad con correcciones auditadas y recaudos escritos solo por el Grupo de Recaudo (Obs. 2).
 */
final class OperacionProductivaTest extends TestCase
{
    use AyudasCuentas;

    private static BaseDatos $bd;
    private string $senal;

    public static function setUpBeforeClass(): void
    {
        self::$bd = new BaseDatos(require RAIZ . '/config/base_datos.php');
        $migrador = new Migrador(self::$bd, RAIZ . '/bd/migraciones');
        $migrador->aplicar();
        $migrador->sembrar(RAIZ . '/bd/semillas/001_identidad_y_estructura.sql');
    }

    /** Al terminar, la base queda sin datos de negocio ni cuentas: las pruebas siguientes parten de la semilla. */
    public static function tearDownAfterClass(): void
    {
        foreach (self::TABLAS_DE_PRUEBA as $tabla) {
            self::$bd->ejecutar("DELETE FROM {$tabla}");
        }
        self::$bd->ejecutar('DELETE FROM usuarios');
    }

    private const TABLAS_DE_PRUEBA = ['incidencias_calidad', 'registros_reproductivos', 'cortes_hato', 'animales', 'conciliaciones_recaudo', 'transformaciones', 'salidas',
        'recepciones_lote', 'recaudos', 'archivos_adjuntos', 'gastos_ejecutados', 'asignaciones_presupuestales', 'procesos_autoconsumo',
        'periodos_cierre', 'homologaciones_producto', 'productos', 'parametros_indicadores', 'categorias_gasto', 'versiones_datos',
        'claves_idempotencia', 'codigos_respaldo_2fa', 'tokens_cuenta', 'sesiones', 'notificaciones', 'usuarios_unidades', 'usuarios_roles',
        'trabajos_fallidos', 'trabajos', 'limites_tasa', 'eventos_auditoria'];

    protected function setUp(): void
    {
        foreach (self::TABLAS_DE_PRUEBA as $tabla) {
            self::$bd->ejecutar("DELETE FROM {$tabla}");
        }
        self::$bd->ejecutar('DELETE FROM usuarios');
        self::$bd->ejecutar('UPDATE unidades_productivas SET activa = 1');
        self::$bd->ejecutar("UPDATE cadena_auditoria SET ultima_secuencia = 0, ultimo_hash = REPEAT('0', 64) WHERE id = 1");
        self::$bd->ejecutar('DELETE FROM roles_permisos');
        $migrador = new Migrador(self::$bd, RAIZ . '/bd/migraciones');
        $migrador->sembrar(RAIZ . '/bd/semillas/001_identidad_y_estructura.sql');
        $migrador->sembrar(RAIZ . '/bd/semillas/002_catalogos_finanzas.sql');
        $this->senal = sys_get_temp_dir() . '/kora-senal-i4-' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        Entorno::establecer('FUNCIONALIDAD_RECAUDOS_ALTA_MANUAL', 'false');
        if (is_file($this->senal)) {
            unlink($this->senal);
        }
    }

    public function testRecepcionesSalidasYExistenciasConSusReglas(): void
    {
        $lider = $this->navegador('190.85.13.10');
        $this->cuentaActiva(self::$bd, $lider, 'lider.gan@kora.local', 'Luis Ganadería', 'lider_unidad', ['GAN']);
        $leche = $this->producto('5013170101', 'Leche de vaca', 'L');
        $fecha = $this->fecha(5);

        // RF-039: el formulario sugiere el consecutivo de la unidad y la fecha.
        $formulario = $lider->get('/produccion/recepciones/nueva');
        self::assertSame(200, $formulario->estado);
        $sugerido = $lider->getHtmx('/produccion/recepciones/numero-sugerido?unidad_id=' . $this->unidad('GAN') . '&fecha_recepcion=' . $fecha);
        $numero = 'GAN-REC-' . substr($fecha, 8, 2) . substr($fecha, 5, 2) . substr($fecha, 2, 2) . '-001';
        self::assertStringContainsString($numero, $sugerido->cuerpo);

        $recepcion = ['unidad_id' => (string) $this->unidad('GAN'), 'fecha_recepcion' => $fecha, 'numero_interno' => $numero, 'producto_id' => (string) $leche, 'cantidad' => '100', 'precio_sugerido' => '2200', 'responsable_id' => $this->usuario('lider.gan@kora.local')];
        $medida = $lider->postHtmx('/produccion/recepciones', ['medida' => 'kg'] + $recepcion);
        self::assertSame(422, $medida->estado);
        self::assertStringContainsString('La medida de la recepción debe coincidir con la del catálogo.', $medida->cuerpo);
        self::assertSame(200, $lider->postHtmx('/produccion/recepciones', $recepcion)->estado);
        $duplicada = $lider->postHtmx('/produccion/recepciones', $recepcion);
        self::assertSame(422, $duplicada->estado);
        self::assertStringContainsString('El lote ya contiene una recepción de este producto.', $duplicada->cuerpo);
        $lote = (int) self::$bd->valor('SELECT id FROM recepciones_lote WHERE numero_interno = :n', ['n' => $numero]);
        self::assertStringContainsString(substr($numero, 0, -3) . '002', $lider->getHtmx('/produccion/recepciones/numero-sugerido?unidad_id=' . $this->unidad('GAN') . '&fecha_recepcion=' . $fecha)->cuerpo, 'El consecutivo avanza.');

        // RF-043: la salida hereda el lote y valida fecha, saldo y proceso, con los mensajes del prototipo.
        $lider->get('/produccion/salidas/nueva?lote=' . $lote);
        $salida = ['recepcion_id' => (string) $lote, 'fecha' => $this->fecha(6), 'cantidad' => '3.333', 'precio_unitario' => '1000.55', 'canal' => 'VENTA_DIRECTA', 'factura' => 'FE-100'];
        $anterior = $lider->postHtmx('/produccion/salidas', ['fecha' => $this->fecha(4)] + $salida);
        self::assertSame(422, $anterior->estado);
        self::assertStringContainsString('La salida no puede ser anterior a la recepción.', $anterior->cuerpo);
        $excedida = $lider->postHtmx('/produccion/salidas', ['cantidad' => '100.001'] + $salida);
        self::assertSame(422, $excedida->estado);
        self::assertStringContainsString('La entrega supera el saldo del lote (100 L).', $excedida->cuerpo);
        $sinProceso = $lider->postHtmx('/produccion/salidas', ['canal' => 'AUTOCONSUMO'] + $salida);
        self::assertSame(422, $sinProceso->estado);
        self::assertStringContainsString('Vincula la entrega con un proceso de autoconsumo.', $sinProceso->cuerpo);

        $recaudosAntes = (int) self::$bd->valor('SELECT COUNT(*) FROM recaudos');
        $venta = $lider->postHtmx('/produccion/salidas', $salida);
        self::assertSame(200, $venta->estado);
        self::assertStringContainsString('no crea un recaudo', $venta->cabeceras['HX-Trigger'] ?? '', 'RF-044: el mensaje aclara que no hay recaudo.');
        self::assertSame($recaudosAntes, (int) self::$bd->valor('SELECT COUNT(*) FROM recaudos'), 'RF-044: la venta directa no crea recaudos.');
        self::assertSame('3334.83', (string) self::$bd->valor('SELECT valor FROM salidas WHERE factura = :f', ['f' => 'FE-100']), 'Valor = 3,333 × 1.000,55 = 3.334,83315, redondeado a 2 decimales.');

        // Autoconsumo con un proceso de otra medida y luego con el correcto («Leche» equivale al 5013170101).
        $procesoKg = $this->proceso('AUTO_001', 'GAN', $this->producto('5013170199', 'Leche en polvo', 'kg'), 'kg', 'Leche en polvo', (int) substr($fecha, 0, 4));
        $incoherente = $lider->postHtmx('/produccion/salidas', ['canal' => 'AUTOCONSUMO', 'proceso_id' => (string) $procesoKg] + $salida);
        self::assertSame(422, $incoherente->estado);
        self::assertStringContainsString('El proceso debe coincidir con la unidad origen, medida y vigencia de la entrega.', $incoherente->cuerpo);
        $procesoLeche = $this->proceso('AUTO_002', 'GAN', null, 'L', 'Leche', (int) substr($fecha, 0, 4));
        self::assertSame(200, $lider->postHtmx('/produccion/salidas', ['canal' => 'AUTOCONSUMO', 'proceso_id' => (string) $procesoLeche, 'cantidad' => '96.667'] + $salida)->estado);

        // RF-041: el lote queda agotado y cerrado con la salida que lo agota.
        $detalle = $lider->get('/produccion/recepciones/' . $lote)->cuerpo;
        self::assertStringContainsString('Cerrado', $detalle);
        self::assertStringContainsString('en 2 salidas', $detalle);
        self::assertStringContainsString('<strong>0 L</strong>', $detalle);

        // RF-040: con salidas, la cantidad no baja de lo entregado y el producto no cambia.
        $version = (string) self::$bd->valor('SELECT version FROM recepciones_lote WHERE id = :id', ['id' => $lote]);
        $menor = $lider->postHtmx('/produccion/recepciones/' . $lote, ['version' => $version, 'cantidad' => '90'] + $recepcion);
        self::assertSame(422, $menor->estado);
        self::assertStringContainsString('La recepción no puede ser menor que las cantidades ya entregadas.', $menor->cuerpo);
        $otroProducto = $lider->postHtmx('/produccion/recepciones/' . $lote, ['version' => $version, 'producto_id' => (string) $this->producto('5013170102', 'Leche descremada', 'L')] + $recepcion);
        self::assertStringContainsString('Este lote tiene salidas. Conserva su producto, unidad, medida y número interno.', $otroProducto->cuerpo);
        $posterior = $lider->postHtmx('/produccion/recepciones/' . $lote, ['version' => $version, 'fecha_recepcion' => $this->fecha(7)] + $recepcion);
        self::assertStringContainsString('La recepción no puede ser posterior a sus salidas.', $posterior->cuerpo);
        self::assertSame(409, $lider->postHtmx('/produccion/recepciones/' . $lote . '/archivar', ['version' => $version, 'motivo' => 'Registro duplicado'])->estado, 'Un lote con salidas no se archiva.');

        // Obs. 5 y RF-051: fuera de su unidad, 403 al escribir y al filtrar; un lote ajeno no existe para él.
        self::assertSame(403, $lider->postHtmx('/produccion/recepciones', ['unidad_id' => (string) $this->unidad('AGR'), 'numero_interno' => 'AGR-REC-010126-001'] + $recepcion)->estado);
        self::assertSame(403, $lider->get('/produccion/recepciones?unidad=' . $this->unidad('IND'))->estado);
        $ajeno = self::$bd->insertar(
            "INSERT INTO recepciones_lote (centro_id, unidad_id, numero_interno, fecha_recepcion, producto_id, cantidad, medida) VALUES (:c, :u, 'AGR-REC-010126-001', :f, :p, 5, 'L')",
            ['c' => $this->centro(), 'u' => $this->unidad('AGR'), 'f' => $fecha, 'p' => $leche]
        );
        self::assertSame(404, $lider->get('/produccion/recepciones/' . $ajeno)->estado);
        self::assertGreaterThanOrEqual(2, (int) self::$bd->valor("SELECT COUNT(*) FROM eventos_auditoria WHERE resultado = 'denegado' AND accion LIKE 'recepciones.%'"));
    }

    public function testTransformacionConRecepcionDelDerivadoEnElMismoFlujo(): void
    {
        $lider = $this->navegador('190.85.13.20');
        $this->cuentaActiva(self::$bd, $lider, 'lider.gan@kora.local', 'Luis Ganadería', 'lider_unidad', ['GAN']);
        $responsable = $this->usuario('lider.gan@kora.local');

        // RF-050 (Obs. 6): sin derivados configurados no hay un desplegable vacío, sino la causa.
        self::assertStringContainsString('No hay derivados lácteos configurados', $lider->get('/produccion/transformaciones/nueva')->cuerpo);
        $queso = $this->producto('5013170201', 'Queso cuajada', 'kg', true, '10', '11');
        self::assertStringContainsString('No hay entregas de leche disponibles', $lider->get('/produccion/transformaciones/nueva')->cuerpo);

        $leche = $this->producto('5013170101', 'Leche de vaca', 'L');
        $lote = self::$bd->insertar(
            "INSERT INTO recepciones_lote (centro_id, unidad_id, numero_interno, fecha_recepcion, producto_id, cantidad, medida) VALUES (:c, :u, 'GAN-REC-010126-001', :f, :p, 100, 'L')",
            ['c' => $this->centro(), 'u' => $this->unidad('GAN'), 'f' => $this->fecha(5), 'p' => $leche]
        );
        $proceso = $this->proceso('AUTO_001', 'GAN', $leche, 'L', 'Leche de vaca', (int) substr($this->fecha(5), 0, 4));
        $lider->get('/produccion/salidas/nueva');
        self::assertSame(200, $lider->postHtmx('/produccion/salidas', ['recepcion_id' => (string) $lote, 'fecha' => $this->fecha(6), 'cantidad' => '100', 'precio_unitario' => '2000', 'canal' => 'AUTOCONSUMO', 'proceso_id' => (string) $proceso])->estado);
        $entrega = (int) self::$bd->valor('SELECT id FROM salidas');

        // RF-047: el desplegable muestra el derivado y el saldo de litros de la entrega.
        $formulario = $lider->get('/produccion/transformaciones/nueva')->cuerpo;
        self::assertStringContainsString('Queso cuajada (kg)', $formulario);
        self::assertStringContainsString('disponibles 100 de 100 L', $formulario);

        $transformacion = [
            'salida_leche_id' => (string) $entrega, 'fecha' => $this->fecha(7), 'litros_asignados' => '120', 'modo_recepcion' => 'nueva',
            'derivado_id' => (string) $queso, 'cantidad_derivado' => '6', 'unidad_receptora_id' => (string) $this->unidad('IND'),
            'numero_interno' => 'IND-REC-' . substr($this->fecha(7), 8, 2) . substr($this->fecha(7), 5, 2) . substr($this->fecha(7), 2, 2) . '-001', 'responsable_id' => $responsable,
        ];
        // RF-048: si falla una regla de la transformación, la recepción del derivado tampoco queda.
        $excedida = $lider->postHtmx('/produccion/transformaciones', $transformacion);
        self::assertSame(422, $excedida->estado);
        self::assertStringContainsString('Los litros asignados superan la entrega de leche disponible.', $excedida->cuerpo);
        self::assertSame(0, (int) self::$bd->valor('SELECT COUNT(*) FROM recepciones_lote WHERE producto_id = :p', ['p' => $queso]), 'La reversión deshizo la recepción.');
        $anterior = $lider->postHtmx('/produccion/transformaciones', ['litros_asignados' => '60', 'fecha' => $this->fecha(5)] + $transformacion);
        self::assertStringContainsString('La transformación debe tener la fecha de recepción del derivado y ser posterior a la entrega de leche.', $anterior->cuerpo);

        self::assertSame(200, $lider->postHtmx('/produccion/transformaciones', ['litros_asignados' => '60'] + $transformacion)->estado);
        self::assertSame(1, (int) self::$bd->valor('SELECT COUNT(*) FROM recepciones_lote WHERE producto_id = :p AND unidad_id = :u', ['p' => $queso, 'u' => $this->unidad('IND')]));

        // RF-051: el líder de GAN ve el resultado (derivado y rendimiento) aunque el derivado sea de IND…
        $listado = $lider->get('/produccion/transformaciones')->cuerpo;
        self::assertStringContainsString('Queso cuajada', $listado);
        self::assertStringContainsString('10,00 L/kg', $listado, 'Rendimiento = 60 L / 6 kg.');
        self::assertStringContainsString('En meta', $listado);
        // …pero no los demás datos de IND.
        self::assertSame(403, $lider->get('/produccion/recepciones?unidad=' . $this->unidad('IND'))->estado);
        self::assertStringNotContainsString('IND-REC', $lider->get('/produccion/recepciones')->cuerpo);

        // RF-046: la entrega no baja de los litros ya asignados.
        $version = (string) self::$bd->valor('SELECT version FROM salidas WHERE id = :id', ['id' => $entrega]);
        $reducida = $lider->postHtmx('/produccion/salidas/' . $entrega, ['version' => $version, 'recepcion_id' => (string) $lote, 'fecha' => $this->fecha(6), 'cantidad' => '50', 'precio_unitario' => '2000', 'canal' => 'AUTOCONSUMO', 'proceso_id' => (string) $proceso]);
        self::assertSame(422, $reducida->estado);
        self::assertStringContainsString('La cantidad es menor que la leche ya asignada a transformaciones.', $reducida->cuerpo);
        $canal = $lider->postHtmx('/produccion/salidas/' . $entrega, ['version' => $version, 'recepcion_id' => (string) $lote, 'fecha' => $this->fecha(6), 'cantidad' => '100', 'precio_unitario' => '2000', 'canal' => 'VENTA_DIRECTA']);
        self::assertStringContainsString('Esta entrega alimenta una transformación láctea. Conserva su origen de leche.', $canal->cuerpo);
    }

    public function testHatoPorCortesConSemaforosYLoteDePractica(): void
    {
        $lider = $this->navegador('190.85.13.30');
        $this->cuentaActiva(self::$bd, $lider, 'lider.gan@kora.local', 'Luis Ganadería', 'lider_unidad', ['GAN']);
        $academica = $this->navegador('190.85.13.31');
        $this->cuentaActiva(self::$bd, $academica, 'academica@kora.local', 'Ana Académica', 'coordinacion_academica');
        $gan = (string) $this->unidad('GAN');

        $inicio = $lider->get('/hato');
        self::assertSame(200, $inicio->estado);
        self::assertStringContainsString('Crea el primer corte del hato', $inicio->cuerpo);
        self::assertSame(200, $lider->postHtmx('/hato/cortes', ['unidad_id' => $gan, 'fecha_corte' => '2026-11-09'])->estado);

        $animal = ['unidad_id' => $gan, 'identificador' => '158', 'fecha_nacimiento' => '2019-03-01', 'lote_hato' => 'PRODUCCION', 'penultimo_parto' => '2024-06-01', 'ultimo_parto' => '2025-08-15', 'estado_reproductivo' => ' preñada ', 'servicio_efectivo' => '2025-12-20'];
        $antes = $lider->postHtmx('/hato/animales', ['penultimo_parto' => '2018-01-01'] + $animal);
        self::assertSame(422, $antes->estado);
        self::assertStringContainsString('Los eventos reproductivos deben ser posteriores al nacimiento.', $antes->cuerpo);
        $invertidos = $lider->postHtmx('/hato/animales', ['penultimo_parto' => '2025-09-01'] + $animal);
        self::assertStringContainsString('El penúltimo parto debe ser anterior al último.', $invertidos->cuerpo);
        $creado = $lider->postHtmx('/hato/animales', $animal);
        self::assertSame(200, $creado->estado, self::errores($creado->cuerpo));

        // RF-054: IEP y días abiertos calculados en el servidor; RF-056: semáforo con color, texto e icono.
        $registro = self::$bd->fila('SELECT iep_dias, dias_abiertos, estado_reproductivo, lote_hato FROM registros_reproductivos');
        self::assertSame(440, (int) $registro['iep_dias'], '2024-06-01 → 2025-08-15.');
        self::assertSame(127, (int) $registro['dias_abiertos'], '2025-08-15 → servicio efectivo 2025-12-20.');
        self::assertSame('PREÑADA', $registro['estado_reproductivo']);
        self::assertStringContainsString('Inventario biológico al 09/11/2026', $creado->cuerpo);
        self::assertStringContainsString('440 d · Fuera de meta', $creado->cuerpo);
        self::assertStringContainsString('127 d · Vigilar', $creado->cuerpo);

        // RF-057: la Coordinación académica solo cambia el lote de práctica, con motivo y auditado.
        $id = (int) self::$bd->valor("SELECT id FROM animales WHERE identificador = '158'");
        self::assertSame(403, $academica->postHtmx('/hato/animales/' . $id, $animal + ['identificador' => '999'])->estado);
        $academica->get('/hato/animales/' . $id);
        $version = (string) self::$bd->valor('SELECT version FROM registros_reproductivos');
        $sinMotivo = $academica->postHtmx('/hato/animales/' . $id . '/practica', ['version' => $version, 'en_practica' => '1', 'motivo' => '']);
        self::assertSame(422, $sinMotivo->estado);
        self::assertSame(200, $academica->postHtmx('/hato/animales/' . $id . '/practica', ['version' => $version, 'en_practica' => '1', 'observaciones_academicas' => 'Apta para palpación', 'motivo' => 'Práctica de palpación del grupo 3'])->estado);
        self::assertSame('PRACTICA', self::$bd->valor('SELECT lote_hato FROM registros_reproductivos'));
        self::assertSame('158', self::$bd->valor('SELECT identificador FROM animales WHERE id = :id', ['id' => $id]), 'Los demás datos no cambian.');
        self::assertSame('Práctica de palpación del grupo 3', self::$bd->valor("SELECT motivo FROM eventos_auditoria WHERE accion = 'hato.gestionar_practica' AND resultado = 'exito'"));

        // RF-053: un corte nuevo copia el estado y recalcula los días abiertos con su fecha; el anterior se conserva.
        self::assertSame(200, $lider->postHtmx('/hato/cortes', ['unidad_id' => $gan, 'fecha_corte' => '2026-11-15'])->estado);
        self::assertSame(2, (int) self::$bd->valor('SELECT COUNT(*) FROM registros_reproductivos'));
        self::assertSame('2026-11-15', self::$bd->valor('SELECT fecha_corte FROM cortes_hato WHERE vigente = 1'));
        $repetido = $lider->postHtmx('/hato/cortes', ['unidad_id' => $gan, 'fecha_corte' => '2026-11-15']);
        self::assertSame(422, $repetido->estado);
    }

    public function testBandejaDeCalidadConCorreccionesAuditadas(): void
    {
        $lider = $this->navegador('190.85.13.40');
        $this->cuentaActiva(self::$bd, $lider, 'lider.gan@kora.local', 'Luis Ganadería', 'lider_unidad', ['GAN']);
        $centro = $this->centro();
        $leche = $this->producto('5013170101', 'Leche de vaca', 'L');
        $lote = self::$bd->insertar(
            "INSERT INTO recepciones_lote (centro_id, unidad_id, numero_interno, fecha_recepcion, producto_id, cantidad, medida) VALUES (:c, :u, 'GAN-REC-010126-001', :f, :p, 50, 'L')",
            ['c' => $centro, 'u' => $this->unidad('GAN'), 'f' => $this->fecha(3), 'p' => $leche]
        );
        // Datos heredados de la importación: una venta sin recepción, un autoconsumo sin proceso y un recaudo sin unidad.
        $huerfana = $this->salidaHeredada('VENTA_DIRECTA', 'GAN-REC-999999-001', '20');
        $sinProceso = $this->salidaHeredada('AUTOCONSUMO', null, '10');
        self::$bd->insertar(
            "INSERT INTO recaudos (centro_id, fecha_pago, valor, tipo_ingreso, clave_natural) VALUES (:c, :f, 3045000, 'Producción', :k)",
            ['c' => $centro, 'f' => $this->fecha(4), 'k' => hash('sha256', 'recaudo-sin-unidad')]
        );
        $calidad = Aplicacion::crear(require RAIZ . '/config/app.php')->contenedor()->obtener(ServicioCalidad::class);
        $abiertas = $calidad->recalcular($centro);
        self::assertSame(2, $abiertas['salida_sin_recepcion'], 'Las dos heredadas no tienen recepción.');
        self::assertSame(1, $abiertas['autoconsumo_sin_proceso']);
        self::assertSame(1, $abiertas['recaudo_sin_clasificar']);
        self::assertSame($abiertas, $calidad->recalcular($centro), 'El recálculo es idempotente.');

        // El líder ve las de GAN pero no la del recaudo sin unidad (solo con alcance de centro).
        $bandeja = $lider->get('/calidad')->cuerpo;
        self::assertStringContainsString('Salida sin recepción vinculada', $bandeja);
        self::assertStringNotContainsString('COP sin unidad', $bandeja);

        // RF-059: vincular valida el saldo del lote y audita el antes y el después; RF-061: sube la versión de datos.
        $incidencia = (int) self::$bd->valor("SELECT id FROM incidencias_calidad WHERE tipo = 'salida_sin_recepcion' AND entidad_id = :s", ['s' => $huerfana]);
        $version = (int) (self::$bd->valor('SELECT version FROM versiones_datos WHERE centro_id = :c', ['c' => $centro]) ?? 0);
        $otroLote = self::$bd->insertar(
            "INSERT INTO recepciones_lote (centro_id, unidad_id, numero_interno, fecha_recepcion, producto_id, cantidad, medida) VALUES (:c, :u, 'GAN-REC-010126-002', :f, :p, 5, 'L')",
            ['c' => $centro, 'u' => $this->unidad('GAN'), 'f' => $this->fecha(3), 'p' => $leche]
        );
        $sinSaldo = $lider->postHtmx('/calidad/' . $incidencia . '/vincular-recepcion', ['recepcion_id' => (string) $otroLote]);
        self::assertSame(422, $sinSaldo->estado);
        self::assertStringContainsString('La entrega supera el saldo del lote (5 L).', $sinSaldo->cuerpo);
        self::assertSame(200, $lider->postHtmx('/calidad/' . $incidencia . '/vincular-recepcion', ['recepcion_id' => (string) $lote])->estado);
        self::assertSame($lote, (int) self::$bd->valor('SELECT recepcion_id FROM salidas WHERE id = :id', ['id' => $huerfana]));
        self::assertSame('resuelta', self::$bd->valor('SELECT estado FROM incidencias_calidad WHERE id = :id', ['id' => $incidencia]));
        $evento = self::$bd->fila("SELECT valores_antes, valores_despues FROM eventos_auditoria WHERE accion = 'calidad.vincular_recepcion'");
        self::assertStringContainsString('null', (string) $evento['valores_antes']);
        self::assertStringContainsString((string) $lote, (string) $evento['valores_despues']);
        self::assertGreaterThan($version, (int) self::$bd->valor('SELECT version FROM versiones_datos WHERE centro_id = :c', ['c' => $centro]));

        // Aceptar sin corrección exige motivo, queda «aceptada» y el recálculo no la reabre.
        $otra = (int) self::$bd->valor("SELECT id FROM incidencias_calidad WHERE tipo = 'autoconsumo_sin_proceso' AND entidad_id = :s", ['s' => $sinProceso]);
        self::assertSame(422, $lider->postHtmx('/calidad/' . $otra . '/aceptar', ['motivo' => 'corto'])->estado);
        self::assertSame(200, $lider->postHtmx('/calidad/' . $otra . '/aceptar', ['motivo' => 'Entrega histórica sin cupo identificable en la fuente'])->estado);
        $calidad->recalcular($centro);
        self::assertSame('aceptada', self::$bd->valor('SELECT estado FROM incidencias_calidad WHERE id = :id', ['id' => $otra]));

        // RF-058: el recálculo también corre como trabajo de la cola (calidad.recalcular), encolado desde la bandeja.
        self::$bd->ejecutar('DELETE FROM incidencias_calidad');
        self::assertSame(202, $lider->postHtmx('/calidad/recalcular')->estado);
        self::assertSame('pendiente', self::$bd->valor("SELECT estado FROM trabajos WHERE tipo = 'calidad.recalcular'"));
        Aplicacion::crear(require RAIZ . '/config/app.php')->contenedor()->obtener(Trabajador::class)->ejecutar(null, 5);
        self::assertSame('completado', self::$bd->valor("SELECT estado FROM trabajos WHERE tipo = 'calidad.recalcular'"));
        self::assertSame(3, (int) self::$bd->valor("SELECT COUNT(*) FROM incidencias_calidad WHERE estado = 'abierta'"), 'La entrega heredada sin lote ni proceso (2 incidencias) y el recaudo sin unidad.');
    }

    public function testSoloElGrupoDeRecaudoEscribeRecaudosYLaConsultaRespetaElAlcance(): void
    {
        $coordinacion = $this->navegador('190.85.13.50');
        $this->cuentaActiva(self::$bd, $coordinacion, 'coordinacion@kora.local', 'Carla Coordinación', 'coordinacion_administrativa');
        $recaudo = $this->navegador('190.85.13.51');
        $this->cuentaActiva(self::$bd, $recaudo, 'recaudo@kora.local', 'Rita Recaudo', 'grupo_recaudo');
        $lider = $this->navegador('190.85.13.52');
        $this->cuentaActiva(self::$bd, $lider, 'lider.gan@kora.local', 'Luis Ganadería', 'lider_unidad', ['GAN']);

        // RF-029 (Obs. 2): la Coordinación administrativa consulta, sin acciones de escritura, y el POST responde 403 auditado.
        $vista = $coordinacion->get('/recaudos');
        self::assertSame(200, $vista->estado);
        self::assertStringNotContainsString('Alta manual', $vista->cuerpo);
        // RF-033: sin la funcionalidad recaudos_alta_manual, el alta manual no existe (404) ni para el Grupo de Recaudo.
        self::assertSame(404, $recaudo->get('/recaudos/nuevo')->estado);
        self::assertStringNotContainsString('Alta manual', $recaudo->get('/recaudos')->cuerpo);
        Entorno::establecer('FUNCIONALIDAD_RECAUDOS_ALTA_MANUAL', 'true');
        $datos = ['fecha_pago' => $this->fecha(8), 'valor' => '3.045.000', 'tipo_ingreso' => 'Producción', 'pasarela' => 'Wompi', 'unidad_id' => (string) $this->unidad('GAN'), 'concepto_fuente' => 'Leche', 'cupon_pago' => 'CUP-1', 'identificacion' => '1.098.765.432', 'motivo' => 'Pago en efectivo en la tienda del centro', 'referencia_soporte' => 'Recibo de caja 0042'];
        self::assertSame(403, $coordinacion->postHtmx('/recaudos', $datos)->estado);
        self::assertSame(1, (int) self::$bd->valor("SELECT COUNT(*) FROM eventos_auditoria WHERE accion = 'recaudos.gestionar' AND resultado = 'denegado'"));

        self::assertStringContainsString('Alta manual', $recaudo->get('/recaudos')->cuerpo);
        self::assertSame(422, $recaudo->postHtmx('/recaudos', ['motivo' => ''] + $datos)->estado, 'El alta manual exige motivo.');
        $creado = $recaudo->postHtmx('/recaudos', $datos);
        self::assertSame(200, $creado->estado);
        $fila = self::$bd->fila('SELECT valor, identificacion_hmac, identificacion_ultimos4, estado_clasificacion FROM recaudos');
        self::assertSame('3045000.00', (string) $fila['valor']);
        self::assertSame('5432', $fila['identificacion_ultimos4']);
        self::assertSame(64, strlen((string) $fila['identificacion_hmac']), 'RF-034: la identificación nunca se guarda en claro.');
        self::assertSame('clasificado', $fila['estado_clasificacion']);
        self::assertStringNotContainsString('1098765432', (string) self::$bd->valor("SELECT valores_despues FROM eventos_auditoria WHERE accion = 'recaudos.crear'"));
        self::assertSame(422, $recaudo->postHtmx('/recaudos', $datos)->estado, 'La clave natural impide el duplicado.');
        self::assertSame(200, $recaudo->postHtmx('/recaudos', ['unidad_id' => (string) $this->unidad('AGR'), 'cupon_pago' => 'CUP-2', 'concepto_fuente' => 'Maíz'] + $datos)->estado);

        // RF-035: el líder de GAN solo ve GAN; filtrar por otra unidad devuelve vacío, nunca datos ajenos.
        $propios = $lider->get('/recaudos')->cuerpo;
        self::assertStringContainsString('CUP-1', $propios);
        self::assertStringNotContainsString('CUP-2', $propios);
        $filtrado = $lider->get('/recaudos?unidad=' . $this->unidad('AGR'));
        self::assertSame(200, $filtrado->estado);
        self::assertStringContainsString('No hay recaudos con estos filtros', $filtrado->cuerpo);
        self::assertStringContainsString('CUP-2', $coordinacion->get('/recaudos?pasarela=Wompi&orden=-valor')->cuerpo);
    }

    private function navegador(string $ip): NavegadorPrueba
    {
        $senal = $this->senal;
        return new NavegadorPrueba(static function (Contenedor $c) use ($senal): void {
            $c->establecer(ServicioMantenimiento::class, new ServicioMantenimiento(
                $c->obtener(BaseDatos::class),
                $c->obtener(ServicioAuditoria::class),
                ['senal' => $senal, 'latido_trabajador' => sys_get_temp_dir() . '/kora-latido-inexistente.txt'] + require RAIZ . '/config/mantenimiento.php',
                (require RAIZ . '/config/app.php')['version_esquema']
            ));
        }, $ip);
    }

    /** Mensajes de error por campo de un 422, para que una aserción fallida diga qué se rechazó. */
    private static function errores(string $html): string
    {
        preg_match_all('/<li><a href="#campo-([^"]+)">([^<]+)</', $html, $m, PREG_SET_ORDER);
        return implode(' | ', array_map(static fn (array $x): string => $x[1] . ': ' . $x[2], $m));
    }

    private function centro(): int
    {
        return (int) self::$bd->valor("SELECT id FROM centros WHERE codigo = '9124'");
    }

    private function unidad(string $codigo): int
    {
        return (int) self::$bd->valor('SELECT id FROM unidades_productivas WHERE codigo = :c', ['c' => $codigo]);
    }

    private function usuario(string $correo): string
    {
        return (string) self::$bd->valor('SELECT id FROM usuarios WHERE correo = :c', ['c' => $correo]);
    }

    /** Día $dia del mes anterior: nunca futuro ni en un mes cerrado. */
    private function fecha(int $dia): string
    {
        return (new \DateTimeImmutable('first day of last month', new \DateTimeZone('America/Bogota')))->format('Y-m-') . sprintf('%02d', $dia);
    }

    private function producto(string $codigo, string $nombre, string $medida, bool $derivado = false, ?string $factor = null, ?string $metaMaxima = null): int
    {
        return self::$bd->insertar(
            'INSERT INTO productos (centro_id, codigo_sgac, nombre, medida, es_derivado_lacteo, factor_leche, meta_rendimiento_max) VALUES (:c, :s, :n, :m, :d, :f, :x)',
            ['c' => $this->centro(), 's' => $codigo, 'n' => $nombre, 'm' => $medida, 'd' => $derivado ? 1 : 0, 'f' => $factor, 'x' => $metaMaxima]
        );
    }

    private function proceso(string $codigo, string $unidad, ?int $producto, string $medida, string $texto, int $vigencia): int
    {
        return self::$bd->insertar(
            "INSERT INTO procesos_autoconsumo (centro_id, codigo, vigencia, unidad_id, destino_texto, producto_id, producto_texto, cupo_cantidad, medida, cupo_valor)
             VALUES (:c, :codigo, :v, :u, 'Bienestar al Aprendiz', :p, :t, 4000, :m, 8000000)",
            ['c' => $this->centro(), 'codigo' => $codigo, 'v' => $vigencia, 'u' => $this->unidad($unidad), 'p' => $producto, 't' => $texto, 'm' => $medida]
        );
    }

    private function salidaHeredada(string $canal, ?string $numero, string $cantidad): int
    {
        return self::$bd->insertar(
            "INSERT INTO salidas (centro_id, unidad_id, numero_interno_texto, fecha, nombre_producto_fuente, cantidad, medida, precio_unitario, valor, canal, clave_natural, origen_tipo)
             VALUES (:c, :u, :n, :f, 'Leche', :q, 'L', 2000, :v, :canal, :k, 'importacion')",
            ['c' => $this->centro(), 'u' => $this->unidad('GAN'), 'n' => $numero, 'f' => $this->fecha(10), 'q' => $cantidad, 'v' => (string) ((int) $cantidad * 2000), 'canal' => $canal, 'k' => hash('sha256', $canal . $cantidad)]
        );
    }
}
