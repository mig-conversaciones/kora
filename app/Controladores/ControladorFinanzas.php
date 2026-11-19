<?php

declare(strict_types=1);

namespace App\Controladores;

use App\Nucleo\Entrada;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Nucleo\Vista;
use App\Servicios\AutorizacionRecurso;
use App\Servicios\ServicioAdjuntos;
use App\Servicios\ServicioAsignaciones;
use App\Servicios\ServicioCatalogos;
use App\Servicios\ServicioGastos;
use App\Servicios\ServicioPeriodos;
use App\Servicios\ServicioProcesos;
use App\Servicios\ServicioProductos;

/**
 * Presupuesto y gastos (RF-024 a RF-028) y procesos de autoconsumo (RF-036 a RF-038). El alcance de datos limita
 * cada listado a las unidades del usuario (Obs. 5); las escrituras pasan por PoliticaAlcanceUnidad y por el cierre
 * de periodo.
 */
final class ControladorFinanzas extends ControladorModulo
{
    private const PESTANAS = [
        ['texto' => 'Asignaciones', 'ruta' => '/finanzas/asignaciones', 'permiso' => 'asignaciones.consultar'],
        ['texto' => 'Gastos ejecutados', 'ruta' => '/finanzas/gastos', 'permiso' => 'gastos.consultar'],
        ['texto' => 'Ejecución por línea', 'ruta' => '/finanzas/ejecucion', 'permiso' => 'asignaciones.consultar'],
        ['texto' => 'Procesos de autoconsumo', 'ruta' => '/finanzas/procesos', 'permiso' => 'procesos.consultar'],
        ['texto' => 'Cierres de periodo', 'ruta' => '/finanzas/periodos', 'permiso' => 'periodos.cerrar'],
    ];

    public function __construct(
        Vista $vista,
        private readonly ServicioAsignaciones $asignaciones,
        private readonly ServicioGastos $gastos,
        private readonly ServicioProcesos $procesos,
        private readonly ServicioPeriodos $periodos,
        private readonly ServicioCatalogos $catalogos,
        private readonly ServicioProductos $productos,
        private readonly ServicioAdjuntos $adjuntos,
    ) {
        parent::__construct($vista);
    }

    // ---------------------------------------------------------------- Asignaciones (RF-024)

    public function asignaciones(Solicitud $solicitud): Respuesta
    {
        return $this->pantalla($solicitud, 'finanzas/asignaciones', $this->datosAsignaciones($solicitud), $this->meta('Asignaciones presupuestales', 'Valor total y desglose por línea de cada unidad y vigencia.'));
    }

    public function formularioAsignacion(Solicitud $solicitud, string $id = ''): Respuesta
    {
        $asignacion = $id === '' ? null : $this->asignaciones->obtener($solicitud, (int) $id);
        $valores = $asignacion ?? ['vigencia' => $this->vigencia($solicitud)];
        return $this->pantalla($solicitud, 'finanzas/asignacion_formulario', ['asignacion' => $asignacion, 'valores' => $valores, 'unidades' => $this->catalogos->unidadesDelAlcance($solicitud)], $this->meta($asignacion === null ? 'Nueva asignación' : 'Editar asignación'));
    }

    public function guardarAsignacion(Solicitud $solicitud, string $id = ''): Respuesta
    {
        $asignacion = $id === '' ? null : $this->asignaciones->obtener($solicitud, (int) $id);
        try {
            $this->asignaciones->guardar($solicitud, $id === '' ? null : (int) $id, $solicitud->cuerpo);
        } catch (ErrorValidacion $error) {
            return $this->validacion($solicitud, $error, 'finanzas/asignacion_formulario', ['asignacion' => $asignacion, 'unidades' => $this->catalogos->unidadesDelAlcance($solicitud)], $this->meta('Asignación'));
        }
        $vigencia = Entrada::entero($solicitud->cuerpo['vigencia'] ?? '', 2020, 2100) ?? self::anioActual();
        $solicitudLista = $solicitud->conAtributo('vigencia_lista', $vigencia);
        return $this->trasGuardar($this->pantalla($solicitudLista, 'finanzas/asignaciones', $this->datosAsignaciones($solicitudLista), $this->meta('Asignaciones presupuestales')), '/finanzas/asignaciones?vigencia=' . $vigencia, $asignacion === null ? 'Asignación creada.' : 'Asignación actualizada.');
    }

