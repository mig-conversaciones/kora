<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Cola\Trabajador;
use App\Importacion\ServicioCargaArchivos;
use App\Nucleo\Aplicacion;
use App\Nucleo\BaseDatos;
use App\Nucleo\Contenedor;
use App\Nucleo\Migrador;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioCalidad;
use App\Servicios\ServicioMantenimiento;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\TestCase;

/**
 * Iteración 5 «Importación y recaudos» de extremo a extremo, con libros XLSX sintéticos (nunca los datos reales):
 * carga segura, detección de fuentes, validación en la cola, vista previa sin efectos, aplicación con procedencia e
 * idempotencia, errores por fila con reporte y aplicación parcial, permisos por fuente y unidad, reversión, hato con
 * fecha de corte, cifras de control, recaudos de la DG sin datos personales, clasificación y conciliación.
 */
final class ImportacionesTest extends TestCase
{
    use AyudasCuentas;

    private const TABLAS = ['conciliaciones_recaudo', 'incidencias_calidad', 'registros_reproductivos', 'cortes_hato', 'animales', 'transformaciones', 'salidas',
        'recepciones_lote', 'recaudos', 'archivos_adjuntos', 'gastos_ejecutados', 'asignaciones_presupuestales',
        'procesos_autoconsumo', 'periodos_cierre', 'homologaciones_producto', 'productos', 'parametros_indicadores', 'categorias_gasto', 'versiones_datos', 'filas_importacion', 'lotes_importacion',
        'claves_idempotencia', 'codigos_respaldo_2fa', 'tokens_cuenta', 'sesiones', 'notificaciones', 'usuarios_unidades', 'usuarios_roles',
        'trabajos_fallidos', 'trabajos', 'limites_tasa', 'eventos_auditoria'];

    private static BaseDatos $bd;
    private string $senal;
    private string $directorio;
    /** @var array<string,string> contenido de cada libro generado (la carga mueve el archivo) */
    private array $contenidos = [];

