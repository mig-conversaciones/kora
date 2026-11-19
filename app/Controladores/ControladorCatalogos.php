<?php

declare(strict_types=1);

namespace App\Controladores;

use App\Nucleo\ErrorHttp;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Nucleo\Vista;
use App\Servicios\AutorizacionRecurso;
use App\Servicios\ServicioCatalogos;
use App\Servicios\ServicioParametros;
use App\Servicios\ServicioProductos;

/**
 * Catálogos y parámetros (RF-016 a RF-023): unidades, productos y derivados, homologación, categorías de gasto,
 * destinos internos y parámetros de los indicadores. Consultan todos los roles con catalogo.consultar; editan los
 * que tienen catalogo.gestionar (los factores de derivados, con catalogo.gestionar_factores).
 */
final class ControladorCatalogos extends ControladorModulo
{
    private const PESTANAS = [
        ['texto' => 'Unidades', 'ruta' => '/catalogos/unidades', 'permiso' => 'catalogo.consultar'],
        ['texto' => 'Productos', 'ruta' => '/catalogos/productos', 'permiso' => 'catalogo.consultar'],
        ['texto' => 'Homologación', 'ruta' => '/catalogos/homologaciones', 'permiso' => 'catalogo.consultar'],
        ['texto' => 'Categorías de gasto', 'ruta' => '/catalogos/categorias', 'permiso' => 'catalogo.consultar'],
        ['texto' => 'Destinos', 'ruta' => '/catalogos/destinos', 'permiso' => 'catalogo.consultar'],
        ['texto' => 'Parámetros', 'ruta' => '/catalogos/parametros', 'permiso' => 'parametros.consultar'],
    ];

    public function __construct(
        Vista $vista,
        private readonly ServicioCatalogos $catalogos,
        private readonly ServicioProductos $productos,
        private readonly ServicioParametros $parametros,
    ) {
        parent::__construct($vista);
    }

    // ---------------------------------------------------------------- Unidades

    public function unidades(Solicitud $solicitud): Respuesta
    {
        return $this->pantalla($solicitud, 'catalogos/unidades', $this->datosUnidades($solicitud), $this->meta('Unidades productivas', 'Código, prefijo de lote, tipo y estado de cada unidad. Una unidad con registros activos no puede inactivarse.'));
    }

    public function editarUnidad(Solicitud $solicitud, string $id): Respuesta
    {
        $unidad = $this->catalogos->unidad(ServicioCatalogos::centro($solicitud), (int) $id);
        return $this->pantalla($solicitud, 'catalogos/unidad_formulario', ['unidad' => $unidad, 'valores' => $unidad], $this->meta('Editar unidad: ' . $unidad['nombre']));
    }

    public function guardarUnidad(Solicitud $solicitud, string $id): Respuesta
    {
        $centro = ServicioCatalogos::centro($solicitud);
        try {
            $this->catalogos->actualizarUnidad($solicitud, (int) $id, $solicitud->cuerpo);
        } catch (ErrorValidacion $error) {
            return $this->validacion($solicitud, $error, 'catalogos/unidad_formulario', ['unidad' => $this->catalogos->unidad($centro, (int) $id)], $this->meta('Editar unidad'));
        }
        return $this->trasGuardar($this->pantalla($solicitud, 'catalogos/unidades', $this->datosUnidades($solicitud), $this->meta('Unidades productivas')), '/catalogos/unidades', 'Unidad actualizada.');
    }

    // ---------------------------------------------------------------- Productos y derivados

    public function productos(Solicitud $solicitud): Respuesta
    {
        return $this->pantalla($solicitud, 'catalogos/productos', $this->datosProductos($solicitud), $this->meta('Catálogo de productos', 'Código SGAC, medida, precios y derivados lácteos con su factor de leche.'));
    }

    public function nuevoProducto(Solicitud $solicitud): Respuesta
    {
        return $this->pantalla($solicitud, 'catalogos/producto_formulario', $this->datosFormularioProducto($solicitud, null), $this->meta('Nuevo producto'));
    }

