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
use App\Servicios\ServicioRecepciones;
use App\Servicios\ServicioSalidas;
use App\Servicios\ServicioTransformaciones;

/**
 * Producción y lotes (I4): recepciones (RF-039 a RF-041), salidas de venta directa y autoconsumo (RF-043 a RF-046)
 * y transformación láctea (RF-047 a RF-051). El alcance de datos limita cada listado a las unidades del usuario; las
 * escrituras pasan por PoliticaAlcanceUnidad y por el cierre de periodo.
 */
final class ControladorProduccion extends ControladorModulo
{
    private const PESTANAS = [
        ['texto' => 'Recepciones y lotes', 'ruta' => '/produccion/recepciones', 'permiso' => 'recepciones.consultar'],
        ['texto' => 'Salidas', 'ruta' => '/produccion/salidas', 'permiso' => 'salidas.consultar'],
        ['texto' => 'Transformación láctea', 'ruta' => '/produccion/transformaciones', 'permiso' => 'transformaciones.consultar'],
    ];

    public function __construct(
        Vista $vista,
        private readonly ServicioRecepciones $recepciones,
        private readonly ServicioSalidas $salidas,
        private readonly ServicioTransformaciones $transformaciones,
        private readonly ServicioCatalogos $catalogos,
        private readonly ServicioProductos $productos,
    ) {
        parent::__construct($vista);
    }

    // ---------------------------------------------------------------- Recepciones (RF-039 a RF-041)

    public function recepciones(Solicitud $solicitud): Respuesta
    {
        return $this->pantalla($solicitud, 'produccion/recepciones', $this->datosRecepciones($solicitud), $this->meta('Recepciones y lotes', 'Lo recibido por cada unidad, con su saldo: un lote se cierra con la salida que lo agota.'));
    }

    public function recepcion(Solicitud $solicitud, string $id): Respuesta
    {
        $lote = $this->recepciones->obtener($solicitud, (int) $id);
        return $this->pantalla($solicitud, 'produccion/recepcion_detalle', [
            'lote' => $lote,
            'salidas' => $this->recepciones->salidasDelLote((int) $id),
            'nombresUnidades' => $this->nombresUnidades($solicitud),
            'puedeEditar' => AutorizacionRecurso::usuario($solicitud)->tienePermiso('recepciones.gestionar'),
            'puedeSalida' => AutorizacionRecurso::usuario($solicitud)->tienePermiso('salidas.gestionar'),
        ], $this->meta('Lote ' . $lote['numero_interno']));
    }

    public function formularioRecepcion(Solicitud $solicitud, string $id = ''): Respuesta
    {
        $lote = $id === '' ? null : $this->recepciones->obtener($solicitud, (int) $id);
        $valores = $lote ?? ['fecha_recepcion' => self::hoy(), 'responsable_id' => (string) AutorizacionRecurso::usuario($solicitud)->id];
        $unidades = $this->catalogos->unidadesDelAlcance($solicitud);
        if ($lote === null && count($unidades) === 1) {
            $valores['unidad_id'] = (string) $unidades[0]['id'];
            $valores['numero_interno'] = $this->recepciones->siguienteNumero(ServicioCatalogos::centro($solicitud), (int) $unidades[0]['id'], self::hoy());
        }
        return $this->pantalla($solicitud, 'produccion/recepcion_formulario', $this->datosFormularioRecepcion($solicitud, $lote) + ['valores' => $valores], $this->meta($lote === null ? 'Nueva recepción' : 'Editar lote ' . $lote['numero_interno']));
    }

    /** Fragmento del número sugerido al cambiar la unidad o la fecha (hx-get desde el formulario). */
    public function numeroSugerido(Solicitud $solicitud): Respuesta
    {
        $unidad = $this->enteroConsulta($solicitud, 'unidad_id');
        $fecha = Entrada::fecha($solicitud->consulta['fecha_recepcion'] ?? '') ?? self::hoy();
        $numero = '';
        if ($unidad !== null && AutorizacionRecurso::alcance($solicitud)->permiteUnidad($unidad)) {
            $numero = $this->recepciones->siguienteNumero(ServicioCatalogos::centro($solicitud), $unidad, $fecha);
        }
        return $this->renderizar($solicitud, 'produccion/numero_sugerido', 'produccion/numero_sugerido', ['numero' => $numero, 'errores' => []]);
    }

