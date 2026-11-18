<?php

declare(strict_types=1);

namespace App\Controladores;

use App\Nucleo\BaseDatos;
use App\Nucleo\ErrorHttp;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Servicios\ServicioMantenimiento;
use App\Servicios\ServicioMonitorCola;
use Throwable;

/**
 * Comprobaciones de salud (ROB-010, ROB-011; 06-04). /health/live es público y mínimo. /health/ready exige el
 * token de monitoreo comparado en tiempo constante (en la I2 también la sesión de superadministrador, D-28); sin
 * autorización responde 404. Devuelve 503 si una dependencia crítica impide operar o si hay mantenimiento activo.
 * Nunca expone credenciales, rutas, versiones ni topología: solo el nombre de cada comprobación y su resultado.
 */
final class ControladorSalud
{
    /** @param array<string,int> $alertas umbrales de config/cola.php */
    public function __construct(
        private readonly BaseDatos $bd,
        private readonly ServicioMantenimiento $mantenimiento,
        private readonly ServicioMonitorCola $monitor,
        private readonly string $tokenMonitoreo,
        private readonly string $versionEsquemaEsperada,
        private readonly array $alertas,
    ) {
    }

    public function vivo(Solicitud $solicitud): Respuesta
    {
        return Respuesta::json(['estado' => 'vivo'])->conCabecera('Cache-Control', 'no-store');
    }

    public function listo(Solicitud $solicitud): Respuesta
    {
        $token = (string) $solicitud->cabecera('X-Token-Monitoreo');
        $usuario = $solicitud->atributo('usuario');
        $esSuperadministrador = $usuario instanceof \App\Politicas\UsuarioSesion && $usuario->esSuperadministrador();
        if (!$esSuperadministrador && ($token === '' || !hash_equals($this->tokenMonitoreo, $token))) {
            throw new ErrorHttp(404);
        }
        $latidoMaximo = (int) $this->alertas['latido_max_segundos'];
        $comprobaciones = [
            'base_datos' => $this->comprobar(fn (): bool => (int) $this->bd->valor('SELECT 1') === 1),
            'esquema' => $this->comprobar(fn (): bool => str_starts_with((string) $this->bd->valor('SELECT MAX(nombre) FROM migraciones_aplicadas'), $this->versionEsquemaEsperada)),
            'cola' => $this->comprobar(fn (): bool => $this->monitor->pendienteMasAntiguo() < (int) $this->alertas['pendiente_max_segundos']),
            'trabajador' => $this->comprobar(fn (): bool => $this->monitor->edadLatido(ServicioMonitorCola::LATIDO_TRABAJADOR) < $latidoMaximo),
            'programador' => $this->comprobar(fn (): bool => $this->monitor->edadLatido(ServicioMonitorCola::LATIDO_PROGRAMADOR) < $latidoMaximo),
            'disco' => $this->comprobar(fn (): bool => (float) disk_free_space(RAIZ . '/almacenamiento') > 500 * 1048576),
            'almacenamiento' => $this->comprobar(fn (): bool => is_writable(RAIZ . '/almacenamiento/cache') && is_writable(RAIZ . '/almacenamiento/registros')),
        ];
        $mantenimiento = $this->mantenimiento->activo();
        $listo = !$mantenimiento && !in_array(false, $comprobaciones, true);
        return Respuesta::json(
            ['estado' => $listo ? 'listo' : 'no_listo', 'mantenimiento' => $mantenimiento, 'comprobaciones' => $comprobaciones],
            $listo ? 200 : 503
        )->conCabecera('Cache-Control', 'no-store');
    }

    private function comprobar(callable $prueba): bool
    {
        try {
            return (bool) $prueba();
        } catch (Throwable) {
            return false;
        }
    }
}
