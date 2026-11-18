<?php

declare(strict_types=1);

namespace App\Nucleo;

/**
 * Sesión del servidor sobre la tabla `sesiones` (ADR-006; RAC-016 a RAC-021).
 *
 * - El ID es opaco, de 256 bits, y solo viaja en la cookie HttpOnly; en la base se guarda su SHA-256.
 * - Nunca se acepta un ID que el servidor no emitió (equivale a session.use_strict_mode) ni por URL.
 * - La expiración por inactividad (30 min; 15 min para privilegiados) y la absoluta (12 h; 8 h) se evalúan en el
 *   servidor en cada solicitud. Las sesiones anónimas solo llevan el token CSRF de los formularios públicos.
 * - El ID se regenera al iniciar sesión, al completar la 2FA, al reautenticarse y al cambiar la contraseña o los
 *   permisos (RAC-018). Revocar = marcar revocada_en.
 *
 * Es una capa propia y no el manejador de sesiones de PHP: así la respuesta (Set-Cookie) pasa por App\Nucleo\Respuesta
 * y el flujo completo se prueba sin servidor web (ADR-006, evolución de la I2).
 */
final class Sesion
{
    private bool $cargada = false;
    private ?string $id = null;
    /** @var array<string,mixed>|null */
    private ?array $fila = null;
    /** @var array<string,mixed> */
    private array $datos = [];
    private bool $sucia = false;
    private bool $emitirCookie = false;
    private bool $borrarCookie = false;
    private string $ip = '';
    private string $agente = '';

    /**
     * @param array{inactividad:int,inactividad_privilegiados:int,absoluta:int,absoluta_privilegiados:int,inactividad_anonima:int} $tiempos
     * @param array{nombre:string,segura:bool,solo_http:bool,same_site:string,ruta:string} $cookie
     */
    public function __construct(
        private readonly BaseDatos $bd,
        private readonly array $tiempos,
        private readonly array $cookie,
        private readonly string $claveHmacIp = '',
    ) {
    }

    public function nombreCookie(): string
    {
        return $this->cookie['nombre'];
    }

