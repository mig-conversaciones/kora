<?php

declare(strict_types=1);

namespace App\Controladores;

use App\Intermediarios\Autorizar;
use App\Nucleo\ErrorHttp;
use App\Nucleo\Funcionalidades;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Nucleo\Url;
use App\Nucleo\Vista;
use App\Politicas\UsuarioSesion;
use App\Servicios\AutorizacionRecurso;
use App\Tableros\FiltrosTablero;
use App\Tableros\ServicioIndicadores;
use App\Tableros\ServicioTableros;

/**
 * Tableros (I6): central (RF-062 a RF-072) y técnico de unidad (RF-073 a RF-079).
 *   - Los filtros se validan en el servidor y viajan en la URL (RF-062); un rango invertido da 422 sin calcular.
 *   - El alcance sale de la sesión; el filtro de unidad solo ofrece y acepta las del alcance (RF-072, Obs. 4).
 *   - El tablero técnico conserva la vigencia y las fechas, y «Volver» recupera los filtros del central (RF-071).
 *   - En la v1 solo Ganadería tiene tablero técnico; las demás unidades muestran «en preparación» (RF-079).
 */
final class ControladorTableros extends ControladorModulo
{
    public function __construct(
        Vista $vista,
        private readonly ServicioTableros $tableros,
        private readonly ServicioIndicadores $indicadores,
        private readonly Funcionalidades $funcionalidades,
    ) {
        parent::__construct($vista);
    }

    public function central(Solicitud $solicitud): Respuesta
    {
        $alcance = AutorizacionRecurso::alcance($solicitud);
        $unidades = $this->indicadores->unidadesDelAlcance($alcance);
        $f = $this->filtros($solicitud, $unidades);
        $datos = [
            'filtros' => $f,
            'unidades' => $unidades,
            'productos' => $this->tableros->opcionesProducto($alcance, $f->vigencia),
            'modo' => $this->modo($solicitud),
            'tablero' => $f->esValido() && !$alcance->estaVacio() ? $this->tableros->central($alcance, $f) : null,
            'rutaCentral' => '/tableros/central' . self::consulta($f->aConsulta()),
            'puedeExportar' => AutorizacionRecurso::usuario($solicitud)->tienePermiso('exportaciones.solicitar'),
            'puedeTecnico' => AutorizacionRecurso::usuario($solicitud)->tienePermiso('tablero_unidad.consultar'),
            'errores' => $f->errores,
        ];
        $estado = $f->esValido() ? 200 : 422;
        return $this->pantalla($solicitud, 'tableros/central', $datos, $this->meta('Tablero central', 'Asignación presupuestal frente a lo vendido por cada unidad (IND_001) y ejecución de los cupos de autoconsumo (IND_002).'), $estado);
    }

    /** Sección de procesos (IND_002) sola: el selector Pesos / Unidades la recarga como fragmento (RF-067). */
    public function procesos(Solicitud $solicitud): Respuesta
    {
        $alcance = AutorizacionRecurso::alcance($solicitud);
        $f = $this->filtros($solicitud, $this->indicadores->unidadesDelAlcance($alcance));
        if (!$f->esValido()) {
            throw new ErrorHttp(422);
        }
        $tablero = $this->tableros->central($alcance, $f);
        return $this->renderizar($solicitud, 'tableros/procesos', 'tableros/procesos', [
            'procesos' => $tablero['procesos'], 'sinProceso' => $tablero['sin_proceso'], 'modo' => $this->modo($solicitud), 'filtros' => $f,
        ]);
    }

    public function unidad(Solicitud $solicitud, string $codigo): Respuesta
    {
        [$unidad, $f, $practica] = $this->contextoUnidad($solicitud, $codigo);
        $usuario = AutorizacionRecurso::usuario($solicitud);
        $habilitado = strtoupper((string) $unidad['codigo']) === 'GAN' || $this->funcionalidades->activa('tablero_otras_unidades');
        $alcance = AutorizacionRecurso::alcance($solicitud);
        $volver = Autorizar::rutaInternaSegura((string) ($solicitud->consulta['volver'] ?? ''));
        $datos = [
            'unidad' => $unidad,
            'filtros' => $f,
            'practica' => $practica,
            'habilitado' => $habilitado,
            'tablero' => $habilitado && $f->esValido() ? $this->tableros->unidad($alcance, (int) $unidad['id'], $f, $practica) : null,
            'filtroSalud' => $this->vistaSalud($solicitud, $usuario),
            'academica' => $usuario->tieneRol('coordinacion_academica'),
            'volver' => str_starts_with($volver, '/tableros/central') ? $volver : '/tableros/central' . self::consulta(['vigencia' => (string) $f->vigencia] + array_intersect_key($f->aConsulta(), ['desde' => 1, 'hasta' => 1])),
            'puedeCentral' => $usuario->tienePermiso('tablero_central.consultar'),
            'puedeExportar' => $usuario->tienePermiso('exportaciones.solicitar'),
            'errores' => $f->errores,
        ];
        $meta = $this->meta((string) $unidad['nombre'], 'Tablero técnico de la unidad: hato, transformación láctea, rotación de lotes y costo por litro.');
        $meta['sobretitulo'] = 'Tablero técnico';
        return $this->pantalla($solicitud, 'tableros/unidad', $datos, $meta, $f->esValido() ? 200 : 422);
    }

