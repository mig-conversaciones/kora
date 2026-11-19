<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Cola\DespachadorCola;
use App\Cola\Trabajador;
use App\Nucleo\Aplicacion;
use App\Nucleo\BaseDatos;
use App\Nucleo\Cache;
use App\Nucleo\Contenedor;
use App\Nucleo\Entorno;
use App\Nucleo\Migrador;
use App\Nucleo\Registro;
use App\Operacion\CifrasControl;
use App\Operacion\GeneradorDatosCarga;
use App\Operacion\MonitorCertificado;
use App\Operacion\ServicioRespaldo;
use App\Operacion\SobreCifrado;
use App\Operacion\VerificadorIntegridad;
use App\Servicios\Programador;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioMonitorCola;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Iteración 7, «Rendimiento y operación»:
 *   - Dataset sintético (RNF-PERF-016, RDL-009) y cadena de auditoría sellada a escala.
 *   - Máximo de consultas por solicitud (RNF-PERF-009): tableros 15, listados 10, formularios 6; caché de permisos
 *     invalidada por la versión de permisos (4.6.2) y precalentamiento (RNF-PERF-012).
 *   - Copias cifradas en sobre: copia, verificación, restauración con cifras de control, alteración detectada,
 *     retención (RDL-003 a RDL-005) y copia previa de las importaciones masivas (RDL-004).
 *   - Integridad de la versión y del .env (RS-022), certificado por vencer (ROB-014), tareas del programador (D.4),
 *     registro de despliegues (ROB-013) y pruebas de humo (ROB-001).
 */
final class RendimientoOperacionTest extends TestCase
{
    private const TABLAS = ['despliegues', 'respaldos', 'exportaciones', 'conciliaciones_recaudo', 'incidencias_calidad', 'registros_reproductivos', 'cortes_hato', 'animales',
        'transformaciones', 'salidas', 'recepciones_lote', 'recaudos', 'archivos_adjuntos', 'gastos_ejecutados', 'asignaciones_presupuestales',
        'procesos_autoconsumo', 'periodos_cierre', 'homologaciones_producto', 'productos', 'parametros_indicadores', 'categorias_gasto', 'versiones_datos', 'filas_importacion', 'lotes_importacion',
        'claves_idempotencia', 'codigos_respaldo_2fa', 'tokens_cuenta', 'sesiones', 'notificaciones', 'usuarios_unidades', 'usuarios_roles',
        'trabajos_fallidos', 'trabajos', 'limites_tasa', 'eventos_auditoria'];
    private const CONTRASENA = 'contraseña sintética de carga 7a';

    private static BaseDatos $bd;
    private string $temporal;

    public static function setUpBeforeClass(): void
    {
        self::$bd = new BaseDatos(require RAIZ . '/config/base_datos.php');
        (new Migrador(self::$bd, RAIZ . '/bd/migraciones'))->aplicar();
    }

    public static function tearDownAfterClass(): void
    {
        self::limpiar();
    }

    protected function setUp(): void
    {
        self::limpiar();
        $migrador = new Migrador(self::$bd, RAIZ . '/bd/migraciones');
        $migrador->sembrar(RAIZ . '/bd/semillas/001_identidad_y_estructura.sql');
        $migrador->sembrar(RAIZ . '/bd/semillas/002_catalogos_finanzas.sql');
        $this->temporal = sys_get_temp_dir() . '/kora-i7-' . bin2hex(random_bytes(4));
        mkdir($this->temporal, 0700, true);
    }

    private static function limpiar(): void
    {
        foreach (self::TABLAS as $tabla) {
            self::$bd->ejecutar("DELETE FROM {$tabla}");
        }
        self::$bd->ejecutar('DELETE FROM usuarios');
        self::$bd->ejecutar("DELETE u FROM unidades_productivas u JOIN centros c ON c.id = u.centro_id WHERE c.codigo = '9999'");
        self::$bd->ejecutar("DELETE FROM destinos_internos WHERE centro_id IN (SELECT id FROM centros WHERE codigo = '9999')");
        self::$bd->ejecutar("DELETE FROM centros WHERE codigo = '9999'");
        self::$bd->ejecutar("UPDATE cadena_auditoria SET ultima_secuencia = 0, ultimo_hash = REPEAT('0', 64) WHERE id = 1");
        self::$bd->ejecutar('DELETE FROM roles_permisos');
    }

