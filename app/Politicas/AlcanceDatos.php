<?php

declare(strict_types=1);

namespace App\Politicas;

/**
 * Alcance de datos de la solicitud, construido solo en el servidor a partir de la sesión (RDI-003, RDI-005).
 * Un alcance sin unidades no significa «todas»: significa «ninguna» (denegación por defecto, RDI-004).
 */
final class AlcanceDatos
{
    /** @param list<int> $unidades */
    private function __construct(
        public readonly int $centroId,
        private readonly array $unidades,
        public readonly bool $todoElCentro,
    ) {
    }

    /** @param list<int> $unidadesAsignadas */
    public static function deUsuario(int $centroId, array $unidadesAsignadas, bool $rolConAlcanceDeCentro): self
    {
        $unidades = array_values(array_unique(array_map('intval', $unidadesAsignadas)));
        return new self($centroId, $unidades, $rolConAlcanceDeCentro);
    }

    public function permiteUnidad(?int $unidadId): bool
    {
        if ($unidadId === null) {
            return $this->todoElCentro;
        }
        return $this->todoElCentro || in_array($unidadId, $this->unidades, true);
    }

    /** @return list<int> */
    public function unidades(): array
    {
        return $this->unidades;
    }

    public function estaVacio(): bool
    {
        return !$this->todoElCentro && $this->unidades === [];
    }

    /** Huella estable para claves de caché (RDI-008). */
    public function huella(): string
    {
        $unidades = $this->unidades;
        sort($unidades);
        return hash('sha256', $this->centroId . '|' . ($this->todoElCentro ? '*' : implode(',', $unidades)));
    }

    /**
     * Condición SQL obligatoria y sus parámetros. El alias proviene del código del repositorio, nunca de la
     * entrada. Un alcance vacío produce una condición falsa, jamás una consulta sin filtro.
     *
     * @return array{0:string,1:array<string,int>}
     */
    public function condicionSql(string $alias): array
    {
        if ($this->estaVacio()) {
            return ['1 = 0', []];
        }
        $parametros = ['alcance_centro' => $this->centroId];
        if ($this->todoElCentro) {
            return ["{$alias}.centro_id = :alcance_centro", $parametros];
        }
        $marcadores = [];
        foreach ($this->unidades as $i => $unidad) {
            $marcadores[] = ":alcance_unidad_{$i}";
            $parametros["alcance_unidad_{$i}"] = $unidad;
        }
        return ["{$alias}.centro_id = :alcance_centro AND {$alias}.unidad_id IN (" . implode(', ', $marcadores) . ')', $parametros];
    }
}
