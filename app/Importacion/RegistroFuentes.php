<?php

declare(strict_types=1);

namespace App\Importacion;

use App\Importacion\Fuentes\FuenteCatalogo;
use App\Importacion\Fuentes\FuenteCupos;
use App\Importacion\Fuentes\FuenteGastos;
use App\Importacion\Fuentes\FuenteHato;
use App\Importacion\Fuentes\FuentePresupuesto;
use App\Importacion\Fuentes\FuenteProduccion;
use App\Importacion\Fuentes\FuenteRecaudosDg;
use App\Importacion\Fuentes\FuenteVentas;

/**
 * Las ocho fuentes de importación (RF-083), en el orden recomendado de aplicación: primero los maestros (catálogo,
 * presupuesto, cupos) y después la operación (producción antes que ventas, para que las salidas encuentren su lote).
 */
final class RegistroFuentes
{
    /** Nombre de la hoja de cada plantilla descargable. */
    public const HOJAS_PLANTILLA = [
        'catalogo_productos' => 'CatalogoProductos_CAB', 'presupuesto' => 'Ingresos - Gastos', 'cupos_autoconsumo' => 'Autoconsumo_2026',
        'recaudos_dg' => 'Ingresos_Centro', 'base_produccion' => 'Base Produccion Centro', 'base_ventas' => 'Base Ventas',
        'estado_reproductivo' => 'ESTADO REP', 'gastos_ejecutados' => 'Gastos',
    ];

    /** @var array<string,FuenteImportacion> */
    private array $fuentes = [];

    public function __construct()
    {
        foreach ([new FuenteCatalogo(), new FuentePresupuesto(), new FuenteCupos(), new FuenteRecaudosDg(), new FuenteProduccion(), new FuenteVentas(), new FuenteHato(), new FuenteGastos()] as $fuente) {
            $this->fuentes[$fuente->clave()] = $fuente;
        }
    }

    /** @return array<string,FuenteImportacion> */
    public function todas(): array
    {
        return $this->fuentes;
    }

    public function fuente(string $clave): FuenteImportacion
    {
        return $this->fuentes[$clave] ?? throw new \InvalidArgumentException("Fuente desconocida: {$clave}.");
    }

    /**
     * Fuentes reconocidas en las hojas del libro: por el nombre de la hoja y porque sus columnas obligatorias
     * aparecen en una de las 10 primeras filas.
     *
     * @param list<array{nombre:string,visible:bool,filas:int,encabezados:list<list<string>>}> $hojas
     * @return list<array{fuente:FuenteImportacion,hoja:string,filas:int,fila_encabezado:int}>
     */
    public function detectar(array $hojas): array
    {
        $detectadas = [];
        foreach ($hojas as $hoja) {
            $nombre = Normalizador::encabezado($hoja['nombre']);
            foreach ($this->fuentes as $fuente) {
                if (preg_match($fuente->patronHoja(), $nombre) !== 1) {
                    continue;
                }
                foreach ($hoja['encabezados'] as $indice => $encabezados) {
                    if (self::mapear($fuente, $encabezados)['faltantes'] === []) {
                        $detectadas[] = ['fuente' => $fuente, 'hoja' => $hoja['nombre'], 'filas' => $hoja['filas'], 'fila_encabezado' => $indice + 1];
                        break;
                    }
                }
            }
        }
        return $detectadas;
    }

    /**
     * Mapea los campos de la fuente a las columnas: primero por coincidencia exacta del alias y después por inclusión.
     *
     * @param list<string> $encabezados ya normalizados
     * @return array{mapa:array<string,int>,faltantes:list<string>}
     */
    public static function mapear(FuenteImportacion $fuente, array $encabezados): array
    {
        $mapa = [];
        $usadas = [];
        foreach ([true, false] as $exacta) {
            foreach ($fuente->columnas() as $campo => $definicion) {
                if (isset($mapa[$campo])) {
                    continue;
                }
                foreach ($definicion['alias'] as $alias) {
                    foreach ($encabezados as $indice => $encabezado) {
                        if ($encabezado === '' || isset($usadas[$indice])) {
                            continue;
                        }
                        if ($exacta ? $encabezado === $alias : str_contains($encabezado, $alias)) {
                            $mapa[$campo] = $indice;
                            $usadas[$indice] = true;
                            break 2;
                        }
                    }
                }
            }
        }
        $faltantes = [];
        foreach ($fuente->columnas() as $campo => $definicion) {
            if ($definicion['obligatoria'] && !isset($mapa[$campo])) {
                $faltantes[] = $campo;
            }
        }
        return ['mapa' => $mapa, 'faltantes' => $faltantes];
    }
}
