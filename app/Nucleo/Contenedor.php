<?php

declare(strict_types=1);

namespace App\Nucleo;

use LogicException;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Contenedor de dependencias mínimo. Toda clase se resuelve una sola vez por solicitud. Las que necesitan valores
 * escalares (configuración, claves) se declaran como fábricas en config/app.php ('servicios'); el resto se
 * construye por su constructor, resolviendo cada parámetro tipado con una clase (autowiring).
 */
final class Contenedor
{
    /** @var array<string,object> */
    private array $instancias = [];

    /** @param array<string,callable(self):object> $fabricas */
    public function __construct(private readonly array $fabricas = [])
    {
        $this->instancias[self::class] = $this;
    }

    /** @param array<string,mixed> $config config/app.php */
    public static function desdeConfiguracion(array $config): self
    {
        /** @var array<string,callable(self):object> $fabricas */
        $fabricas = $config['servicios'] ?? [];
        return new self($fabricas);
    }

    /**
     * @template T of object
     * @param class-string<T> $clase
     * @return T
     */
    public function obtener(string $clase): object
    {
        if (!isset($this->instancias[$clase])) {
            $this->instancias[$clase] = isset($this->fabricas[$clase])
                ? ($this->fabricas[$clase])($this)
                : $this->construir($clase);
        }
        /** @var T */
        return $this->instancias[$clase];
    }

    /** Registra una instancia ya construida (pruebas o arranque). */
    public function establecer(string $clase, object $instancia): void
    {
        $this->instancias[$clase] = $instancia;
    }

    /**
     * @param class-string $clase
     */
    private function construir(string $clase): object
    {
        if (!class_exists($clase)) {
            throw new LogicException("La clase {$clase} no existe.");
        }
        $reflexion = new ReflectionClass($clase);
        if (!$reflexion->isInstantiable()) {
            throw new LogicException("La clase {$clase} no se puede instanciar.");
        }
        $constructor = $reflexion->getConstructor();
        if ($constructor === null) {
            return new $clase();
        }
        $argumentos = [];
        foreach ($constructor->getParameters() as $parametro) {
            $tipo = $parametro->getType();
            if ($tipo instanceof ReflectionNamedType && !$tipo->isBuiltin()) {
                /** @var class-string $dependencia */
                $dependencia = $tipo->getName();
                $argumentos[] = $this->obtener($dependencia);
                continue;
            }
            if ($parametro->isDefaultValueAvailable()) {
                $argumentos[] = $parametro->getDefaultValue();
                continue;
            }
            throw new LogicException("No se puede resolver \${$parametro->getName()} de {$clase}: declara una fábrica en config/app.php.");
        }
        return $reflexion->newInstanceArgs($argumentos);
    }
}
