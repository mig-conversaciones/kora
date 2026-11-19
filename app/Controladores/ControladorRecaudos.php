<?php

declare(strict_types=1);

namespace App\Controladores;

use App\Nucleo\Entrada;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\Funcionalidades;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Nucleo\Vista;
use App\Servicios\AutorizacionRecurso;
use App\Servicios\ServicioCatalogos;
use App\Servicios\ServicioGestionRecaudos;
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
        private readonly ServicioGestionRecaudos $gestion,
        private readonly Funcionalidades $funcionalidades,
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

    // ---------------------------------------------------------------- Clasificación (RF-031)

    public function clasificacion(Solicitud $solicitud): Respuesta
    {
        return $this->pantalla($solicitud, 'recaudos/clasificacion', $this->datosClasificacion($solicitud), $this->meta('Recaudos por clasificar', 'Asigna la unidad y el producto. Las sugerencias no se aplican hasta que las confirmas; un recaudo ambiguo no se prorratea.'));
    }

    public function clasificar(Solicitud $solicitud): Respuesta
    {
        $ids = array_values(array_filter(array_map('intval', (array) ($solicitud->cuerpo['ids'] ?? []))));
        $unidad = Entrada::entero($solicitud->cuerpo['unidad_id'] ?? '', 1);
        $producto = trim((string) ($solicitud->cuerpo['producto_id'] ?? '')) === '' ? null : Entrada::entero($solicitud->cuerpo['producto_id'], 1);
        try {
            $hechos = $this->gestion->clasificar($solicitud, $ids, $unidad, $producto);
        } catch (ErrorValidacion $error) {
            return $this->validacion($solicitud, $error, 'recaudos/clasificacion', $this->datosClasificacion($solicitud), $this->meta('Recaudos por clasificar'));
        }
        return $this->trasGuardar($this->clasificacion($solicitud), '/recaudos/clasificar', $hechos === 1 ? 'Recaudo clasificado.' : "{$hechos} recaudos clasificados.");
    }

    // ---------------------------------------------------------------- Conciliación (RF-032)

    public function conciliacion(Solicitud $solicitud, string $id): Respuesta
    {
        return $this->pantalla($solicitud, 'recaudos/conciliacion', $this->gestion->candidatas($solicitud, (int) $id), $this->meta('Conciliar recaudo', 'Vincula el recaudo con las ventas directas por prefactura, factura o referencia de la transacción.'));
    }

    public function conciliar(Solicitud $solicitud, string $id): Respuesta
    {
        $salidas = array_values(array_filter(array_map('intval', (array) ($solicitud->cuerpo['salidas'] ?? []))));
        try {
            $estado = $this->gestion->conciliar($solicitud, (int) $id, $salidas, (string) ($solicitud->cuerpo['criterio'] ?? ''));
        } catch (ErrorValidacion $error) {
            return $this->validacion($solicitud, $error, 'recaudos/conciliacion', $this->gestion->candidatas($solicitud, (int) $id), $this->meta('Conciliar recaudo'));
        }
        return $this->trasGuardar($this->conciliacion($solicitud, $id), '/recaudos/' . (int) $id . '/conciliar', $estado === 'conciliado' ? 'Recaudo conciliado.' : 'Conciliado con diferencia de valor: aparece en el informe de diferencias.');
    }

    public function diferencias(Solicitud $solicitud): Respuesta
    {
        $hoy = new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota'));
        $anio = $this->enteroConsulta($solicitud, 'anio', (int) $hoy->format('Y'), 2020, 2100) ?? (int) $hoy->format('Y');
        $mes = $this->enteroConsulta($solicitud, 'mes', (int) $hoy->format('n'), 1, 12) ?? (int) $hoy->format('n');
        return $this->pantalla($solicitud, 'recaudos/diferencias', ['filas' => $this->gestion->diferencias($solicitud, $anio, $mes), 'anio' => $anio, 'mes' => $mes], $this->meta('Diferencias de conciliación', 'Recaudos cuyo valor no coincide con el de las ventas conciliadas.'));
    }

    /** @return array<string,mixed> */
    private function datosClasificacion(Solicitud $solicitud): array
    {
        $centro = ServicioCatalogos::centro($solicitud);
        return ['filas' => $this->gestion->porClasificar($solicitud), 'unidades' => $this->catalogos->unidades($centro, true), 'productos' => $this->productos->opciones($centro)];
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
            'puedeRegistrar' => self::puedeRegistrar($solicitud) && $this->funcionalidades->activa('recaudos_alta_manual'),
            'puedeGestionar' => AutorizacionRecurso::usuario($solicitud)->tieneRol('grupo_recaudo'),
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
        return ['titulo' => $titulo, 'sobretitulo' => 'Mi gestión', 'descripcion' => $descripcion, 'pestanas' => [['texto' => 'Consulta', 'ruta' => '/recaudos'], ['texto' => 'Por clasificar', 'ruta' => '/recaudos/clasificar', 'permiso' => 'recaudos.clasificar'], ['texto' => 'Diferencias', 'ruta' => '/recaudos/diferencias', 'permiso' => 'recaudos.conciliar']], 'migas' => [['texto' => 'Recaudos'], ['texto' => $titulo]]];
    }
}
