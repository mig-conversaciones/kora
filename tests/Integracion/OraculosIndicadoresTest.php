<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Cola\Trabajador;
use App\Importacion\ServicioCargaArchivos;
use App\Nucleo\Aplicacion;
use App\Nucleo\BaseDatos;
use App\Nucleo\Contenedor;
use App\Nucleo\Migrador;
use App\Politicas\AlcanceDatos;
use App\Politicas\IdentidadActor;
use App\Servicios\ServicioParametros;
use App\Tableros\FiltrosTablero;
use App\Tableros\ServicioExportacion;
use App\Tableros\ServicioIndicadores;
use PHPUnit\Framework\TestCase;

/**
 * Oráculos de los indicadores (RF-066) y tableros de la I6 de extremo a extremo.
 *
 * Tres conjuntos:
 *   1. Escenario del centro (vigencia 2026), armado con las cifras de 2.10: asignación, venta directa, autoconsumo,
 *      Leche homologada, canales excluyentes, presupuesto anual y alcance del líder (Obs. 4).
 *   2. Escenario de capacitación (vigencia 2027), equivalente al del prototipo (makeDemo): IND_002, IND_08, IND_12 e
 *      IND_17, con las mismas variaciones de sus 23 pruebas.
 *   3. Libros reales de referencias/, solo si están en la máquina (no se versionan: tienen datos personales). Se
 *      importan con el flujo real y se comparan las cifras con 2.10. En la CI se omite.
 * Ninguna falla tolera diferencias: son pruebas de no regresión de las fórmulas.
 */
final class OraculosIndicadoresTest extends TestCase
{
    use AyudasCuentas;

    private const TABLAS = ['exportaciones', 'conciliaciones_recaudo', 'incidencias_calidad', 'registros_reproductivos', 'cortes_hato', 'animales', 'transformaciones', 'salidas',
        'recepciones_lote', 'recaudos', 'archivos_adjuntos', 'gastos_ejecutados', 'asignaciones_presupuestales',
        'procesos_autoconsumo', 'periodos_cierre', 'homologaciones_producto', 'productos', 'parametros_indicadores', 'categorias_gasto', 'versiones_datos', 'filas_importacion', 'lotes_importacion',
        'claves_idempotencia', 'codigos_respaldo_2fa', 'tokens_cuenta', 'sesiones', 'notificaciones', 'usuarios_unidades', 'usuarios_roles',
        'trabajos_fallidos', 'trabajos', 'limites_tasa', 'eventos_auditoria'];

    private static BaseDatos $bd;
    private int $centro;
    /** @var array<string,int> */
    private array $productos = [];
    private int $secuencia = 0;
    private string $directorio;

