<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Cola\Trabajador;
use App\Importacion\ServicioCargaArchivos;
use App\Importacion\ServicioCargaInicial;
use App\Importacion\ServicioImportaciones;
use App\Nucleo\Aplicacion;
use App\Nucleo\BaseDatos;
use App\Nucleo\Contenedor;
use App\Nucleo\Entorno;
use App\Nucleo\Migrador;
use App\Operacion\ServicioRespaldo;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioVersionDatos;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\TestCase;

/**
 * Iteración 8: carga inicial guiada del piloto con cifras de control y acta firmada (RF-094, RDL-011).
 *
 *   1. Libros sintéticos con tres fuentes (catálogo, producción y ventas): permisos, inicio único, faltantes,
 *      diferencias por filas rechazadas, corrección y nueva carga, verificación, firma con nueva verificación (un
 *      cambio posterior impide firmar), acta sin datos personales y bloqueo tras la firma.
 *   2. Ensayo con los libros reales de referencias/ y las ocho fuentes, solo si están en la máquina (no se versionan).
 *      Solo se comparan conteos: ningún valor personal sale de la prueba.
 */
final class CargaInicialTest extends TestCase
{
    use AyudasCuentas;

    private const TABLAS = ['cargas_iniciales', 'conciliaciones_recaudo', 'incidencias_calidad', 'registros_reproductivos', 'cortes_hato', 'animales', 'transformaciones', 'salidas',
        'recepciones_lote', 'recaudos', 'archivos_adjuntos', 'gastos_ejecutados', 'asignaciones_presupuestales',
        'procesos_autoconsumo', 'periodos_cierre', 'homologaciones_producto', 'productos', 'parametros_indicadores', 'categorias_gasto', 'versiones_datos', 'filas_importacion', 'lotes_importacion',
        'claves_idempotencia', 'codigos_respaldo_2fa', 'tokens_cuenta', 'sesiones', 'notificaciones', 'usuarios_unidades', 'usuarios_roles',
        'trabajos_fallidos', 'trabajos', 'limites_tasa', 'eventos_auditoria'];

    private const PASOS_SINTETICOS = [
        'catalogo_productos' => ['obligatoria' => true, 'libro' => 'Catálogo sintético'],
        'gastos_ejecutados' => ['obligatoria' => false, 'libro' => 'Opcional, no se carga'],
        'base_produccion' => ['obligatoria' => true, 'libro' => 'Base de producción sintética'],
        'base_ventas' => ['obligatoria' => true, 'libro' => 'Base de ventas sintética'],
    ];