    public function archivarAsignacion(Solicitud $solicitud, string $id): Respuesta
    {
        try {
            $this->asignaciones->archivar($solicitud, (int) $id, Entrada::entero($solicitud->cuerpo['version'] ?? '', 1) ?? 0, (string) ($solicitud->cuerpo['motivo'] ?? ''));
        } catch (ErrorValidacion $error) {
            return $this->validacion($solicitud, $error, 'finanzas/asignacion_formulario', ['asignacion' => $this->asignaciones->obtener($solicitud, (int) $id), 'unidades' => $this->catalogos->unidadesDelAlcance($solicitud)], $this->meta('Asignación'));
        }
        return $this->trasGuardar($this->pantalla($solicitud, 'finanzas/asignaciones', $this->datosAsignaciones($solicitud), $this->meta('Asignaciones presupuestales')), '/finanzas/asignaciones', 'Asignación archivada.');
    }

    // ---------------------------------------------------------------- Gastos (RF-025, RF-027)

    public function gastos(Solicitud $solicitud): Respuesta
    {
        return $this->pantalla($solicitud, 'finanzas/gastos', $this->datosGastos($solicitud), $this->meta('Gastos ejecutados', 'Gastos por unidad, fecha y categoría, con el componente académico (vacas de práctica) y su soporte.'));
    }

