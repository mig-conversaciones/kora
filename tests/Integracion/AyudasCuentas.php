<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Nucleo\BaseDatos;
use App\Nucleo\Cifrador;
use App\Nucleo\Contenedor;
use App\Nucleo\Entorno;
use App\Nucleo\Solicitud;
use App\Servicios\ServicioCuentas;
use App\Servicios\ServicioNotificacionesCuenta;
use App\Servicios\ServicioTotp;

/**
 * Auxiliares de las pruebas de extremo a extremo con cuentas reales: invitar por el servicio (como la CLI), activar
 * con la contraseña, enrolar la 2FA desde la página e ingresar. La clase que lo usa define self::$bd.
 */
trait AyudasCuentas
{
    private static string $contrasenaPrueba = 'mi finca tiene vacas lecheras felices';

    /** @param list<string> $unidades códigos de unidad */
    private function invitarCuenta(BaseDatos $bd, string $correo, string $nombre, string $rol, array $unidades = []): string
    {
        $contenedor = Contenedor::desdeConfiguracion(require RAIZ . '/config/app.php');
        $centroId = $rol === 'superadministrador' ? null : (int) $bd->valor("SELECT id FROM centros WHERE codigo = '9124'");
        $ids = array_map(static fn (string $c): int => (int) $bd->valor('SELECT id FROM unidades_productivas WHERE codigo = :c', ['c' => $c]), $unidades);
        $solicitud = (new Solicitud('CLI', '/cli', [], [], [], [], ''))->conAtributo('origen', 'cli');
        $contenedor->obtener(ServicioCuentas::class)->invitar($solicitud, $correo, $nombre, $rol, $centroId, $ids);
        return $this->tokenDelUltimoCorreo($bd, 'invitacion');
    }

    /**
     * Invita, activa y deja la sesión completa (con 2FA si el rol la exige). Devuelve la clave TOTP si se enroló.
     *
     * @param list<string> $unidades
     */
    private function cuentaActiva(BaseDatos $bd, NavegadorPrueba $navegador, string $correo, string $nombre, string $rol, array $unidades = []): ?string
    {
        $token = $this->invitarCuenta($bd, $correo, $nombre, $rol, $unidades);
        $navegador->get('/cuenta/activar?token=' . $token);
        $navegador->post('/cuenta/activar', ['token' => $token, 'contrasena' => self::$contrasenaPrueba, 'confirmacion' => self::$contrasenaPrueba]);
        if ($navegador->destino() !== '/2fa/enrolar') {
            self::assertSame('/', $navegador->destino(), (string) $navegador->ultima?->cuerpo);
            $navegador->get('/');
            return null;
        }
        $pagina = $navegador->get('/2fa/enrolar')->cuerpo;
        self::assertSame(1, preg_match('/<code class="clave">([A-Z2-7 ]+)<\/code>/', $pagina, $m));
        $clave = self::base32(str_replace(' ', '', $m[1]));
        $navegador->post('/2fa/enrolar', ['codigo' => ServicioTotp::codigoParaPaso($clave, intdiv(time(), 30))]);
        $navegador->get('/');
        return $clave;
    }

    private function tokenDelUltimoCorreo(BaseDatos $bd, string $plantilla): string
    {
        $cifrador = Cifrador::desdeEntorno(Entorno::texto('CLAVES_CIFRADO'));
        foreach ($bd->consultar("SELECT carga FROM trabajos WHERE tipo = 'correo.sistema' ORDER BY id DESC") as $fila) {
            $carga = json_decode((string) $fila['carga'], true);
            if (($carga['plantilla'] ?? '') === $plantilla) {
                $datos = json_decode($cifrador->descifrar($carga['datos_cifrados'], ServicioNotificacionesCuenta::CONTEXTO_CIFRADO), true);
                parse_str((string) parse_url($datos['enlace'], PHP_URL_QUERY), $consulta);
                return (string) $consulta['token'];
            }
        }
        self::fail("No se encoló el correo {$plantilla}.");
    }

    private static function base32(string $texto): string
    {
        $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($texto) as $c) {
            $bits .= str_pad(decbin((int) strpos($alfabeto, $c)), 5, '0', STR_PAD_LEFT);
        }
        $binario = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $binario .= chr((int) bindec($byte));
            }
        }
        return $binario;
    }
}
