<?php

declare(strict_types=1);

namespace App\Importacion;

use App\Nucleo\BaseDatos;

/**
 * Una fuente de importación (RF-083): cómo se reconoce su hoja, cómo se mapean sus columnas, cómo se valida y
 * normaliza cada fila, cuál es su clave natural (RF-089) y cómo se aplica, con procedencia, y se revierte (RF-090).
 */
abstract class FuenteImportacion
{
    /** Valor de lotes_importacion.fuente. */
    abstract public function clave(): string;

    abstract public function nombre(): string;

    /** Permiso que exige la fuente (RF-092). */
    abstract public function permiso(): string;

    /** Expresión regular sobre el nombre normalizado de la hoja. */
    abstract public function patronHoja(): string;

    /**
     * Columnas: campo => alias normalizados. Se busca primero la coincidencia exacta y luego la parcial.
     *
     * @return array<string,array{alias:list<string>,obligatoria:bool}>
     */
    abstract public function columnas(): array;

    /** Tabla de destino. */
    abstract public function tabla(): string;

    /**
     * Valida y normaliza una fila. Devuelve null si la fila no es de datos y se omite sin error (por ejemplo, totales).
     *
     * @param array<string,mixed> $valores campo => valor de la celda
     * @return array{datos:array<string,mixed>,clave:string,errores:list<array{columna:string,mensaje:string}>,advertencias:list<string>}|null
     */
    abstract public function validar(array $valores, ContextoImportacion $contexto): ?array;

    /**
     * Registro actual con la misma clave natural, o null si no existe.
     *
     * @param array<string,mixed> $datos
     * @return array<string,mixed>|null
     */
    abstract public function actual(ContextoImportacion $contexto, array $datos): ?array;

    /** @return list<string> columnas que decide si una fila existente cambia */
    abstract public function camposComparables(): array;

    /**
     * Crea o actualiza el registro y devuelve su id. $procedencia trae el lote, el archivo, la hoja y la fila.
     *
     * @param array<string,mixed> $datos
     * @param array<string,mixed>|null $actual
     * @param array<string,scalar|null> $procedencia
     */
    abstract public function aplicar(ContextoImportacion $contexto, array $datos, ?array $actual, array $procedencia): int;

    /**
     * Opciones que el usuario elige al cargar (vigencia, fecha de corte, unidad del hato).
     *
     * @return list<string>
     */
    public function opcionesRequeridas(): array
    {
        return [];
    }

    /** Fila de encabezados conocida (base 1), o null para detectarla en las 10 primeras filas. */
    public function filaEncabezado(): ?int
    {
        return null;
    }

    /** ¿Se pueden aplicar solo las filas válidas cuando hay errores (RF-087)? */
    public function permiteParcial(): bool
    {
        return true;
    }

    /**
     * Campos cuyo valor original no se guarda nunca, ni siquiera en el reporte de errores (RF-034).
     *
     * @return list<string>
     */
    public function camposSensibles(): array
    {
        return [];
    }

    /** Campo que suma las cifras de control (RF-094), o null si solo se cuentan filas. */
    public function campoSuma(): ?string
    {
        return null;
    }

    /** Registros creados después que impiden revertir este (RF-090). Null si no hay. */
    public function dependencias(BaseDatos $bd, int $id, int $loteId): ?string
    {
        return null;
    }

    /** Reversión de un registro creado por el lote: se archiva. */
    public function revertirCreado(BaseDatos $bd, int $id, int $usuario, string $motivo): void
    {
        $bd->ejecutar(
            "UPDATE {$this->tabla()} SET archivado_en = UTC_TIMESTAMP(3), archivado_por = :u, motivo_archivo = :m, version = version + 1 WHERE id = :id AND archivado_en IS NULL",
            ['u' => $usuario, 'm' => mb_substr('Reversión de importación: ' . $motivo, 0, 500), 'id' => $id]
        );
    }

    /**
     * Reversión de un registro actualizado: recupera los valores anteriores.
     *
     * @param array<string,mixed> $anteriores
     */
    public function restaurar(BaseDatos $bd, int $id, array $anteriores, int $usuario): void
    {
        if ($anteriores === []) {
            return;
        }
        $this->actualizarFila($bd, $id, $anteriores, $usuario);
    }

    /**
     * @param array<string,mixed> $datos
     * @param array<string,mixed>|null $actual
     */
    public function accion(array $datos, ?array $actual): string
    {
        if ($actual === null) {
            return 'crear';
        }
        foreach ($this->camposComparables() as $campo) {
            if (self::comparable($datos[$campo] ?? null) !== self::comparable($actual[$campo] ?? null)) {
                return 'actualizar';
            }
        }
        return 'sin_cambios';
    }

    /** Valor comparable: los decimales «12.500» y «12.5» son iguales. */
    public static function comparable(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        $texto = (string) $valor;
        if (preg_match('/^-?\d+\.\d+$/', $texto) === 1) {
            $texto = rtrim(rtrim($texto, '0'), '.');
        }
        return $texto;
    }

    // ---------------------------------------------------------------- Ayudas para las subclases

    /**
     * @param array<string,scalar|null> $valores
     * @param array<string,scalar|null> $procedencia
     */
    protected function insertarFila(BaseDatos $bd, array $valores, array $procedencia, int $usuario): int
    {
        $todos = $valores + $procedencia + ['creado_por' => $usuario, 'actualizado_por' => $usuario];
        $columnas = array_keys($todos);
        return $bd->insertar(
            sprintf('INSERT INTO %s (%s) VALUES (:%s)', $this->tabla(), implode(', ', $columnas), implode(', :', $columnas)),
            $todos
        );
    }

    /** @param array<string,mixed> $valores */
    protected function actualizarFila(BaseDatos $bd, int $id, array $valores, int $usuario): void
    {
        $asignaciones = implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", array_keys($valores)));
        $bd->ejecutar(
            "UPDATE {$this->tabla()} SET {$asignaciones}, version = version + 1, actualizado_por = :usuario_actor WHERE id = :id_registro",
            $valores + ['usuario_actor' => $usuario, 'id_registro' => $id]
        );
    }

    /**
     * @param list<array{columna:string,mensaje:string}> $errores
     */
    protected static function error(array &$errores, string $columna, string $mensaje): void
    {
        $errores[] = ['columna' => $columna, 'mensaje' => $mensaje];
    }

    /** @param list<array{columna:string,mensaje:string}> $errores */
    protected static function exigirUnidad(ContextoImportacion $contexto, ?int $unidad, string $columna, array &$errores): void
    {
        if ($unidad !== null && !$contexto->alcance->permiteUnidad($unidad)) {
            self::error($errores, $columna, 'Unidad fuera de su alcance.');
        }
    }
}