    public function crearProducto(Solicitud $solicitud): Respuesta
    {
        try {
            $this->productos->crear($solicitud, $solicitud->cuerpo, $this->puede($solicitud, 'catalogo.gestionar_factores'));
        } catch (ErrorValidacion $error) {
            return $this->validacion($solicitud, $error, 'catalogos/producto_formulario', $this->datosFormularioProducto($solicitud, null), $this->meta('Nuevo producto'));
        }
        return $this->trasGuardar($this->pantalla($solicitud, 'catalogos/productos', $this->datosProductos($solicitud), $this->meta('Catálogo de productos')), '/catalogos/productos', 'Producto creado.');
    }

    public function editarProducto(Solicitud $solicitud, string $id): Respuesta
    {
        $this->exigirAlguno($solicitud, ['catalogo.gestionar', 'catalogo.gestionar_factores']);
        $producto = $this->productos->obtener(ServicioCatalogos::centro($solicitud), (int) $id);
        return $this->pantalla($solicitud, 'catalogos/producto_formulario', $this->datosFormularioProducto($solicitud, $producto) + ['valores' => $producto], $this->meta('Editar producto: ' . $producto['nombre']));
    }

    public function guardarProducto(Solicitud $solicitud, string $id): Respuesta
    {
        $this->exigirAlguno($solicitud, ['catalogo.gestionar', 'catalogo.gestionar_factores']);
        $producto = $this->productos->obtener(ServicioCatalogos::centro($solicitud), (int) $id);
        try {
            $this->productos->actualizar($solicitud, (int) $id, $solicitud->cuerpo, $this->puede($solicitud, 'catalogo.gestionar'), $this->puede($solicitud, 'catalogo.gestionar_factores'));
        } catch (ErrorValidacion $error) {
            return $this->validacion($solicitud, $error, 'catalogos/producto_formulario', $this->datosFormularioProducto($solicitud, $producto), $this->meta('Editar producto'));
        }
        return $this->trasGuardar($this->pantalla($solicitud, 'catalogos/productos', $this->datosProductos($solicitud), $this->meta('Catálogo de productos')), '/catalogos/productos', 'Producto actualizado.');
    }

    // ---------------------------------------------------------------- Homologación

    public function homologaciones(Solicitud $solicitud): Respuesta
    {
        return $this->pantalla($solicitud, 'catalogos/homologaciones', $this->datosHomologaciones($solicitud), $this->meta('Homologación de conceptos', 'Relaciona los textos de las fuentes con productos del catálogo. Los conceptos genéricos quedan como «concepto fuente» hasta que alguien decida.'));
    }

    public function guardarHomologacion(Solicitud $solicitud): Respuesta
    {
        try {
            $this->catalogos->guardarHomologacion($solicitud, $solicitud->cuerpo);
        } catch (ErrorValidacion $error) {
            return $this->validacion($solicitud, $error, 'catalogos/homologaciones', $this->datosHomologaciones($solicitud), $this->meta('Homologación de conceptos'));
        }
        return $this->trasGuardar($this->pantalla($solicitud, 'catalogos/homologaciones', $this->datosHomologaciones($solicitud), $this->meta('Homologación de conceptos')), '/catalogos/homologaciones', 'Equivalencia guardada.');
    }

    // ---------------------------------------------------------------- Categorías de gasto

    public function categorias(Solicitud $solicitud): Respuesta
    {
        return $this->pantalla($solicitud, 'catalogos/categorias', $this->datosCategorias($solicitud), $this->meta('Categorías de gasto', 'Cada categoría pertenece a una línea presupuestal. Solo Mantenimiento y Alimentación cuentan en IND_17.'));
    }

