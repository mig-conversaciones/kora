<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Nucleo\BaseDatos;
use App\Nucleo\Migrador;
use App\Servicios\ServicioTotp;
use PHPUnit\Framework\TestCase;

/**
 * Cierre de sesiones desde la administración sin desactivar la cuenta (ASVS V7.4.5): el administrador cierra las de
 * una persona y el superadministrador las de todos menos la suya. Las dos acciones exigen reautenticación y motivo, y
 * quedan auditadas.
 */
final class CierreSesionesTest extends TestCase
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

    public function testAdministradorCierraLasSesionesDeUnaPersonaYSuperadministradorLasDeTodos(): void
    {
        $lider = new NavegadorPrueba(null, '190.85.20.10');
        $this->cuentaActiva(self::$bd, $lider, 'lider.sesiones@kora.local', 'Leo Sesiones', 'lider_unidad', ['GAN']);
        $otroEquipo = new NavegadorPrueba(null, '190.85.20.11');
        $otroEquipo->get('/ingresar');
        $otroEquipo->post('/ingresar', ['correo' => 'lider.sesiones@kora.local', 'contrasena' => self::$contrasenaPrueba]);
        self::assertSame(200, $otroEquipo->get('/')->estado);
        $id = (int) self::$bd->valor("SELECT id FROM usuarios WHERE correo = 'lider.sesiones@kora.local'");

        $admin = new NavegadorPrueba(null, '190.85.20.12');
        $totpAdmin = (string) $this->cuentaActiva(self::$bd, $admin, 'admin.sesiones@kora.local', 'Ada Admin', 'administrador');
        self::assertStringContainsString('Hay 2 sesiones abiertas', $admin->get("/sistema/usuarios/{$id}")->cuerpo);

        // Exige reautenticación reciente y motivo. El enrolamiento de la 2FA acaba de reautenticar: se envejece la marca.
        self::$bd->ejecutar("UPDATE sesiones SET reautenticada_en = UTC_TIMESTAMP(3) - INTERVAL 10 MINUTE WHERE usuario_id = (SELECT id FROM usuarios WHERE correo = 'admin.sesiones@kora.local')");
        $admin->postHtmx("/sistema/usuarios/{$id}/cerrar-sesiones", ['motivo' => 'Equipo perdido en campo']);
        self::assertStringStartsWith('/reautenticar', $admin->destino());
        $this->reautenticar($admin, "/sistema/usuarios/{$id}", $totpAdmin);
        self::assertSame(422, $admin->postHtmx("/sistema/usuarios/{$id}/cerrar-sesiones", ['motivo' => 'no'])->estado);
        $respuesta = $admin->postHtmx("/sistema/usuarios/{$id}/cerrar-sesiones", ['motivo' => 'Equipo perdido en campo']);
        self::assertSame(200, $respuesta->estado);
        self::assertStringContainsString('Hay 0 sesiones abiertas', $respuesta->cuerpo);

        // Las dos sesiones de la persona quedaron cerradas, pero la cuenta sigue activa: puede volver a ingresar.
        self::assertSame(303, $lider->get('/tableros/central')->estado);
        self::assertSame(303, $otroEquipo->get('/tableros/central')->estado);
        self::assertSame('activo', self::$bd->valor('SELECT estado FROM usuarios WHERE id = :id', ['id' => $id]));
        self::assertSame('Equipo perdido en campo', self::$bd->valor("SELECT motivo FROM eventos_auditoria WHERE accion = 'sesion.revocar_usuario'"));

        // Sobre la propia cuenta no (para eso está Mi cuenta), y cerrar las de todos es solo del superadministrador.
        $propio = (int) self::$bd->valor("SELECT id FROM usuarios WHERE correo = 'admin.sesiones@kora.local'");
        self::assertSame(403, $admin->postHtmx("/sistema/usuarios/{$propio}/cerrar-sesiones", ['motivo' => 'Prueba sobre mí'])->estado);
        self::assertSame(403, $admin->postHtmx('/sistema/usuarios/sesiones/cerrar-todas', ['motivo' => 'Incidente simulado'])->estado);

        $super = new NavegadorPrueba(null, '190.85.20.13');
        $totpSuper = (string) $this->cuentaActiva(self::$bd, $super, 'super.sesiones@kora.local', 'Sara Superadmin', 'superadministrador');
        self::$bd->ejecutar("UPDATE sesiones SET reautenticada_en = UTC_TIMESTAMP(3) - INTERVAL 10 MINUTE WHERE usuario_id = (SELECT id FROM usuarios WHERE correo = 'super.sesiones@kora.local')");
        self::assertSame(204, $super->postHtmx('/sistema/usuarios/sesiones/cerrar-todas', ['motivo' => 'Incidente de seguridad simulado'])->estado, 'Sin reautenticación, a /reautenticar.');
        $this->reautenticar($super, '/sistema/usuarios', $totpSuper);
        self::assertStringContainsString('Cerrar las sesiones de todos', $super->get('/sistema/usuarios')->cuerpo);
        self::assertSame(200, $super->postHtmx('/sistema/usuarios/sesiones/cerrar-todas', ['motivo' => 'Incidente de seguridad simulado'])->estado);
        self::assertSame(303, $admin->get('/sistema/usuarios')->estado, 'La sesión del administrador se cerró.');
        self::assertSame(200, $super->get('/sistema/usuarios')->estado, 'La del superadministrador sigue abierta.');
        self::assertSame(1, (int) self::$bd->valor('SELECT COUNT(*) FROM sesiones WHERE revocada_en IS NULL AND expira_absoluta_en > UTC_TIMESTAMP(3)'));
        self::assertSame(1, (int) self::$bd->valor("SELECT COUNT(*) FROM eventos_auditoria WHERE accion = 'sesion.revocar_todas'"));
    }

    /** Los roles con 2FA se reautentican con un código: el del paso siguiente, porque el actual ya se usó al enrolar. */
    private function reautenticar(NavegadorPrueba $navegador, string $volver, string $claveTotp): void
    {
        $navegador->get('/reautenticar');
        $navegador->post('/reautenticar', ['secreto' => ServicioTotp::codigoParaPaso($claveTotp, intdiv(time(), 30) + 1)]);
        $navegador->get($volver);
    }
}