    public function guardarRecepcion(Solicitud $solicitud, string $id = ''): Respuesta
    {
        $lote = $id === '' ? null : $this->recepciones->obtener($solicitud, (int) $id);
        try {
            $nuevo = $this->recepciones->guardar($solicitud, $id === '' ? null : (int) $id, $solicitud->cuerpo);
        } catch (ErrorValidacion $error) {
            return $this->validacion($solicitud, $error, 'produccion/recepcion_formulario', $this->datosFormularioRecepcion($solicitud, $lote), $this->meta('Recepción'));
        }
        return $this->trasGuardar($this->recepcion($solicitud, (string) $nuevo), '/produccion/recepciones/' . $nuevo, $lote === null ? 'Recepción registrada.' : 'Lote actualizado.');
    }

    public function archivarRecepcion(Solicitud $solicitud, string $id): Respuesta
    {
        try {
            $this->recepciones->archivar($solicitud, (int) $id, Entrada::entero($solicitud->cuerpo['version'] ?? '', 1) ?? 0, (string) ($solicitud->cuerpo['motivo'] ?? ''));
        } catch (ErrorValidacion $error) {
            $lote = $this->recepciones->obtener($solicitud, (int) $id);
            return $this->validacion($solicitud, $error, 'produccion/recepcion_formulario', $this->datosFormularioRecepcion($solicitud, $lote) + ['valores' => $lote], $this->meta('Lote'));
        }
        return $this->trasGuardar($this->recepciones($solicitud), '/produccion/recepciones', 'Recepción archivada.');
    }

    // ---------------------------------------------------------------- Salidas (RF-043 a RF-046)

    public function salidas(Solicitud $solicitud): Respuesta
    {
        return $this->pantalla($solicitud, 'produccion/salidas', $this->datosSalidas($solicitud), $this->meta('Salidas', 'Ventas directas y entregas por autoconsumo, cada una desde su lote.'));
    }

    public function formularioSalida(Solicitud $solicitud, string $id = ''): Respuesta
    {
        $salida = $id === '' ? null : $this->salidas->obtener($solicitud, (int) $id);
        $valores = $salida ?? ['fecha' => self::hoy(), 'canal' => 'VENTA_DIRECTA', 'recepcion_id' => (string) ($solicitud->consulta['lote'] ?? '')];
        return $this->pantalla($solicitud, 'produccion/salida_formulario', $this->datosFormularioSalida($solicitud, $salida) + ['valores' => $valores], $this->meta($salida === null ? 'Nueva salida' : 'Editar salida'));
    }

    public function guardarSalida(Solicitud $solicitud, string $id = ''): Respuesta
    {
        $salida = $id === '' ? null : $this->salidas->obtener($solicitud, (int) $id);
        try {
            $this->salidas->guardar($solicitud, $id === '' ? null : (int) $id, $solicitud->cuerpo);
        } catch (ErrorValidacion $error) {
            return $this->validacion($solicitud, $error, 'produccion/salida_formulario', $this->datosFormularioSalida($solicitud, $salida), $this->meta('Salida'));
        }
        $mensaje = $salida === null ? 'Salida registrada.' : 'Salida actualizada.';
        if (($solicitud->cuerpo['canal'] ?? '') === 'VENTA_DIRECTA' && $salida === null) {
            $mensaje .= ' La venta no crea un recaudo: se cruza con él por conciliación.';
        }
        return $this->trasGuardar($this->salidas($solicitud), '/produccion/salidas', $mensaje);
    }