    public function crearCategoria(Solicitud $solicitud): Respuesta
    {
        try {
            $this->catalogos->crearCategoria($solicitud, $solicitud->cuerpo);
        } catch (ErrorValidacion $error) {
            return $this->validacion($solicitud, $error, 'catalogos/categorias', $this->datosCategorias($solicitud), $this->meta('Categorías de gasto'));
        }
        return $this->trasGuardar($this->pantalla($solicitud, 'catalogos/categorias', $this->datosCategorias($solicitud), $this->meta('Categorías de gasto')), '/catalogos/categorias', 'Categoría creada.');
    }

    public function estadoCategoria(Solicitud $solicitud, string $id, string $estado): Respuesta
    {
        if (!in_array($estado, ['activar', 'inactivar'], true)) {
            throw new ErrorHttp(404);
        }
        $this->catalogos->cambiarEstadoCategoria($solicitud, (int) $id, $estado === 'activar');
        return $this->trasGuardar($this->pantalla($solicitud, 'catalogos/categorias', $this->datosCategorias($solicitud), $this->meta('Categorías de gasto')), '/catalogos/categorias', $estado === 'activar' ? 'Categoría activada.' : 'Categoría inactivada: ya no aparece en los gastos nuevos.');
    }

    // ---------------------------------------------------------------- Destinos

    public function destinos(Solicitud $solicitud): Respuesta
    {
        return $this->pantalla($solicitud, 'catalogos/destinos', $this->datosDestinos($solicitud), $this->meta('Destinos y clientes internos', 'Destinos del autoconsumo. Un destino vinculado a una unidad le permite consultar las entregas que recibe.'));
    }

    public function guardarDestino(Solicitud $solicitud, string $id = ''): Respuesta
    {
        try {
            $this->catalogos->guardarDestino($solicitud, $id === '' ? null : (int) $id, $solicitud->cuerpo);
        } catch (ErrorValidacion $error) {
            return $this->validacion($solicitud, $error, 'catalogos/destinos', $this->datosDestinos($solicitud) + ['editando' => $id === '' ? null : (int) $id], $this->meta('Destinos y clientes internos'));
        }
        return $this->trasGuardar($this->pantalla($solicitud, 'catalogos/destinos', $this->datosDestinos($solicitud), $this->meta('Destinos y clientes internos')), '/catalogos/destinos', 'Destino guardado.');
    }

    // ---------------------------------------------------------------- Parámetros

    public function parametros(Solicitud $solicitud): Respuesta
    {
        return $this->pantalla($solicitud, 'catalogos/parametros', $this->datosParametros($solicitud), $this->meta('Parámetros de los indicadores', 'Cada cambio es un valor nuevo con su fecha de inicio de vigencia; el historial se conserva.'));
    }

    public function registrarParametro(Solicitud $solicitud): Respuesta
    {
        try {
            $this->parametros->registrar($solicitud, $solicitud->cuerpo);
        } catch (ErrorValidacion $error) {
            return $this->validacion($solicitud, $error, 'catalogos/parametros', $this->datosParametros($solicitud), $this->meta('Parámetros de los indicadores'));
        }
        return $this->trasGuardar($this->pantalla($solicitud, 'catalogos/parametros', $this->datosParametros($solicitud), $this->meta('Parámetros de los indicadores')), '/catalogos/parametros', 'Parámetro registrado.');
    }

    // ---------------------------------------------------------------- Datos de las vistas

    /** @return array<string,mixed> */
    private function datosUnidades(Solicitud $solicitud): array
    {
        $centro = ServicioCatalogos::centro($solicitud);
        $unidades = $this->catalogos->unidades($centro);
        $bloqueos = [];
        foreach ($unidades as $u) {
            $bloqueos[(int) $u['id']] = $this->catalogos->bloqueosUnidad($centro, (int) $u['id']);
        }
        return ['unidades' => $unidades, 'bloqueos' => $bloqueos, 'puedeEditar' => $this->puede($solicitud, 'catalogo.gestionar')];
    }