    public static function setUpBeforeClass(): void
    {
        self::$bd = new BaseDatos(require RAIZ . '/config/base_datos.php');
        $migrador = new Migrador(self::$bd, RAIZ . '/bd/migraciones');
        $migrador->aplicar();
        $migrador->sembrar(RAIZ . '/bd/semillas/001_identidad_y_estructura.sql');
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
        $this->senal = sys_get_temp_dir() . '/kora-senal-i5-' . bin2hex(random_bytes(4)) . '.json';
        $this->directorio = sys_get_temp_dir() . '/kora-importaciones-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_file($this->senal)) {
            unlink($this->senal);
        }
        if (is_dir($this->directorio)) {
            $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directorio, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterador as $archivo) {
                $archivo->isDir() ? rmdir($archivo->getPathname()) : unlink($archivo->getPathname());
            }
            rmdir($this->directorio);
        }
    }

    public function testLaCargaRechazaArchivosNoValidosAntesDeProcesarlos(): void
    {
        $lider = $this->navegador('190.85.14.10');
        $this->cuentaActiva(self::$bd, $lider, 'lider.produccion@kora.local', 'Pedro Producción', 'lider_produccion_centro');
        $lider->get('/importaciones');

        $pdf = $this->archivo('libro.xlsx', "%PDF-1.7\n%falso");
        self::assertStringContainsString('su contenido no corresponde a un .xlsx', $lider->postHtmx('/importaciones/libro', [], [], ['archivo' => $pdf])->cuerpo);
        $macros = $this->archivo('libro.xlsm', 'PK');
        self::assertStringContainsString('Los libros .xlsm y .xls no se aceptan', $lider->postHtmx('/importaciones/libro', [], [], ['archivo' => $macros])->cuerpo);
        $grande = ['tamano' => 11 * 1024 * 1024] + $this->archivo('grande.xlsx', 'PK');
        $respuesta = $lider->postHtmx('/importaciones/libro', [], [], ['archivo' => $grande]);
        self::assertSame(422, $respuesta->estado);
        self::assertStringContainsString('supera 10 MB', $respuesta->cuerpo);

        // Protección contra bombas ZIP: más de 500 entradas.
        $ruta = (string) tempnam(sys_get_temp_dir(), 'kora-zip-');
        $zip = new \ZipArchive();
        $zip->open($ruta, \ZipArchive::OVERWRITE);
        for ($i = 0; $i < 501; $i++) {
            $zip->addFromString("parte{$i}.xml", 'x');
        }
        $zip->close();
        $bomba = ['nombre' => 'bomba.xlsx', 'ruta' => $ruta, 'tamano' => (int) filesize($ruta), 'error' => UPLOAD_ERR_OK];
        self::assertStringContainsString('demasiadas partes internas', $lider->postHtmx('/importaciones/libro', [], [], ['archivo' => $bomba])->cuerpo);
        self::assertSame(0, (int) self::$bd->valor('SELECT COUNT(*) FROM lotes_importacion'));
    }

    public function testCatalogoProduccionYVentasConVistaPreviaProcedenciaIdempotenciaYReversion(): void
    {
        $lider = $this->navegador('190.85.14.20');
        $this->cuentaActiva(self::$bd, $lider, 'lider.produccion@kora.local', 'Pedro Producción', 'lider_produccion_centro');
        $fecha = $this->fecha(5);
        $libro = $this->libro([
            'CatalogoProductos_CAB' => [
                ['Catálogo de productos del CAB'],
                ['Nombre', 'Codigo SGAC', 'Unidad  Productiva', 'Precio Minimo', 'Precio Maximo', 'Precio de Venta', 'Medida', 'Clase', 'CodigoFamilia', 'NombreFamilia'],
                ['Leche de vaca', '5013170101', 'UP_Ganaderia', 2000, 2400, 2200, 'Litros', 'Lácteos', '5013', 'Productos lácteos'],
                ['Queso cuajada', '5013170201', '', '', '', 18000, 'Kilos', 'Lácteos', '5013', 'Productos lácteos'],
                ['Bovino en pie', '1010150100', 'UP_Ganaderia', '', '', '', 'Kilogramo', 'Bovinos', '1010', 'Animales vivos'],
                ['Asesoría técnica', '8010150000', '', '', '', '', 'Hora', 'Servicios', '8010', 'Servicios'],
            ],
            'Base Produccion Centro' => [
                ['Num_Interno', 'Fecha Recepción', 'Nombre Proveedor', 'Nombre Producto', 'Código SGARC', 'Cantidad Recibida', 'Unidad de Medida', 'Precio de Venta Sugerido', 'Usuario que recibe'],
                ['GAN-REC-010126-001', new \DateTimeImmutable($fecha), 'UP_Ganaderia', 'Leche de vaca', '5013170101', 100, 'Litros', 2200, 'Persona que recibe'],
            ],
            'Base Ventas' => [
                ['Num_Interno_Compra', 'No. Factura Didáctica', 'Fecha Venta', 'Código SGARC', 'Nombre del Producto', 'Unidad de Medida', 'Cantidad Vendida', 'Precio Unitario Venta', 'Subtotal', 'Método de Pago', 'Origen', 'Código Transacción', 'ID Cliente'],
                ['GAN-REC-010126-001', 'FD-1', new \DateTimeImmutable($fecha), '5013170101', 'Leche de vaca', 'Litros', 10, 2200, 22000, 'TRANSFERENCIA', 'NEQUI', 'NO APLICA', '1098765432'],
                ['GAN-REC-010126-001', 'FD-1', new \DateTimeImmutable($fecha), '5013170101', 'Leche de vaca', 'Litros', 10, 2200, 22000, 'TRANSFERENCIA', 'NEQUI', 'NO APLICA', '1098765432'],
                ['AGR-REC-03122025', '', new \DateTimeImmutable($fecha), '', '', 'LIBRA', 3, 6000, 18000, 'TRANSFERENCIA', 'NEQUI', 'M1', 'CLIENTE SENA'],
            ],
        ]);

        // RF-083: el libro se reconoce por sus hojas; el rol elige las fuentes que puede importar.
        $lider->get('/importaciones');
        $seleccion = $lider->postHtmx('/importaciones/libro', [], [], ['archivo' => $libro]);
        self::assertSame(200, $seleccion->estado, $seleccion->cuerpo);
        foreach (['Catálogo de productos', 'Base de producción', 'Base de ventas'] as $fuente) {
            self::assertStringContainsString($fuente, $seleccion->cuerpo);
        }
        $token = self::token($seleccion->cuerpo);

        // RF-085 y RF-087: el lote se valida en la cola y nada de negocio cambia antes de aplicar.
        self::assertSame(200, $lider->postHtmx('/importaciones', ['token' => $token, 'fuentes' => ['catalogo_productos']])->estado);
        $this->procesarCola();
        $catalogo = self::$bd->fila("SELECT * FROM lotes_importacion WHERE fuente = 'catalogo_productos'");
        self::assertSame('con_errores', $catalogo['estado']);
        self::assertSame([4, 3, 1], [(int) $catalogo['filas_total'], (int) $catalogo['filas_validas'], (int) $catalogo['filas_error']]);
        self::assertSame(0, (int) self::$bd->valor('SELECT COUNT(*) FROM productos'), 'La vista previa no aplica nada.');
        $vista = $lider->get('/importaciones/' . $catalogo['ulid'])->cuerpo;
        self::assertStringContainsString('La medida no se reconoce', $vista);

        // RF-091: el reporte tiene exactamente las filas con error de la vista previa.
        $reporte = $lider->get('/importaciones/' . $catalogo['ulid'] . '/errores');
        self::assertSame(200, $reporte->estado);
        self::assertSame(1 + 1, self::filasXlsx($reporte->cuerpo), 'Encabezado + una fila con error.');

        // Con errores, aplicar exige confirmar «solo las válidas».
        self::assertSame(422, $lider->postHtmx('/importaciones/' . $catalogo['ulid'] . '/aplicar')->estado);
        self::assertSame(200, $lider->postHtmx('/importaciones/' . $catalogo['ulid'] . '/aplicar', ['solo_validas' => '1'])->estado);
        $this->procesarCola();
        self::assertSame('aplicado', self::$bd->valor('SELECT estado FROM lotes_importacion WHERE id = :id', ['id' => $catalogo['id']]));
        $bovino = self::$bd->fila("SELECT medida, categoria_rotacion, origen_tipo, lote_importacion_id, origen_fila FROM productos WHERE codigo_sgac = '1010150100'");
        self::assertSame(['kg', 'animal', 'importacion', (string) $catalogo['id'], '5'], [$bovino['medida'], $bovino['categoria_rotacion'], $bovino['origen_tipo'], (string) $bovino['lote_importacion_id'], (string) $bovino['origen_fila']], 'Procedencia: lote y fila (RF-088).');

        // RF-089: el mismo archivo ya aplicado se informa y no se vuelve a aplicar.
        $otraVez = $lider->postHtmx('/importaciones/libro', [], [], ['archivo' => $this->copia($libro)]);
        self::assertStringContainsString('Este mismo archivo ya se aplicó', $otraVez->cuerpo);

        // Producción y ventas: la venta encuentra su lote; la que no lo tiene queda sin vínculo (RF-045).
        self::assertSame(200, $lider->postHtmx('/importaciones', ['token' => $token, 'fuentes' => ['base_produccion', 'base_ventas']])->estado);
        $this->procesarCola();
        $produccion = self::$bd->fila("SELECT * FROM lotes_importacion WHERE fuente = 'base_produccion'");
        $ventas = self::$bd->fila("SELECT * FROM lotes_importacion WHERE fuente = 'base_ventas'");
        self::assertSame('validado', $produccion['estado']);
        self::assertSame(3, (int) $ventas['filas_validas'], 'Dos ventas idénticas son dos salidas: la clave lleva el número de ocurrencia.');
        $lider->postHtmx('/importaciones/' . $produccion['ulid'] . '/aplicar');
        $this->procesarCola();
        $lider->postHtmx('/importaciones/' . $ventas['ulid'] . '/aplicar');
        $this->procesarCola();
        self::assertSame(2, (int) self::$bd->valor('SELECT COUNT(*) FROM salidas WHERE recepcion_id IS NOT NULL'));
        $huerfana = self::$bd->fila("SELECT recepcion_id, unidad_id, medida, destino FROM salidas WHERE numero_interno_texto = 'AGR-REC-03122025'");
        self::assertNull($huerfana['recepcion_id']);
        self::assertSame($this->unidad('AGR'), (int) $huerfana['unidad_id'], 'La unidad sale del prefijo.');
        self::assertSame('lb', $huerfana['medida']);
        self::assertSame('Cliente ****5432', self::$bd->valor("SELECT destino FROM salidas WHERE factura = 'FD-1' LIMIT 1"), 'La identificación del cliente nunca queda en claro.');
        $cifras = json_decode((string) self::$bd->valor('SELECT cifras_control FROM lotes_importacion WHERE id = :id', ['id' => $ventas['id']]), true);
        self::assertTrue($cifras['verificada'], 'RF-094: las cifras de la base coinciden con las del archivo.');
        self::assertStringContainsString('Verificada', $lider->get('/importaciones/acta')->cuerpo);

        // RF-090: la producción no se revierte mientras las ventas dependan de ella; las ventas sí, con reautenticación.
        self::assertStringContainsString('No se puede revertir', $lider->get('/importaciones/' . $produccion['ulid'])->cuerpo);
        $lider->postHtmx('/importaciones/' . $ventas['ulid'] . '/revertir', ['motivo' => 'Base de ventas equivocada']);
        $lider->get('/reautenticar');
        $lider->post('/reautenticar', ['secreto' => self::$contrasenaPrueba]);
        $lider->get('/importaciones/' . $ventas['ulid']);
        self::assertSame(422, $lider->postHtmx('/importaciones/' . $ventas['ulid'] . '/revertir', ['motivo' => 'corto'])->estado);
        self::assertSame(200, $lider->postHtmx('/importaciones/' . $ventas['ulid'] . '/revertir', ['motivo' => 'Base de ventas equivocada'])->estado);
        $this->procesarCola();
        self::assertSame('revertido', self::$bd->valor('SELECT estado FROM lotes_importacion WHERE id = :id', ['id' => $ventas['id']]));
        self::assertSame(0, (int) self::$bd->valor('SELECT COUNT(*) FROM salidas WHERE archivado_en IS NULL'));
        self::assertSame('Base de ventas equivocada', self::$bd->valor("SELECT motivo FROM eventos_auditoria WHERE accion = 'importaciones.revertir'"));
    }

    public function testElLiderDeUnidadSoloImportaFilasDeSuUnidadYLaActualizacionTraeSoloLoNuevo(): void
    {
        $lider = $this->navegador('190.85.14.30');
        $this->cuentaActiva(self::$bd, $lider, 'lider.gan@kora.local', 'Luis Ganadería', 'lider_unidad', ['GAN']);
        $leche = self::$bd->insertar("INSERT INTO productos (centro_id, codigo_sgac, nombre, medida) VALUES (:c, '5013170101', 'Leche de vaca', 'L')", ['c' => $this->centro()]);
        self::assertGreaterThan(0, $leche);
        $filas = [['Num_Interno', 'Fecha Recepción', 'Nombre Proveedor', 'Nombre Producto', 'Código SGARC', 'Cantidad Recibida', 'Unidad de Medida']];
        for ($i = 1; $i <= 3; $i++) {
            $filas[] = [sprintf('GAN-REC-010126-%03d', $i), new \DateTimeImmutable($this->fecha(2)), 'UP_Ganaderia', 'Leche de vaca', '5013170101', 10 * $i, 'Litros'];
        }
        $filas[] = ['AGR-REC-010126-001', new \DateTimeImmutable($this->fecha(2)), 'up_Agricola', 'Leche de vaca', '5013170101', 5, 'Litros'];
        $lider->get('/importaciones');
        $token = self::token($lider->postHtmx('/importaciones/libro', [], [], ['archivo' => $this->libro(['Base Produccion Centro' => $filas])])->cuerpo);
        $lider->postHtmx('/importaciones', ['token' => $token, 'fuentes' => ['base_produccion']]);
        $this->procesarCola();
        $lote = self::$bd->fila("SELECT * FROM lotes_importacion WHERE fuente = 'base_produccion'");
        self::assertSame(1, (int) $lote['filas_error']);
        self::assertStringContainsString('Unidad fuera de su alcance.', (string) self::$bd->valor("SELECT errores FROM filas_importacion WHERE lote_id = :l AND estado = 'error'", ['l' => $lote['id']]));
        $lider->postHtmx('/importaciones/' . $lote['ulid'] . '/aplicar', ['solo_validas' => '1']);
        $this->procesarCola();
        self::assertSame(3, (int) self::$bd->valor('SELECT COUNT(*) FROM recepciones_lote'));
        self::assertSame(0, (int) self::$bd->valor('SELECT COUNT(*) FROM recepciones_lote WHERE unidad_id = :u', ['u' => $this->unidad('AGR')]));

        // RF-089: el archivo actualizado con una fila más y otra corregida inserta solo la nueva y actualiza la corregida.
        $filas[1][5] = 15;
        $filas[] = ['GAN-REC-010126-004', new \DateTimeImmutable($this->fecha(3)), 'UP_Ganaderia', 'Leche de vaca', '5013170101', 40, 'Litros'];
        unset($filas[4]);
        $token = self::token($lider->postHtmx('/importaciones/libro', [], [], ['archivo' => $this->libro(['Base Produccion Centro' => array_values($filas)])])->cuerpo);
        $lider->postHtmx('/importaciones', ['token' => $token, 'fuentes' => ['base_produccion']]);
        $this->procesarCola();
        $segundo = self::$bd->fila("SELECT * FROM lotes_importacion WHERE fuente = 'base_produccion' ORDER BY id DESC LIMIT 1");
        self::assertSame([1, 1, 2], [(int) $segundo['filas_nuevas'], (int) $segundo['filas_actualizadas'], (int) $segundo['filas_sin_cambios']]);
        self::assertStringContainsString('Cambios sobre registros existentes', $lider->get('/importaciones/' . $segundo['ulid'])->cuerpo);
        $lider->postHtmx('/importaciones/' . $segundo['ulid'] . '/aplicar');
        $this->procesarCola();
        self::assertSame(4, (int) self::$bd->valor('SELECT COUNT(*) FROM recepciones_lote'));
        self::assertSame('15.000', (string) self::$bd->valor("SELECT cantidad FROM recepciones_lote WHERE numero_interno = 'GAN-REC-010126-001'"));
    }

    public function testRecaudosDeLaDgSinDatosPersonalesConClasificacionYConciliacion(): void
    {
        $recaudo = $this->navegador('190.85.14.40');
        $this->cuentaActiva(self::$bd, $recaudo, 'recaudo@kora.local', 'Rita Recaudo', 'grupo_recaudo');
        $leche = self::$bd->insertar("INSERT INTO productos (centro_id, codigo_sgac, nombre, medida) VALUES (:c, '5013170101', 'Leche de vaca', 'L')", ['c' => $this->centro()]);
        $encabezado = ['Regional', 'Código', 'CentroFormacion', 'Identificacion', 'PrimerNombre', 'PrimerApellido', 'Valor Pago', 'CupónPago', 'Fecha', 'Producto', 'Unidad productiva', 'Prefactura', 'TipoIngreso', 'OrigenPago', 'Asignado', 'NroFacElec'];
        $filas = [
            ['Ingresos_Centro Agropecuario de Buga'], $encabezado,
            ['Valle', '9124', 'CAB', '14898970', 'Marta', 'Zuluaga-77', 19000, 'CUP-A', new \DateTimeImmutable($this->fecha(4)), 'Leche', 'Ganadería', '75010', 'Producción', 'Wompi', '', 'FE-1'],
            ['Valle', '9124', 'CAB', '22334455', 'Jorge', 'Ruiz', 50000, 'CUP-B', new \DateTimeImmutable($this->fecha(4)), 'Huevos', 'Ganadería y Granja', '', 'Producción', 'QR', '', ''],
            ['Valle', '9124', 'CAB', '', '', '', 7000, 'CUP-C', new \DateTimeImmutable($this->fecha(5)), 'Matrícula', 'Comercio', '', 'Servicios', 'Ecollect', '', ''],
        ];
        $recaudo->get('/importaciones');
        $token = self::token($recaudo->postHtmx('/importaciones/libro', [], [], ['archivo' => $this->libro(['Ingresos_Centro Agropecuario de' => $filas])])->cuerpo);
        $recaudo->postHtmx('/importaciones', ['token' => $token, 'fuentes' => ['recaudos_dg']]);
        $this->procesarCola();
        $lote = self::$bd->fila("SELECT * FROM lotes_importacion WHERE fuente = 'recaudos_dg'");
        $recaudo->postHtmx('/importaciones/' . $lote['ulid'] . '/aplicar');
        $this->procesarCola();
        self::assertSame(3, (int) self::$bd->valor('SELECT COUNT(*) FROM recaudos'));
        $leido = self::$bd->fila("SELECT unidad_id, producto_id, identificacion_ultimos4, identificacion_hmac, estado_clasificacion FROM recaudos WHERE cupon_pago = 'CUP-A'");
        self::assertSame([$this->unidad('GAN'), $leche, '8970', 'clasificado'], [(int) $leido['unidad_id'], (int) $leido['producto_id'], $leido['identificacion_ultimos4'], $leido['estado_clasificacion']]);
        self::assertSame('ambiguo', self::$bd->valor("SELECT estado_clasificacion FROM recaudos WHERE cupon_pago = 'CUP-B'"), 'Una unidad ambigua no se prorratea.');

        // RF-034: ni nombres ni identificaciones en claro en ninguna tabla que guarde datos del lote.
        foreach (['Marta', 'Zuluaga-77', '14898970', '22334455'] as $dato) {
            foreach (['recaudos' => "CONCAT_WS('|', concepto_fuente, unidad_fuente, cupon_pago, identificacion_hmac)", 'filas_importacion' => 'CAST(datos AS CHAR)', 'eventos_auditoria' => "CONCAT_WS('|', CAST(metadatos AS CHAR), CAST(valores_despues AS CHAR))"] as $tabla => $columnas) {
                self::assertSame(0, (int) self::$bd->valor("SELECT COUNT(*) FROM {$tabla} WHERE {$columnas} LIKE :d", ['d' => '%' . $dato . '%']), "«{$dato}» no debe estar en {$tabla}.");
            }
        }

        // RF-031: el ambiguo se decide a mano; clasificar en lote crea un evento de auditoría por recaudo.
        $vista = $recaudo->get('/recaudos/clasificar')->cuerpo;
        self::assertStringContainsString('Ambiguo', $vista);
        $pendientes = array_map('intval', array_column(self::$bd->consultar("SELECT id FROM recaudos WHERE estado_clasificacion <> 'clasificado'"), 'id'));
        self::assertCount(1, $pendientes, 'CUP-C tiene unidad y concepto; queda pendiente solo el ambiguo.');
        self::assertSame(200, $recaudo->postHtmx('/recaudos/clasificar', ['ids' => array_map('strval', $pendientes), 'unidad_id' => (string) $this->unidad('GRA')])->estado);
        self::assertSame(1, (int) self::$bd->valor("SELECT COUNT(*) FROM eventos_auditoria WHERE accion = 'recaudos.clasificar' AND resultado = 'exito'"));
        self::assertSame('clasificado', self::$bd->valor("SELECT estado_clasificacion FROM recaudos WHERE cupon_pago = 'CUP-B'"));

        // RF-032: conciliación con una venta de la misma prefactura y valor distinto: «con diferencia» y en el informe.
        $lote2 = self::$bd->insertar(
            "INSERT INTO recepciones_lote (centro_id, unidad_id, numero_interno, fecha_recepcion, producto_id, cantidad, medida) VALUES (:c, :u, 'GAN-REC-010126-009', :f, :p, 50, 'L')",
            ['c' => $this->centro(), 'u' => $this->unidad('GAN'), 'f' => $this->fecha(1), 'p' => $leche]
        );
        $venta = self::$bd->insertar(
            "INSERT INTO salidas (centro_id, unidad_id, recepcion_id, fecha, producto_id, cantidad, medida, precio_unitario, valor, canal, factura, clave_natural)
             VALUES (:c, :u, :r, :f, :p, 8, 'L', 2200, 17600, 'VENTA_DIRECTA', '75010', :k)",
            ['c' => $this->centro(), 'u' => $this->unidad('GAN'), 'r' => $lote2, 'f' => $this->fecha(4), 'p' => $leche, 'k' => hash('sha256', 'venta-conciliable')]
        );
        $id = (int) self::$bd->valor("SELECT id FROM recaudos WHERE cupon_pago = 'CUP-A'");
        self::assertStringContainsString('factura 75010', $recaudo->get('/recaudos/' . $id . '/conciliar')->cuerpo);
        self::assertSame(200, $recaudo->postHtmx('/recaudos/' . $id . '/conciliar', ['salidas' => [(string) $venta], 'criterio' => 'prefactura'])->estado);
        self::assertSame('con_diferencia', self::$bd->valor('SELECT estado_conciliacion FROM recaudos WHERE id = :id', ['id' => $id]));
        $mes = (int) substr($this->fecha(4), 5, 2);
        $anio = (int) substr($this->fecha(4), 0, 4);
        self::assertStringContainsString('$ 1.400', $recaudo->get("/recaudos/diferencias?anio={$anio}&mes={$mes}")->cuerpo, '19.000 − 17.600 = 1.400.');

        // RF-089: reimportar el mismo contenido en otro archivo no duplica.
        $filas[] = ['Valle', '9124', 'CAB', '', '', '', 8000, 'CUP-D', new \DateTimeImmutable($this->fecha(6)), 'Leche', 'Ganadería', '', 'Producción', 'Wompi', '', ''];
        $token = self::token($recaudo->postHtmx('/importaciones/libro', [], [], ['archivo' => $this->libro(['Ingresos_Centro Agropecuario de' => $filas])])->cuerpo);
        $recaudo->postHtmx('/importaciones', ['token' => $token, 'fuentes' => ['recaudos_dg']]);
        $this->procesarCola();
        $nuevo = self::$bd->fila("SELECT * FROM lotes_importacion WHERE fuente = 'recaudos_dg' ORDER BY id DESC LIMIT 1");
        self::assertSame([1, 3], [(int) $nuevo['filas_nuevas'], (int) $nuevo['filas_sin_cambios']]);
    }

    public function testHatoConFechaDeCorteElegidaPermisoPorFuenteEIncidenciaDeIep(): void
    {
        $academica = $this->navegador('190.85.14.50');
        $this->cuentaActiva(self::$bd, $academica, 'academica@kora.local', 'Ana Académica', 'coordinacion_academica');
        $encabezado = ['VACA', 'LOTE', 'FECHA DE NACIMIENTO', 'FECHA ACTUAL ', 'DIAS', 'MESES', 'AÑOS', 'PENULTIMO PARTO', 'ULTIMO PARTO', 'IEP', '# SERVICIOS POST PARTO', 'FECHA SERVICIO EFECTIVO', 'ULTIMO PALPACION', 'ESTADO REPRODUCTIVO', 'EDAD GESTACION/DIAS', 'DIAS ABIERTOS'];
        $fila = static fn (string $vaca, string $lote, string $actual, int $iep): array => [$vaca, $lote, new \DateTimeImmutable('2019-03-01'), new \DateTimeImmutable($actual), 0, 0, 0, new \DateTimeImmutable('2024-06-01'), new \DateTimeImmutable('2025-08-15'), $iep, 1, new \DateTimeImmutable('2025-12-20'), '', 'preñada', '3-5 MESES', 127];
        $libro = $this->libro(['ESTADO REP' => [['Estado reproductivo'], $encabezado, $fila('158', 'VACA HORRA', '2026-09-15', 440), $fila('285', 'VACA PRODUCCION', '2026-11-07', 450), $fila('301', 'PRACTICA', '2026-11-07', 440)]]);
        $academica->get('/importaciones');
        $seleccion = $academica->postHtmx('/importaciones/libro', [], [], ['archivo' => $libro])->cuerpo;
        self::assertStringContainsString('Estado reproductivo del hato', $seleccion);
        $token = self::token($seleccion);

        // Sin fecha elegida, la columna «Fecha actual» debe ser única: aquí hay dos fechas.
        $academica->postHtmx('/importaciones', ['token' => $token, 'fuentes' => ['estado_reproductivo'], 'unidad_hato' => (string) $this->unidad('GAN')]);
        $this->procesarCola();
        self::assertStringContainsString('elige la fecha del corte al cargar', (string) self::$bd->valor("SELECT errores FROM filas_importacion WHERE estado = 'error' LIMIT 1"));

        $academica->postHtmx('/importaciones', ['token' => $token, 'fuentes' => ['estado_reproductivo'], 'unidad_hato' => (string) $this->unidad('GAN'), 'fecha_corte' => '2026-11-09']);
        $this->procesarCola();
        $lote = self::$bd->fila("SELECT * FROM lotes_importacion WHERE fuente = 'estado_reproductivo' ORDER BY id DESC LIMIT 1");
        self::assertSame('validado', $lote['estado']);
        $academica->postHtmx('/importaciones/' . $lote['ulid'] . '/aplicar');
        $this->procesarCola();
        self::assertSame('2026-11-09', self::$bd->valor('SELECT fecha_corte FROM cortes_hato WHERE vigente = 1'));
        self::assertSame([1, 1, 1], array_map('intval', array_column(self::$bd->consultar('SELECT COUNT(*) AS n FROM registros_reproductivos GROUP BY lote_hato ORDER BY lote_hato'), 'n')));
        self::assertSame(440, (int) self::$bd->valor("SELECT r.iep_dias FROM registros_reproductivos r JOIN animales a ON a.id = r.animal_id WHERE a.identificador = '285'"));

        // RF-054: el IEP importado (450) difiere del calculado (440): incidencia de calidad sin bloquear la importación.
        Aplicacion::crear(require RAIZ . '/config/app.php')->contenedor()->obtener(ServicioCalidad::class)->recalcular($this->centro());
        self::assertSame(1, (int) self::$bd->valor("SELECT COUNT(*) FROM incidencias_calidad WHERE tipo = 'iep_diferente' AND estado = 'abierta'"));

        // RF-092: la Coordinación académica no importa recaudos.
        $dg = $this->libro(['Ingresos_Centro Agropecuario de' => [['Ingresos'], ['Regional', 'Código', 'Identificacion', 'Valor Pago', 'CupónPago', 'Fecha', 'Producto', 'Unidad productiva', 'TipoIngreso'], ['Valle', '9124', '', 100, 'X', new \DateTimeImmutable($this->fecha(2)), 'Leche', 'Ganadería', 'Producción']]]);
        $vista = $academica->postHtmx('/importaciones/libro', [], [], ['archivo' => $dg])->cuerpo;
        self::assertStringContainsString('Tu rol no importa esta fuente.', $vista);
        self::assertSame(403, $academica->postHtmx('/importaciones', ['token' => self::token($vista), 'fuentes' => ['recaudos_dg']])->estado);
    }

    // ---------------------------------------------------------------- Ayudas

    private function navegador(string $ip): NavegadorPrueba
    {
        $senal = $this->senal;
        $directorio = $this->directorio;
        return new NavegadorPrueba(static function (Contenedor $c) use ($senal, $directorio): void {
            $c->establecer(ServicioMantenimiento::class, new ServicioMantenimiento(
                $c->obtener(BaseDatos::class),
                $c->obtener(ServicioAuditoria::class),
                ['senal' => $senal, 'latido_trabajador' => sys_get_temp_dir() . '/kora-latido-inexistente.txt'] + require RAIZ . '/config/mantenimiento.php',
                (require RAIZ . '/config/app.php')['version_esquema']
            ));
            // Las pruebas no llegan por HTTP: se omite is_uploaded_file y se guarda en un directorio temporal.
            $c->establecer(ServicioCargaArchivos::class, new ServicioCargaArchivos($directorio, false));
        }, $ip);
    }

    /** Procesa la cola con el trabajador real (validación, aplicación, reversión y recálculo de calidad). */
    private function procesarCola(): void
    {
        $aplicacion = Aplicacion::crear(require RAIZ . '/config/app.php');
        $aplicacion->contenedor()->establecer(ServicioCargaArchivos::class, new ServicioCargaArchivos($this->directorio, false));
        for ($i = 0; $i < 3; $i++) {
            $aplicacion->contenedor()->obtener(Trabajador::class)->ejecutar(null, 10);
        }
    }

    /**
     * Libro XLSX sintético: hoja => filas.
     *
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
        $this->contenidos[$ruta] = (string) file_get_contents($ruta);
        return ['nombre' => 'BBDD prueba.xlsx', 'ruta' => $ruta, 'tamano' => (int) filesize($ruta), 'error' => UPLOAD_ERR_OK];
    }

    /**
     * @param array{nombre:string,ruta:string,tamano:int,error:int} $libro
     * @return array{nombre:string,ruta:string,tamano:int,error:int}
     */
    private function copia(array $libro): array
    {
        $ruta = sys_get_temp_dir() . '/kora-libro-' . bin2hex(random_bytes(6)) . '.xlsx';
        file_put_contents($ruta, $this->contenidos[$libro['ruta']]);
        return ['ruta' => $ruta] + $libro;
    }

    /** @return array{nombre:string,ruta:string,tamano:int,error:int} */
    private function archivo(string $nombre, string $contenido): array
    {
        $ruta = (string) tempnam(sys_get_temp_dir(), 'kora-subida-');
        file_put_contents($ruta, $contenido);
        return ['nombre' => $nombre, 'ruta' => $ruta, 'tamano' => strlen($contenido), 'error' => UPLOAD_ERR_OK];
    }

    private static function token(string $html): string
    {
        self::assertSame(1, preg_match('/name="token" value="([^"]+)"/', $html, $m), 'La selección de fuentes trae el token de la carga.');
        return html_entity_decode($m[1]);
    }

    private static function filasXlsx(string $contenido): int
    {
        $ruta = (string) tempnam(sys_get_temp_dir(), 'kora-reporte-') . '.xlsx';
        file_put_contents($ruta, $contenido);
        $lector = new Reader();
        $lector->open($ruta);
        $filas = 0;
        foreach ($lector->getSheetIterator() as $hoja) {
            foreach ($hoja->getRowIterator() as $fila) {
                $filas++;
            }
        }
        $lector->close();
        unlink($ruta);
        return $filas;
    }

    private function centro(): int
    {
        return (int) self::$bd->valor("SELECT id FROM centros WHERE codigo = '9124'");
    }

    private function unidad(string $codigo): int
    {
        return (int) self::$bd->valor('SELECT id FROM unidades_productivas WHERE codigo = :c', ['c' => $codigo]);
    }

    private function fecha(int $dia): string
    {
        return (new \DateTimeImmutable('first day of last month', new \DateTimeZone('America/Bogota')))->format('Y-m-') . sprintf('%02d', $dia);
    }
}
