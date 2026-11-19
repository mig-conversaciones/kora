<?php

declare(strict_types=1);

namespace App\Importacion;

use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Lectura en streaming de libros XLSX con OpenSpout (D-35, RF-086): nunca carga el libro completo en memoria. De las
 * celdas con fórmula toma el valor calculado guardado en el archivo; las fechas llegan como DateTimeImmutable.
 */
final class LectorLibro
{
    /**
     * Hojas del libro con su número de filas (para la detección de fuentes y el límite de filas).
     *
     * @return list<array{nombre:string,visible:bool,filas:int,encabezados:list<list<string>>}>
     */
    public static function hojas(string $ruta): array
    {
        $lector = self::abrir($ruta);
        $hojas = [];
        try {
            foreach ($lector->getSheetIterator() as $hoja) {
                $filas = 0;
                $primeras = [];
                foreach ($hoja->getRowIterator() as $fila) {
                    $filas++;
                    if ($filas <= 10) {
                        $primeras[] = array_map(static fn (mixed $v): string => Normalizador::encabezado(is_scalar($v) ? $v : ''), self::valores($fila->getCells()));
                    }
                }
                $hojas[] = ['nombre' => $hoja->getName(), 'visible' => $hoja->isVisible(), 'filas' => $filas, 'encabezados' => $primeras];
            }
        } finally {
            $lector->close();
        }
        return $hojas;
    }

    /**
     * Filas de una hoja: número de fila (base 1) => valores. Generador: lee una fila a la vez.
     *
     * @return \Generator<int,list<mixed>>
     */
    public static function filas(string $ruta, string $nombreHoja): \Generator
    {
        $lector = self::abrir($ruta);
        try {
            foreach ($lector->getSheetIterator() as $hoja) {
                if ($hoja->getName() !== $nombreHoja) {
                    continue;
                }
                $numero = 0;
                foreach ($hoja->getRowIterator() as $fila) {
                    $numero++;
                    yield $numero => self::valores($fila->getCells());
                }
                return;
            }
        } finally {
            $lector->close();
        }
    }

    private static function abrir(string $ruta): Reader
    {
        $opciones = new Options();
        $opciones->SHOULD_FORMAT_DATES = false;
        $opciones->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $lector = new Reader($opciones);
        $lector->open($ruta);
        return $lector;
    }

    /**
     * @param array<int,\OpenSpout\Common\Entity\Cell> $celdas
     * @return list<mixed>
     */
    private static function valores(array $celdas): array
    {
        $valores = [];
        foreach ($celdas as $celda) {
            $valor = $celda instanceof FormulaCell ? $celda->getComputedValue() : $celda->getValue();
            $valores[] = $valor;
        }
        return $valores;
    }
}
