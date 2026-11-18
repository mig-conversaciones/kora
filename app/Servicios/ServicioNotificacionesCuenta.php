<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Cola\DespachadorCola;
use App\Nucleo\Cifrador;
use App\Nucleo\Url;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Correos de seguridad de la cuenta (Anexo I): invitación, recuperación, contraseña cambiada y segundo factor. Se
 * encolan (nunca se envían en la solicitud) y el destinatario viaja como id. Los datos que llevan un enlace con
 * token se cifran dentro de la carga del trabajo («datos_cifrados»), de modo que la tabla trabajos no guarda el
 * token en claro (RAU-007); EnviarCorreo los descifra al enviar.
 */
final class ServicioNotificacionesCuenta
{
    public const CONTEXTO_CIFRADO = 'correo.datos';

    public function __construct(private readonly DespachadorCola $despachador, private readonly Cifrador $cifrador, private readonly string $origen)
    {
    }

    public function invitacion(int $usuarioId, string $token, string $rol, bool $requiere2fa): void
    {
        $this->encolar($usuarioId, 'invitacion', [
            'enlace' => $this->enlace('/cuenta/activar', $token),
            'rol' => $rol,
            'requiere_2fa' => $requiere2fa,
        ], true);
    }

    public function recuperacion(int $usuarioId, string $token): void
    {
        $this->encolar($usuarioId, 'recuperacion', ['enlace' => $this->enlace('/cuenta/restablecer', $token)], true);
    }

    public function contrasenaCambiada(int $usuarioId): void
    {
        $this->encolar($usuarioId, 'contrasena_cambiada', [
            'fecha' => self::ahoraBogota(),
            'enlace_recuperar' => $this->origen . Url::a('/cuenta/recuperar'),
        ]);
    }

    public function segundoFactor(int $usuarioId, string $evento, ?int $codigosRestantes = null): void
    {
        $this->encolar($usuarioId, 'segundo_factor', ['fecha' => self::ahoraBogota(), 'evento' => $evento, 'codigos_restantes' => $codigosRestantes]);
    }

    /** @param array<string,mixed> $datos */
    private function encolar(int $usuarioId, string $plantilla, array $datos, bool $cifrar = false): void
    {
        $carga = ['plantilla' => $plantilla, 'destinatario_id' => $usuarioId];
        if ($cifrar) {
            $carga['datos_cifrados'] = $this->cifrador->cifrar(json_encode($datos, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), self::CONTEXTO_CIFRADO);
        } else {
            $carga['datos'] = $datos;
        }
        $this->despachador->encolar('correo.sistema', $carga);
    }

    private function enlace(string $ruta, string $token): string
    {
        // El token va en la consulta, no en la ruta: el registro técnico guarda la ruta sin la consulta (RAU-007).
        return $this->origen . Url::a($ruta) . '?token=' . rawurlencode($token);
    }

    private static function ahoraBogota(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('America/Bogota')))->format('d/m/Y \a \l\a\s H:i');
    }
}