    public function formularioGasto(Solicitud $solicitud, string $id = ''): Respuesta
    {
        $gasto = $id === '' ? null : $this->gastos->obtener($solicitud, (int) $id);
        $hoy = (new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota')))->format('Y-m-d');
        return $this->pantalla($solicitud, 'finanzas/gasto_formulario', $this->datosFormularioGasto($solicitud, $gasto) + ['valores' => $gasto ?? ['fecha' => $hoy]], $this->meta($gasto === null ? 'Nuevo gasto' : 'Editar gasto'));
    }

    public function guardarGasto(Solicitud $solicitud, string $id = ''): Respuesta
    {
        $gasto = $id === '' ? null : $this->gastos->obtener($solicitud, (int) $id);
        try {
            $this->gastos->guardar($solicitud, $id === '' ? null : (int) $id, $solicitud->cuerpo, $solicitud->archivos['soporte'] ?? null);
        } catch (ErrorValidacion $error) {
            return $this->validacion($solicitud, $error, 'finanzas/gasto_formulario', $this->datosFormularioGasto($solicitud, $gasto), $this->meta('Gasto'));
        }
        return $this->trasGuardar($this->pantalla($solicitud, 'finanzas/gastos', $this->datosGastos($solicitud), $this->meta('Gastos ejecutados')), '/finanzas/gastos', $gasto === null ? 'Gasto registrado.' : 'Gasto actualizado.');
    }

    public function archivarGasto(Solicitud $solicitud, string $id): Respuesta
    {
        try {
            $this->gastos->archivar($solicitud, (int) $id, Entrada::entero($solicitud->cuerpo['version'] ?? '', 1) ?? 0, (string) ($solicitud->cuerpo['motivo'] ?? ''));
        } catch (ErrorValidacion $error) {
            $gasto = $this->gastos->obtener($solicitud, (int) $id);
            return $this->validacion($solicitud, $error, 'finanzas/gasto_formulario', $this->datosFormularioGasto($solicitud, $gasto) + ['valores' => $gasto], $this->meta('Gasto'));
        }
        return $this->trasGuardar($this->pantalla($solicitud, 'finanzas/gastos', $this->datosGastos($solicitud), $this->meta('Gastos ejecutados')), '/finanzas/gastos', 'Gasto archivado.');
    }

    /** Descarga privada del soporte (RF-027): sin acceso al gasto, 404. */
    public function soporte(Solicitud $solicitud, string $id, string $adjunto): Respuesta
    {
        $archivo = $this->gastos->soporte($solicitud, (int) $id, (int) $adjunto);
        $nombre = preg_replace('/[^A-Za-z0-9._ -]/', '_', $archivo['nombre']) ?: 'soporte';
        return (new Respuesta((string) file_get_contents($archivo['ruta']), 200))
            ->conCabecera('Content-Type', $archivo['tipo'])
            ->conCabecera('Content-Disposition', 'attachment; filename="' . $nombre . '"; filename*=UTF-8\'\'' . rawurlencode($archivo['nombre']))
            ->conCabecera('X-Content-Type-Options', 'nosniff')
            ->conCabecera('Cache-Control', 'private, no-store');
    }

    // ---------------------------------------------------------------- Ejecución por línea (RF-026)

    public function ejecucion(Solicitud $solicitud): Respuesta
    {
        $vigencia = $this->vigencia($solicitud);
        $unidad = $this->enteroConsulta($solicitud, 'unidad');
        return $this->pantalla($solicitud, 'finanzas/ejecucion', [
            'vigencia' => $vigencia,
            'unidad' => $unidad,
            'unidades' => $this->catalogos->unidadesDelAlcance($solicitud),
            'filas' => $this->gastos->ejecucion($solicitud, $vigencia, $unidad),
        ], $this->meta('Ejecución por línea presupuestal', 'Asignación frente al gasto ejecutado acumulado del año. No corresponde a ejecución CDP/RP.'));
    }

    // ---------------------------------------------------------------- Procesos de autoconsumo (RF-036, RF-037)

    public function procesos(Solicitud $solicitud): Respuesta
    {
        return $this->pantalla($solicitud, 'finanzas/procesos', $this->datosProcesos($solicitud), $this->meta('Procesos de autoconsumo', 'Cupos anuales por unidad de origen, destino y producto, con su avance físico y monetario.'));
    }

    public function formularioProceso(Solicitud $solicitud, string $id = ''): Respuesta
    {
        $proceso = $id === '' ? null : $this->procesos->obtener($solicitud, (int) $id);
        $vigencia = $this->vigencia($solicitud);
        $valores = $proceso ?? ['vigencia' => $vigencia, 'codigo' => $this->procesos->siguienteCodigo(ServicioCatalogos::centro($solicitud), $vigencia)];
        return $this->pantalla($solicitud, 'finanzas/proceso_formulario', $this->datosFormularioProceso($solicitud, $proceso) + ['valores' => $valores], $this->meta($proceso === null ? 'Nuevo proceso' : 'Editar proceso ' . $proceso['codigo']));
    }

    public function guardarProceso(Solicitud $solicitud, string $id = ''): Respuesta
    {
        $proceso = $id === '' ? null : $this->procesos->obtener($solicitud, (int) $id);
        try {
            $this->procesos->guardar($solicitud, $id === '' ? null : (int) $id, $solicitud->cuerpo);
        } catch (ErrorValidacion $error) {
            return $this->validacion($solicitud, $error, 'finanzas/proceso_formulario', $this->datosFormularioProceso($solicitud, $proceso), $this->meta('Proceso'));
        }
        $vigencia = Entrada::entero($solicitud->cuerpo['vigencia'] ?? '', 2020, 2100) ?? self::anioActual();
        $solicitudLista = $solicitud->conAtributo('vigencia_lista', $vigencia);
        return $this->trasGuardar($this->pantalla($solicitudLista, 'finanzas/procesos', $this->datosProcesos($solicitudLista), $this->meta('Procesos de autoconsumo')), '/finanzas/procesos?vigencia=' . $vigencia, $proceso === null ? 'Proceso creado.' : 'Proceso actualizado.');
    }

    public function archivarProceso(Solicitud $solicitud, string $id): Respuesta
    {
        try {
            $this->procesos->archivar($solicitud, (int) $id, Entrada::entero($solicitud->cuerpo['version'] ?? '', 1) ?? 0, (string) ($solicitud->cuerpo['motivo'] ?? ''));
        } catch (ErrorValidacion $error) {
            $proceso = $this->procesos->obtener($solicitud, (int) $id);
            return $this->validacion($solicitud, $error, 'finanzas/proceso_formulario', $this->datosFormularioProceso($solicitud, $proceso) + ['valores' => $proceso], $this->meta('Proceso'));
        }
        return $this->trasGuardar($this->pantalla($solicitud, 'finanzas/procesos', $this->datosProcesos($solicitud), $this->meta('Procesos de autoconsumo')), '/finanzas/procesos', 'Proceso archivado.');
    }

    // ---------------------------------------------------------------- Cierres de periodo (RF-028)

    public function periodos(Solicitud $solicitud): Respuesta
    {
        return $this->pantalla($solicitud, 'finanzas/periodos', $this->datosPeriodos($solicitud), $this->meta('Cierres de periodo', 'Un mes cerrado no admite crear, editar, archivar ni importar gastos, salidas, recepciones ni recaudos.'));
    }

    public function cerrarPeriodo(Solicitud $solicitud, string $anio, string $mes): Respuesta
    {
        $this->periodos->cerrar($solicitud, ServicioCatalogos::centro($solicitud), (int) $anio, (int) $mes);
        $lista = $solicitud->conAtributo('vigencia_lista', (int) $anio);
        return $this->trasGuardar($this->pantalla($lista, 'finanzas/periodos', $this->datosPeriodos($lista), $this->meta('Cierres de periodo')), '/finanzas/periodos?vigencia=' . (int) $anio, 'Periodo cerrado.');
    }

    public function reabrirPeriodo(Solicitud $solicitud, string $anio, string $mes): Respuesta
    {
        $lista = $solicitud->conAtributo('vigencia_lista', (int) $anio);
        try {
            $this->periodos->reabrir($solicitud, ServicioCatalogos::centro($solicitud), (int) $anio, (int) $mes, (string) ($solicitud->cuerpo['motivo'] ?? ''));
        } catch (ErrorValidacion $error) {
            return $this->validacion($lista, $error, 'finanzas/periodos', ['mesConError' => (int) $mes] + $this->datosPeriodos($lista), $this->meta('Cierres de periodo'));
        }
        return $this->trasGuardar($this->pantalla($lista, 'finanzas/periodos', $this->datosPeriodos($lista), $this->meta('Cierres de periodo')), '/finanzas/periodos?vigencia=' . (int) $anio, 'Periodo reabierto; la reapertura quedó auditada.');
    }

    // ---------------------------------------------------------------- Datos de las vistas

    private function vigencia(Solicitud $solicitud): int
    {
        $lista = $solicitud->atributo('vigencia_lista');
        return is_int($lista) ? $lista : ($this->enteroConsulta($solicitud, 'vigencia', null, 2020, 2100) ?? self::anioActual());
    }

    /** @return array<string,string> id => nombre de las unidades del centro */
    private function nombresUnidades(Solicitud $solicitud): array
    {
        $nombres = [];
        foreach ($this->catalogos->unidades(AutorizacionRecurso::alcance($solicitud)->centroId) as $u) {
            $nombres[(string) $u['id']] = (string) $u['nombre'];
        }
        return $nombres;
    }

    /** @return array<string,mixed> */
    private function datosAsignaciones(Solicitud $solicitud): array
    {
        $vigencia = $this->vigencia($solicitud);
        return $this->asignaciones->listar($solicitud, $vigencia, 1) + [
            'vigencia' => $vigencia,
            'nombresUnidades' => $this->nombresUnidades($solicitud),
            'puedeEditar' => AutorizacionRecurso::usuario($solicitud)->tienePermiso('asignaciones.gestionar'),
        ];
    }

    /** @return array<string,mixed> */
    private function datosGastos(Solicitud $solicitud): array
    {
        $filtros = [
            'unidad' => $this->enteroConsulta($solicitud, 'unidad'),
            'vigencia' => $this->vigencia($solicitud),
            'mes' => $this->enteroConsulta($solicitud, 'mes', null, 1, 12),
            'categoria' => $this->enteroConsulta($solicitud, 'categoria'),
        ];
        $pagina = $this->enteroConsulta($solicitud, 'pagina', 1) ?? 1;
        $categorias = [];
        foreach ($this->catalogos->categorias(ServicioCatalogos::centro($solicitud)) as $c) {
            $categorias[(string) $c['id']] = (string) $c['nombre'];
        }
        return $this->gastos->listar($solicitud, $filtros, $pagina) + [
            'filtros' => $filtros,
            'pagina' => $pagina,
            'categorias' => $categorias,
            'unidades' => $this->catalogos->unidadesDelAlcance($solicitud),
            'nombresUnidades' => $this->nombresUnidades($solicitud),
            'puedeEditar' => AutorizacionRecurso::usuario($solicitud)->tienePermiso('gastos.gestionar'),
        ];
    }

    /**
     * @param array<string,mixed>|null $gasto
     * @return array<string,mixed>
     */
    private function datosFormularioGasto(Solicitud $solicitud, ?array $gasto): array
    {
        return [
            'gasto' => $gasto,
            'unidades' => $this->catalogos->unidadesDelAlcance($solicitud),
            'categorias' => $this->catalogos->categorias(ServicioCatalogos::centro($solicitud), true),
            'soportes' => $gasto === null ? [] : $this->adjuntos->deEntidad('gastos_ejecutados', (int) $gasto['id']),
            'periodoCerrado' => $gasto !== null && $this->periodos->estaCerrado((int) $gasto['centro_id'], (string) $gasto['fecha']),
        ];
    }

    /** @return array<string,mixed> */
    private function datosProcesos(Solicitud $solicitud): array
    {
        $vigencia = $this->vigencia($solicitud);
        return [
            'filas' => $this->procesos->listar($solicitud, $vigencia),
            'vigencia' => $vigencia,
            'nombresUnidades' => $this->nombresUnidades($solicitud),
            'puedeEditar' => AutorizacionRecurso::usuario($solicitud)->tienePermiso('procesos.gestionar'),
        ];
    }

    /**
     * @param array<string,mixed>|null $proceso
     * @return array<string,mixed>
     */
    private function datosFormularioProceso(Solicitud $solicitud, ?array $proceso): array
    {
        $centro = ServicioCatalogos::centro($solicitud);
        return [
            'proceso' => $proceso,
            'unidades' => $this->catalogos->unidadesDelAlcance($solicitud),
            'destinos' => $this->catalogos->destinos($centro, true),
            'productos' => $this->productos->opciones($centro),
        ];
    }

    /** @return array<string,mixed> */
    private function datosPeriodos(Solicitud $solicitud): array
    {
        $vigencia = $this->vigencia($solicitud);
        return ['vigencia' => $vigencia, 'meses' => $this->periodos->delAnio(ServicioCatalogos::centro($solicitud), $vigencia), 'mesConError' => null];
    }

    /** @return array<string,mixed> */
    private function meta(string $titulo, string $descripcion = ''): array
    {
        return [
            'titulo' => $titulo,
            'sobretitulo' => 'Presupuesto y gastos',
            'descripcion' => $descripcion,
            'pestanas' => self::PESTANAS,
            'migas' => [['texto' => 'Presupuesto y gastos'], ['texto' => $titulo]],
        ];
    }
}