    /** IND_003 y salud reproductiva solos: el interruptor de práctica y los filtros rápidos los recargan (RF-073). */
    public function hato(Solicitud $solicitud, string $codigo): Respuesta
    {
        [$unidad, $f, $practica] = $this->contextoUnidad($solicitud, $codigo);
        $usuario = AutorizacionRecurso::usuario($solicitud);
        $tablero = $this->tableros->unidad(AutorizacionRecurso::alcance($solicitud), (int) $unidad['id'], $f, $practica);
        return $this->renderizar($solicitud, 'tableros/hato', 'tableros/hato', [
            'unidad' => $unidad, 'filtros' => $f, 'practica' => $practica, 'hato' => $tablero['hato'],
            'filtroSalud' => $this->vistaSalud($solicitud, $usuario), 'academica' => $usuario->tieneRol('coordinacion_academica'),
        ]);
    }

    // ---------------------------------------------------------------- Ayudas

    /**
     * @param list<array<string,mixed>> $unidades
     */
    private function filtros(Solicitud $solicitud, array $unidades): FiltrosTablero
    {
        return FiltrosTablero::desdeConsulta($solicitud->consulta, array_map(static fn (array $u): int => (int) $u['id'], $unidades), self::anioActual());
    }

    /** @return array{0:array<string,mixed>,1:FiltrosTablero,2:bool} */
    private function contextoUnidad(Solicitud $solicitud, string $codigo): array
    {
        $alcance = AutorizacionRecurso::alcance($solicitud);
        $unidades = $this->indicadores->unidadesDelAlcance($alcance);
        $unidad = null;
        foreach ($unidades as $u) {
            if (strtoupper((string) $u['codigo']) === strtoupper($codigo)) {
                $unidad = $u;
            }
        }
        if ($unidad === null) {
            // Fuera del alcance o inexistente: no se revela cuál de las dos (RAC-028).
            throw new ErrorHttp(404);
        }
        // En el técnico la unidad es la de la ruta; la de la consulta no aplica.
        $consulta = ['unidad' => ''] + $solicitud->consulta;
        $consulta['unidad'] = '';
        $f = FiltrosTablero::desdeConsulta($consulta, [(int) $unidad['id']], self::anioActual())->conUnidad((int) $unidad['id']);
        $usuario = AutorizacionRecurso::usuario($solicitud);
        // Apagado por defecto para el líder y encendido para la Coordinación académica (RF-073).
        $porDefecto = $usuario->tieneRol('coordinacion_academica');
        $valor = (string) ($solicitud->consulta['practica'] ?? '');
        $practica = $valor === '' ? $porDefecto : $valor === '1';
        return [$unidad, $f, $practica];
    }

    /** Filtro rápido de la tabla de salud: población, alertas, preñadas o práctica (RF-078). */
    private function vistaSalud(Solicitud $solicitud, UsuarioSesion $usuario): string
    {
        $vista = (string) ($solicitud->consulta['salud'] ?? '');
        if (in_array($vista, ['poblacion', 'alertas', 'prenadas', 'practica'], true)) {
            return $vista;
        }
        return $usuario->tieneRol('coordinacion_academica') ? 'practica' : 'alertas';
    }

    private function modo(Solicitud $solicitud): string
    {
        return ($solicitud->consulta['modo'] ?? '') === 'unidades' ? 'unidades' : 'pesos';
    }

    /** @param array<string,string> $parametros */
    public static function consulta(array $parametros): string
    {
        $parametros = array_filter($parametros, static fn (string $v): bool => $v !== '');
        return $parametros === [] ? '' : '?' . http_build_query($parametros);
    }

    /**
     * URL absoluta de una ruta con sus parámetros.
     *
     * @param array<string,string> $parametros
     */
    public static function enlace(string $ruta, array $parametros = []): string
    {
        return Url::a($ruta) . self::consulta($parametros);
    }

    /** @return array<string,mixed> */
    private function meta(string $titulo, string $descripcion = ''): array
    {
        return ['sobretitulo' => 'Vista general', 'titulo' => $titulo, 'descripcion' => $descripcion, 'pestanas' => [], 'migas' => [['texto' => 'Vista general'], ['texto' => $titulo]]];
    }
}