    // ---------------------------------------------------------------- Dataset y consultas

    public function testGeneradorProduceDatasetAisladoConCadenaIntegraYNuncaEnProduccion(): void
    {
        try {
            (new GeneradorDatosCarga(self::$bd, 'produccion'))->generar('mini', 2026);
            self::fail('El generador no debe ejecutarse en producción.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('RDL-009', $error->getMessage());
        }
        self::assertSame(0, (int) self::$bd->valor('SELECT COUNT(*) FROM salidas'), 'Rechazado en producción no escribe nada.');

        $resultado = $this->dataset(6);
        self::assertGreaterThan(300, $resultado['resumen']['salidas']);
        self::assertSame(8250, $resultado['resumen']['auditoria']);
        self::assertCount(6, $resultado['usuarios']);
        $cifras = (new CifrasControl(self::$bd))->calcular();
        self::assertTrue($cifras['auditoria']['cadena_integra'], $cifras['auditoria']['detalle']);
        self::assertSame(8250, $cifras['auditoria']['sellados']);
        $verificacion = (string) self::$bd->valor("SELECT id FROM centros WHERE codigo = '9999'");
        self::assertGreaterThan(0, $cifras['tablas']['salidas'][$verificacion]['filas'], 'El segundo centro tiene su propio volumen.');
        self::assertSame(0, (int) self::$bd->valor(
            'SELECT COUNT(*) FROM salidas s JOIN unidades_productivas u ON u.id = s.unidad_id WHERE u.centro_id <> s.centro_id'
        ), 'Ninguna salida apunta a una unidad de otro centro.');

        // Misma semilla: mismas cifras de negocio aunque cambien los ULID y los hashes.
        try {
            (new GeneradorDatosCarga(self::$bd, 'local'))->generar('mini', 2026);
            self::fail('Con datos existentes no debe generar.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('ya tiene datos', $error->getMessage());
        }
    }

    public function testConsultasPorSolicitudDentroDelMaximoYAlcanceRespetado(): void
    {
        $usuarios = $this->dataset(12)['usuarios'];
        $lider = $this->cuenta($usuarios, 'lider_unidad', 'GAN');
        $centro = $this->cuenta($usuarios, 'lider_produccion_centro');
        $recaudo = $this->cuenta($usuarios, 'grupo_recaudo');

        $casos = [
            [$centro, '/tableros/central?vigencia=2026', 15, true],
            [$lider, '/tableros/central?vigencia=2026', 15, true],
            [$lider, '/tableros/unidad/GAN?vigencia=2026', 15, true],
            [$centro, '/produccion/salidas', 10, false],
            [$lider, '/produccion/salidas?pagina=2', 10, false],
            [$centro, '/produccion/recepciones', 10, false],
            [$recaudo, '/recaudos', 10, false],
            [$recaudo, '/recaudos/clasificar', 10, false],
            [$lider, '/produccion/salidas/nueva', 6, false],
        ];
        foreach ($casos as [$usuario, $ruta, $maximo, $frio]) {
            if ($frio) {
                self::$bd->ejecutar('UPDATE versiones_datos SET version = version + 1');
            }
            [$respuesta, $consultas] = $this->medir($usuario, $ruta);
            self::assertSame(200, $respuesta->estado, $ruta);
            self::assertLessThanOrEqual($maximo, $consultas, "{$ruta} hizo {$consultas} consultas (máximo {$maximo}, RNF-PERF-009).");
        }

        // El líder de Ganadería no recibe enlaces a otras unidades (Obs. 4), ni el de otro centro datos del piloto.
        [$central] = $this->medir($lider, '/tableros/central?vigencia=2026');
        foreach ($lider['unidadesAjenas'] as $ajena) {
            self::assertStringNotContainsString("/tableros/unidad/{$ajena}", $central->cuerpo);
        }
        $ajeno = $this->cuenta($usuarios, 'lider_unidad', null, '9999');
        [$otroCentro] = $this->medir($ajeno, '/tableros/central?vigencia=2026');
        self::assertSame(200, $otroCentro->estado);
        self::assertStringNotContainsString('Granja', $otroCentro->cuerpo);
    }

    public function testPermisosEnCacheSeInvalidanAlCambiarElAcceso(): void
    {
        $lider = $this->cuenta($this->dataset(6)['usuarios'], 'lider_unidad', 'GAN');
        $navegador = $this->navegadorCon($lider);
        self::assertSame(200, $navegador->get('/produccion/salidas')->estado);
        self::assertSame(200, $navegador->get('/produccion/salidas')->estado, 'Segunda solicitud con los permisos en caché.');
        // Quitar el rol y subir la versión de permisos, como ServicioUsuarios: la siguiente solicitud ya no puede.
        self::$bd->ejecutar('DELETE FROM usuarios_roles WHERE usuario_id = (SELECT id FROM usuarios WHERE correo = :c)', ['c' => $lider['correo']]);
        self::$bd->ejecutar('UPDATE usuarios SET version_permisos = version_permisos + 1 WHERE correo = :c', ['c' => $lider['correo']]);
        self::assertContains($navegador->get('/produccion/salidas')->estado, [403, 404], 'La revocación tiene efecto en la siguiente solicitud.');
    }

    public function testPrecalentamientoDejaCalienteLaVistaPorDefectoDeCadaAlcance(): void
    {
        $usuarios = $this->dataset(12)['usuarios'];
        $lider = $this->cuenta($usuarios, 'lider_unidad', 'GAN');
        self::$bd->ejecutar('UPDATE versiones_datos SET version = version + 1');
        [, $frio] = $this->medir($lider, '/tableros/central');
        self::$bd->ejecutar('UPDATE versiones_datos SET version = version + 1');

        $aplicacion = Aplicacion::crear(require RAIZ . '/config/app.php');
        $aplicacion->contenedor()->obtener(DespachadorCola::class)->encolar('cache.precalentar', ['fecha' => 'prueba']);
        $aplicacion->contenedor()->obtener(Trabajador::class)->ejecutar('predeterminada', 30);
        $resultado = json_decode((string) self::$bd->valor("SELECT resultado FROM trabajos WHERE tipo = 'cache.precalentar'"), true);
        self::assertSame('completado', self::$bd->valor("SELECT estado FROM trabajos WHERE tipo = 'cache.precalentar'"));
        self::assertGreaterThanOrEqual(2, $resultado['central'], 'Al menos el alcance del centro y el de una unidad.');
        self::assertGreaterThanOrEqual(1, $resultado['tecnico']);

        [$respuesta, $caliente] = $this->medir($lider, '/tableros/central');
        self::assertSame(200, $respuesta->estado);
        self::assertLessThan($frio, $caliente, "Tras precalentar, la vista por defecto no recalcula ({$frio} consultas en frío, {$caliente} después).");
        self::assertLessThanOrEqual(6, $caliente);
    }

    /** Anexo H, H.2: vencimiento con variación, estampida de 20 procesos y caída del almacenamiento. */
    public function testCacheVenceConVariacionEvitaEstampidaYSobreviveSinAlmacenamiento(): void
    {
        $directorio = $this->temporal . '/cache';
        $cache = new Cache($directorio);
        $cache->recordar('vence:1', 100, static fn (): int => 1);
        $entrada = json_decode((string) file_get_contents((string) (glob($directorio . '/*/*.json') ?: [''])[0]), true);
        self::assertGreaterThanOrEqual(time() + 89, $entrada['expira'], 'TTL con variación de ±10 %.');
        self::assertLessThanOrEqual(time() + 110, $entrada['expira']);
        $calculos = 0;
        $cache->recordar('vence:2', 0, static function () use (&$calculos): int {
            return ++$calculos;
        });
        self::assertSame(2, $cache->recordar('vence:2', 0, static function () use (&$calculos): int {
            return ++$calculos;
        }), 'Vencida la entrada, se recalcula.');

        // Estampida: 20 procesos simultáneos sobre una clave fría producen un solo cálculo.
        $contador = $this->temporal . '/calculos.txt';
        $codigo = sprintf(
            'require %s; $c = new App\Nucleo\Cache(%s); $c->recordar("estampida:1", 60, function () { file_put_contents(%s, "x", FILE_APPEND | LOCK_EX); usleep(600000); return 1; });',
            var_export(RAIZ . '/vendor/autoload.php', true),
            var_export($directorio, true),
            var_export($contador, true)
        );
        $procesos = [];
        $tuberiasAbiertas = [];
        for ($i = 0; $i < 20; $i++) {
            $procesos[] = proc_open([PHP_BINARY, '-r', $codigo], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias);
            $tuberiasAbiertas[] = $tuberias;
        }
        foreach ($procesos as $i => $proceso) {
            stream_get_contents($tuberiasAbiertas[$i][1]);
            fclose($tuberiasAbiertas[$i][1]);
            fclose($tuberiasAbiertas[$i][2]);
            proc_close($proceso);
        }
        self::assertSame('x', file_get_contents($contador), 'Un solo cálculo para 20 solicitudes simultáneas.');

        // Sin almacenamiento utilizable (la ruta del directorio es un archivo): cálculo directo, sin avisos.
        file_put_contents($this->temporal . '/no-es-directorio', '');
        $rota = new Cache($this->temporal . '/no-es-directorio');
        self::assertSame(['total' => 7], $rota->recordar('tablero:sin-disco', 60, static fn (): array => ['total' => 7]));
    }

    // ---------------------------------------------------------------- Copias de seguridad

    public function testSobreCifradoRechazaLlaveAjenaAlteracionYTruncamiento(): void
    {
        $llaves = SobreCifrado::generarLlaves();
        $origen = $this->temporal . '/claro.txt';
        file_put_contents($origen, str_repeat('KORA datos de prueba ', 10000));
        SobreCifrado::cifrar($origen, $this->temporal . '/c.kora', $llaves['publica']);
        self::assertStringNotContainsString('KORA datos', (string) file_get_contents($this->temporal . '/c.kora'));
        SobreCifrado::descifrar($this->temporal . '/c.kora', $this->temporal . '/d.txt', $llaves['par']);
        self::assertSame(hash_file('sha256', $origen), hash_file('sha256', $this->temporal . '/d.txt'));
        self::assertSame($llaves['publica'], SobreCifrado::publicaDelPar($llaves['par']));

        $casos = [
            'llave ajena' => static fn (string $c): string => $c,
            'alteración' => static fn (string $c): string => substr_replace($c, chr(ord($c[strlen($c) - 40]) ^ 1), strlen($c) - 40, 1),
            'truncamiento' => static fn (string $c): string => substr($c, 0, (int) (strlen($c) * 0.6)),
        ];
        foreach ($casos as $caso => $danar) {
            $archivo = $this->temporal . "/{$caso}.kora";
            file_put_contents($archivo, $danar((string) file_get_contents($this->temporal . '/c.kora')));
            try {
                SobreCifrado::descifrar($archivo, $this->temporal . "/{$caso}.txt", $caso === 'llave ajena' ? SobreCifrado::generarLlaves()['par'] : $llaves['par']);
                self::fail("Debe rechazar la {$caso}.");
            } catch (RuntimeException) {
                self::assertFileDoesNotExist($this->temporal . "/{$caso}.txt", 'No deja un archivo parcial.');
            }
        }
    }

    public function testCopiaSeRestauraConCifrasDeControlYDetectaUnArchivoDanado(): void
    {
        $this->dataset(3);
        $llaves = SobreCifrado::generarLlaves();
        $almacenamiento = $this->temporal . '/almacenamiento';
        mkdir($almacenamiento . '/importaciones', 0700, true);
        mkdir($almacenamiento . '/cache/ab', 0700, true);
        file_put_contents($almacenamiento . '/importaciones/libro.xlsx', 'contenido del libro');
        file_put_contents($almacenamiento . '/cache/ab/entrada.json', '{}');
        $respaldo = $this->respaldo($llaves['publica'], $almacenamiento);
        if (!$this->clienteMysqlDisponible()) {
            self::markTestSkipped('Sin mysqldump/mysql en RESPALDO_MYSQLDUMP y RESPALDO_MYSQL.');
        }

        $antes = (new CifrasControl(self::$bd))->calcular();
        $copia = $respaldo->crear('manual', 'Prueba de la I7');
        self::assertSame([], $respaldo->verificar($copia['identificador']));
        $fila = self::$bd->fila('SELECT estado, bytes, archivos FROM respaldos WHERE identificador = :i', ['i' => $copia['identificador']]);
        self::assertSame('completado', $fila['estado'] ?? null);
        self::assertCount(2, json_decode((string) $fila['archivos'], true));
        foreach (glob($this->temporal . '/copias/*') ?: [] as $archivo) {
            self::assertStringNotContainsString('contenido del libro', (string) file_get_contents($archivo), 'Nada en claro en el directorio de copias.');
        }

        // Restauración sobre la misma base de pruebas: borra datos y los recupera de la copia.
        self::$bd->ejecutar('DELETE FROM gastos_ejecutados');
        self::assertNotSame($antes['huella'], (new CifrasControl(self::$bd))->calcular()['huella']);
        try {
            $respaldo->restaurar($copia['identificador'], Entorno::texto('BD_NOMBRE'), $llaves['par']);
            self::fail('Restaurar sobre la base activa exige aceptar la pérdida.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('acepto-perdida', $error->getMessage());
        }
        $resultado = $respaldo->restaurar($copia['identificador'], Entorno::texto('BD_NOMBRE'), $llaves['par'], true, $this->temporal . '/restaurado');
        $despues = (new CifrasControl(self::$bd))->calcular();
        self::assertSame([], CifrasControl::diferencias($antes, $despues), 'Conteos, sumas, migración y cadena coinciden tras restaurar.');
        self::assertLessThan(14400, $resultado['segundos'], 'Dentro del RTO de 4 h.');
        self::assertSame('contenido del libro', file_get_contents($this->temporal . '/restaurado/importaciones/libro.xlsx'));
        self::assertFileDoesNotExist($this->temporal . '/restaurado/cache/ab/entrada.json', 'La caché no se copia.');

        // Un byte cambiado en la copia: se detecta antes de restaurar (runbook «Backup corrupto»).
        $archivo = $this->temporal . '/copias/' . $copia['identificador'] . '.sql.gz.kora';
        $contenido = (string) file_get_contents($archivo);
        file_put_contents($archivo, substr_replace($contenido, chr(ord($contenido[100]) ^ 1), 100, 1));
        self::assertNotSame([], $respaldo->verificar($copia['identificador']));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Backup corrupto');
        $respaldo->restaurar($copia['identificador'], 'kora_restauracion', $llaves['par']);
    }

    public function testRetencionConserva30DiariasY12MensualesYLasDeRiesgo30Dias(): void
    {
        $respaldo = $this->respaldo(SobreCifrado::generarLlaves()['publica'], $this->temporal);
        $ahora = new DateTimeImmutable('2026-11-19 12:00:00', new DateTimeZone('UTC'));
        for ($dia = 0; $dia < 400; $dia++) {
            $fecha = $ahora->modify("-{$dia} days")->format('Y-m-d 02:30:00');
            self::$bd->ejecutar(
                "INSERT INTO respaldos (identificador, tipo, estado, creado_en) VALUES (:i, 'diaria', 'completado', :f)",
                ['i' => sprintf('kora-diaria-%sT023000Z-%04x', str_replace('-', '', substr($fecha, 0, 10)), $dia), 'f' => $fecha]
            );
        }
        foreach ([5, 45] as $dias) {
            self::$bd->ejecutar(
                "INSERT INTO respaldos (identificador, tipo, estado, creado_en) VALUES (:i, 'predespliegue', 'completado', :f)",
                ['i' => "kora-predespliegue-{$dias}", 'f' => $ahora->modify("-{$dias} days")->format('Y-m-d H:i:s')]
            );
        }
        $antigua = 'kora-diaria-' . $ahora->modify('-399 days')->format('Ymd') . 'T023000Z-018f';
        $respaldo->directorio();
        file_put_contents($this->temporal . '/copias/' . $antigua . '.sql.gz.kora', 'x');
        $podadas = $respaldo->podar($ahora);
        $vigentes = array_column(self::$bd->consultar('SELECT identificador, tipo, creado_en FROM respaldos WHERE eliminado_en IS NULL ORDER BY creado_en DESC'), null, 'identificador');
        $diarias = array_filter($vigentes, static fn (array $r): bool => $r['tipo'] === 'diaria');
        // 30 diarias (21-oct a 19-nov) más la última de cada uno de los 12 meses: octubre y noviembre ya están entre las
        // 30, así que se suman 10 (de dic-2025 a sep-2026).
        self::assertCount(40, $diarias);
        $meses = array_unique(array_map(static fn (array $r): string => substr((string) $r['creado_en'], 0, 7), $diarias));
        self::assertGreaterThanOrEqual(12, count($meses));
        self::assertArrayHasKey('kora-predespliegue-5', $vigentes);
        self::assertArrayNotHasKey('kora-predespliegue-45', $vigentes);
        self::assertContains($antigua, $podadas);
        self::assertFileDoesNotExist($this->temporal . '/copias/' . $antigua . '.sql.gz.kora');
    }

    public function testCopiaPreviaSoloEnCambiosMasivosYObligatoriaEnProduccion(): void
    {
        $local = $this->respaldo('', $this->temporal, 'local');
        self::assertNull($local->copiaPrevia('preimportacion', 'Lote pequeño', 10), 'Por debajo del umbral no hay copia.');
        self::assertNull($local->copiaPrevia('preimportacion', 'Lote masivo', 5000), 'Fuera de producción, sin llave, se omite y se registra.');
        $produccion = $this->respaldo('', $this->temporal, 'produccion');
        self::assertNull($produccion->copiaPrevia('preimportacion', 'Lote pequeño', 10));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RDL-004');
        $produccion->copiaPrevia('preimportacion', 'Lote masivo', 5000);
    }

    // ---------------------------------------------------------------- Integridad, certificado y programador

    public function testVerificadorDetectaArchivoAlteradoYEnvSinRegistroDeRotacion(): void
    {
        $raiz = $this->temporal . '/release';
        mkdir($raiz . '/app', 0700, true);
        file_put_contents($raiz . '/app/a.php', '<?php // a');
        file_put_contents($raiz . '/.htaccess', 'Require all denied');
        file_put_contents($raiz . '/.env', "APP_ENTORNO=staging\n");
        @chmod($raiz . '/.env', 0600);
        file_put_contents($raiz . '/manifiesto.sha256', hash_file('sha256', $raiz . '/.htaccess') . "  .htaccess\n" . hash_file('sha256', $raiz . '/app/a.php') . "  app/a.php\n");
        $verificador = new VerificadorIntegridad($raiz, $raiz . '/.env', $raiz . '/estado', true);
        self::assertSame(['alertas' => [], 'archivos' => 2], $verificador->verificar());

        $verificador->registrarHuellaEnv();
        file_put_contents($raiz . '/app/a.php', '<?php // alterado');
        file_put_contents($raiz . '/.env', "APP_ENTORNO=staging\nCLAVE=otra\n");
        $alertas = implode(' ', $verificador->verificar()['alertas']);
        self::assertStringContainsString('app/a.php', $alertas);
        self::assertStringContainsString('sin registro de rotación', $alertas);
        $verificador->registrarHuellaEnv();
        self::assertStringNotContainsString('rotación', implode(' ', $verificador->verificar()['alertas']));

        unlink($raiz . '/manifiesto.sha256');
        self::assertStringContainsString('no tiene manifiesto', implode(' ', $verificador->verificar()['alertas']));
    }

    public function testCertificadoConCatorceDiasDisparaElAviso(): void
    {
        // En Windows, PHP no trae una configuración de OpenSSL por defecto: se usa la del propio PHP si existe.
        $configuracion = is_file(dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf') ? ['config' => dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf'] : [];
        $llave = @openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + $configuracion);
        if ($llave === false) {
            self::markTestSkipped('OpenSSL sin configuración para generar llaves en este equipo.');
        }
        $pem = static function (int $dias) use ($llave, $configuracion): string {
            $csr = openssl_csr_new(['commonName' => 'kora.prueba.invalid'], $llave, $configuracion);
            $certificado = openssl_csr_sign($csr, null, $llave, $dias, $configuracion);
            openssl_x509_export($certificado, $salida);
            return $salida;
        };
        $catorce = MonitorCertificado::diasRestantesDePem($pem(14));
        self::assertContains($catorce, [13, 14]);
        self::assertTrue(MonitorCertificado::requiereAviso($catorce, 15), 'Con 14 días y umbral de 15 se avisa (ROB-014).');
        self::assertFalse(MonitorCertificado::requiereAviso(MonitorCertificado::diasRestantesDePem($pem(60)), 15));
        self::assertNull(MonitorCertificado::diasRestantesDePem('no es un certificado'));
        self::assertFalse(MonitorCertificado::requiereAviso(null, 15));
    }

    public function testProgramadorEncolaIntegridadYPrecalentamientoSoloEnDiasHabiles(): void
    {
        $contenedor = Contenedor::desdeConfiguracion(require RAIZ . '/config/app.php');
        $programador = static fn (string $estado): Programador => new Programador(
            self::$bd,
            $contenedor->obtener(DespachadorCola::class),
            $contenedor->obtener(ServicioMonitorCola::class),
            $contenedor->obtener(ServicioAuditoria::class),
            $contenedor->obtener(Registro::class),
            require RAIZ . '/config/cola.php',
            $estado,
            $estado,
            'America/Bogota',
            false,
            'http://localhost',
        );
        mkdir($this->temporal . '/jueves');
        mkdir($this->temporal . '/sabado');
        $jueves = $programador($this->temporal . '/jueves')->ejecutar(new DateTimeImmutable('2026-11-19 07:00:00', new DateTimeZone('America/Bogota')));
        self::assertArrayHasKey('verificar_integridad', $jueves);
        self::assertArrayHasKey('precalentar', $jueves);
        self::assertNull($jueves['certificado_dias'], 'Sin HTTPS no hay certificado que vigilar.');
        $segunda = $programador($this->temporal . '/jueves')->ejecutar(new DateTimeImmutable('2026-11-19 07:05:00', new DateTimeZone('America/Bogota')));
        self::assertArrayNotHasKey('precalentar', $segunda, 'Una vez por día.');
        $sabado = $programador($this->temporal . '/sabado')->ejecutar(new DateTimeImmutable('2026-11-21 07:00:00', new DateTimeZone('America/Bogota')));
        self::assertArrayHasKey('verificar_integridad', $sabado);
        self::assertArrayNotHasKey('precalentar', $sabado, 'El precalentamiento es de días hábiles.');
        self::assertSame(1, (int) self::$bd->valor("SELECT COUNT(*) FROM trabajos WHERE tipo = 'cache.precalentar'"));
    }

    public function testRegistroDeDespliegueYPruebasDeHumo(): void
    {
        [$codigo, $salida] = $this->cli(['cli/registrar_despliegue.php', '--version=v9.9.9', '--tipo=despliegue', '--desde=v9.9.8', '--commit=abc1234', '--duracion=42', '--responsable=CI']);
        self::assertSame(0, $codigo, $salida);
        $fila = self::$bd->fila("SELECT * FROM despliegues WHERE version = 'v9.9.9'");
        self::assertSame(['despliegue', 'v9.9.8', 'abc1234', 'CI', 'exito', 42], [$fila['tipo'], $fila['desde_version'], $fila['commit_git'], $fila['responsable'], $fila['resultado'], (int) $fila['duracion_s']]);
        $evento = json_decode((string) self::$bd->valor("SELECT metadatos FROM eventos_auditoria WHERE accion = 'sistema.desplegar'"), true);
        self::assertSame('v9.9.9', $evento['version'] ?? null, 'La auditoría registra sistema.desplegar (ROB-013).');
        [$codigo] = $this->cli(['cli/registrar_despliegue.php', '--version=v9.9.8', '--tipo=reversion', '--desde=v9.9.9']);
        self::assertSame(0, $codigo);
        self::assertSame(1, (int) self::$bd->valor("SELECT COUNT(*) FROM eventos_auditoria WHERE accion = 'sistema.revertir'"));
        [$codigo] = $this->cli(['cli/registrar_despliegue.php', '--version=no válida', '--tipo=despliegue']);
        self::assertSame(2, $codigo);

        if (!is_file(RAIZ . '/public/assets/manifest.json')) {
            self::markTestSkipped('Activos sin construir (npm run construir): las pruebas de humo los exigen.');
        }
        [$codigo, $salida] = $this->cli(['cli/humo.php']);
        self::assertSame(0, $codigo, $salida);
        self::assertStringContainsString('todo en verde', $salida);
    }

    // ---------------------------------------------------------------- Auxiliares

    /** @return array{resumen:array<string,int>,usuarios:list<array<string,mixed>>} */
    private function dataset(int $cuentas): array
    {
        $resultado = (new GeneradorDatosCarga(self::$bd, 'local'))->generar('mini', 2026, 20261119, $cuentas, static fn (string $c): string => password_hash($c, PASSWORD_ARGON2ID), self::CONTRASENA);
        // La caché vive en archivos y el dataset es determinista: una versión aleatoria evita leer entradas de otra ejecución.
        self::$bd->ejecutar('UPDATE versiones_datos SET version = :v', ['v' => random_int(1_000_000, 2_000_000_000)]);
        return $resultado;
    }

    /**
     * @param list<array<string,mixed>> $usuarios
     * @return array<string,mixed>
     */
    private function cuenta(array $usuarios, string $rol, ?string $unidad = null, string $centro = '9124'): array
    {
        foreach ($usuarios as $usuario) {
            if ($usuario['rol'] === $rol && $usuario['centro'] === $centro && ($unidad === null || $usuario['unidadPropia'] === $unidad)) {
                return $usuario;
            }
        }
        self::fail("No hay cuenta {$rol} {$unidad} en el centro {$centro}.");
    }

    /** @var array<string,NavegadorPrueba> */
    private array $navegadores = [];
    private int $consultas = 0;

    /** @param array<string,mixed> $usuario */
    private function navegadorCon(array $usuario): NavegadorPrueba
    {
        if (!isset($this->navegadores[$usuario['correo']])) {
            $navegador = new NavegadorPrueba(function (Contenedor $c): void {
                $c->obtener(BaseDatos::class)->observar(function (): void {
                    $this->consultas++;
                });
            }, '10.77.' . random_int(1, 250) . '.' . random_int(1, 250));
            $navegador->get('/ingresar');
            $navegador->post('/ingresar', ['correo' => $usuario['correo'], 'contrasena' => self::CONTRASENA]);
            self::assertSame(303, $navegador->ultima?->estado);
            self::assertStringNotContainsString('/ingresar', $navegador->destino(), "Ingreso de {$usuario['correo']}.");
            $this->navegadores[$usuario['correo']] = $navegador;
        }
        return $this->navegadores[$usuario['correo']];
    }

    /**
     * @param array<string,mixed> $usuario
     * @return array{0:\App\Nucleo\Respuesta,1:int}
     */
    private function medir(array $usuario, string $ruta): array
    {
        $navegador = $this->navegadorCon($usuario);
        $this->consultas = 0;
        $respuesta = $navegador->get($ruta);
        return [$respuesta, $this->consultas];
    }

    private function respaldo(string $llavePublica, string $almacenamiento, string $entorno = 'local'): ServicioRespaldo
    {
        return new ServicioRespaldo(self::$bd, [
            'directorio' => $this->temporal . '/copias', 'almacenamiento' => $almacenamiento,
            'mysqldump' => Entorno::texto('RESPALDO_MYSQLDUMP', 'mysqldump'), 'mysql' => Entorno::texto('RESPALDO_MYSQL', 'mysql'),
            'llave_publica' => $llavePublica, 'destino_externo' => '', 'diarias' => 30, 'mensuales' => 12,
            'umbral_importacion' => 500, 'entorno' => $entorno,
            'bd' => ['host' => Entorno::texto('BD_HOST', '127.0.0.1'), 'puerto' => Entorno::entero('BD_PUERTO', 3306), 'usuario' => Entorno::texto('BD_USUARIO'),
                'contrasena' => Entorno::texto('BD_CONTRASENA', ''), 'nombre' => Entorno::texto('BD_NOMBRE')],
        ]);
    }

    private function clienteMysqlDisponible(): bool
    {
        foreach ([Entorno::texto('RESPALDO_MYSQLDUMP', 'mysqldump'), Entorno::texto('RESPALDO_MYSQL', 'mysql')] as $binario) {
            $proceso = @proc_open([$binario, '--version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias);
            if (!is_resource($proceso)) {
                return false;
            }
            stream_get_contents($tuberias[1]);
            fclose($tuberias[1]);
            fclose($tuberias[2]);
            if (proc_close($proceso) !== 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * Ejecuta una CLI contra la base de pruebas (gancho KORA_BD_PRUEBAS de cli/arranque.php).
     *
     * @param list<string> $argumentos
     * @return array{0:int,1:string}
     */
    private function cli(array $argumentos): array
    {
        $entorno = getenv() + ['KORA_BD_PRUEBAS' => Entorno::texto('BD_NOMBRE')];
        $entorno['KORA_BD_PRUEBAS'] = Entorno::texto('BD_NOMBRE');
        $proceso = proc_open(array_merge([PHP_BINARY], $argumentos), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias, RAIZ, $entorno);
        self::assertIsResource($proceso);
        $salida = (string) stream_get_contents($tuberias[1]) . (string) stream_get_contents($tuberias[2]);
        fclose($tuberias[1]);
        fclose($tuberias[2]);
        return [proc_close($proceso), $salida];
    }
}