    public function archivarSalida(Solicitud $solicitud, string $id): Respuesta
    {
        try {
            $this->salidas->archivar($solicitud, (int) $id, Entrada::entero($solicitud->cuerpo['version'] ?? '', 1) ?? 0, (string) ($solicitud->cuerpo['motivo'] ?? ''));
        } catch (ErrorValidacion $error) {
            $salida = $this->salidas->obtener($solicitud, (int) $id);
            return $this->validacion($solicitud, $error, 'produccion/salida_formulario', $this->datosFormularioSalida($solicitud, $salida) + ['valores' => $salida], $this->meta('Salida'));
        }
        return $this->trasGuardar($this->salidas($solicitud), '/produccion/salidas', 'Salida archivada.');
    }

    // ---------------------------------------------------------------- Transformaciones (RF-047 a RF-051)

    public function transformaciones(Solicitud $solicitud): Respuesta
    {
        return $this->pantalla($solicitud, 'produccion/transformaciones', $this->datosTransformaciones($solicitud), $this->meta('Transformación láctea', 'Leche entregada por autoconsumo y el derivado que produjo, con su rendimiento en litros por unidad.'));
    }

    public function formularioTransformacion(Solicitud $solicitud): Respuesta
    {
        $valores = ['fecha' => self::hoy(), 'modo_recepcion' => 'nueva', 'responsable_id' => (string) AutorizacionRecurso::usuario($solicitud)->id];
        return $this->pantalla($solicitud, 'produccion/transformacion_formulario', $this->datosFormularioTransformacion($solicitud) + ['valores' => $valores], $this->meta('Nueva transformación'));
    }

    public function guardarTransformacion(Solicitud $solicitud): Respuesta
    {
        try {
            $this->transformaciones->crear($solicitud, $solicitud->cuerpo);
        } catch (ErrorValidacion $error) {
            return $this->validacion($solicitud, $error, 'produccion/transformacion_formulario', $this->datosFormularioTransformacion($solicitud), $this->meta('Nueva transformación'));
        }
        return $this->trasGuardar($this->transformaciones($solicitud), '/produccion/transformaciones', 'Transformación registrada con la recepción del derivado.');
    }

    public function archivarTransformacion(Solicitud $solicitud, string $id): Respuesta
    {
        try {
            $this->transformaciones->archivar($solicitud, (int) $id, Entrada::entero($solicitud->cuerpo['version'] ?? '', 1) ?? 0, (string) ($solicitud->cuerpo['motivo'] ?? ''));
        } catch (ErrorValidacion $error) {
            return $this->validacion($solicitud, $error, 'produccion/transformaciones', $this->datosTransformaciones($solicitud), $this->meta('Transformación láctea'));
        }
        return $this->trasGuardar($this->transformaciones($solicitud), '/produccion/transformaciones', 'Transformación archivada; el lote del derivado queda sin vínculo.');
    }

    // ---------------------------------------------------------------- Datos de las vistas

    private static function hoy(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota')))->format('Y-m-d');
    }

    /** @return array<string,string> */
    private function nombresUnidades(Solicitud $solicitud): array
    {
        $nombres = [];
        foreach ($this->catalogos->unidades(ServicioCatalogos::centro($solicitud)) as $u) {
            $nombres[(string) $u['id']] = (string) $u['nombre'];
        }
        return $nombres;
    }

    /** @return array<string,mixed> */
    private function datosRecepciones(Solicitud $solicitud): array
    {
        $filtros = [
            'unidad' => $this->enteroConsulta($solicitud, 'unidad'),
            'desde' => Entrada::fecha($solicitud->consulta['desde'] ?? ''),
            'hasta' => Entrada::fecha($solicitud->consulta['hasta'] ?? ''),
            'producto' => $this->enteroConsulta($solicitud, 'producto'),
            'estado' => in_array($solicitud->consulta['estado'] ?? '', ['abierto', 'cerrado'], true) ? (string) $solicitud->consulta['estado'] : null,
        ];
        $pagina = $this->enteroConsulta($solicitud, 'pagina', 1) ?? 1;
        return $this->recepciones->listar($solicitud, $filtros, $pagina) + [
            'filtros' => $filtros,
            'pagina' => $pagina,
            'unidades' => $this->catalogos->unidadesDelAlcance($solicitud),
            'nombresUnidades' => $this->nombresUnidades($solicitud),
            'puedeEditar' => AutorizacionRecurso::usuario($solicitud)->tienePermiso('recepciones.gestionar'),
        ];
    }