    /** Carga la sesión de la cookie de la solicitud (una sola vez por solicitud). */
    public function cargar(Solicitud $solicitud): void
    {
        if ($this->cargada) {
            return;
        }
        $this->cargada = true;
        $this->ip = $solicitud->ip;
        $this->agente = mb_substr((string) $solicitud->cabecera('User-Agent'), 0, 255);
        $id = (string) ($solicitud->cookies[$this->cookie['nombre']] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/', $id) !== 1) {
            return;
        }
        $fila = $this->bd->fila(
            'SELECT id, usuario_id, datos, nivel_autenticacion, ultima_actividad_en, expira_absoluta_en, reautenticada_en,
                    TIMESTAMPDIFF(SECOND, ultima_actividad_en, UTC_TIMESTAMP(3)) AS inactiva_segundos,
                    TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(3), expira_absoluta_en) AS restante_absoluta
               FROM sesiones WHERE id = :id AND revocada_en IS NULL',
            ['id' => hash('sha256', $id)]
        );
        if ($fila === null) {
            $this->borrarCookie = true;
            return;
        }
        $datos = json_decode((string) $fila['datos'], true);
        $datos = is_array($datos) ? $datos : [];
        $limite = $this->limiteInactividad($fila['usuario_id'] === null ? null : (bool) ($datos['_privilegiada'] ?? false));
        if ((int) $fila['inactiva_segundos'] > $limite || (int) $fila['restante_absoluta'] <= 0) {
            $this->bd->ejecutar('UPDATE sesiones SET revocada_en = UTC_TIMESTAMP(3) WHERE id = :id', ['id' => $fila['id']]);
            $this->borrarCookie = true;
            return;
        }
        $this->id = $id;
        $this->fila = $fila;
        $this->datos = $datos;
    }

    public function existe(): bool
    {
        return $this->id !== null;
    }

    public function usuarioId(): ?int
    {
        return $this->fila !== null && $this->fila['usuario_id'] !== null ? (int) $this->fila['usuario_id'] : null;
    }

    /** anonimo | contrasena | 2fa */
    public function nivel(): string
    {
        return (string) ($this->fila['nivel_autenticacion'] ?? 'anonimo');
    }

    /** Segundos desde la última reautenticación con el segundo factor o la contraseña; null si nunca. */
    public function segundosDesdeReautenticacion(): ?int
    {
        $marca = $this->fila['reautenticada_en'] ?? null;
        if ($marca === null) {
            return null;
        }
        return time() - (int) strtotime($marca . ' UTC');
    }

    /** Segundos hasta la expiración por inactividad (para el aviso de RAC-019); 0 sin sesión autenticada. */
    public function segundosHastaExpirar(): int
    {
        if ($this->usuarioId() === null) {
            return 0;
        }
        $restanteAbsoluta = (int) ($this->fila['restante_absoluta'] ?? 0);
        return max(0, min($this->limiteInactividad((bool) ($this->datos['_privilegiada'] ?? false)), $restanteAbsoluta));
    }

    public function obtener(string $clave, mixed $defecto = null): mixed
    {
        return $this->datos[$clave] ?? $defecto;
    }

    public function guardar(string $clave, mixed $valor): void
    {
        $this->asegurar();
        $this->datos[$clave] = $valor;
        $this->sucia = true;
    }

    public function olvidar(string $clave): void
    {
        if (array_key_exists($clave, $this->datos)) {
            unset($this->datos[$clave]);
            $this->sucia = true;
        }
    }

    /** Token sincronizador de CSRF (RS-005). Crea una sesión anónima si no existe. */
    public function tokenCsrf(): string
    {
        $token = $this->datos['_csrf'] ?? null;
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->guardar('_csrf', $token);
        }
        return $token;
    }

    /**
     * Abre la sesión autenticada con un ID nuevo (RAC-018). El ID anterior (anónimo) queda revocado.
     */
    public function iniciarAutenticada(int $usuarioId, string $nivel, bool $privilegiada): void
    {
        $datos = ['_privilegiada' => $privilegiada];
        $this->revocarActual();
        $this->crear($usuarioId, $nivel, $datos, $privilegiada);
    }

    /** Eleva el nivel (contraseña → 2fa) con un ID nuevo y marca la reautenticación. */
    public function elevar(string $nivel): void
    {
        $this->regenerar();
        $this->bd->ejecutar(
            'UPDATE sesiones SET nivel_autenticacion = :nivel, reautenticada_en = UTC_TIMESTAMP(3) WHERE id = :id',
            ['nivel' => $nivel, 'id' => hash('sha256', (string) $this->id)]
        );
        $this->fila['nivel_autenticacion'] = $nivel;
        $this->fila['reautenticada_en'] = gmdate('Y-m-d H:i:s');
    }

    /** Reautenticación reciente para operaciones sensibles (RAC-026). */
    public function marcarReautenticada(): void
    {
        $this->regenerar();
        $this->bd->ejecutar('UPDATE sesiones SET reautenticada_en = UTC_TIMESTAMP(3) WHERE id = :id', ['id' => hash('sha256', (string) $this->id)]);
        $this->fila['reautenticada_en'] = gmdate('Y-m-d H:i:s');
    }

    /** Nuevo ID con los mismos datos y metadatos; el anterior queda revocado (RAC-018). */
    public function regenerar(): void
    {
        if ($this->id === null || $this->fila === null) {
            return;
        }
        $anterior = hash('sha256', $this->id);
        $nuevo = bin2hex(random_bytes(32));
        $this->datos['_csrf'] = bin2hex(random_bytes(32));
        $this->bd->transaccion(function (BaseDatos $bd) use ($anterior, $nuevo): void {
            $bd->ejecutar(
                'INSERT INTO sesiones (id, usuario_id, datos, nivel_autenticacion, creada_en, ultima_actividad_en, expira_absoluta_en,
                                       reautenticada_en, ip_tratada, ip_hmac, agente)
                 SELECT :nuevo, usuario_id, :datos, nivel_autenticacion, creada_en, UTC_TIMESTAMP(3), expira_absoluta_en,
                        reautenticada_en, ip_tratada, ip_hmac, agente
                   FROM sesiones WHERE id = :anterior',
                ['nuevo' => hash('sha256', $nuevo), 'datos' => $this->datosJson(), 'anterior' => $anterior]
            );
            $bd->ejecutar('UPDATE sesiones SET revocada_en = UTC_TIMESTAMP(3) WHERE id = :anterior', ['anterior' => $anterior]);
        });
        $this->id = $nuevo;
        $this->fila['id'] = hash('sha256', $nuevo);
        $this->sucia = false;
        $this->emitirCookie = true;
    }

    /** Cierra la sesión actual (RAC-020). */
    public function cerrar(): void
    {
        $this->revocarActual();
        $this->id = null;
        $this->fila = null;
        $this->datos = [];
        $this->sucia = false;
        $this->emitirCookie = false;
        $this->borrarCookie = true;
    }

    /** Revoca las demás sesiones del usuario («cerrar todas las demás», RAC-020). */
    public function revocarOtras(int $usuarioId): int
    {
        return $this->bd->ejecutar(
            'UPDATE sesiones SET revocada_en = UTC_TIMESTAMP(3) WHERE usuario_id = :usuario AND revocada_en IS NULL AND id <> :actual',
            ['usuario' => $usuarioId, 'actual' => $this->id === null ? '' : hash('sha256', $this->id)]
        );
    }

    /** Huella de la sesión actual (SHA-256 del ID) para marcarla en el listado de sesiones. */
    public function huella(): ?string
    {
        return $this->id === null ? null : hash('sha256', $this->id);
    }

    /**
     * Guarda los cambios y agrega la cookie y la cabecera X-Sesion-Expira-En a la respuesta.
     */
    public function persistir(Respuesta $respuesta): Respuesta
    {
        if ($this->id !== null) {
            $this->bd->ejecutar(
                $this->sucia
                    ? 'UPDATE sesiones SET datos = :datos, ultima_actividad_en = UTC_TIMESTAMP(3) WHERE id = :id AND revocada_en IS NULL'
                    : 'UPDATE sesiones SET ultima_actividad_en = UTC_TIMESTAMP(3) WHERE id = :id AND revocada_en IS NULL',
                $this->sucia ? ['datos' => $this->datosJson(), 'id' => hash('sha256', $this->id)] : ['id' => hash('sha256', $this->id)]
            );
            $this->sucia = false;
            if ($this->usuarioId() !== null) {
                $respuesta->conCabecera('X-Sesion-Expira-En', (string) $this->segundosHastaExpirar());
            }
        }
        if ($this->emitirCookie && $this->id !== null) {
            $respuesta->agregarCookie($this->cabeceraCookie($this->id, 0));
        } elseif ($this->borrarCookie) {
            $respuesta->agregarCookie($this->cabeceraCookie('', -1));
        }
        $this->emitirCookie = false;
        $this->borrarCookie = false;
        return $respuesta;
    }

    /** Crea una sesión anónima si aún no hay una (formularios públicos con CSRF). */
    private function asegurar(): void
    {
        if ($this->id === null) {
            $this->crear(null, 'anonimo', $this->datos, false);
        }
    }

    /** @param array<string,mixed> $datos */
    private function crear(?int $usuarioId, string $nivel, array $datos, bool $privilegiada): void
    {
        $id = bin2hex(random_bytes(32));
        $absoluta = $usuarioId === null
            ? $this->tiempos['absoluta']
            : ($privilegiada ? $this->tiempos['absoluta_privilegiados'] : $this->tiempos['absoluta']);
        $datos['_csrf'] = bin2hex(random_bytes(32));
        $this->datos = $datos;
        $this->bd->ejecutar(
            'INSERT INTO sesiones (id, usuario_id, datos, nivel_autenticacion, creada_en, ultima_actividad_en, expira_absoluta_en, ip_tratada, ip_hmac, agente)
             VALUES (:id, :usuario, :datos, :nivel, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), UTC_TIMESTAMP(3) + INTERVAL :absoluta SECOND, :ip, :ip_hmac, :agente)',
            [
                'id' => hash('sha256', $id),
                'usuario' => $usuarioId,
                'datos' => $this->datosJson(),
                'nivel' => $nivel,
                'absoluta' => $absoluta,
                'ip' => \App\Servicios\ServicioAuditoria::tratarIp($this->ip),
                'ip_hmac' => $this->ip === '' || $this->claveHmacIp === '' ? null : hash_hmac('sha256', $this->ip, $this->claveHmacIp),
                'agente' => $this->agente === '' ? null : $this->agente,
            ]
        );
        $this->id = $id;
        $this->fila = [
            'id' => hash('sha256', $id),
            'usuario_id' => $usuarioId,
            'nivel_autenticacion' => $nivel,
            'reautenticada_en' => null,
            'restante_absoluta' => $absoluta,
        ];
        $this->sucia = false;
        $this->emitirCookie = true;
        $this->borrarCookie = false;
    }

    private function revocarActual(): void
    {
        if ($this->id !== null) {
            $this->bd->ejecutar('UPDATE sesiones SET revocada_en = UTC_TIMESTAMP(3) WHERE id = :id', ['id' => hash('sha256', $this->id)]);
        }
    }

    private function limiteInactividad(?bool $privilegiada): int
    {
        if ($privilegiada === null) {
            return $this->tiempos['inactividad_anonima'];
        }
        return $privilegiada ? $this->tiempos['inactividad_privilegiados'] : $this->tiempos['inactividad'];
    }

    private function datosJson(): string
    {
        return json_encode($this->datos, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function cabeceraCookie(string $valor, int $maxAge): string
    {
        $partes = [$this->cookie['nombre'] . '=' . $valor, 'Path=' . $this->cookie['ruta'], 'SameSite=' . $this->cookie['same_site']];
        if ($maxAge < 0) {
            $partes[] = 'Max-Age=0';
            $partes[] = 'Expires=Thu, 01 Jan 1970 00:00:00 GMT';
        }
        if ($this->cookie['solo_http']) {
            $partes[] = 'HttpOnly';
        }
        if ($this->cookie['segura']) {
            $partes[] = 'Secure';
        }
        return implode('; ', $partes);
    }
}
