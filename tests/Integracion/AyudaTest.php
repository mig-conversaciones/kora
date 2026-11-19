<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Nucleo\BaseDatos;
use App\Nucleo\Migrador;
use PHPUnit\Framework\TestCase;

/**
 * Ayuda en línea (RDOC-009): cada rol ve primero sus guías, la guía de operación solo la ven quienes consultan la salud
 * del sistema y cada guía se muestra con su índice y el botón de impresión.
 */
final class AyudaTest extends TestCase
{
    use AyudasCuentas;

    private const TABLAS = ['claves_idempotencia', 'codigos_respaldo_2fa', 'tokens_cuenta', 'sesiones', 'notificaciones', 'usuarios_unidades', 'usuarios_roles', 'trabajos_fallidos', 'trabajos', 'limites_tasa', 'eventos_auditoria'];

    private static BaseDatos $bd;

    public static function setUpBeforeClass(): void
    {
        self::$bd = new BaseDatos(require RAIZ . '/config/base_datos.php');
        $migrador = new Migrador(self::$bd, RAIZ . '/bd/migraciones');
        $migrador->aplicar();
        $migrador->sembrar(RAIZ . '/bd/semillas/001_identidad_y_estructura.sql');
    }

    protected function setUp(): void
    {
        foreach (self::TABLAS as $tabla) {
            self::$bd->ejecutar("DELETE FROM {$tabla}");
        }
        self::$bd->ejecutar('DELETE FROM usuarios');
        self::$bd->ejecutar("UPDATE cadena_auditoria SET ultima_secuencia = 0, ultimo_hash = REPEAT('0', 64) WHERE id = 1");
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::TABLAS as $tabla) {
            self::$bd->ejecutar("DELETE FROM {$tabla}");
        }
        self::$bd->ejecutar('DELETE FROM usuarios');
    }

    public function testCadaRolVeSusGuiasYLaDeOperacionQuedaReservada(): void
    {
        $lider = new NavegadorPrueba(null, '190.85.19.10');
        $this->cuentaActiva(self::$bd, $lider, 'lider.ayuda@kora.local', 'Lina Ayuda', 'lider_unidad', ['GAN']);
        $indice = $lider->get('/ayuda');
        self::assertSame(200, $indice->estado);
        $propias = substr($indice->cuerpo, 0, (int) strpos($indice->cuerpo, 'Otros roles'));
        self::assertStringContainsString('Líder de unidad productiva', $propias);
        self::assertStringContainsString('Primeros pasos en KORA', $propias);
        self::assertStringNotContainsString('Guía de operación', $indice->cuerpo);
        self::assertSame(404, $lider->get('/ayuda/operacion')->estado);
        self::assertSame(404, $lider->get('/ayuda/administracion')->estado);
        self::assertSame(404, $lider->get('/ayuda/no-existe')->estado);

        $guia = $lider->get('/ayuda/lider-unidad');
        self::assertSame(200, $guia->estado);
        self::assertStringContainsString('<h1 class="titulo-pagina">Líder de unidad productiva</h1>', $guia->cuerpo);
        self::assertStringContainsString('En esta guía', $guia->cuerpo);
        self::assertStringContainsString('data-imprimir', $guia->cuerpo);
        self::assertStringContainsString('href="' . \App\Nucleo\Url::a('/ayuda/primeros-pasos') . '"', $guia->cuerpo, 'Los enlaces entre guías van a la ayuda.');

        self::assertSame(303, (new NavegadorPrueba(null, '190.85.19.11'))->get('/ayuda')->estado, 'La ayuda exige sesión.');
    }
}
