<?php

declare(strict_types=1);

namespace App\Politicas;

/**
 * Autorizador central (RAC-023, RAC-024, RAC-027). Denegación por defecto: sin usuario, sin permiso o con un
 * permiso desconocido, la respuesta es «denegado». Si el recurso del permiso (prefijo antes del punto) tiene una
 * política registrada, ella decide las condiciones ABAC; si no, rige solo el RBAC.
 */
final class Autorizador
{
    /** @param array<string,Politica> $politicas prefijo de permiso => política */
    public function __construct(private readonly array $politicas = [])
    {
    }

    public function decidir(?UsuarioSesion $usuario, string $permiso, ?object $recurso = null, ?AlcanceDatos $alcance = null): Decision
    {
        if ($usuario === null || $permiso === '') {
            return Decision::denegar('Sin usuario autenticado o sin permiso declarado.');
        }
        $prefijo = strstr($permiso, '.', true) ?: $permiso;
        $politica = $this->politicas[$prefijo] ?? null;
        if ($politica !== null) {
            return $politica->decidir($usuario, $permiso, $recurso, $alcance ?? AlcanceDatos::deUsuario(0, [], false));
        }
        return $usuario->tienePermiso($permiso) ? Decision::permitir() : Decision::denegar('Permiso no asignado al rol.');
    }
}
