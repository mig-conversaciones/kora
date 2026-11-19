<?php

declare(strict_types=1);

namespace App\Nucleo;

use PDOException;
use Throwable;

/**
 * Convierte cualquier excepción en una respuesta controlada (regla de oro del 4.3.3): nunca un 500 con HTML
 * genérico ni detalles internos (RS-010). Para HTMX devuelve el fragmento que responseHandling inyecta en
 * #contenedor-errores; para navegación, una página completa; para clientes JSON, un objeto JSON. Siempre con el
 * request_id como código de soporte (RUI-015).
 */
final class ManejadorErrores
{
    private const MENSAJES = [
        401 => ['Inicia sesión para continuar', 'Esta sección requiere una sesión activa. El inicio de sesión se habilita con la gestión de identidad.'],
        400 => ['Solicitud no válida', 'La solicitud no tiene el formato esperado. Recarga la página e intenta de nuevo.'],
        403 => ['Acceso no permitido', 'Tu perfil no tiene permiso para realizar esta acción.'],
        404 => ['No encontramos esta página', 'La dirección no existe o no está disponible para tu perfil.'],
        405 => ['Operación no permitida', 'Esta dirección no admite la operación solicitada.'],
        409 => ['El registro cambió', 'Otra persona modificó este registro. Recarga para ver la versión actual.'],
        410 => ['Enlace no disponible', 'El enlace venció o ya se usó.'],
        413 => ['Contenido demasiado grande', 'El archivo o los datos superan el tamaño permitido.'],
        422 => ['Revisa los datos', 'Algunos campos tienen errores. Corrígelos e intenta de nuevo.'],
        429 => ['Demasiados intentos', 'Espera un momento antes de volver a intentarlo.'],
        500 => ['Ocurrió un error inesperado', 'Ya quedó registrado. Intenta de nuevo en unos minutos; si continúa, informa el código de soporte.'],
        503 => ['El servicio no está disponible', 'Intenta de nuevo en unos minutos. Si el problema continúa, informa el código de soporte.'],
    ];

    public function __construct(private readonly Vista $vista, private readonly Registro $registro)
    {
    }

    public function convertir(Solicitud $solicitud, Throwable $error): Respuesta
    {
        $estado = match (true) {
            $error instanceof ErrorHttp => $error->estado,
            $error instanceof ErrorValidacion => 422,
            $error instanceof PDOException => 503,
            default => 500,
        };
        if (!isset(self::MENSAJES[$estado])) {
            $estado = $estado >= 500 ? 500 : 400;
        }
        $this->registrar($solicitud, $estado, $error);

        [$titulo, $mensaje] = self::MENSAJES[$estado];
        if ($error instanceof ErrorHttp && $error->getMessage() !== '') {
            $mensaje = $error->getMessage();
        }
        if ($error instanceof ErrorHttp && $error->titulo !== '') {
            $titulo = $error->titulo;
        }
        $idSolicitud = (string) $solicitud->atributo('request_id', '');

        if ($this->esperaJson($solicitud)) {
            $respuesta = Respuesta::json(['error' => ['estado' => $estado, 'titulo' => $titulo, 'mensaje' => $mensaje, 'codigo_soporte' => $idSolicitud]], $estado);
        } else {
            $datos = [
                'solicitud' => $solicitud,
                'titulo' => $titulo,
                'mensaje' => $mensaje,
                'estado' => $estado,
                'codigoSoporte' => $idSolicitud,
                'errores' => $error instanceof ErrorValidacion ? $error->errores() : [],
            ];
            $html = $solicitud->esHtmx()
                ? $this->vista->fragmento('errores/fragmento', $datos)
                : $this->paginaSegura($datos);
            $respuesta = Respuesta::html($html, $estado)->conCabecera('Vary', 'HX-Request');
            if ($solicitud->esHtmx() && $estado !== 503) {
                // El error controlado va a la región de errores y nunca reemplaza el formulario, que conserva lo
                // escrito (un 409 «Periodo cerrado» no debe borrar el gasto que se estaba editando).
                $respuesta->conCabecera('HX-Retarget', '#contenedor-errores')->conCabecera('HX-Reswap', 'innerHTML');
            }
        }
        if ($estado === 503) {
            $respuesta->conCabecera('Retry-After', '120');
        }
        return $respuesta->conCabecera('Cache-Control', 'no-store');
    }

    private function esperaJson(Solicitud $solicitud): bool
    {
        return str_starts_with($solicitud->ruta, '/api/')
            || str_starts_with($solicitud->ruta, '/health/')
            || (str_contains((string) $solicitud->cabecera('Accept'), 'application/json') && !$solicitud->esHtmx());
    }

    /**
     * La página de error usa la plantilla base; si la propia plantilla falla, se responde con HTML mínimo para
     * no encadenar errores.
     *
     * @param array<string,mixed> $datos
     */
    private function paginaSegura(array $datos): string
    {
        try {
            return $this->vista->pagina('errores/pagina', 'errores/fragmento', $datos);
        } catch (Throwable $error) {
            $this->registro->error('error_al_renderizar_error', ['clase' => $error::class]);
            $titulo = htmlspecialchars((string) $datos['titulo'], ENT_QUOTES, 'UTF-8');
            $mensaje = htmlspecialchars((string) $datos['mensaje'], ENT_QUOTES, 'UTF-8');
            $codigo = htmlspecialchars((string) $datos['codigoSoporte'], ENT_QUOTES, 'UTF-8');
            return "<!doctype html><html lang=\"es-CO\"><meta charset=\"utf-8\"><title>{$titulo}</title>"
                . "<main role=\"alert\"><h1>{$titulo}</h1><p>{$mensaje}</p><p>Código de soporte: {$codigo}</p></main></html>";
        }
    }

    private function registrar(Solicitud $solicitud, int $estado, Throwable $error): void
    {
        $contexto = ['estado' => $estado, 'metodo' => $solicitud->metodo, 'ruta' => $solicitud->ruta];
        if ($estado >= 500) {
            // Detalle técnico solo en el registro interno, nunca en la respuesta (RS-010).
            $this->registro->error('excepcion_no_controlada', $contexto + [
                'clase' => $error::class,
                'mensaje' => mb_substr($error->getMessage(), 0, 500),
                'archivo' => str_replace(defined('RAIZ') ? RAIZ : '', '', $error->getFile()) . ':' . $error->getLine(),
            ]);
            return;
        }
        if ($estado !== 404 && $estado !== 422) {
            $this->registro->aviso('error_http', $contexto);
        }
    }
}
