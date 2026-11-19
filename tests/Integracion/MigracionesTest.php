<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Nucleo\BaseDatos;
use App\Nucleo\Migrador;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * RBD-011: aplicar, revertir y volver a aplicar todas las migraciones sobre una base vacía. Corre contra
 * kora_pruebas (MySQL 5.7 en local; MariaDB 10.6 y MySQL 5.7 en la CI).
 */
final class MigracionesTest extends TestCase
{
    private const TABLAS_ESPERADAS = [
        'centros', 'unidades_productivas', 'destinos_internos',
        'usuarios', 'roles', 'permisos', 'roles_permisos', 'usuarios_roles', 'usuarios_unidades',
        'sesiones', 'tokens_cuenta', 'codigos_respaldo_2fa', 'limites_tasa', 'claves_idempotencia',
        'eventos_auditoria', 'cadena_auditoria',
        'trabajos', 'trabajos_fallidos', 'estado_circuitos',
        'configuracion_sistema', 'versiones_datos', 'notificaciones', 'archivos_adjuntos',
        'lotes_importacion', 'filas_importacion',
        'productos', 'homologaciones_producto', 'categorias_gasto', 'parametros_indicadores',
        'asignaciones_presupuestales', 'gastos_ejecutados', 'procesos_autoconsumo', 'recaudos', 'periodos_cierre',
        'recepciones_lote', 'salidas', 'transformaciones', 'conciliaciones_recaudo',
        'animales', 'cortes_hato', 'registros_reproductivos',
        'incidencias_calidad', 'exportaciones',
    ];
    private const TOTAL_MIGRACIONES = 12;

    private BaseDatos $bd;
    private Migrador $migrador;

    protected function setUp(): void
    {
        $this->bd = new BaseDatos(require RAIZ . '/config/base_datos.php');
        $this->migrador = new Migrador($this->bd, RAIZ . '/bd/migraciones');
    }

    public function testIdaVueltaYReaplicacion(): void
    {
        $this->migrador->revertir(100);
        self::assertSame([], $this->tablasPresentes());

        $aplicadas = $this->migrador->aplicar();
        self::assertCount(self::TOTAL_MIGRACIONES, $aplicadas);
        $esperadas = self::TABLAS_ESPERADAS;
        sort($esperadas);
        self::assertSame($esperadas, $this->tablasPresentes(), 'Exactamente las tablas de las migraciones 001 a 012.');
        self::assertSame('012_crear_calidad_exportaciones', $this->migrador->ultimaAplicada());
        self::assertSame(1, (int) $this->bd->valor('SELECT COUNT(*) FROM cadena_auditoria'));

        self::assertCount(self::TOTAL_MIGRACIONES, $this->migrador->revertir(self::TOTAL_MIGRACIONES));
        self::assertSame([], $this->tablasPresentes());

        self::assertCount(self::TOTAL_MIGRACIONES, $this->migrador->aplicar());
        self::assertSame([], $this->migrador->aplicar(), 'Una segunda ejecución no aplica nada.');
    }

    public function testTodasLasTablasUsanInnoDbYUtf8mb4(): void
    {
        $this->migrador->aplicar();
        $filas = $this->bd->consultar(
            'SELECT TABLE_NAME AS tabla, ENGINE AS motor, TABLE_COLLATION AS intercalacion
               FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        );
        self::assertNotEmpty($filas);
        foreach ($filas as $fila) {
            self::assertSame('InnoDB', $fila['motor'], (string) $fila['tabla']);
            self::assertSame('utf8mb4_unicode_ci', $fila['intercalacion'], (string) $fila['tabla']);
        }
    }

    public function testRechazaUnaMigracionAplicadaQueCambio(): void
    {
        $directorio = sys_get_temp_dir() . '/kora-migraciones-' . bin2hex(random_bytes(4));
        mkdir($directorio);
        try {
            $this->bd->ejecutar('DROP TABLE IF EXISTS prueba_alterada');
            $migrador = new Migrador($this->bd, $directorio);
            file_put_contents("{$directorio}/900_prueba_alterada.sql", "CREATE TABLE prueba_alterada (id INT);\n");
            file_put_contents("{$directorio}/900_prueba_alterada.down.sql", "DROP TABLE IF EXISTS prueba_alterada;\n");
            $migrador->aplicar();
            file_put_contents("{$directorio}/900_prueba_alterada.sql", "CREATE TABLE prueba_alterada (id BIGINT);\n");
            self::assertSame('alterada', $migrador->estado()[0]['estado']);
            try {
                $migrador->aplicar();
                self::fail('Debía rechazar la migración alterada.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('cambió de contenido', $error->getMessage());
            }
            file_put_contents("{$directorio}/900_prueba_alterada.sql", "CREATE TABLE prueba_alterada (id INT);\n");
            $migrador->revertir(1);
        } finally {
            $this->bd->ejecutar("DELETE FROM migraciones_aplicadas WHERE nombre = '900_prueba_alterada'");
            $this->bd->ejecutar('DROP TABLE IF EXISTS prueba_alterada');
            array_map('unlink', glob("{$directorio}/*") ?: []);
            rmdir($directorio);
        }
    }

    /** @return list<string> */
    private function tablasPresentes(): array
    {
        $filas = $this->bd->consultar(
            "SELECT TABLE_NAME AS tabla FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME <> 'migraciones_aplicadas' ORDER BY TABLE_NAME"
        );
        return array_map(static fn (array $f): string => (string) $f['tabla'], $filas);
    }
}
