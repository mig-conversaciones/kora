<?php

declare(strict_types=1);

namespace App\Intermediarios;

use App\Nucleo\ErrorHttp;
use App\Nucleo\Funcionalidades;
use App\Nucleo\Respuesta;
use App\Nucleo\Sesion;
use App\Nucleo\Solicitud;
use App\Nucleo\Url;
use App\Politicas\Autorizador;
use App\Politicas\UsuarioSesion;
use App\Servicios\ServicioAlcance;
use App\Servicios\ServicioAuditoria;

/**
 * Autorización de la ruta (RAC-023, RAC-024, RAC-026, RAC-028, ROB-007; base 04-07, 4.7.4). Último eslabón:
 *   1. Funcionalidad inactiva → 404.
 *   2. Ruta pública → continúa.
 *   3. Sin usuario autenticado → al inicio de sesión (303, o HX-Redirect para HTMX); si falta el segundo factor,
 *      a completarlo.
 *   4. Construye el AlcanceDatos en el servidor y verifica el permiso RBAC con el Autorizador → 403 auditado.
 *   5. Ruta con «reautenticacion» → exige 2FA o contraseña en los últimos 5 min.
 * Las condiciones sobre un registro concreto (ABAC) se verifican en el controlador con la política del recurso.
 */
final class Autorizar implements Intermediario
{
    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly ServicioAlcance $alcances,
        private readonly Funcionalidades $funcionalidades,
        private readonly ServicioAuditoria $auditoria,
        private readonly Sesion $sesion,
        private readonly int $reautenticacionSegundos,
    ) {
    }

    public function procesar(Solicitud $solicitud, callable $siguiente): Respuesta
    {
        /** @var array{permiso?:string,opciones?:array<string,mixed>}|null $ruta */
        $ruta = $solicitud->atributo('ruta');
        if ($ruta === null) {
            // Ruta inexistente: el controlador final responde el 404 (o 405) del enrutador.
            return $siguiente($solicitud);
        }
        $opciones = $ruta['opciones'] ?? [];
        if (isset($opciones['funcionalidad']) && !$this->funcionalidades->activa((string) $opciones['funcionalidad'])) {
            throw new ErrorHttp(404);
        }
        if (!empty($opciones['publica'])) {
            return $siguiente($solicitud);
        }
        $usuario = $solicitud->atributo('usuario');
        if (!$usuario instanceof UsuarioSesion) {
            $pendiente = $solicitud->atributo('usuario_pendiente');
            $destino = $pendiente instanceof UsuarioSesion ? ($pendiente->tiene2fa ? '/2fa' : '/2fa/enrolar') : '/ingresar';
            return $this->redirigir($solicitud, $destino, $destino === '/ingresar');
        }
        $permiso = (string) ($ruta['permiso'] ?? '');
        $alcance = $this->alcances->paraUsuario($usuario);
        $solicitud = $solicitud->conAtributo('alcance', $alcance);
        $soloSesion = $permiso === '' && !empty($opciones['autenticada']);
        if (!$soloSesion && !$this->autorizador->decidir($usuario, $permiso, null, $alcance)->permitida()) {
            $this->auditoria->registrar($solicitud, $permiso, 'denegado', null, ['ruta' => $solicitud->ruta]);
            throw new ErrorHttp(403);
        }
        if (!empty($opciones['reautenticacion'])) {
            $segundos = $this->sesion->segundosDesdeReautenticacion();
            if ($segundos === null || $segundos > $this->reautenticacionSegundos) {
                return $this->redirigir($solicitud, '/reautenticar', true);
            }
        }
        return $siguiente($solicitud);
    }

    /** Redirección que conserva el destino (solo rutas internas de GET) para volver después. */
    private function redirigir(Solicitud $solicitud, string $destino, bool $conVolver): Respuesta
    {
        $volver = '';
        if ($solicitud->metodo === 'GET' && !$solicitud->esHtmx()) {
            $volver = $solicitud->ruta;
        } elseif ($solicitud->esHtmx()) {
            // Para HTMX se vuelve a la página desde la que se hizo la solicitud (misma aplicación).
            $actual = (string) parse_url((string) $solicitud->cabecera('HX-Current-URL'), PHP_URL_PATH);
            $volver = \App\Nucleo\Solicitud::normalizarRuta($actual, Url::base());
        }
        $volver = self::rutaInternaSegura($volver);
        $url = Url::a($destino) . ($conVolver && $volver !== '' && $volver !== '/' ? '?volver=' . rawurlencode($volver) : '');
        return $solicitud->esHtmx()
            ? Respuesta::redireccionHtmx($url)
            : Respuesta::redireccion($url);
    }

    /** Solo rutas internas simples: evita redirecciones abiertas (?volver=//otro.sitio o esquemas). */
    public static function rutaInternaSegura(string $ruta): string
    {
        return preg_match('#^/(?!/)[A-Za-z0-9/_-]*$#', $ruta) === 1 ? $ruta : '';
    }
}
