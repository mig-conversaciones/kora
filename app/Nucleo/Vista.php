<?php

declare(strict_types=1);

namespace App\Nucleo;

use RuntimeException;
use Throwable;

/**
 * Motor de plantillas PHP nativas. Las vistas solo presentan y escapan (RNF-011): dentro de una plantilla $this es
 * esta clase y ofrece e() (escape), url() (ruta base), asset() (nombre con huella según manifest.json) y parcial().
 * pagina() envuelve el contenido en diseno/base.php; fragmento() devuelve solo el HTML que HTMX intercambia (RI-003).
 */
final class Vista
{
    /** @var array<string,string>|null */
    private ?array $manifiesto = null;

    /** @var array<string,mixed> datos compartidos por todas las plantillas de la solicitud */
    private array $compartidos = [];

    /** Solicitud que se está renderizando (para el token CSRF de los formularios). */
    private ?Solicitud $solicitud = null;

    /**
     * @param array{directorio:string,manifiesto:string,nombre_app:string,entorno:string,menu:list<array<string,mixed>>,config_htmx:array<string,mixed>} $config
     */
    public function __construct(private readonly array $config)
    {
    }

    /** @param array<string,mixed> $datos */
    public function fragmento(string $nombre, array $datos = []): string
    {
        if (($datos['solicitud'] ?? null) instanceof Solicitud) {
            $this->solicitud = $datos['solicitud'];
        }
        return $this->renderizar($nombre, $datos);
    }

    /**
     * Página completa: la plantilla de página recibe $contenidoFragmento (el HTML ya renderizado de su parte
     * intercambiable, el mismo que devolvería fragmento()) y el resultado se inserta en la plantilla base con la
     * configuración de htmx, el nonce y el menú.
     *
     * @param array<string,mixed> $datos
     */
    public function pagina(string $pagina, string $fragmento, array $datos = []): string
    {
        $solicitud = $datos['solicitud'] ?? null;
        if ($solicitud instanceof Solicitud) {
            $this->solicitud = $solicitud;
        }
        $contenidoFragmento = $pagina === $fragmento ? '' : $this->renderizar($fragmento, $datos);
        $contenido = $this->renderizar($pagina, $datos + ['fragmento' => $fragmento, 'contenidoFragmento' => $contenidoFragmento]);
        $nonce = $solicitud instanceof Solicitud ? (string) $solicitud->atributo('nonce', '') : '';
        $configHtmx = $this->config['config_htmx'] + ['inlineScriptNonce' => $nonce, 'inlineStyleNonce' => $nonce];
        $usuario = $solicitud instanceof Solicitud ? $solicitud->atributo('usuario') : null;
        $usuario = $usuario instanceof \App\Politicas\UsuarioSesion ? $usuario : null;
        $sesion = $solicitud instanceof Solicitud ? $solicitud->atributo('sesion') : null;
        $diseno = (string) ($datos['diseno'] ?? 'base');
        return $this->renderizar('diseno/' . ($diseno === 'acceso' ? 'acceso' : 'base'), [
            'titulo' => (string) ($datos['titulo'] ?? $this->config['nombre_app']),
            'contenido' => $contenido,
            'nonce' => $nonce,
            'csrf' => $this->csrf(),
            'configHtmx' => $configHtmx,
            'menu' => $this->menuPara($usuario),
            'rutaActual' => $solicitud instanceof Solicitud ? $solicitud->ruta : '/',
            'migas' => $datos['migas'] ?? [],
            'usuario' => $usuario,
            'preferencias' => $usuario->preferencias ?? [],
            'entorno' => $this->config['entorno'],
            'sesionExpiraEn' => $sesion instanceof Sesion ? $sesion->segundosHastaExpirar() : 0,
            'mantenimientoActivo' => $solicitud instanceof Solicitud && $solicitud->atributo('mantenimiento') !== null,
        ]);
    }

    /**
     * Token CSRF de la sesión de la solicitud actual (RS-005), para el campo oculto _csrf de los formularios que no
     * usan HTMX. Crea una sesión anónima si hace falta. Vacío fuera de una solicitud web.
     */
    public function csrf(): string
    {
        $sesion = $this->solicitud?->atributo('sesion');
        return $sesion instanceof Sesion ? $sesion->tokenCsrf() : '';
    }

