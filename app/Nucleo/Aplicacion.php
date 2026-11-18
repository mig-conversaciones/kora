<?php

declare(strict_types=1);

namespace App\Nucleo;

use App\Intermediarios\Autenticar;
use App\Intermediarios\Autorizar;
use App\Intermediarios\CabecerasSeguridad;
use App\Intermediarios\Idempotencia;
use App\Intermediarios\IdSolicitud;
use App\Intermediarios\IniciarSesion;
use App\Intermediarios\Intermediario;
use App\Intermediarios\LimitarTasa;
use App\Intermediarios\Mantenimiento;
use App\Intermediarios\VerificarCsrf;
use Throwable;

/**
 * Resuelve la ruta y ejecuta la cadena de intermediarios en orden fijo (04-02, 4.2.4). El identificador de
 * solicitud y las cabeceras de seguridad van primero para que incluso los errores y el aviso de mantenimiento
 * salgan correlacionados y con CSP. Por eso los errores del enrutador y del controlador se convierten dentro de
 * la cadena: la respuesta de error también atraviesa CabecerasSeguridad.
 */
final class Aplicacion
{
    /** Orden fijo de la cadena (04-02, 4.2.4), completa desde la I2. */
    private const CADENA = [
        IdSolicitud::class,
        CabecerasSeguridad::class,
        Mantenimiento::class,
        IniciarSesion::class,
        LimitarTasa::class,
        VerificarCsrf::class,
        Autenticar::class,
        Idempotencia::class,
        Autorizar::class,
    ];

    public function __construct(private readonly Contenedor $contenedor, private readonly Enrutador $enrutador)
    {
    }

    /** @param array<string,mixed> $config */
    public static function crear(array $config): self
    {
        $enrutador = new Enrutador();
        (require RAIZ . '/config/rutas.php')($enrutador);
        return new self(Contenedor::desdeConfiguracion($config), $enrutador);
    }

    public function contenedor(): Contenedor
    {
        return $this->contenedor;
    }

    public function manejar(Solicitud $solicitud): Respuesta
    {
        try {
            // Un 404 o 405 del enrutador no corta aquí: viaja hasta el final de la cadena para que la respuesta de
            // error también reciba el request_id, el nonce y las cabeceras de seguridad.
            $ruta = null;
            $errorRuta = null;
            try {
                $ruta = $this->enrutador->resolver($solicitud->metodo, $solicitud->ruta);
                $solicitud = $solicitud->conAtributo('ruta', $ruta);
            } catch (ErrorHttp $error) {
                $errorRuta = $error;
            }
            $controlador = function (Solicitud $s) use ($ruta, $errorRuta): Respuesta {
                try {
                    if ($ruta === null) {
                        throw $errorRuta;
                    }
                    [$clase, $metodo] = $ruta['accion'];
                    $respuesta = $this->contenedor->obtener($clase)->{$metodo}($s, ...array_values($ruta['parametros']));
                    if (!$respuesta instanceof Respuesta) {
                        throw new \LogicException("{$clase}::{$metodo} no devolvió una Respuesta.");
                    }
                    return $respuesta;
                } catch (Throwable $error) {
                    return $this->contenedor->obtener(ManejadorErrores::class)->convertir($s, $error);
                }
            };
            $cadena = array_reduce(
                array_reverse(self::CADENA),
                function (callable $siguiente, string $clase): callable {
                    return function (Solicitud $s) use ($siguiente, $clase): Respuesta {
                        /** @var Intermediario $intermediario */
                        $intermediario = $this->contenedor->obtener($clase);
                        try {
                            return $intermediario->procesar($s, $siguiente);
                        } catch (Throwable $error) {
                            // Un intermediario que corta con una excepción (401, 403, 404 por funcionalidad) se
                            // convierte aquí, para que los eslabones externos agreguen CSP y X-Request-ID.
                            return $this->contenedor->obtener(ManejadorErrores::class)->convertir($s, $error);
                        }
                    };
                },
                $controlador
            );
            return $cadena($solicitud);
        } catch (Throwable $error) {
            // Falla dentro de un intermediario: se convierte sin cabeceras de la cadena (último recurso).
            return $this->contenedor->obtener(ManejadorErrores::class)->convertir($solicitud, $error);
        }
    }
}
