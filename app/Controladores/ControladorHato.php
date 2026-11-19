<?php

declare(strict_types=1);

namespace App\Controladores;

use App\Nucleo\Entrada;
use App\Nucleo\ErrorHttp;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Nucleo\Vista;
use App\Servicios\AutorizacionRecurso;
use App\Servicios\ServicioCatalogos;
use App\Servicios\ServicioHato;

/**
 * Hato por cortes (RF-052 a RF-057): inventario del corte con semáforos, nuevo corte, ficha del animal y lote de
 * práctica académica.
 */
final class ControladorHato extends ControladorModulo
{
    public function __construct(Vista $vista, private readonly ServicioHato $hato)
    {
        parent::__construct($vista);
    }

    public function inventario(Solicitud $solicitud): Respuesta
    {
        return $this->pantalla($solicitud, 'hato/inventario', $this->datosInventario($solicitud), $this->meta('Hato', 'Inventario de hembras por corte, con su estado reproductivo y los semáforos de IEP y días abiertos.'));
    }

    public function crearCorte(Solicitud $solicitud): Respuesta
    {
        $unidad = Entrada::entero($solicitud->cuerpo['unidad_id'] ?? '', 1) ?? 0;
        $fecha = Entrada::fecha($solicitud->cuerpo['fecha_corte'] ?? '');
        $lista = $solicitud->conAtributo('unidad_hato', $unidad);
        try {
            if ($fecha === null) {
                throw new ErrorValidacion(['fecha_corte' => 'Escribe la fecha del corte (AAAA-MM-DD).']);
            }
            $this->hato->crearCorte($solicitud, $unidad, $fecha);
        } catch (ErrorValidacion $error) {
            return $this->validacion($lista, $error, 'hato/inventario', $this->datosInventario($lista), $this->meta('Hato'));
        }
        return $this->trasGuardar($this->pantalla($lista, 'hato/inventario', $this->datosInventario($lista), $this->meta('Hato')), '/hato?unidad=' . $unidad, 'Corte creado: es el nuevo corte vigente.');
    }

    public function formularioAnimal(Solicitud $solicitud, string $id = ''): Respuesta
    {
        $datos = $id === '' ? null : $this->hato->animal($solicitud, (int) $id);
        $valores = $datos === null
            ? ['unidad_id' => (string) ($solicitud->consulta['unidad'] ?? ''), 'lote_hato' => 'PRODUCCION']
            : $datos['animal'] + ($datos['registro'] ?? []);
        return $this->pantalla($solicitud, 'hato/animal_formulario', $this->datosAnimal($solicitud, $datos) + ['valores' => $valores], $this->meta($datos === null ? 'Nuevo animal' : 'Animal ' . $datos['animal']['identificador']));
    }

    public function guardarAnimal(Solicitud $solicitud, string $id = ''): Respuesta
    {
        $datos = $id === '' ? null : $this->hato->animal($solicitud, (int) $id);
        try {
            $nuevo = $this->hato->guardarAnimal($solicitud, $id === '' ? null : (int) $id, $solicitud->cuerpo);
        } catch (ErrorValidacion $error) {
            return $this->validacion($solicitud, $error, 'hato/animal_formulario', $this->datosAnimal($solicitud, $datos), $this->meta('Animal'));
        }
        $unidad = (int) $this->hato->animal($solicitud, $nuevo)['animal']['unidad_id'];
        $lista = $solicitud->conAtributo('unidad_hato', $unidad);
        return $this->trasGuardar($this->pantalla($lista, 'hato/inventario', $this->datosInventario($lista), $this->meta('Hato')), '/hato?unidad=' . $unidad, $datos === null ? 'Animal registrado en el corte vigente.' : 'Animal actualizado.');
    }

