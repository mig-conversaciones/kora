<?php

declare(strict_types=1);

namespace App\Nucleo;

use PDO;
use PDOStatement;
use Throwable;

/**
 * Envoltura mínima sobre PDO: conexión perezosa, transacciones reutilizables y conteo de consultas por
 * solicitud, que las pruebas usan para detectar N+1 (RNF-PERF-009).
 */
final class BaseDatos
{
    private ?PDO $pdo = null;
    private int $consultas = 0;
    private int $profundidad = 0;

    /** @param array{dsn:string,usuario:string,contrasena:string,opciones:array<int,mixed>,inicializacion:list<string>} $config */
    public function __construct(private readonly array $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO($this->config['dsn'], $this->config['usuario'], $this->config['contrasena'], $this->config['opciones']);
            foreach ($this->config['inicializacion'] as $sentencia) {
                $this->pdo->exec($sentencia);
            }
        }
        return $this->pdo;
    }

    /**
     * @param array<int|string,scalar|null> $parametros
     * @return list<array<string,mixed>>
     */
    public function consultar(string $sql, array $parametros = []): array
    {
        return $this->preparar($sql, $parametros)->fetchAll();
    }

    /**
     * @param array<int|string,scalar|null> $parametros
     * @return array<string,mixed>|null
     */
    public function fila(string $sql, array $parametros = []): ?array
    {
        $fila = $this->preparar($sql, $parametros)->fetch();
        return $fila === false ? null : $fila;
    }

    /** @param array<int|string,scalar|null> $parametros */
    public function valor(string $sql, array $parametros = []): mixed
    {
        $valor = $this->preparar($sql, $parametros)->fetchColumn();
        return $valor === false ? null : $valor;
    }

    /** @param array<int|string,scalar|null> $parametros */
    public function ejecutar(string $sql, array $parametros = []): int
    {
        return $this->preparar($sql, $parametros)->rowCount();
    }

    /** @param array<int|string,scalar|null> $parametros */
    public function insertar(string $sql, array $parametros = []): int
    {
        $this->preparar($sql, $parametros);
        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * Ejecuta $operacion en una transacción. Las llamadas anidadas reutilizan la transacción externa para
     * que el cambio de negocio y el trabajo encolado se confirmen juntos (outbox, RF-Q-004).
     *
     * @template T
     * @param callable(self):T $operacion
     * @return T
     */
    public function transaccion(callable $operacion): mixed
    {
        if ($this->profundidad > 0) {
            $this->profundidad++;
            try {
                return $operacion($this);
            } finally {
                $this->profundidad--;
            }
        }
        $this->pdo()->beginTransaction();
        $this->profundidad = 1;
        try {
            $resultado = $operacion($this);
            $this->pdo()->commit();
            return $resultado;
        } catch (Throwable $error) {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }
            throw $error;
        } finally {
            $this->profundidad = 0;
        }
    }

    public function enTransaccion(): bool
    {
        return $this->profundidad > 0;
    }

    public function totalConsultas(): int
    {
        return $this->consultas;
    }

    /** @param array<int|string,scalar|null> $parametros */
    private function preparar(string $sql, array $parametros): PDOStatement
    {
        $this->consultas++;
        $sentencia = $this->pdo()->prepare($sql);
        foreach ($parametros as $clave => $valor) {
            $marcador = is_int($clave) ? $clave + 1 : ':' . ltrim((string) $clave, ':');
            $tipo = match (true) {
                is_int($valor) => PDO::PARAM_INT,
                is_bool($valor) => PDO::PARAM_BOOL,
                $valor === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $sentencia->bindValue($marcador, $valor, $tipo);
        }
        $sentencia->execute();
        return $sentencia;
    }
}