    public static function setUpBeforeClass(): void
    {
        self::$bd = new BaseDatos(require RAIZ . '/config/base_datos.php');
        (new Migrador(self::$bd, RAIZ . '/bd/migraciones'))->aplicar();
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::TABLAS as $tabla) {
            self::$bd->ejecutar("DELETE FROM {$tabla}");
        }
        self::$bd->ejecutar('DELETE FROM usuarios');
    }

    protected function setUp(): void
    {
        foreach (self::TABLAS as $tabla) {
            self::$bd->ejecutar("DELETE FROM {$tabla}");
        }
        self::$bd->ejecutar('DELETE FROM usuarios');
        self::$bd->ejecutar("UPDATE cadena_auditoria SET ultima_secuencia = 0, ultimo_hash = REPEAT('0', 64) WHERE id = 1");
        self::$bd->ejecutar('DELETE FROM roles_permisos');
        $migrador = new Migrador(self::$bd, RAIZ . '/bd/migraciones');
        $migrador->sembrar(RAIZ . '/bd/semillas/001_identidad_y_estructura.sql');
        $migrador->sembrar(RAIZ . '/bd/semillas/002_catalogos_finanzas.sql');
        $this->centro = (int) self::$bd->valor("SELECT id FROM centros WHERE codigo = '9124'");
        // La caché de tableros vive en archivos: una versión de datos aleatoria evita leer entradas de otra ejecución.
        self::$bd->ejecutar('UPDATE versiones_datos SET version = :v WHERE centro_id = :c', ['v' => random_int(1_000_000, 2_000_000_000), 'c' => $this->centro]);
        $this->directorio = sys_get_temp_dir() . '/kora-oraculos-' . bin2hex(random_bytes(4));
    }

    // ---------------------------------------------------------------- 1. Centro, vigencia 2026 (2.10)

    public function testIndicadoresDelCentroReproducenLosOraculosDe210(): void
    {
        $this->escenarioCentro();
        $centro = $this->alcanceCentro();
        $gan = $this->filtros(['vigencia' => '2026', 'unidad' => (string) $this->unidad('GAN')], $centro);

        $m = $this->indicadores()->central($centro, $gan);
        self::assertSame('185000000.00', $m['kpi']['asignacion']);
        self::assertSame('3265000.00', $m['kpi']['venta_directa']);
        self::assertSame(61, $m['kpi']['recaudos']);
        self::assertSame('176000.00', $m['kpi']['autoconsumo']);
        self::assertSame('3441000.00', $m['kpi']['total']);
        self::assertSame('181559000.00', $m['kpi']['saldo']);
        self::assertEqualsWithDelta(3441000 / 185000000 * 100, $m['kpi']['cobertura'], 1e-4);
        self::assertSame(['45000000.00', '80000000.00', '60000000.00'], [$m['unidades'][0]['mantenimiento'], $m['unidades'][0]['alimentacion'], $m['unidades'][0]['materiales_formacion']]);

        $todo = $this->indicadores()->central($centro, $this->filtros(['vigencia' => '2026'], $centro));
        self::assertSame('1024000000.00', $todo['kpi']['asignacion'], 'Centro: 9 asignaciones.');
        self::assertSame(9, $todo['kpi']['asignaciones']);
        self::assertSame(1, $todo['sin_unidad']['recaudos'], 'El recaudo ambiguo no se reparte.');

        // El presupuesto es anual; el recaudo sí se filtra por fechas.
        $enero = $this->indicadores()->central($centro, $this->filtros(['vigencia' => '2026', 'unidad' => (string) $this->unidad('GAN'), 'desde' => '2026-01-01', 'hasta' => '2026-01-31'], $centro));
        self::assertSame(['185000000.00', '0.00', '176000.00'], [$enero['kpi']['asignacion'], $enero['kpi']['venta_directa'], $enero['kpi']['autoconsumo']]);

        // Canales excluyentes: el total de «Todos» es la suma de los dos canales.
        $auto = $this->indicadores()->central($centro, $this->filtros(['vigencia' => '2026', 'unidad' => (string) $this->unidad('GAN'), 'canal' => 'autoconsumo'], $centro));
        $directa = $this->indicadores()->central($centro, $this->filtros(['vigencia' => '2026', 'unidad' => (string) $this->unidad('GAN'), 'canal' => 'venta_directa'], $centro));
        self::assertSame('0.00', $auto['kpi']['venta_directa']);
        self::assertSame('0.00', $directa['kpi']['autoconsumo']);
        self::assertSame((float) $m['kpi']['total'], (float) $auto['kpi']['total'] + (float) $directa['kpi']['total']);

        // Leche homologada de forma explícita; un producto inexistente da 0.
        $leche = $this->indicadores()->central($centro, $this->filtros(['vigencia' => '2026', 'unidad' => (string) $this->unidad('GAN'), 'producto' => 'p' . $this->productos['leche']], $centro));
        self::assertSame('3045000.00', $leche['kpi']['venta_directa']);
        $nada = $this->indicadores()->central($centro, $this->filtros(['vigencia' => '2026', 'unidad' => (string) $this->unidad('GAN'), 'producto' => 'p999999'], $centro));
        self::assertSame('0.00', $nada['kpi']['venta_directa']);

        // AUTO_001: 0 % de avance porque las entregas no tienen proceso; se avisan como pendientes.
        $proceso = $m['procesos'][0];
        self::assertSame(['AUTO_001', '0.000', 0.0], [$proceso['codigo'], $proceso['entregado'], $proceso['avance_fisico']]);
        self::assertSame(2, $m['sin_proceso']['salidas']);

        // Estado de la información (RF-069).
        $estado = $this->indicadores()->estadoInformacion($centro);
        self::assertSame(['2026-04-01', '2026-04-24', 2, 2, '2026-11-09'], [$estado['recaudos_desde'], $estado['recaudos_hasta'], $estado['salidas_sin_recepcion'], $estado['entregas_sin_proceso'], $estado['corte_hato']]);

        // Sin presupuesto ni población no hay porcentajes infinitos.
        self::assertNull(ServicioIndicadores::porcentaje('10.00', '0.00'));
        self::assertNull(ServicioIndicadores::cociente(3, 0));
    }

    public function testIndicadorDelHatoConYSinLoteDePractica(): void
    {
        $this->escenarioCentro();
        $alcance = $this->alcanceCentro();
        $sin = $this->indicadores()->hato($alcance, $this->unidad('GAN'), false);
        $con = $this->indicadores()->hato($alcance, $this->unidad('GAN'), true);
        self::assertSame([35, 21, 10, 4, 31], [$sin['inventario'], $sin['produccion'], $sin['horra'], $sin['practica'], $sin['poblacion']]);
        self::assertEqualsWithDelta(67.7419, $sin['tasa'], 1e-4);
        self::assertEqualsWithDelta(60.0, $con['tasa'], 1e-9);
        self::assertEqualsWithDelta(60.0, $sin['tasa_completa'], 1e-9, 'El anillo siempre muestra las 35 hembras.');
        self::assertSame('advertencia', $sin['semaforo']['tono'], '67,7 % está bajo la meta del 75 %.');
        self::assertEqualsWithDelta(4 / 35 * 100, $sin['disponibilidad'], 1e-4);
    }

    // ---------------------------------------------------------------- 2. Capacitación, vigencia 2027 (prototipo)

    public function testEscenarioDeCapacitacionReproduceLasPruebasDelPrototipo(): void
    {
        $ids = $this->escenarioCapacitacion();
        $alcance = $this->alcanceCentro();
        $gan = $this->unidad('GAN');
        $f = $this->filtros(['vigencia' => '2027', 'unidad' => (string) $gan], $alcance);

        // IND_002: 3.200 L entregados de 4.000 (80 %), 5.760.000 COP.
        $auto = $this->indicadores()->procesos($alcance, $f)[0];
        self::assertSame(['AUTO_001', '3200.000', '5760000.00', 80.0, 4], [$auto['codigo'], $auto['entregado'], $auto['valor'], $auto['avance_fisico'], $auto['lotes']]);

        // IND_08: aprovechamiento global del 97 % con cuatro derivados.
        $t = $this->indicadores()->transformacion($alcance, $gan, $f);
        self::assertCount(4, $t['lotes']);
        self::assertEqualsWithDelta(97.0, $t['global'], 0.001);
        $queso = array_values(array_filter($t['derivados'], static fn (array $d): bool => $d['producto'] === 'Queso cuajada DEMO'))[0];
        self::assertTrue($queso['en_meta'], '800 L / 97 kg = 8,25 L/kg, dentro del máximo de 8,5.');

        // IND_17: (24.400.000 − 3.000.000) / 16.000 = 1.337,5 COP/L; meta de 1.650.
        $c = $this->indicadores()->costoLitro($alcance, $gan, $f);
        self::assertSame(['16000.000', '24400000.00', '3000000.00'], [$c['litros'], $c['bruto'], $c['academico']]);
        self::assertEqualsWithDelta(1337.5, $c['costo'], 1e-9);
        self::assertEqualsWithDelta(1650.0, $c['meta'], 1e-9);
        self::assertTrue($c['en_meta']);
        self::assertNotNull($c['presupuestado'], 'Con la vigencia completa se muestra el costo presupuestado como referencia (D-20).');

        // IND_12: el lote de leche se cierra al primer día.
        $hasta = $this->filtros(['vigencia' => '2027', 'unidad' => (string) $gan, 'hasta' => '2027-04-30'], $alcance);
        $leche = $this->lote($this->indicadores()->rotacion($alcance, $gan, $hasta, '2027-12-31'), 'GAN-REC-DEMO-001');
        self::assertSame([1, '2027-04-06'], [$leche['dias'], $leche['cierre']]);

        // Variaciones del prototipo, en el mismo orden:
        // a) cambiar el cupo no altera el costo por litro;
        self::$bd->ejecutar('UPDATE procesos_autoconsumo SET cupo_cantidad = 100000 WHERE id = :id', ['id' => $ids['auto_001']]);
        self::assertEqualsWithDelta(1337.5, $this->indicadores()->costoLitro($alcance, $gan, $f)['costo'], 1e-9);
        // b) sin gastos en el periodo el costo queda pendiente;
        self::assertNull($this->indicadores()->costoLitro($alcance, $gan, $this->filtros(['vigencia' => '2027', 'unidad' => (string) $gan, 'desde' => '2027-05-01'], $alcance))['costo']);
        // c) una entrega con medida incompatible no suma;
        self::$bd->ejecutar("UPDATE salidas SET medida = 'kg' WHERE id = :id", ['id' => $ids['salida_auto_1']]);
        self::$bd->ejecutar('UPDATE procesos_autoconsumo SET cupo_cantidad = 4000 WHERE id = :id', ['id' => $ids['auto_001']]);
        $incompatible = $this->indicadores()->procesos($alcance, $f)[0];
        self::assertSame(['2400.000', 1], [$incompatible['entregado'], $incompatible['incompatibles']]);
        self::$bd->ejecutar("UPDATE salidas SET medida = 'L' WHERE id = :id", ['id' => $ids['salida_auto_1']]);
        // d) quitar el factor del queso deja 3 lotes con vínculo;
        self::$bd->ejecutar('UPDATE productos SET factor_leche = 0 WHERE id = :id', ['id' => $this->productos['queso']]);
        self::assertCount(3, $this->indicadores()->transformacion($alcance, $gan, $f)['lotes']);
        // e) un lote con saldo queda abierto, con su antigüedad, y fuera del promedio;
        self::$bd->ejecutar('UPDATE salidas SET cantidad = 3000 WHERE id = :id', ['id' => $ids['salida_venta_1']]);
        $abierto = $this->lote($this->indicadores()->rotacion($alcance, $gan, $hasta, '2027-12-31'), 'GAN-REC-DEMO-001');
        self::assertSame([null, '200.000', 25], [$abierto['cierre'], $abierto['saldo'], $abierto['dias']]);
        // f) una salida parcial anterior al filtro también cuenta para el cierre.
        self::$bd->ejecutar('UPDATE salidas SET cantidad = 3200 WHERE id = :id', ['id' => $ids['salida_venta_1']]);
        self::$bd->ejecutar("UPDATE recepciones_lote SET fecha_recepcion = '2027-04-06' WHERE id = :id", ['id' => $ids['lote_leche_1']]);
        self::$bd->ejecutar("UPDATE salidas SET fecha = '2027-04-04' WHERE id = :id", ['id' => $ids['salida_auto_1']]);
        $parcial = $this->lote($this->indicadores()->rotacion($alcance, $gan, $this->filtros(['vigencia' => '2027', 'unidad' => (string) $gan, 'desde' => '2027-04-06', 'hasta' => '2027-04-30'], $alcance), '2027-12-31'), 'GAN-REC-DEMO-001');
        self::assertSame('4000.000', $parcial['vendido']);
        self::assertNotNull($parcial['cierre']);
    }

    public function testFiltrosValidanRangoYNoAmplianElAlcance(): void
    {
        $alcance = AlcanceDatos::deUsuario(1, [5], false);
        $invertido = FiltrosTablero::desdeConsulta(['desde' => '2026-05-01', 'hasta' => '2026-04-01'], [5], 2026);
        self::assertFalse($invertido->esValido());
        self::assertArrayHasKey('hasta', $invertido->errores);
        $fuera = FiltrosTablero::desdeConsulta(['unidad' => '9', 'vigencia' => '2026', 'canal' => 'raro'], [5], 2026);
        self::assertNull($fuera->unidadId, 'Una unidad fuera del alcance se descarta.');
        self::assertSame('todos', $fuera->canal);
        $recortado = FiltrosTablero::desdeConsulta(['vigencia' => '2026', 'desde' => '2025-06-01', 'hasta' => '2027-02-01'], [5], 2026);
        self::assertSame(['2026-01-01', '2026-12-31', true], [$recortado->inicio(), $recortado->fin(), $recortado->vigenciaCompleta()]);
        self::assertSame(['vigencia' => '2026', 'desde' => '2025-06-01', 'hasta' => '2027-02-01'], $recortado->aConsulta(), 'Recargar la URL reproduce el estado.');
        self::assertTrue($alcance->permiteUnidad(5));
    }

    // ---------------------------------------------------------------- De extremo a extremo

    public function testTableroCentralDelLiderSoloContieneSusUnidadesYLaCacheSeInvalida(): void
    {
        $this->escenarioCentro();
        $lider = new NavegadorPrueba(null, '190.85.16.10');
        $this->cuentaActiva(self::$bd, $lider, 'lider.gan@kora.local', 'Lucía Ganadería', 'lider_unidad', ['GAN']);
        $html = $lider->get('/tableros/central?vigencia=2026')->cuerpo;
        self::assertStringContainsString('185 M', $html);
        self::assertStringContainsString('3,27 M', $html);
        self::assertStringContainsString('176 mil', $html);
        self::assertStringContainsString('3,44 M', $html);
        self::assertStringNotContainsString('1,02 mil M', $html, 'Obs. 4: el líder no ve la asignación del centro.');
        self::assertStringNotContainsString('Granja', $html, 'Ninguna otra unidad en el filtro, el gráfico ni las tablas.');
        self::assertStringNotContainsString('100.000.000', $html);

        // Manipular la unidad hacia otra fuera del alcance no trae sus datos.
        $manipulado = $lider->get('/tableros/central?vigencia=2026&unidad=' . $this->unidad('GRA'))->cuerpo;
        self::assertStringContainsString('Esa unidad no está en tu alcance', $manipulado);
        self::assertStringNotContainsString('Granja', str_replace('Esa unidad no está en tu alcance', '', $manipulado));

        // Rango invertido: 422 con el mensaje en línea y sin cifras.
        $invertido = $lider->getHtmx('/tableros/central?vigencia=2026&desde=2026-05-01&hasta=2026-04-01');
        self::assertSame(422, $invertido->estado);
        self::assertStringContainsString('no puede ser anterior', $invertido->cuerpo);
        self::assertStringNotContainsString('185 M', $invertido->cuerpo);

        // La caché se invalida en cuanto cambia la versión de datos (escritura relevante).
        self::assertStringContainsString('176 mil', $lider->get('/tableros/central?vigencia=2026')->cuerpo);
        $this->salida('GAN', '2026-01-20', 'leche', '10', '2400', 'AUTOCONSUMO');
        self::assertStringContainsString('176 mil', $lider->get('/tableros/central?vigencia=2026')->cuerpo, 'Sin incrementar la versión se sirve la caché.');
        self::$bd->ejecutar('UPDATE versiones_datos SET version = version + 1 WHERE centro_id = :c', ['c' => $this->centro]);
        self::assertStringContainsString('200 mil', $lider->get('/tableros/central?vigencia=2026')->cuerpo);

        // IND_002 en unidades físicas se recarga como fragmento.
        $procesos = $lider->getHtmx('/tableros/central/procesos?vigencia=2026&modo=unidades');
        self::assertStringContainsString('id="seccion-procesos"', $procesos->cuerpo);
        self::assertStringContainsString('no se suman cantidades de medidas distintas', mb_strtolower($procesos->cuerpo));
    }

    public function testTableroTecnicoConservaFiltrosYRespetaPracticaYAlcance(): void
    {
        $this->escenarioCentro();
        $academica = new NavegadorPrueba(null, '190.85.16.20');
        $this->cuentaActiva(self::$bd, $academica, 'coordinacion.academica@kora.local', 'Ana Académica', 'coordinacion_academica');
        $volver = '/tableros/central?vigencia=2026&desde=2026-01-01';
        $html = $academica->get('/tableros/unidad/GAN?vigencia=2026&desde=2026-01-01&volver=' . rawurlencode($volver))->cuerpo;
        self::assertStringContainsString('09/11/2026', $html, 'Corte del hato en la cabecera.');
        self::assertStringContainsString('60,0 %', $html, 'La Coordinación académica incluye la práctica por defecto.');
        self::assertStringContainsString(htmlspecialchars('/tableros/central?vigencia=2026&desde=2026-01-01'), $html, 'Volver recupera los filtros.');
        self::assertLessThan(strpos($html, 'titulo-ind003'), strpos($html, 'titulo-salud'), 'Para la Coordinación académica la tabla va antes que los gráficos.');

        $sinPractica = $academica->getHtmx('/tableros/unidad/GAN/hato?vigencia=2026&practica=0');
        self::assertStringContainsString('67,7 %', $sinPractica->cuerpo);
        self::assertStringContainsString('id="seccion-hato"', $sinPractica->cuerpo);

        self::assertStringContainsString('Tablero técnico en preparación', $academica->get('/tableros/unidad/GRA?vigencia=2026')->cuerpo);

        $lider = new NavegadorPrueba(null, '190.85.16.21');
        $this->cuentaActiva(self::$bd, $lider, 'lider.gra@kora.local', 'Gabriel Granja', 'lider_unidad', ['GRA']);
        self::assertSame(404, $lider->get('/tableros/unidad/GAN')->estado, 'Otra unidad: no existe para él.');
    }

    public function testExportacionesRespetanAlcanceCaducidadYAutor(): void
    {
        $this->escenarioCentro();
        $lider = $this->navegador('190.85.16.30');
        $this->cuentaActiva(self::$bd, $lider, 'lider.gan@kora.local', 'Lucía Ganadería', 'lider_unidad', ['GAN']);
        $lider->get('/tableros/central?vigencia=2026');

        $pedido = $lider->postHtmx('/exportaciones', ['tipo' => 'tablero_central', 'formato' => 'csv', 'vigencia' => '2026']);
        self::assertSame(202, $pedido->estado, $pedido->cuerpo);
        $this->procesarCola();
        $exportacion = self::$bd->fila('SELECT * FROM exportaciones ORDER BY id DESC LIMIT 1');
        $listo = $lider->getHtmx('/exportaciones/' . $exportacion['ulid']);
        self::assertStringContainsString('Descargar', $listo->cuerpo);
        $archivo = $lider->get('/exportaciones/' . $exportacion['ulid'] . '/descargar');
        self::assertSame(200, $archivo->estado);
        self::assertSame('private, no-store', $archivo->cabeceras['Cache-Control']);
        self::assertStringStartsWith('attachment;', $archivo->cabeceras['Content-Disposition']);
        self::assertStringStartsWith("\xEF\xBB\xBF", $archivo->cuerpo, 'CSV en UTF-8 con BOM.');
        self::assertStringContainsString('"Ganadería",185000000,45000000,80000000,60000000,3265000,176000,3441000,1.86', $archivo->cuerpo, 'Los números van como números.');
        self::assertStringNotContainsString('Granja', $archivo->cuerpo, 'La exportación del líder solo contiene GAN.');

        // XLSX y PDF del técnico; el PDF reproduce las cifras de pantalla.
        $lider->postHtmx('/exportaciones', ['tipo' => 'tablero_unidad', 'formato' => 'pdf', 'vigencia' => '2026', 'unidad_tecnica' => (string) $this->unidad('GAN'), 'practica' => '0']);
        $lider->postHtmx('/exportaciones', ['tipo' => 'recaudos', 'formato' => 'xlsx', 'vigencia' => '2026']);
        $this->procesarCola();
        $pdf = self::$bd->fila("SELECT * FROM exportaciones WHERE formato = 'pdf'");
        self::assertNotNull($pdf['ruta_privada']);
        self::assertStringStartsWith('%PDF', $lider->get('/exportaciones/' . $pdf['ulid'] . '/descargar')->cuerpo);
        $servicio = Aplicacion::crear(require RAIZ . '/config/app.php')->contenedor()->obtener(ServicioExportacion::class);
        $actor = IdentidadActor::cargar(self::$bd, (int) $pdf['usuario_id'], $this->centro);
        $html = $servicio->htmlPdf($servicio->datos('tablero_unidad', json_decode((string) $pdf['filtros'], true), $actor));
        self::assertStringContainsString('67,7 %', $html);
        self::assertStringContainsString('Lucía Ganadería', $html);
        self::assertStringContainsString('09/11/2026', $html);
        $recaudos = self::$bd->fila("SELECT * FROM exportaciones WHERE tipo = 'recaudos'");
        self::assertSame(200, $lider->get('/exportaciones/' . $recaudos['ulid'] . '/descargar')->estado);
        $columnas = $servicio->datos('recaudos', ['vigencia' => 2026], $actor)['tablas'][0]['columnas'];
        self::assertSame([], preg_grep('/identificaci|pagador|nombre/i', $columnas), 'Sin columnas de datos personales (RF-034).');

        // Otro usuario recibe 404; vencido, el archivo deja de estar disponible y la limpieza lo borra.
        $otro = $this->navegador('190.85.16.31');
        $this->cuentaActiva(self::$bd, $otro, 'coordinacion.admin@kora.local', 'Carla Administración', 'coordinacion_administrativa');
        self::assertSame(404, $otro->get('/exportaciones/' . $exportacion['ulid'] . '/descargar')->estado);
        self::$bd->ejecutar('UPDATE exportaciones SET expira_en = UTC_TIMESTAMP(3) - INTERVAL 1 MINUTE WHERE id = :id', ['id' => $exportacion['id']]);
        self::assertSame(404, $lider->get('/exportaciones/' . $exportacion['ulid'] . '/descargar')->estado);
        $ruta = RAIZ . '/almacenamiento/exportaciones/' . $exportacion['ruta_privada'];
        self::assertFileExists($ruta);
        self::assertGreaterThanOrEqual(1, $servicio->limpiarVencidas());
        self::assertNull(self::$bd->valor('SELECT ruta_privada FROM exportaciones WHERE id = :id', ['id' => $exportacion['id']]));
        self::assertFileDoesNotExist($ruta);
    }

    // ---------------------------------------------------------------- 3. Libros reales (solo en local)

    public function testLibrosRealesReproducenLosOraculosDe210(): void
    {
        $libros = ['admon' => RAIZ . '/referencias/BBDD_DATOS ADMON.xlsx', 'produccion' => RAIZ . '/referencias/BBDD_Produccion de Centro.xlsx', 'hato' => RAIZ . '/referencias/INFO_Hato.xlsx'];
        foreach ($libros as $ruta) {
            if (!is_file($ruta)) {
                self::markTestSkipped('Los libros reales no están en esta máquina (no se versionan).');
            }
        }
        $usuario = $this->navegador('190.85.16.40');
        $this->cuentaActiva(self::$bd, $usuario, 'carga.piloto@kora.local', 'Carga del piloto', 'coordinacion_administrativa');
        $id = (int) self::$bd->valor("SELECT id FROM usuarios WHERE correo = 'carga.piloto@kora.local'");
        foreach (['grupo_recaudo', 'lider_produccion_centro', 'coordinacion_academica'] as $rol) {
            self::$bd->ejecutar('INSERT INTO usuarios_roles (usuario_id, rol_id) SELECT :u, id FROM roles WHERE codigo = :r', ['u' => $id, 'r' => $rol]);
        }
        self::$bd->ejecutar('UPDATE usuarios SET version_permisos = version_permisos + 1 WHERE id = :u', ['u' => $id]);
        $usuario->get('/importaciones');

        $this->importarReal($usuario, $libros['produccion'], ['catalogo_productos'], []);
        $this->importarReal($usuario, $libros['admon'], ['presupuesto', 'cupos_autoconsumo', 'recaudos_dg'], ['vigencia' => '2026']);
        $this->importarReal($usuario, $libros['produccion'], ['base_produccion'], []);
        $this->importarReal($usuario, $libros['produccion'], ['base_ventas'], []);
        $this->importarReal($usuario, $libros['hato'], ['estado_reproductivo'], ['unidad_hato' => (string) $this->unidad('GAN'), 'fecha_corte' => '2026-11-09']);

        $alcance = $this->alcanceCentro();
        $centro = $this->indicadores()->central($alcance, $this->filtros(['vigencia' => '2026'], $alcance));
        $gan = $this->indicadores()->central($alcance, $this->filtros(['vigencia' => '2026', 'unidad' => (string) $this->unidad('GAN')], $alcance));
        $leche = (int) self::$bd->valor("SELECT id FROM productos WHERE codigo_sgac = '5013170101'");
        $ganLeche = $this->indicadores()->central($alcance, $this->filtros(['vigencia' => '2026', 'unidad' => (string) $this->unidad('GAN'), 'producto' => 'p' . $leche], $alcance));
        $hato = $this->indicadores()->hato($alcance, $this->unidad('GAN'), false);
        $estado = $this->indicadores()->estadoInformacion($alcance);
        $auto = array_values(array_filter($gan['procesos'], static fn (array $p): bool => $p['codigo'] === 'AUTO_001'));

        self::assertSame('1024000000.00', $centro['kpi']['asignacion']);
        self::assertSame(9, $centro['kpi']['asignaciones']);
        self::assertSame('185000000.00', $gan['kpi']['asignacion']);
        self::assertSame(['3265000.00', 61], [$gan['kpi']['venta_directa'], $gan['kpi']['recaudos']]);
        self::assertSame('176000.00', $gan['kpi']['autoconsumo']);
        self::assertSame('3441000.00', $gan['kpi']['total']);
        self::assertSame('3045000.00', $ganLeche['kpi']['venta_directa']);
        self::assertSame(0.0, $auto[0]['avance_fisico'] ?? null);
        self::assertSame([35, 21, 10, 4], [$hato['inventario'], $hato['produccion'], $hato['horra'], $hato['practica']]);
        self::assertEqualsWithDelta(67.7419, $hato['tasa'], 1e-4);
        // RF-069 dice «al 24/04/2026», pero el libro y la extracción del prototipo terminan el 19/04/2026: errata del SRS.
        self::assertSame(['2026-04-01', '2026-04-19', '2026-11-09'], [$estado['recaudos_desde'], $estado['recaudos_hasta'], $estado['corte_hato']]);
        // 2.10 cuenta 146 salidas y 145 sin recepción; una fila de la base de ventas no tiene medida ni producto y se rechaza
        // en la importación (queda en el reporte de errores): 144 aplicadas sin recepción + 1 rechazada = 145.
        $rechazadas = (int) self::$bd->valor("SELECT filas_error FROM lotes_importacion WHERE fuente = 'base_ventas'");
        self::assertSame([144, 1, 17, 1], [$estado['salidas_sin_recepcion'], $rechazadas, $estado['entregas_sin_proceso'], $estado['recaudos_sin_unidad']]);
    }

    // ---------------------------------------------------------------- Escenarios

    /** Centro 2026 con las cifras de 2.10 (sintéticas, sin datos personales). */
    private function escenarioCentro(): void
    {
        $this->productosBase();
        $asignaciones = ['GAN' => [185000000, 45000000, 80000000, 60000000], 'GRA' => [100000000, 0, 0, 0], 'API' => [50000000, 0, 0, 0], 'AGR' => [150000000, 0, 0, 0],
            'HOR' => [60000000, 0, 0, 0], 'INV' => [80000000, 0, 0, 0], 'IND' => [200000000, 0, 0, 0], 'GOU' => [99000000, 0, 0, 0], 'CAF' => [100000000, 0, 0, 0]];
        foreach ($asignaciones as $codigo => [$total, $mant, $alim, $mat]) {
            self::$bd->ejecutar(
                'INSERT INTO asignaciones_presupuestales (centro_id, unidad_id, vigencia, valor_total, mantenimiento, alimentacion, materiales_formacion) VALUES (:c, :u, 2026, :t, :m, :a, :f)',
                ['c' => $this->centro, 'u' => $this->unidad($codigo), 't' => $total, 'm' => $mant, 'a' => $alim, 'f' => $mat]
            );
        }
        // 61 recaudos «Producción» de GAN por 3.265.000: 60 de Leche por 3.045.000 y uno de un concepto sin homologar.
        for ($i = 1; $i <= 60; $i++) {
            $this->recaudo('GAN', sprintf('2026-04-%02d', 1 + intdiv($i - 1, 3)), $i === 60 ? '50000' : '50762.71', 'leche');
        }
        // Ajuste para que la suma sea exacta: 59 × 50.762,71 + 50.000 = 3.045.000 − 0,11 → se corrige con el primero.
        self::$bd->ejecutar('UPDATE recaudos SET valor = valor + 0.11 WHERE id = (SELECT id FROM (SELECT MIN(id) AS id FROM recaudos) x)');
        $this->recaudo('GAN', '2026-04-24', '220000', null, 'Queso');
        $this->recaudo('GAN', '2026-04-10', '99999', 'leche', null, 'Servicios');
        $this->recaudo('GRA', '2026-04-15', '500000', null, 'Huevos');
        $this->recaudo(null, '2026-04-12', '100000', null, 'Ganadería y Granja');
        // Autoconsumo de GAN en enero: 176.000, sin proceso y sin recepción (como en la base fuente).
        $this->salida('GAN', '2026-01-10', 'leche', '50', '2000', 'AUTOCONSUMO');
        $this->salida('GAN', '2026-01-15', 'leche', '38', '2000', 'AUTOCONSUMO');
        self::$bd->ejecutar(
            "INSERT INTO procesos_autoconsumo (centro_id, unidad_id, codigo, vigencia, producto_id, producto_texto, cupo_cantidad, medida, cupo_valor)
             VALUES (:c, :u, 'AUTO_001', 2026, :p, 'Leche de vaca', 1000, 'L', 2000000)",
            ['c' => $this->centro, 'u' => $this->unidad('GAN'), 'p' => $this->productos['leche']]
        );
        // Hato: 21 en producción, 10 horras y 4 de práctica; 12 preñadas.
        self::$bd->ejecutar("INSERT INTO cortes_hato (centro_id, unidad_id, fecha_corte, vigente) VALUES (:c, :u, '2026-11-09', 1)", ['c' => $this->centro, 'u' => $this->unidad('GAN')]);
        $corte = (int) self::$bd->valor('SELECT MAX(id) FROM cortes_hato');
        for ($i = 1; $i <= 35; $i++) {
            $lote = $i <= 21 ? 'PRODUCCION' : ($i <= 31 ? 'HORRA' : 'PRACTICA');
            self::$bd->ejecutar('INSERT INTO animales (centro_id, unidad_id, identificador) VALUES (:c, :u, :i)', ['c' => $this->centro, 'u' => $this->unidad('GAN'), 'i' => (string) (100 + $i)]);
            self::$bd->ejecutar(
                'INSERT INTO registros_reproductivos (centro_id, unidad_id, corte_id, animal_id, lote_hato, estado_reproductivo, iep_dias, dias_abiertos)
                 VALUES (:c, :u, :corte, :a, :l, :e, :iep, :da)',
                ['c' => $this->centro, 'u' => $this->unidad('GAN'), 'corte' => $corte, 'a' => (int) self::$bd->valor('SELECT MAX(id) FROM animales'), 'l' => $lote,
                    'e' => $i % 3 === 0 ? 'PREÑADA' : 'VACÍA', 'iep' => 350 + $i * 2, 'da' => 80 + $i * 2]
            );
        }
    }

    /**
     * Escenario de capacitación del prototipo (makeDemo) en la vigencia 2027.
     *
     * @return array<string,int>
     */
    private function escenarioCapacitacion(): array
    {
        $this->productosBase();
        $gan = $this->unidad('GAN');
        $ids = [];
        foreach ([['AUTO_001', 'leche', 4000, 'L', 7200000], ['AUTO_002', 'bovino', 1000, 'kg', 8000000]] as [$codigo, $producto, $cupo, $medida, $valor]) {
            self::$bd->ejecutar(
                'INSERT INTO procesos_autoconsumo (centro_id, unidad_id, codigo, vigencia, producto_id, producto_texto, cupo_cantidad, medida, cupo_valor, destino_texto)
                 VALUES (:c, :u, :cod, 2027, :p, :t, :q, :m, :v, \'Agroindustria\')',
                ['c' => $this->centro, 'u' => $gan, 'cod' => $codigo, 'p' => $this->productos[$producto], 't' => $producto, 'q' => $cupo, 'm' => $medida, 'v' => $valor]
            );
        }
        $ids['auto_001'] = (int) self::$bd->valor("SELECT id FROM procesos_autoconsumo WHERE codigo = 'AUTO_001' AND vigencia = 2027");
        $auto002 = (int) self::$bd->valor("SELECT id FROM procesos_autoconsumo WHERE codigo = 'AUTO_002' AND vigencia = 2027");
        $derivados = [['queso', 8.0], ['yogurt', 1.0], ['manjar', 3.4], ['cortado', 4.0]];
        for ($i = 1; $i <= 4; $i++) {
            $fecha = sprintf('2027-04-%02d', $i * 5);
            $siguiente = sprintf('2027-04-%02d', $i * 5 + 1);
            $lote = $this->recepcion('GAN', 'GAN-REC-DEMO-00' . $i, $fecha, 'leche', '4000', 'L');
            $auto = $this->salida('GAN', $fecha, 'leche', '800', '1800', 'AUTOCONSUMO', $lote, $ids['auto_001']);
            $venta = $this->salida('GAN', $siguiente, 'leche', '3200', '2200', 'VENTA_DIRECTA', $lote);
            if ($i === 1) {
                $ids += ['lote_leche_1' => $lote, 'salida_auto_1' => $auto, 'salida_venta_1' => $venta];
            }
            [$producto, $factor] = $derivados[$i - 1];
            $cantidad = number_format(800 * 0.97 / $factor, 3, '.', '');
            $derivado = $this->recepcion('IND', 'IND-REC-DEMO-00' . $i, $siguiente, $producto, $cantidad, $producto === 'yogurt' ? 'L' : 'kg');
            self::$bd->ejecutar(
                'INSERT INTO transformaciones (centro_id, unidad_id, fecha, salida_leche_id, recepcion_derivado_id, litros_asignados) VALUES (:c, :u, :f, :s, :r, 800)',
                ['c' => $this->centro, 'u' => $gan, 'f' => $siguiente, 's' => $auto, 'r' => $derivado]
            );
        }
        $bovino = $this->recepcion('GAN', 'GAN-REC-DEMO-BOV', '2027-04-01', 'bovino', '650', 'kg');
        $this->salida('GAN', '2027-04-19', 'bovino', '650', '8000', 'AUTOCONSUMO', $bovino, $auto002);
        $categorias = array_column(self::$bd->consultar('SELECT id, codigo FROM categorias_gasto WHERE centro_id = :c', ['c' => $this->centro]), 'id', 'codigo');
        foreach ([['ALIMENTACION', 18000000, 2200000], ['MANTENIMIENTO', 6400000, 800000], ['MATERIALES_FORMACION', 1000000, 0]] as [$categoria, $valor, $academico]) {
            self::$bd->ejecutar(
                "INSERT INTO gastos_ejecutados (centro_id, unidad_id, fecha, categoria_id, valor, valor_componente_academico) VALUES (:c, :u, '2027-04-30', :cat, :v, :a)",
                ['c' => $this->centro, 'u' => $gan, 'cat' => $categorias[$categoria], 'v' => $valor, 'a' => $academico]
            );
        }
        self::$bd->ejecutar(
            'INSERT INTO asignaciones_presupuestales (centro_id, unidad_id, vigencia, valor_total, mantenimiento, alimentacion, materiales_formacion) VALUES (:c, :u, 2027, 185000000, 45000000, 80000000, 60000000)',
            ['c' => $this->centro, 'u' => $gan]
        );
        return $ids;
    }

    private function productosBase(): void
    {
        $productos = [
            'leche' => ['5013170101', 'Leche de vaca', 'L', 'fresco', null, null],
            'bovino' => ['1010150100', 'Bovino en pie', 'kg', 'animal', null, null],
            'queso' => ['DEMO-QUESO', 'Queso cuajada DEMO', 'kg', 'fresco', 8.0, 8.5],
            'yogurt' => ['DEMO-YOGURT', 'Yogurt DEMO', 'L', 'fresco', 1.0, 1.05],
            'manjar' => ['DEMO-MANJAR', 'Manjar blanco DEMO', 'kg', 'fresco', 3.4, 3.6],
            'cortado' => ['DEMO-CORTADO', 'Cortado DEMO', 'kg', 'fresco', 4.0, 4.2],
        ];
        foreach ($productos as $clave => [$codigo, $nombre, $medida, $rotacion, $factor, $maximo]) {
            self::$bd->ejecutar(
                'INSERT INTO productos (centro_id, codigo_sgac, nombre, medida, categoria_rotacion, es_derivado_lacteo, factor_leche, meta_rendimiento_min, meta_rendimiento_max)
                 VALUES (:c, :s, :n, :m, :r, :d, :f, :min, :max)',
                ['c' => $this->centro, 's' => $codigo, 'n' => $nombre, 'm' => $medida, 'r' => $rotacion, 'd' => $factor === null ? 0 : 1, 'f' => $factor, 'min' => $factor, 'max' => $maximo]
            );
            $this->productos[$clave] = (int) self::$bd->valor('SELECT id FROM productos WHERE codigo_sgac = :s', ['s' => $codigo]);
        }
    }

    private function recaudo(?string $unidad, string $fecha, string $valor, ?string $producto, ?string $concepto = null, string $tipo = 'Producción'): void
    {
        $this->secuencia++;
        self::$bd->ejecutar(
            'INSERT INTO recaudos (centro_id, unidad_id, producto_id, concepto_fuente, fecha_pago, valor, tipo_ingreso, estado_clasificacion, clave_natural)
             VALUES (:c, :u, :p, :concepto, :f, :v, :t, :e, :k)',
            ['c' => $this->centro, 'u' => $unidad === null ? null : $this->unidad($unidad), 'p' => $producto === null ? null : $this->productos[$producto],
                'concepto' => $concepto, 'f' => $fecha, 'v' => $valor, 't' => $tipo, 'e' => $unidad === null ? 'ambiguo' : 'clasificado', 'k' => hash('sha256', 'recaudo' . $this->secuencia)]
        );
    }

    private function recepcion(string $unidad, string $numero, string $fecha, string $producto, string $cantidad, string $medida): int
    {
        self::$bd->ejecutar(
            'INSERT INTO recepciones_lote (centro_id, unidad_id, numero_interno, fecha_recepcion, producto_id, cantidad, medida) VALUES (:c, :u, :n, :f, :p, :q, :m)',
            ['c' => $this->centro, 'u' => $this->unidad($unidad), 'n' => $numero, 'f' => $fecha, 'p' => $this->productos[$producto], 'q' => $cantidad, 'm' => $medida]
        );
        return (int) self::$bd->valor('SELECT MAX(id) FROM recepciones_lote');
    }

    private function salida(string $unidad, string $fecha, string $producto, string $cantidad, string $precio, string $canal, ?int $recepcion = null, ?int $proceso = null): int
    {
        $this->secuencia++;
        $medida = (string) self::$bd->valor('SELECT medida FROM productos WHERE id = :p', ['p' => $this->productos[$producto]]);
        self::$bd->ejecutar(
            'INSERT INTO salidas (centro_id, unidad_id, recepcion_id, fecha, producto_id, cantidad, medida, precio_unitario, valor, canal, proceso_id, clave_natural)
             VALUES (:c, :u, :r, :f, :p, :q, :m, :pu, :v, :canal, :proceso, :k)',
            ['c' => $this->centro, 'u' => $this->unidad($unidad), 'r' => $recepcion, 'f' => $fecha, 'p' => $this->productos[$producto], 'q' => $cantidad, 'm' => $medida,
                'pu' => $precio, 'v' => \App\Nucleo\Entrada::valorSalida($cantidad, $precio), 'canal' => $canal, 'proceso' => $proceso, 'k' => hash('sha256', 'salida' . $this->secuencia)]
        );
        return (int) self::$bd->valor('SELECT MAX(id) FROM salidas');
    }

    // ---------------------------------------------------------------- Ayudas

    /**
     * @param list<string> $fuentes
     * @param array<string,string> $opciones
     */
    private function importarReal(NavegadorPrueba $navegador, string $ruta, array $fuentes, array $opciones): void
    {
        $copia = (string) tempnam(sys_get_temp_dir(), 'kora-real-');
        copy($ruta, $copia);
        $seleccion = $navegador->postHtmx('/importaciones/libro', [], [], ['archivo' => ['nombre' => basename($ruta), 'ruta' => $copia, 'tamano' => (int) filesize($copia), 'error' => UPLOAD_ERR_OK]]);
        if (preg_match('/name="token" value="([^"]+)"/', $seleccion->cuerpo, $m) !== 1) {
            self::fail('La carga del libro real no devolvió la selección de fuentes.');
        }
        $navegador->postHtmx('/importaciones', ['token' => html_entity_decode($m[1]), 'fuentes' => $fuentes] + $opciones);
        $this->procesarCola();
        foreach ($fuentes as $fuente) {
            $lote = self::$bd->fila('SELECT ulid, estado FROM lotes_importacion WHERE fuente = :f ORDER BY id DESC LIMIT 1', ['f' => $fuente]);
            self::assertNotNull($lote, $fuente);
            $navegador->postHtmx('/importaciones/' . $lote['ulid'] . '/aplicar', ['solo_validas' => '1']);
        }
        $this->procesarCola();
    }

    private function navegador(string $ip): NavegadorPrueba
    {
        $directorio = $this->directorio;
        return new NavegadorPrueba(static function (Contenedor $c) use ($directorio): void {
            $c->establecer(ServicioCargaArchivos::class, new ServicioCargaArchivos($directorio . '/importaciones', false));
        }, $ip);
    }

    private function procesarCola(): void
    {
        $aplicacion = Aplicacion::crear(require RAIZ . '/config/app.php');
        $aplicacion->contenedor()->establecer(ServicioCargaArchivos::class, new ServicioCargaArchivos($this->directorio . '/importaciones', false));
        for ($i = 0; $i < 4; $i++) {
            $aplicacion->contenedor()->obtener(Trabajador::class)->ejecutar(null, 20);
        }
    }

    private function indicadores(): ServicioIndicadores
    {
        return new ServicioIndicadores(self::$bd, Aplicacion::crear(require RAIZ . '/config/app.php')->contenedor()->obtener(ServicioParametros::class));
    }

    private function alcanceCentro(): AlcanceDatos
    {
        return AlcanceDatos::deUsuario($this->centro, [], true);
    }

    /** @param array<string,string> $consulta */
    private function filtros(array $consulta, AlcanceDatos $alcance): FiltrosTablero
    {
        $unidades = array_map(static fn (array $u): int => (int) $u['id'], $this->indicadores()->unidadesDelAlcance($alcance));
        return FiltrosTablero::desdeConsulta($consulta, $unidades, 2026);
    }

    /**
     * @param array<string,mixed> $rotacion
     * @return array<string,mixed>
     */
    private function lote(array $rotacion, string $numero): array
    {
        foreach ($rotacion['lotes'] as $lote) {
            if ($lote['lote'] === $numero) {
                return $lote;
            }
        }
        self::fail("No está el lote {$numero}.");
    }

    private function unidad(string $codigo): int
    {
        return (int) self::$bd->valor('SELECT id FROM unidades_productivas WHERE codigo = :c AND centro_id = :centro', ['c' => $codigo, 'centro' => $this->centro]);
    }
}