    /**
     * Menú calculado en el servidor a partir de los permisos (RF-002): una entrada con «permiso» solo aparece si el
     * usuario lo tiene; las entradas aún no entregadas («iteracion») se muestran deshabilitadas a todos.
     *
     * @return list<array<string,mixed>>
     */
    private function menuPara(?\App\Politicas\UsuarioSesion $usuario): array
    {
        $menu = [];
        foreach ($this->config['menu'] as $grupo) {
            $entradas = array_values(array_filter(
                $grupo['entradas'],
                static function (array $e) use ($usuario): bool {
                    if (!empty($e['autenticada']) && $usuario === null) {
                        return false;
                    }
                    if (!isset($e['permiso'])) {
                        return true;
                    }
                    // «permiso» puede ser una lista: basta con tener cualquiera de ellos.
                    foreach ((array) $e['permiso'] as $permiso) {
                        if ($usuario !== null && $usuario->tienePermiso((string) $permiso)) {
                            return true;
                        }
                    }
                    return false;
                }
            ));
            if ($entradas !== []) {
                $menu[] = ['grupo' => $grupo['grupo'], 'entradas' => $entradas];
            }
        }
        return $menu;
    }

    /** @param array<string,mixed> $datos */
    public function parcial(string $nombre, array $datos = []): string
    {
        return $this->renderizar($nombre, $datos);
    }

    /** @param array<string,mixed> $datos */
    public function compartir(array $datos): void
    {
        $this->compartidos = $datos + $this->compartidos;
    }

    /** Escape para contexto HTML de texto y de atributo entre comillas. */
    public function e(mixed $valor): string
    {
        if ($valor === null || is_array($valor) || (is_object($valor) && !method_exists($valor, '__toString'))) {
            return '';
        }
        return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** Pesos colombianos sin decimales y con separador de miles «.» (RUI-009): 3.045.000. */
    public function moneda(mixed $valor): string
    {
        return '$ ' . number_format((float) $valor, 0, ',', '.');
    }

    /** Número con coma decimal y los decimales indicados, sin ceros de relleno a la derecha más allá del mínimo. */
    public function decimal(mixed $valor, int $decimales = 2): string
    {
        return number_format((float) $valor, $decimales, ',', '.');
    }

    /** Cantidad física (hasta 3 decimales) sin ceros de relleno: «1234.500» → «1.234,5». No pasa por float. */
    public function cantidad(mixed $valor): string
    {
        $texto = (string) $valor;
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $texto, $m) !== 1) {
            return $texto;
        }
        $fraccion = rtrim($m[3] ?? '', '0');
        return $m[1] . number_format((float) $m[2], 0, ',', '.') . ($fraccion === '' ? '' : ',' . $fraccion);
    }

    /** Fecha DATE o DATETIME (AAAA-MM-DD…) en formato DD/MM/AAAA. */
    public function fecha(mixed $valor): string
    {
        $texto = (string) $valor;
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $texto, $m) === 1 ? "{$m[3]}/{$m[2]}/{$m[1]}" : $texto;
    }

    public function url(string $ruta): string
    {
        return Url::a($ruta);
    }

    /** URL pública de un recurso construido (css/app.css → /assets/css/app.3f9a1c2b.css). */
    public function asset(string $ruta): string
    {
        $manifiesto = $this->manifiesto();
        return Url::a('/assets/' . ($manifiesto[$ruta] ?? $ruta));
    }

    public function nombreApp(): string
    {
        return $this->config['nombre_app'];
    }

    /** @return array<string,string> */
    private function manifiesto(): array
    {
        if ($this->manifiesto === null) {
            $contenido = is_file($this->config['manifiesto']) ? (string) file_get_contents($this->config['manifiesto']) : '{}';
            $datos = json_decode($contenido, true);
            $this->manifiesto = is_array($datos) ? array_map('strval', $datos) : [];
        }
        return $this->manifiesto;
    }

    /** @param array<string,mixed> $datos */
    private function renderizar(string $nombre, array $datos): string
    {
        if (preg_match('#^[a-z0-9_]+(/[a-z0-9_]+)*$#', $nombre) !== 1) {
            throw new RuntimeException("Nombre de vista no válido: {$nombre}");
        }
        $archivo = $this->config['directorio'] . '/' . $nombre . '.php';
        if (!is_file($archivo)) {
            throw new RuntimeException("No existe la vista {$nombre}.");
        }
        $datos += $this->compartidos;
        $nivel = ob_get_level();
        ob_start();
        try {
            (function (string $__archivo, array $__datos): void {
                extract($__datos, EXTR_SKIP);
                require $__archivo;
            })->call($this, $archivo, $datos);
        } catch (Throwable $error) {
            while (ob_get_level() > $nivel) {
                ob_end_clean();
            }
            throw $error;
        }
        return (string) ob_get_clean();
    }
}