    /** @return array<string,mixed> */
    private function datosProductos(Solicitud $solicitud): array
    {
        $centro = ServicioCatalogos::centro($solicitud);
        $buscar = mb_substr(trim((string) ($solicitud->consulta['buscar'] ?? '')), 0, 100);
        $derivados = !empty($solicitud->consulta['derivados']);
        $pagina = $this->enteroConsulta($solicitud, 'pagina', 1) ?? 1;
        return $this->productos->listar($centro, $buscar, $derivados, $pagina) + [
            'buscar' => $buscar,
            'derivados' => $derivados,
            'pagina' => $pagina,
            'incidencias' => $this->productos->incidenciasDerivados($centro),
            'puedeEditar' => $this->puede($solicitud, 'catalogo.gestionar') || $this->puede($solicitud, 'catalogo.gestionar_factores'),
            'puedeCrear' => $this->puede($solicitud, 'catalogo.gestionar'),
        ];
    }

    /**
     * @param array<string,mixed>|null $producto
     * @return array<string,mixed>
     */
    private function datosFormularioProducto(Solicitud $solicitud, ?array $producto): array
    {
        return [
            'producto' => $producto,
            'unidades' => $this->catalogos->unidades(ServicioCatalogos::centro($solicitud), true),
            'puedeGenerales' => $this->puede($solicitud, 'catalogo.gestionar'),
            'puedeFactores' => $this->puede($solicitud, 'catalogo.gestionar_factores'),
        ];
    }

    /** @return array<string,mixed> */
    private function datosHomologaciones(Solicitud $solicitud): array
    {
        $centro = ServicioCatalogos::centro($solicitud);
        $buscar = mb_substr(trim((string) ($solicitud->consulta['buscar'] ?? '')), 0, 100);
        return [
            'homologaciones' => $this->catalogos->homologaciones($centro, $buscar),
            'productos' => $this->productos->opciones($centro),
            'buscar' => $buscar,
            'puedeEditar' => $this->puede($solicitud, 'catalogo.gestionar'),
        ];
    }

    /** @return array<string,mixed> */
    private function datosCategorias(Solicitud $solicitud): array
    {
        return ['categorias' => $this->catalogos->categorias(ServicioCatalogos::centro($solicitud)), 'puedeEditar' => $this->puede($solicitud, 'catalogo.gestionar')];
    }

    /** @return array<string,mixed> */
    private function datosDestinos(Solicitud $solicitud): array
    {
        $centro = ServicioCatalogos::centro($solicitud);
        return [
            'destinos' => $this->catalogos->destinos($centro),
            'unidades' => $this->catalogos->unidades($centro, true),
            'editando' => $this->enteroConsulta($solicitud, 'editar'),
            'puedeEditar' => $this->puede($solicitud, 'catalogo.gestionar'),
        ];
    }

    /** @return array<string,mixed> */
    private function datosParametros(Solicitud $solicitud): array
    {
        $centro = ServicioCatalogos::centro($solicitud);
        return [
            'vigentes' => $this->parametros->vigentes($centro),
            'historial' => $this->parametros->historial($centro),
            'puedeEditar' => $this->puede($solicitud, 'parametros.gestionar'),
        ];
    }

    /** @return array<string,mixed> */
    private function meta(string $titulo, string $descripcion = ''): array
    {
        return [
            'titulo' => $titulo,
            'sobretitulo' => 'Catálogos',
            'descripcion' => $descripcion,
            'pestanas' => self::PESTANAS,
            'migas' => [['texto' => 'Catálogos'], ['texto' => $titulo]],
        ];
    }

    private function puede(Solicitud $solicitud, string $permiso): bool
    {
        return AutorizacionRecurso::usuario($solicitud)->tienePermiso($permiso);
    }

    /** @param list<string> $permisos */
    private function exigirAlguno(Solicitud $solicitud, array $permisos): void
    {
        foreach ($permisos as $permiso) {
            if ($this->puede($solicitud, $permiso)) {
                return;
            }
        }
        throw new ErrorHttp(403);
    }
}