    /**
     * @param array<string,mixed>|null $lote
     * @return array<string,mixed>
     */
    private function datosFormularioRecepcion(Solicitud $solicitud, ?array $lote): array
    {
        $centro = ServicioCatalogos::centro($solicitud);
        return [
            'lote' => $lote,
            'unidades' => $this->catalogos->unidadesDelAlcance($solicitud),
            'productos' => $this->productos->opciones($centro),
            'responsables' => $this->recepciones->responsables($centro),
        ];
    }

    /** @return array<string,mixed> */
    private function datosSalidas(Solicitud $solicitud): array
    {
        $canal = (string) ($solicitud->consulta['canal'] ?? '');
        $filtros = [
            'unidad' => $this->enteroConsulta($solicitud, 'unidad'),
            'canal' => in_array($canal, ['VENTA_DIRECTA', 'AUTOCONSUMO'], true) ? $canal : null,
            'desde' => Entrada::fecha($solicitud->consulta['desde'] ?? ''),
            'hasta' => Entrada::fecha($solicitud->consulta['hasta'] ?? ''),
            'sin_recepcion' => ($solicitud->consulta['sin_recepcion'] ?? '') === '1',
        ];
        $pagina = $this->enteroConsulta($solicitud, 'pagina', 1) ?? 1;
        return $this->salidas->listar($solicitud, $filtros, $pagina) + [
            'filtros' => $filtros,
            'pagina' => $pagina,
            'unidades' => $this->catalogos->unidadesDelAlcance($solicitud),
            'nombresUnidades' => $this->nombresUnidades($solicitud),
            'puedeEditar' => AutorizacionRecurso::usuario($solicitud)->tienePermiso('salidas.gestionar'),
        ];
    }

    /**
     * @param array<string,mixed>|null $salida
     * @return array<string,mixed>
     */
    private function datosFormularioSalida(Solicitud $solicitud, ?array $salida): array
    {
        return [
            'salida' => $salida,
            'lotes' => $this->salidas->lotesDisponibles($solicitud, $salida === null ? null : (int) $salida['recepcion_id']),
            'procesos' => $this->salidas->procesosDisponibles($solicitud),
        ];
    }

    /** @return array<string,mixed> */
    private function datosTransformaciones(Solicitud $solicitud): array
    {
        $pagina = $this->enteroConsulta($solicitud, 'pagina', 1) ?? 1;
        return $this->transformaciones->listar($solicitud, $pagina) + [
            'pagina' => $pagina,
            'nombresUnidades' => $this->nombresUnidades($solicitud),
            'puedeEditar' => AutorizacionRecurso::usuario($solicitud)->tienePermiso('transformaciones.gestionar'),
        ];
    }

    /** @return array<string,mixed> */
    private function datosFormularioTransformacion(Solicitud $solicitud): array
    {
        $centro = ServicioCatalogos::centro($solicitud);
        $usuario = AutorizacionRecurso::usuario($solicitud);
        return [
            'entregas' => $this->transformaciones->entregasDisponibles($solicitud),
            'derivados' => $this->transformaciones->derivados($centro),
            'recepcionesLibres' => $this->transformaciones->recepcionesLibres($centro),
            'unidadesReceptoras' => $this->catalogos->unidades($centro, true),
            'responsables' => $this->recepciones->responsables($centro),
            'puedeCatalogo' => $usuario->tienePermiso('catalogo.gestionar_factores') || $usuario->tienePermiso('catalogo.gestionar'),
            'puedeSalida' => $usuario->tienePermiso('salidas.gestionar'),
        ];
    }

    /** @return array<string,mixed> */
    private function meta(string $titulo, string $descripcion = ''): array
    {
        return [
            'titulo' => $titulo,
            'sobretitulo' => 'Producción y lotes',
            'descripcion' => $descripcion,
            'pestanas' => self::PESTANAS,
            'migas' => [['texto' => 'Producción y lotes'], ['texto' => $titulo]],
        ];
    }
}
