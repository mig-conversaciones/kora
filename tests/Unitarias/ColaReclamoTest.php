<?php

declare(strict_types=1);

namespace Pruebas\Unitarias;

use App\Cola\ConductorColaMysql;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Reclamo de la cola ante conflictos de bloqueo (RF-Q-006). En MariaDB, dos trabajadores con SKIP LOCKED pueden
 * interbloquearse (1213); el reclamo se repite en lugar de terminar el trabajador con un error fatal.
 */
final class ColaReclamoTest extends TestCase
{
    public function testInterbloqueoYEsperaAgotadaSeReintentan(): void
    {
        self::assertTrue(ConductorColaMysql::esConflictoDeBloqueo(self::error('40001', 1213)), 'Deadlock found when trying to get lock.');
        self::assertTrue(ConductorColaMysql::esConflictoDeBloqueo(self::error('HY000', 1205)), 'Lock wait timeout exceeded.');
    }

    public function testOtrosErroresNoSeReintentan(): void
    {
        self::assertFalse(ConductorColaMysql::esConflictoDeBloqueo(self::error('23000', 1062)), 'Una clave duplicada no es un conflicto de bloqueo.');
        self::assertFalse(ConductorColaMysql::esConflictoDeBloqueo(self::error('42000', 1064)), 'Un error de sintaxis tampoco.');
        self::assertFalse(ConductorColaMysql::esConflictoDeBloqueo(new PDOException('sin detalle')), 'Sin errorInfo no se reintenta.');
    }

    private static function error(string $estado, int $codigo): PDOException
    {
        $error = new PDOException("SQLSTATE[{$estado}]: {$codigo}");
        $error->errorInfo = [$estado, $codigo, 'detalle'];
        return $error;
    }
}
