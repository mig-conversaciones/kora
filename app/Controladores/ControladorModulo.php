<?php

declare(strict_types=1);

namespace App\Controladores;

use App\Nucleo\Entrada;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Nucleo\Url;

/**
 * Base de los módulos de gestión (I3): todas las pantallas comparten la página modulos/pagina (encabezado y
 * pestañas de la sección) y el mismo ciclo HTMX:
 *   1. GET del listado: página completa o fragmento.
 *   2. GET del formulario, en el mismo contenedor.
 *   3. POST: 422 con el formulario y lo escrito, o el listado actualizado con un mensaje y la URL del listado en el
 *      historial (HX-Push-Url).
 */
abstract class ControladorModulo extends ControladorBase
{
    /**
     * @param array<string,mixed> $datos
     * @param array<string,mixed> $meta sobretitulo, titulo, descripcion, pestanas, acciones, migas
     */
    protected function pantalla(Solicitud $solicitud, string $fragmento, array $datos, array $meta, int $estado = 200): Respuesta
    {
        return $this->renderizar($solicitud, 'modulos/pagina', $fragmento, $datos + $meta + ['valores' => [], 'errores' => []], $estado);
    }

    /**
     * 422 con el formulario, lo escrito y los errores por campo (RNF-005). Nunca devuelve secretos escritos.
     *
     * @param array<string,mixed> $datos
     * @param array<string,mixed> $meta
     */
    protected function validacion(Solicitud $solicitud, \App\Nucleo\ErrorValidacion $error, string $fragmento, array $datos, array $meta): Respuesta
    {
        $valores = $solicitud->cuerpo;
        unset($valores['_csrf'], $valores['secreto'], $valores['contrasena']);
        return $this->pantalla($solicitud, $fragmento, ['valores' => $valores, 'errores' => $error->errores()] + $datos, $meta, 422);
    }

    /** Listado actualizado tras guardar: mensaje y URL del listado en el historial del navegador. */
    protected function trasGuardar(Respuesta $respuesta, string $ruta, string $mensaje): Respuesta
    {
        return $this->conToast($respuesta, 'success', $mensaje)->conCabecera('HX-Push-Url', Url::a($ruta));
    }

    protected function enteroConsulta(Solicitud $solicitud, string $clave, ?int $porDefecto = null, int $minimo = 1, int $maximo = PHP_INT_MAX): ?int
    {
        return Entrada::entero($solicitud->consulta[$clave] ?? '', $minimo, $maximo) ?? $porDefecto;
    }

    protected static function anioActual(): int
    {
        return (int) (new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota')))->format('Y');
    }
}