    private static BaseDatos $bd;
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
        $this->directorio = sys_get_temp_dir() . '/kora-carga-inicial-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directorio)) {
            $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directorio, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterador as $archivo) {
                $archivo->isDir() ? rmdir($archivo->getPathname()) : unlink($archivo->getPathname());
            }
            rmdir($this->directorio);
        }
    }

    public function testCargaGuiadaConDiferenciasCorreccionVerificacionYFirma(): void
    {
        $lider = $this->navegador('190.85.18.10', self::PASOS_SINTETICOS);
        $this->cuentaActiva(self::$bd, $lider, 'lider.ganaderia@kora.local', 'Luis Ganadería', 'lider_unidad', ['GAN']);
        self::assertSame(403, $lider->get('/importaciones/carga-inicial')->estado, 'El líder de unidad no conduce la carga.');

        $coordinacion = $this->navegador('190.85.18.11', self::PASOS_SINTETICOS);
        $this->cuentaActiva(self::$bd, $coordinacion, 'coordinacion@kora.local', 'Clara Coordinación', 'coordinacion_administrativa');
        $this->agregarRol('coordinacion@kora.local', 'lider_produccion_centro');
        $pagina = $coordinacion->get('/importaciones/carga-inicial');
        self::assertSame(200, $pagina->estado);
        self::assertStringContainsString('Iniciar la carga inicial', $pagina->cuerpo);
        self::assertStringContainsString('Carga inicial', $coordinacion->get('/importaciones')->cuerpo, 'La pestaña aparece para quien puede conducirla.');

        // Inicio único por centro, con vigencia válida.
        self::assertSame(422, $coordinacion->postHtmx('/importaciones/carga-inicial', ['vigencia' => 'dos mil'])->estado);
        self::assertSame(200, $coordinacion->postHtmx('/importaciones/carga-inicial', ['vigencia' => '2027'])->estado);
        $ulid = (string) self::$bd->valor("SELECT ulid FROM cargas_iniciales WHERE estado = 'en_curso'");
        self::assertNotSame('', $ulid);
        self::assertStringContainsString('Ya hay una carga inicial en curso', $coordinacion->postHtmx('/importaciones/carga-inicial', ['vigencia' => '2027'])->cuerpo);

        // Sin lotes: las tres obligatorias faltan y la opcional se omite.
        $coordinacion->postHtmx("/importaciones/carga-inicial/{$ulid}/verificar");
        $resultado = $this->resultado($ulid);
        self::assertSame('con_diferencias', self::$bd->valor('SELECT estado FROM cargas_iniciales WHERE ulid = :u', ['u' => $ulid]));
        self::assertSame(3, $resultado['diferencias']);
        self::assertTrue($resultado['fuentes'][1]['omitida']);

        // Un registro previo a la carga es una diferencia (RDL-011): no viene de los libros.
        self::$bd->ejecutar("INSERT INTO productos (centro_id, codigo_sgac, nombre, medida) VALUES (:c, '9999999999', 'Producto del prototipo', 'L')", ['c' => $this->centro()]);

        $fecha = (new \DateTimeImmutable('first day of last month'))->format('Y-m-') . '05';
        $catalogo = ['CatalogoProductos_CAB' => [
            ['Catálogo de productos del CAB'],
            ['Nombre', 'Codigo SGAC', 'Unidad  Productiva', 'Precio Minimo', 'Precio Maximo', 'Precio de Venta', 'Medida', 'Clase', 'CodigoFamilia', 'NombreFamilia'],
            ['Leche de vaca', '5013170101', 'UP_Ganaderia', 2000, 2400, 2200, 'Litros', 'Lácteos', '5013', 'Productos lácteos'],
            ['Queso cuajada', '5013170201', '', '', '', 18000, 'Kilos', 'Lácteos', '5013', 'Productos lácteos'],
        ]];
        $produccion = ['Base Produccion Centro' => [
            ['Num_Interno', 'Fecha Recepción', 'Nombre Proveedor', 'Nombre Producto', 'Código SGARC', 'Cantidad Recibida', 'Unidad de Medida', 'Precio de Venta Sugerido', 'Usuario que recibe'],
            ['GAN-REC-010126-001', new \DateTimeImmutable($fecha), 'UP_Ganaderia', 'Leche de vaca', '5013170101', 100, 'Litros', 2200, 'Persona que recibe'],
        ]];
        $encabezadoVentas = ['Num_Interno_Compra', 'No. Factura Didáctica', 'Fecha Venta', 'Código SGARC', 'Nombre del Producto', 'Unidad de Medida', 'Cantidad Vendida', 'Precio Unitario Venta', 'Subtotal', 'Método de Pago', 'Origen', 'Código Transacción', 'ID Cliente'];
        $venta = static fn (string $factura, int $cantidad): array => ['GAN-REC-010126-001', $factura, new \DateTimeImmutable($fecha), '5013170101', 'Leche de vaca', 'Litros', $cantidad, 2200, $cantidad * 2200, 'EFECTIVO', 'CAJA', 'NO APLICA', '1098765432'];
        $ventasConError = ['Base Ventas' => [$encabezadoVentas, $venta('FD-1', 10), $venta('FD-2', 5), ['GAN-REC-010126-001', 'FD-3', new \DateTimeImmutable($fecha), '', '', 'BULTO', 3, 6000, 18000, 'EFECTIVO', 'CAJA', 'NO APLICA', '1098765432']]];

        // En el orden de la carga: el catálogo aplicado antes de validar la producción y las ventas que lo referencian.
        $this->importar($coordinacion, $this->libro($catalogo), ['catalogo_productos']);
        $this->importar($coordinacion, $this->libro($produccion), ['base_produccion']);
        $this->importar($coordinacion, $this->libro($ventasConError), ['base_ventas']);
        $coordinacion->postHtmx("/importaciones/carga-inicial/{$ulid}/verificar");
        $resultado = $this->resultado($ulid);
        $porFuente = array_column($resultado['fuentes'], null, 'fuente');
        self::assertSame(['Filas: 3 en el archivo y 2 en la base.', 'Suma: 51000.000 en el archivo y 33000.000 en la base.'], $porFuente['base_ventas']['diferencias']);
        self::assertSame(4, $porFuente['base_ventas']['rechazadas'][0]['fila'], 'El acta dice qué fila corregir.');
        self::assertSame(['1 registro(s) en la base que no vienen de esta carga (RDL-011).'], $porFuente['catalogo_productos']['diferencias']);
        self::assertSame([], $porFuente['base_produccion']['diferencias']);
        self::assertSame([1, '100.000'], [$porFuente['base_produccion']['base']['filas'], $porFuente['base_produccion']['base']['suma']]);

        // Con diferencias no se firma.
        $recaudo = $this->navegador('190.85.18.12', self::PASOS_SINTETICOS);
        $this->cuentaActiva(self::$bd, $recaudo, 'recaudo@kora.local', 'Rita Recaudo', 'grupo_recaudo');
        // Firmar exige reautenticación reciente (RAC-026).
        $recaudo->postHtmx("/importaciones/carga-inicial/{$ulid}/firmar", ['declaracion' => '1']);
        self::assertStringStartsWith('/reautenticar', $recaudo->destino());
        $this->reautenticar($recaudo);
        $firma = $recaudo->postHtmx("/importaciones/carga-inicial/{$ulid}/firmar", ['declaracion' => '1']);
        self::assertSame(422, $firma->estado);
        self::assertStringContainsString('Solo se firma una carga verificada', $firma->cuerpo);
        self::assertSame(403, $recaudo->postHtmx("/importaciones/carga-inicial/{$ulid}/verificar")->estado, 'El Grupo de Recaudo firma, pero no conduce la carga.');

        // Se corrige: el registro ajeno se archiva y la fila con error se corrige en el libro y se vuelve a cargar.
        self::$bd->ejecutar("UPDATE productos SET archivado_en = UTC_TIMESTAMP(3) WHERE codigo_sgac = '9999999999'");
        $this->importar($coordinacion, $this->libro(['Base Ventas' => [$encabezadoVentas, $venta('FD-1', 10), $venta('FD-2', 5), $venta('FD-3', 3)]]), ['base_ventas']);
        $coordinacion->postHtmx("/importaciones/carga-inicial/{$ulid}/verificar");
        $resultado = $this->resultado($ulid);
        self::assertSame(0, $resultado['diferencias'], json_encode($resultado, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame('verificada', self::$bd->valor('SELECT estado FROM cargas_iniciales WHERE ulid = :u', ['u' => $ulid]));

        // Un cambio después de verificar impide firmar: la firma vuelve a calcular las cifras.
        $salida = (int) self::$bd->valor('SELECT id FROM salidas WHERE archivado_en IS NULL ORDER BY id LIMIT 1');
        self::$bd->ejecutar('UPDATE salidas SET valor = valor + 1 WHERE id = :id', ['id' => $salida]);
        $firma = $recaudo->postHtmx("/importaciones/carga-inicial/{$ulid}/firmar", ['declaracion' => '1']);
        self::assertSame(422, $firma->estado);
        self::assertStringContainsString('Los datos cambiaron desde la verificación', $firma->cuerpo);
        self::assertSame('con_diferencias', self::$bd->valor('SELECT estado FROM cargas_iniciales WHERE ulid = :u', ['u' => $ulid]));
        self::$bd->ejecutar('UPDATE salidas SET valor = valor - 1 WHERE id = :id', ['id' => $salida]);
        $coordinacion->postHtmx("/importaciones/carga-inicial/{$ulid}/verificar");

        // La firma exige la declaración, queda auditada y bloquea una nueva carga o su anulación.
        self::assertSame(422, $recaudo->postHtmx("/importaciones/carga-inicial/{$ulid}/firmar", ['observaciones' => 'Sin declaración'])->estado);
        self::assertSame(200, $recaudo->postHtmx("/importaciones/carga-inicial/{$ulid}/firmar", ['declaracion' => '1', 'observaciones' => 'Cifras revisadas con el libro.'])->estado);
        $carga = self::$bd->fila('SELECT estado, firmada_por, huella FROM cargas_iniciales WHERE ulid = :u', ['u' => $ulid]);
        self::assertSame('firmada', $carga['estado']);
        self::assertSame((string) self::$bd->valor("SELECT id FROM usuarios WHERE correo = 'recaudo@kora.local'"), (string) $carga['firmada_por']);
        foreach (['carga_inicial.iniciar', 'carga_inicial.verificar', 'carga_inicial.firmar'] as $accion) {
            self::assertGreaterThan(0, (int) self::$bd->valor('SELECT COUNT(*) FROM eventos_auditoria WHERE accion = :a', ['a' => $accion]), $accion);
        }
        self::assertStringContainsString('ya está firmada', $coordinacion->postHtmx('/importaciones/carga-inicial', ['vigencia' => '2027'])->cuerpo);
        $this->reautenticar($coordinacion);
        self::assertSame(422, $coordinacion->postHtmx("/importaciones/carga-inicial/{$ulid}/anular", ['motivo' => 'Intento de anular una carga firmada'])->estado);

        // El acta muestra la firma y la huella, y ningún dato personal del libro.
        $acta = $coordinacion->get("/importaciones/carga-inicial/{$ulid}/acta")->cuerpo;
        self::assertStringContainsString('Verificada y firmada', $acta);
        self::assertStringContainsString((string) $carga['huella'], $acta);
        self::assertStringNotContainsString('1098765432', $acta);
        self::assertSame(403, $lider->get("/importaciones/carga-inicial/{$ulid}/acta")->estado);
    }

    public function testAnulacionConMotivoPermiteEmpezarDeNuevo(): void
    {
        $coordinacion = $this->navegador('190.85.18.20', self::PASOS_SINTETICOS);
        $this->cuentaActiva(self::$bd, $coordinacion, 'coordinacion@kora.local', 'Clara Coordinación', 'coordinacion_administrativa');
        $coordinacion->get('/importaciones/carga-inicial');
        $coordinacion->postHtmx('/importaciones/carga-inicial', ['vigencia' => '2027']);
        $ulid = (string) self::$bd->valor('SELECT ulid FROM cargas_iniciales');
        $this->reautenticar($coordinacion);
        self::assertSame(422, $coordinacion->postHtmx("/importaciones/carga-inicial/{$ulid}/anular", ['motivo' => 'corto'])->estado);
        self::assertSame(200, $coordinacion->postHtmx("/importaciones/carga-inicial/{$ulid}/anular", ['motivo' => 'Se cargó la vigencia equivocada.'])->estado);
        self::assertSame('anulada', self::$bd->valor('SELECT estado FROM cargas_iniciales WHERE ulid = :u', ['u' => $ulid]));
        self::assertSame('Se cargó la vigencia equivocada.', self::$bd->valor("SELECT motivo FROM eventos_auditoria WHERE accion = 'carga_inicial.anular'"));
        self::assertSame(200, $coordinacion->postHtmx('/importaciones/carga-inicial', ['vigencia' => '2026'])->estado);
        self::assertSame(1, (int) self::$bd->valor("SELECT COUNT(*) FROM cargas_iniciales WHERE estado = 'en_curso'"));
        self::assertStringContainsString('Cargas anteriores', $coordinacion->get('/importaciones/carga-inicial')->cuerpo);
    }

    public function testEnsayoConLosLibrosRealesYLasOchoFuentes(): void
    {
        $libros = ['admon' => RAIZ . '/referencias/BBDD_DATOS ADMON.xlsx', 'produccion' => RAIZ . '/referencias/BBDD_Produccion de Centro.xlsx', 'hato' => RAIZ . '/referencias/INFO_Hato.xlsx'];
        foreach ($libros as $ruta) {
            if (!is_file($ruta)) {
                self::markTestSkipped('Los libros reales no están en esta máquina (no se versionan).');
            }
        }
        $usuario = $this->navegador('190.85.18.30', ServicioCargaInicial::PASOS);
        $this->cuentaActiva(self::$bd, $usuario, 'carga.piloto@kora.local', 'Carga del piloto', 'coordinacion_administrativa');
        foreach (['grupo_recaudo', 'lider_produccion_centro', 'coordinacion_academica'] as $rol) {
            $this->agregarRol('carga.piloto@kora.local', $rol);
        }
        $usuario->get('/importaciones/carga-inicial');
        self::assertSame(200, $usuario->postHtmx('/importaciones/carga-inicial', ['vigencia' => '2026'])->estado);
        $ulid = (string) self::$bd->valor('SELECT ulid FROM cargas_iniciales');

        $gan = (string) self::$bd->valor("SELECT id FROM unidades_productivas WHERE codigo = 'GAN'");
        $this->importar($usuario, $this->copiaReal($libros['produccion']), ['catalogo_productos']);
        $this->importar($usuario, $this->copiaReal($libros['admon']), ['presupuesto', 'cupos_autoconsumo', 'recaudos_dg'], ['vigencia' => '2026']);
        $this->importar($usuario, $this->copiaReal($libros['produccion']), ['base_produccion']);
        $this->importar($usuario, $this->copiaReal($libros['produccion']), ['base_ventas']);
        $this->importar($usuario, $this->copiaReal($libros['hato']), ['estado_reproductivo'], ['unidad_hato' => $gan, 'fecha_corte' => '2026-11-09']);
        $usuario->postHtmx("/importaciones/carga-inicial/{$ulid}/verificar");

        $resultado = $this->resultado($ulid);
        $porFuente = array_column($resultado['fuentes'], null, 'fuente');
        // Volumen de 2.10: 106 recaudos, 6 recepciones, 146 salidas (una sin medida ni producto se rechaza) y 35 animales.
        self::assertSame([106, 106], [$porFuente['recaudos_dg']['archivo']['filas'], $porFuente['recaudos_dg']['base']['filas']]);
        self::assertSame([6, 6], [$porFuente['base_produccion']['archivo']['filas'], $porFuente['base_produccion']['base']['filas']]);
        self::assertSame([146, 145], [$porFuente['base_ventas']['archivo']['filas'], $porFuente['base_ventas']['base']['filas']]);
        self::assertSame([35, 35], [$porFuente['estado_reproductivo']['archivo']['filas'], $porFuente['estado_reproductivo']['base']['filas']]);
        self::assertTrue($porFuente['gastos_ejecutados']['omitida'] ?? false);
        // Los libros actuales tienen 21 filas que no se pueden cargar: 20 del catálogo (19 medidas no reconocidas y un
        // precio mínimo mayor que el máximo; 190 filas, 170 productos) y 1 de ventas (sin medida). Las demás fuentes
        // coinciden en filas, sumas y unidades. La carga no queda verificada hasta que se corrijan en los libros.
        $conDiferencias = array_keys(array_filter($porFuente, static fn (array $f): bool => $f['diferencias'] !== []));
        self::assertSame(['catalogo_productos', 'base_ventas'], $conDiferencias, json_encode(array_map(static fn (array $f): array => $f['diferencias'], $porFuente), JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame([190, 170, 20], [$porFuente['catalogo_productos']['archivo']['filas'], $porFuente['catalogo_productos']['base']['filas'], count($porFuente['catalogo_productos']['rechazadas'])]);
        self::assertCount(1, $porFuente['base_ventas']['rechazadas']);
        self::assertSame('con_diferencias', self::$bd->valor('SELECT estado FROM cargas_iniciales WHERE ulid = :u', ['u' => $ulid]));
    }

    // ---------------------------------------------------------------- Ayudas

    /** @return array<string,mixed> */
    private function resultado(string $ulid): array
    {
        return json_decode((string) self::$bd->valor('SELECT resultado FROM cargas_iniciales WHERE ulid = :u', ['u' => $ulid]), true) ?: [];
    }

    private function reautenticar(NavegadorPrueba $navegador): void
    {
        $navegador->get('/reautenticar');
        $navegador->post('/reautenticar', ['secreto' => self::$contrasenaPrueba]);
        $navegador->get('/importaciones/carga-inicial'); // La sesión se regenera: se toma el nuevo token CSRF.
    }

    private function agregarRol(string $correo, string $rol): void
    {
        $id = (int) self::$bd->valor('SELECT id FROM usuarios WHERE correo = :c', ['c' => $correo]);
        self::$bd->ejecutar('INSERT INTO usuarios_roles (usuario_id, rol_id) SELECT :u, id FROM roles WHERE codigo = :r', ['u' => $id, 'r' => $rol]);
        self::$bd->ejecutar('UPDATE usuarios SET version_permisos = version_permisos + 1 WHERE id = :u', ['u' => $id]);
    }

    /**
     * @param array{nombre:string,ruta:string,tamano:int,error:int} $libro
     * @param list<string> $fuentes
     * @param array<string,string> $opciones
     */
    private function importar(NavegadorPrueba $navegador, array $libro, array $fuentes, array $opciones = []): void
    {
        $seleccion = $navegador->postHtmx('/importaciones/libro', [], [], ['archivo' => $libro]);
        self::assertSame(1, preg_match('/name="token" value="([^"]+)"/', $seleccion->cuerpo, $m), 'La carga del libro devuelve la selección de fuentes.');
        $navegador->postHtmx('/importaciones', ['token' => html_entity_decode($m[1]), 'fuentes' => $fuentes] + $opciones);
        $this->procesarCola();
        foreach ($fuentes as $fuente) {
            $lote = self::$bd->fila('SELECT ulid FROM lotes_importacion WHERE fuente = :f ORDER BY id DESC LIMIT 1', ['f' => $fuente]);
            self::assertNotNull($lote, $fuente);
            $navegador->postHtmx('/importaciones/' . $lote['ulid'] . '/aplicar', ['solo_validas' => '1']);
        }
        $this->procesarCola();
    }

    /**
     * @param array<string,array{obligatoria:bool,libro:string}> $pasos
     */
    private function navegador(string $ip, array $pasos): NavegadorPrueba
    {
        $directorio = $this->directorio;
        return new NavegadorPrueba(static function (Contenedor $c) use ($directorio, $pasos): void {
            $c->establecer(ServicioCargaArchivos::class, new ServicioCargaArchivos($directorio, false));
            $c->establecer(ServicioCargaInicial::class, new ServicioCargaInicial(
                $c->obtener(BaseDatos::class),
                $c->obtener(ServicioImportaciones::class),
                self::respaldoSinConfigurar($c->obtener(BaseDatos::class)),
                $c->obtener(ServicioAuditoria::class),
                $c->obtener(ServicioVersionDatos::class),
                $pasos,
            ));
        }, $ip);
    }

    /** Las copias se prueban en RendimientoOperacionTest; aquí no se toma una copia real de la base de pruebas. */
    private static function respaldoSinConfigurar(BaseDatos $bd): ServicioRespaldo
    {
        return new ServicioRespaldo($bd, [
            'directorio' => sys_get_temp_dir(), 'almacenamiento' => sys_get_temp_dir(), 'mysqldump' => 'mysqldump', 'mysql' => 'mysql', 'llave_publica' => '',
            'destino_externo' => '', 'diarias' => 30, 'mensuales' => 12, 'umbral_importacion' => 500, 'entorno' => 'pruebas',
            'bd' => ['host' => Entorno::texto('BD_HOST', '127.0.0.1'), 'puerto' => Entorno::entero('BD_PUERTO', 3306), 'usuario' => Entorno::texto('BD_USUARIO'),
                'contrasena' => Entorno::texto('BD_CONTRASENA', ''), 'nombre' => Entorno::texto('BD_NOMBRE')],
        ]);
    }

    private function procesarCola(): void
    {
        $aplicacion = Aplicacion::crear(require RAIZ . '/config/app.php');
        $aplicacion->contenedor()->establecer(ServicioCargaArchivos::class, new ServicioCargaArchivos($this->directorio, false));
        for ($i = 0; $i < 4; $i++) {
            $aplicacion->contenedor()->obtener(Trabajador::class)->ejecutar(null, 20);
        }
    }

    /**
     * @param array<string,list<list<mixed>>> $hojas
     * @return array{nombre:string,ruta:string,tamano:int,error:int}
     */
    private function libro(array $hojas): array
    {
        $ruta = sys_get_temp_dir() . '/kora-libro-' . bin2hex(random_bytes(6)) . '.xlsx';
        $escritor = new Writer();
        $escritor->openToFile($ruta);
        $primera = true;
        foreach ($hojas as $nombre => $filas) {
            $hoja = $primera ? $escritor->getCurrentSheet() : $escritor->addNewSheetAndMakeItCurrent();
            $primera = false;
            $hoja->setName($nombre);
            foreach ($filas as $fila) {
                $escritor->addRow(Row::fromValues($fila));
            }
        }
        $escritor->close();
        return ['nombre' => 'BBDD prueba.xlsx', 'ruta' => $ruta, 'tamano' => (int) filesize($ruta), 'error' => UPLOAD_ERR_OK];
    }

    /** @return array{nombre:string,ruta:string,tamano:int,error:int} */
    private function copiaReal(string $ruta): array
    {
        $copia = (string) tempnam(sys_get_temp_dir(), 'kora-real-');
        copy($ruta, $copia);
        return ['nombre' => basename($ruta), 'ruta' => $copia, 'tamano' => (int) filesize($copia), 'error' => UPLOAD_ERR_OK];
    }

    private function centro(): int
    {
        return (int) self::$bd->valor("SELECT id FROM centros WHERE codigo = '9124'");
    }
}
