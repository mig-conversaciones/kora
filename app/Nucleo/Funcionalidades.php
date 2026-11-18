<?php

declare(strict_types=1);

namespace App\Nucleo;

/**
 * Funcionalidades configurables (ROB-007). El valor de config/funcionalidades.php se puede sobrescribir en el
 * .env con FUNCIONALIDAD_<CLAVE>=true|false. Una clave desconocida se considera inactiva (denegación por defecto).
 */
final class Funcionalidades
{
    /** @param array<string,array{activa:bool,descripcion:string,responsable:string,creada:string,retiro_previsto:?string}> $definiciones */
    public function __construct(private readonly array $definiciones)
    {
    }

    public function activa(string $clave): bool
    {
        if (!isset($this->definiciones[$clave])) {
            return false;
        }
        return Entorno::booleano('FUNCIONALIDAD_' . strtoupper($clave), $this->definiciones[$clave]['activa']);
    }

    /** @return array<string,array{activa:bool,descripcion:string,responsable:string,creada:string,retiro_previsto:?string}> */
    public function todas(): array
    {
        $resultado = [];
        foreach ($this->definiciones as $clave => $definicion) {
            $resultado[$clave] = ['activa' => $this->activa($clave)] + $definicion;
        }
        return $resultado;
    }
}
