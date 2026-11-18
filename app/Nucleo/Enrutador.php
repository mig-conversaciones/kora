<?php

declare(strict_types=1);

namespace App\Nucleo;

use LogicException;

/**
 * Enrutamiento manual. Cada ruta declara el permiso que su política debe verificar; una ruta sin permiso y
 * no marcada como pública se rechaza al registrarla (denegación por defecto, RAC-023).
 */
final class Enrutador
{
    /** @var list<array{metodo:string,regex:string,parametros:list<string>,accion:array{0:class-string,1:string},permiso:string,opciones:array<string,mixed>}> */
    private array $rutas = [];

    /**
     * @param array{0:class-string,1:string} $accion
     * @param array<string,mixed> $opciones publica, exceptuadaMantenimiento, reautenticacion, funcionalidad
     */
    public function agregar(string $metodo, string $patron, array $accion, string $permiso, array $opciones = []): void
    {
        // Denegación por defecto (RAC-023): sin permiso, la ruta debe declararse pública o «autenticada» (cualquier
        // usuario con sesión completa, para su propia cuenta).
        if ($permiso === '' && empty($opciones['publica']) && empty($opciones['autenticada'])) {
            throw new LogicException("La ruta {$metodo} {$patron} no declara permiso.");
        }
        preg_match_all('/\{(\w+)\}/', $patron, $nombres);
        $this->rutas[] = [
            'metodo' => strtoupper($metodo),
            'regex' => '#^' . preg_replace('/\{(\w+)\}/', '(?P<$1>[A-Za-z0-9_-]+)', $patron) . '$#',
            'parametros' => $nombres[1],
            'accion' => $accion,
            'permiso' => $permiso,
            'opciones' => $opciones,
        ];
    }

    /** @return array{accion:array{0:class-string,1:string},permiso:string,parametros:array<string,string>,opciones:array<string,mixed>} */
    public function resolver(string $metodo, string $ruta): array
    {
        $existeConOtroMetodo = false;
        foreach ($this->rutas as $definicion) {
            if (preg_match($definicion['regex'], $ruta, $coincidencias) !== 1) {
                continue;
            }
            if ($definicion['metodo'] !== strtoupper($metodo)) {
                $existeConOtroMetodo = true;
                continue;
            }
            return [
                'accion' => $definicion['accion'],
                'permiso' => $definicion['permiso'],
                'parametros' => array_intersect_key($coincidencias, array_flip($definicion['parametros'])),
                'opciones' => $definicion['opciones'],
            ];
        }
        throw new ErrorHttp($existeConOtroMetodo ? 405 : 404);
    }
}
