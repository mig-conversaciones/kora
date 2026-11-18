<?php

declare(strict_types=1);

namespace App\Nucleo;

use RuntimeException;

/**
 * Ejecutor de migraciones (RBD-011, RDL-001). Cada archivo NNN_nombre.sql tiene su NNN_nombre.down.sql. Las
 * sentencias se separan por «;» al final de la línea. Se registra nombre y SHA-256 en migraciones_aplicadas y se
 * rechaza volver a usar un archivo aplicado cuya suma cambió: una migración publicada no se edita, se corrige con
 * otra nueva. MySQL y MariaDB confirman cada DDL de forma implícita, así que no se envuelve en transacción.
 */
final class Migrador
{
    public function __construct(private readonly BaseDatos $bd, private readonly string $directorio)
    {
    }

    public function prepararRegistro(): void
    {
        $this->bd->ejecutar(
            "CREATE TABLE IF NOT EXISTS migraciones_aplicadas (
                nombre      VARCHAR(190) NOT NULL COMMENT 'Archivo sin extensión (NNN_nombre)',
                checksum    CHAR(64)     NOT NULL COMMENT 'SHA-256 del archivo aplicado',
                aplicada_en DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                duracion_ms INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (nombre)
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Registro de migraciones (000)'"
        );
    }

    /** @return array<string,string> nombre => ruta del .sql, en orden */
    public function disponibles(): array
    {
        $resultado = [];
        foreach (glob($this->directorio . '/[0-9][0-9][0-9]_*.sql') ?: [] as $archivo) {
            if (str_ends_with($archivo, '.down.sql')) {
                continue;
            }
            $resultado[basename($archivo, '.sql')] = $archivo;
        }
        ksort($resultado, SORT_STRING);
        return $resultado;
    }

    /** @return array<string,string> nombre => checksum */
    public function aplicadas(): array
    {
        $this->prepararRegistro();
        $filas = $this->bd->consultar('SELECT nombre, checksum FROM migraciones_aplicadas ORDER BY nombre');
        $resultado = [];
        foreach ($filas as $fila) {
            $resultado[(string) $fila['nombre']] = (string) $fila['checksum'];
        }
        return $resultado;
    }

    /**
     * Estado de cada migración: aplicada, pendiente o alterada (checksum distinto).
     *
     * @return list<array{nombre:string,estado:string}>
     */
    public function estado(): array
    {
        $aplicadas = $this->aplicadas();
        $resultado = [];
        foreach ($this->disponibles() as $nombre => $archivo) {
            $estado = 'pendiente';
            if (isset($aplicadas[$nombre])) {
                $estado = hash_equals($aplicadas[$nombre], $this->checksum($archivo)) ? 'aplicada' : 'alterada';
            }
            $resultado[] = ['nombre' => $nombre, 'estado' => $estado];
        }
        foreach (array_diff_key($aplicadas, $this->disponibles()) as $nombre => $checksum) {
            $resultado[] = ['nombre' => $nombre, 'estado' => 'sin_archivo'];
        }
        return $resultado;
    }

    /**
     * Aplica las pendientes en orden.
     *
     * @param callable(string):void|null $informar
     * @return list<string> nombres aplicados
     */
    public function aplicar(?callable $informar = null): array
    {
        $aplicadas = $this->aplicadas();
        foreach ($this->disponibles() as $nombre => $archivo) {
            if (isset($aplicadas[$nombre]) && !hash_equals($aplicadas[$nombre], $this->checksum($archivo))) {
                throw new RuntimeException("La migración {$nombre} ya aplicada cambió de contenido. Crea una migración nueva en lugar de editarla.");
            }
        }
        $hechas = [];
        foreach ($this->disponibles() as $nombre => $archivo) {
            if (isset($aplicadas[$nombre])) {
                continue;
            }
            $inicio = hrtime(true);
            $this->ejecutarArchivo($archivo);
            $this->bd->ejecutar(
                'INSERT INTO migraciones_aplicadas (nombre, checksum, duracion_ms) VALUES (:nombre, :checksum, :duracion)',
                ['nombre' => $nombre, 'checksum' => $this->checksum($archivo), 'duracion' => (int) ((hrtime(true) - $inicio) / 1_000_000)]
            );
            $hechas[] = $nombre;
            if ($informar !== null) {
                $informar($nombre);
            }
        }
        return $hechas;
    }

    /**
     * Revierte las últimas $cantidad migraciones aplicadas, en orden inverso.
     *
     * @param callable(string):void|null $informar
     * @return list<string> nombres revertidos
     */
    public function revertir(int $cantidad, ?callable $informar = null): array
    {
        $aplicadas = array_keys($this->aplicadas());
        rsort($aplicadas, SORT_STRING);
        $hechas = [];
        foreach (array_slice($aplicadas, 0, max(0, $cantidad)) as $nombre) {
            $abajo = $this->directorio . '/' . $nombre . '.down.sql';
            if (!is_file($abajo)) {
                throw new RuntimeException("La migración {$nombre} no tiene archivo de reversión.");
            }
            $this->ejecutarArchivo($abajo);
            $this->bd->ejecutar('DELETE FROM migraciones_aplicadas WHERE nombre = :nombre', ['nombre' => $nombre]);
            $hechas[] = $nombre;
            if ($informar !== null) {
                $informar($nombre);
            }
        }
        return $hechas;
    }

    public function ultimaAplicada(): ?string
    {
        $this->prepararRegistro();
        $valor = $this->bd->valor('SELECT MAX(nombre) FROM migraciones_aplicadas');
        return $valor === null ? null : (string) $valor;
    }

    /**
     * Ejecuta una semilla idempotente (INSERT IGNORE) en la misma conexión, para que sus variables de sesión
     * (SET @centro) sigan vigentes entre sentencias (RDL-008).
     */
    public function sembrar(string $archivo): int
    {
        $sentencias = self::separarSentencias((string) file_get_contents($archivo));
        foreach ($sentencias as $sentencia) {
            $this->bd->pdo()->exec($sentencia);
        }
        return count($sentencias);
    }

    /** @return list<string> */
    public static function separarSentencias(string $sql): array
    {
        $sentencias = [];
        $actual = '';
        foreach (preg_split('/\R/', $sql) ?: [] as $linea) {
            $recortada = trim($linea);
            if ($actual === '' && ($recortada === '' || str_starts_with($recortada, '--'))) {
                continue;
            }
            $actual .= $linea . "\n";
            if (str_ends_with(rtrim($linea), ';')) {
                $sentencias[] = rtrim(trim($actual), ';');
                $actual = '';
            }
        }
        if (trim($actual) !== '') {
            $sentencias[] = trim($actual);
        }
        return $sentencias;
    }

    private function ejecutarArchivo(string $archivo): void
    {
        foreach (self::separarSentencias((string) file_get_contents($archivo)) as $sentencia) {
            $this->bd->pdo()->exec($sentencia);
        }
    }

    private function checksum(string $archivo): string
    {
        // Se normalizan los finales de línea para que el checksum no cambie entre Windows y Linux (Git autocrlf).
        return hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($archivo)));
    }
}
