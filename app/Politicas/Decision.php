<?php

declare(strict_types=1);

namespace App\Politicas;

/** Resultado de una decisión de autorización (RAC-028): permitida, denegada (403) o fuera de alcance (404). */
final class Decision
{
    private function __construct(private readonly bool $permitida, private readonly bool $fueraDeAlcance, private readonly string $motivo)
    {
    }

    public static function permitir(): self
    {
        return new self(true, false, '');
    }

    public static function denegar(string $motivo): self
    {
        return new self(false, false, $motivo);
    }

    public static function fueraDeAlcance(): self
    {
        return new self(false, true, 'Recurso fuera del alcance del usuario.');
    }

    public function permitida(): bool
    {
        return $this->permitida;
    }

    public function fueraDeAlcanceDelUsuario(): bool
    {
        return $this->fueraDeAlcance;
    }

    public function motivo(): string
    {
        return $this->motivo;
    }
}
