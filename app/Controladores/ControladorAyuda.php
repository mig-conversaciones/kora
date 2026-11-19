<?php

declare(strict_types=1);

namespace App\Controladores;

use App\Nucleo\ErrorHttp;
use App\Nucleo\Markdown;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Nucleo\Url;
use App\Nucleo\Vista;
use App\Servicios\AutorizacionRecurso;

/**
 * Ayuda en línea (RDOC-009): las guías de docs/guias/ como páginas de la aplicación, imprimibles en PDF. Cada usuario
 * ve primero las de sus roles; la guía de operación solo la ven quienes consultan la salud del sistema.
 */
final class ControladorAyuda extends ControladorModulo
{
    /** clave => [archivo, título, roles a los que va dirigida, permiso necesario] */
    public const GUIAS = [
        'primeros-pasos' => ['primeros-pasos.md', 'Primeros pasos en KORA', [], null],
        'coordinacion-administrativa' => ['usuario-coordinacion-administrativa.md', 'Coordinación administrativa', ['coordinacion_administrativa'], null],
        'grupo-recaudo' => ['usuario-grupo-recaudo.md', 'Grupo de Recaudo', ['grupo_recaudo'], null],
        'lider-produccion-centro' => ['usuario-lider-produccion-centro.md', 'Líder de producción de centro', ['lider_produccion_centro'], null],
        'lider-unidad' => ['usuario-lider-unidad.md', 'Líder de unidad productiva', ['lider_unidad'], null],
        'coordinacion-academica' => ['usuario-coordinacion-academica.md', 'Coordinación académica', ['coordinacion_academica'], null],
        'administracion' => ['usuario-administracion.md', 'Administración del sistema', ['administrador', 'superadministrador'], 'usuarios.gestionar'],
        'carga-inicial' => ['carga-inicial.md', 'Carga inicial del piloto', ['coordinacion_administrativa', 'grupo_recaudo', 'administrador'], null],
        'operacion' => ['operacion.md', 'Guía de operación', ['superadministrador'], 'sistema.salud'],
    ];

    public function __construct(Vista $vista, private readonly string $directorio = RAIZ . '/docs/guias')
    {
        parent::__construct($vista);
    }

    public function indice(Solicitud $solicitud): Respuesta
    {
        $usuario = AutorizacionRecurso::usuario($solicitud);
        $propias = [];
        $otras = [];
        foreach ($this->visibles($solicitud) as $clave => [, $titulo, $roles]) {
            $entrada = ['clave' => $clave, 'titulo' => $titulo];
            if ($roles === [] || array_intersect($roles, $usuario->roles) !== []) {
                $propias[] = $entrada;
            } else {
                $otras[] = $entrada;
            }
        }
        return $this->pantalla($solicitud, 'ayuda/indice', ['propias' => $propias, 'otras' => $otras], $this->meta('Ayuda', 'Guías de uso por rol. Cada una se puede imprimir o guardar en PDF.'));
    }

    public function guia(Solicitud $solicitud, string $clave): Respuesta
    {
        [$archivo, $titulo] = $this->visibles($solicitud)[$clave] ?? throw new ErrorHttp(404);
        $ruta = $this->directorio . '/' . $archivo;
        if (!is_file($ruta)) {
            throw new ErrorHttp(404);
        }
        $markdown = new Markdown(static fn (string $guia): string => Url::a('/ayuda/' . self::claveDeArchivo($guia)));
        // El título del archivo (# …) ya lo muestra el encabezado de la página.
        $texto = (string) preg_replace('/\A#\s+[^\n]*\n/', '', (string) file_get_contents($ruta));
        $html = $markdown->html($texto);
        return $this->pantalla($solicitud, 'ayuda/guia', ['html' => $html, 'indice' => $markdown->titulos(), 'clave' => $clave], $this->meta($titulo, '', true));
    }

    /** @return array<string,array{0:string,1:string,2:list<string>,3:?string}> */
    private function visibles(Solicitud $solicitud): array
    {
        $usuario = AutorizacionRecurso::usuario($solicitud);
        return array_filter(self::GUIAS, static fn (array $g): bool => $g[3] === null || $usuario->tienePermiso($g[3]) || $usuario->esSuperadministrador());
    }

    private static function claveDeArchivo(string $archivo): string
    {
        foreach (self::GUIAS as $clave => [$nombre]) {
            if ($nombre === $archivo . '.md') {
                return $clave;
            }
        }
        return $archivo;
    }

    /** @return array<string,mixed> */
    private function meta(string $titulo, string $descripcion = '', bool $guia = false): array
    {
        return [
            'titulo' => $titulo, 'sobretitulo' => 'Ayuda', 'descripcion' => $descripcion,
            'migas' => $guia ? [['texto' => 'Ayuda', 'url' => '/ayuda'], ['texto' => $titulo]] : [['texto' => 'Ayuda']],
        ];
    }
}
