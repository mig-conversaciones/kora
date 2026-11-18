<?php

declare(strict_types=1);

namespace App\Controladores;

use App\Nucleo\ErrorValidacion;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Nucleo\Vista;

/**
 * Base de todos los controladores (04-02, 4.2.5). Concentra las decisiones de presentación que deben ser idénticas
 * en todo el sistema: página completa o fragmento HTMX, respuestas de validación que conservan lo escrito, mensajes
 * por HX-Trigger y estados vacíos. Los controladores hijos solo orquestan.
 *
 * En la I2 el constructor incorpora Autorizador y ServicioAuditoria y se agrega autorizar() (403, o 404 fuera del
 * alcance, RAC-028), tal como lo define el SRS. En la I0 no hay sesiones ni datos de negocio.
 */
abstract class ControladorBase
{
    public function __construct(protected readonly Vista $vista)
    {
    }

    /**
     * Página completa o solo el fragmento según HX-Request (RI-003). Toda respuesta varía por HX-Request y no se
     * almacena en cachés compartidas.
     *
     * @param array<string,mixed> $datos
     */
    protected function renderizar(Solicitud $solicitud, string $pagina, string $fragmento, array $datos = [], int $estado = 200): Respuesta
    {
        $datos += ['solicitud' => $solicitud, 'usuario' => $solicitud->atributo('usuario')];
        $html = $solicitud->esHtmx()
            ? $this->vista->fragmento($fragmento, $datos)
            : $this->vista->pagina($pagina, $fragmento, $datos);
        return Respuesta::html($html, $estado)
            ->conCabecera('Vary', 'HX-Request')
            ->conCabecera('Cache-Control', 'no-store');
    }

    /**
     * 422 con el fragmento del formulario: conserva lo escrito (salvo secretos) y marca los campos inválidos para
     * que retroalimentacion-ui.js lleve el foco al resumen de errores (RNF-005, RUI-012).
     *
     * @param array<string,mixed> $datos
     * @param array<string,mixed>|null $valores lo escrito; por defecto, el cuerpo de la solicitud
     */
    protected function respuestaValidacion(Solicitud $solicitud, ErrorValidacion $error, string $fragmentoFormulario, array $datos = [], ?array $valores = null): Respuesta
    {
        $valores ??= $solicitud->cuerpo;
        unset($valores['contrasena'], $valores['contrasena_actual'], $valores['codigo'], $valores['_csrf']);
        return $this->renderizar($solicitud, $fragmentoFormulario, $fragmentoFormulario, $datos + [
            'valores' => $valores,
            'errores' => $error->errores(),
        ], 422);
    }

    /**
     * Mensaje para retroalimentacion-ui.js. El JSON se codifica en ASCII (ó) porque las cabeceras HTTP no
     * transportan UTF-8 de forma confiable (D-34). X-Toast-Type se conserva por compatibilidad (RI-004).
     */
    protected function conToast(Respuesta $respuesta, string $tipo, string $mensaje, ?string $titulo = null): Respuesta
    {
        $evento = ['mostrarToast' => array_filter(['tipo' => $tipo, 'mensaje' => $mensaje, 'titulo' => $titulo])];
        return $respuesta
            ->conCabecera('HX-Trigger', json_encode($evento, JSON_THROW_ON_ERROR))
            ->conCabecera('X-Toast-Type', $tipo);
    }

    /** Redirección a una ruta interna: 303 para navegación y HX-Redirect para HTMX. */
    protected function redirigir(Solicitud $solicitud, string $ruta): Respuesta
    {
        $url = \App\Nucleo\Url::a($ruta);
        return $solicitud->esHtmx() ? Respuesta::redireccionHtmx($url) : Respuesta::redireccion($url);
    }

    /** Destino «volver» de la consulta o el cuerpo, solo si es una ruta interna simple (sin redirección abierta). */
    protected function volver(Solicitud $solicitud, string $porDefecto = '/'): string
    {
        $candidato = (string) ($solicitud->cuerpo['volver'] ?? $solicitud->consulta['volver'] ?? '');
        $seguro = \App\Intermediarios\Autorizar::rutaInternaSegura($candidato);
        return $seguro === '' ? $porDefecto : $seguro;
    }

    /**
     * Estado vacío explicativo con acción sugerida; nunca una tabla vacía (RUI-014).
     *
     * @param array{texto:string,url:string}|null $accion
     */
    protected function estadoVacio(Solicitud $solicitud, string $titulo, string $descripcion, ?array $accion = null): Respuesta
    {
        return $this->renderizar($solicitud, 'parciales/estado_vacio', 'parciales/estado_vacio', [
            'titulo' => $titulo,
            'descripcion' => $descripcion,
            'accion' => $accion,
        ]);
    }
}