    public function cambiarPractica(Solicitud $solicitud, string $id): Respuesta
    {
        $datos = $this->hato->animal($solicitud, (int) $id);
        try {
            $this->hato->cambiarPractica($solicitud, (int) $id, $solicitud->cuerpo);
        } catch (ErrorValidacion $error) {
            return $this->validacion($solicitud, $error, 'hato/animal_formulario', $this->datosAnimal($solicitud, $datos) + ['valores' => $datos['animal'] + ($datos['registro'] ?? [])], $this->meta('Animal ' . $datos['animal']['identificador']));
        }
        $lista = $solicitud->conAtributo('unidad_hato', (int) $datos['animal']['unidad_id']);
        return $this->trasGuardar($this->pantalla($lista, 'hato/inventario', $this->datosInventario($lista), $this->meta('Hato')), '/hato?unidad=' . (int) $datos['animal']['unidad_id'], 'Lote de práctica actualizado; el cambio quedó auditado con su motivo.');
    }

    public function archivarAnimal(Solicitud $solicitud, string $id): Respuesta
    {
        $datos = $this->hato->animal($solicitud, (int) $id);
        try {
            $this->hato->archivarAnimal($solicitud, (int) $id, Entrada::entero($solicitud->cuerpo['version'] ?? '', 1) ?? 0, (string) ($solicitud->cuerpo['motivo'] ?? ''));
        } catch (ErrorValidacion $error) {
            return $this->validacion($solicitud, $error, 'hato/animal_formulario', $this->datosAnimal($solicitud, $datos) + ['valores' => $datos['animal'] + ($datos['registro'] ?? [])], $this->meta('Animal'));
        }
        $lista = $solicitud->conAtributo('unidad_hato', (int) $datos['animal']['unidad_id']);
        return $this->trasGuardar($this->pantalla($lista, 'hato/inventario', $this->datosInventario($lista), $this->meta('Hato')), '/hato', 'Animal archivado.');
    }

    /** @return array<string,mixed> */
    private function datosInventario(Solicitud $solicitud): array
    {
        $unidades = $this->hato->unidadesPecuarias($solicitud);
        $usuario = AutorizacionRecurso::usuario($solicitud);
        $base = ['unidades' => $unidades, 'unidad' => null, 'corte' => null, 'cortes' => [], 'animales' => [], 'totales' => [], 'puedeEditar' => $usuario->tienePermiso('hato.gestionar'), 'puedePractica' => $usuario->tienePermiso('hato.gestionar_practica'), 'lotes' => ServicioHato::LOTES];
        if ($unidades === []) {
            return $base;
        }
        $pedida = $solicitud->atributo('unidad_hato');
        $unidad = is_int($pedida) && $pedida > 0 ? $pedida : ($this->enteroConsulta($solicitud, 'unidad') ?? (int) $unidades[0]['id']);
        if (!in_array($unidad, array_map(static fn (array $u): int => (int) $u['id'], $unidades), true)) {
            throw new ErrorHttp(404);
        }
        $centro = ServicioCatalogos::centro($solicitud);
        $corte = $this->hato->corte($centro, $unidad, $this->enteroConsulta($solicitud, 'corte'));
        $inventario = $corte === null ? ['animales' => [], 'totales' => []] : $this->hato->inventario($solicitud, $unidad, (int) $corte['id']);
        return ['unidad' => $unidad, 'corte' => $corte, 'cortes' => $this->hato->cortes($centro, $unidad)] + $inventario + $base;
    }

    /**
     * @param array<string,mixed>|null $datos
     * @return array<string,mixed>
     */
    private function datosAnimal(Solicitud $solicitud, ?array $datos): array
    {
        $usuario = AutorizacionRecurso::usuario($solicitud);
        return [
            'animal' => $datos['animal'] ?? null,
            'registro' => $datos['registro'] ?? null,
            'corte' => $datos['corte'] ?? null,
            'unidades' => $this->hato->unidadesPecuarias($solicitud),
            'lotes' => ServicioHato::LOTES,
            'puedeEditar' => $usuario->tienePermiso('hato.gestionar'),
            'puedePractica' => $usuario->tienePermiso('hato.gestionar_practica'),
        ];
    }

    /** @return array<string,mixed> */
    private function meta(string $titulo, string $descripcion = ''): array
    {
        return [
            'titulo' => $titulo,
            'sobretitulo' => 'Hato',
            'descripcion' => $descripcion,
            'pestanas' => [],
            'migas' => [['texto' => 'Hato'], ['texto' => $titulo]],
        ];
    }
}
