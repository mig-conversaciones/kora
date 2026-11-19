<?php

declare(strict_types=1);

namespace App\Controladores;

use App\Nucleo\Entrada;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Nucleo\Vista;
use App\Servicios\AutorizacionRecurso;
use App\Servicios\ServicioCatalogos;
use App\Servicios\ServicioProductos;
use App\Servicios\ServicioRecaudos;

/**
 * Recaudos (RF-029, RF-035): consulta para todos los roles según su alcance; registro solo para el Grupo de Recaudo.
 * La interfaz no muestra acciones de escritura sin permiso, y el servidor las rechaza aunque la solicitud se construya
 * a mano (PoliticaRecaudos).
 */
final class ControladorRecaudos extends ControladorModulo
{
    public function __construct(
        Vista $vista,
        private readonly ServicioRecaudos $recaudos,
        private readonly ServicioCatalogos $catalogos,
        private readonly ServicioProductos $productos,
    ) {
        parent::__construct($vista);
    }

    public function listado(Solicitud $solicitud): Respuesta
    {
        return $this->pantalla($solicitud, 'recaudos/listado', $this->datosListado($solicitud), $this->meta('Recaudos', 'Pagos recibidos por la Dirección General. Solo el Grupo de Recaudo los registra.'));
    }

    public function formulario(Solicitud $solicitud): Respuesta
    {
        return $this->pantalla($solicitud, 'recaudos/formulario', $this->datosFormulario($solicitud) + ['valores' => ['tipo_ingreso' => 'Producción']], $this->meta('Nuevo recaudo'));
    }

    public function guardar(Solicitud $solicitud): Respuesta
    {
        try {
            $this->recaudos->crear($solicitud, $solicitud->cuerpo);
        } catch (ErrorValidacion $error) {
            return $this->validacion($solicitud, $error, 'recaudos/formulario', $this->datosFormulario($solicitud), $this->meta('Nuevo recaudo'));
        }
        return $this->trasGuardar($this->listado($solicitud), '/recaudos', 'Recaudo registrado.');
    }

    /** @return array<string,mixed> */
    private function datosListado(Solicitud $solicitud): array
    {
        $texto = static fn (string $clave, array $permitidos) => in_array($solicitud->consulta[$clave] ?? '', $permitidos, true) ? (string) $solicitud->consulta[$clave] : null;
        $filtros = [
            'desde' => Entrada::fecha($solicitud->consulta['desde'] ?? ''),
            'hasta' => Entrada::fecha($solicitud->consulta['hasta'] ?? ''),
            'unidad' => $this->enteroConsulta($solicitud, 'unidad'),
            'producto' => $this->enteroConsulta($solicitud, 'producto'),
            'pasarela' => $texto('pasarela', ServicioRecaudos::PASARELAS),
            'clasificacion' => $texto('clasificacion', ['pendiente', 'clasificado', 'ambiguo']),
            'conciliacion' => $texto('conciliacion', ['sin_conciliar', 'conciliado', 'con_diferencia']),
            'orden' => $texto('orden', array_keys(ServicioRecaudos::ORDENES)) ?? '-fecha',
        ];
        $pagina = $this->enteroConsulta($solicitud, 'pagina', 1) ?? 1;
        $nombres = [];
        foreach ($this->catalogos->unidades(ServicioCatalogos::centro($solicitud)) as $u) {
            $nombres[(string) $u['id']] = (string) $u['nombre'];
        }
        return $this->recaudos->listar($solicitud, $filtros, $pagina) + [
            'filtros' => $filtros,
            'pagina' => $pagina,
            'unidades' => $this->catalogos->unidadesDelAlcance($solicitud),
            'productos' => $this->productos->opciones(ServicioCatalogos::centro($solicitud)),
            'nombresUnidades' => $nombres,
            'puedeRegistrar' => self::puedeRegistrar($solicitud),
        ];
    }

    /** @return array<string,mixed> */
    private function datosFormulario(Solicitud $solicitud): array
    {
        $centro = ServicioCatalogos::centro($solicitud);
        return ['unidades' => $this->catalogos->unidades($centro, true), 'productos' => $this->productos->opciones($centro)];
    }

    /** La acción solo se muestra a quien la política dejaría escribir (RF-029). */
    private static function puedeRegistrar(Solicitud $solicitud): bool
    {
        $usuario = AutorizacionRecurso::usuario($solicitud);
        return $usuario->tienePermiso('recaudos.gestionar') && $usuario->tieneRol('grupo_recaudo');
    }

    /** @return array<string,mixed> */
    private function meta(string $titulo, string $descripcion = ''): array
    {
        return ['titulo' => $titulo, 'sobretitulo' => 'Mi gestión', 'descripcion' => $descripcion, 'pestanas' => [], 'migas' => [['texto' => 'Recaudos'], ['texto' => $titulo]]];
    }
}
