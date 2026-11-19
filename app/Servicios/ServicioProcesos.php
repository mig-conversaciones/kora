<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\Entrada;
use App\Nucleo\ErrorHttp;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\Solicitud;
use App\Politicas\RecursoUnidad;
use App\Repositorios\RepositorioProcesos;
use PDOException;

/**
 * Procesos de autoconsumo, es decir cupos anuales (RF-036, RF-037):
 *   - Código AUTO_xxx único por vigencia. El sistema sugiere el siguiente.
 *   - El destino es un cliente interno del catálogo o un texto libre. El producto es del catálogo, y la medida del
 *     cupo es la del producto.
 *   - Con entregas registradas, la unidad, la medida, el producto y la vigencia ya no cambian.
 *   - La ejecución cuenta solo las entregas de autoconsumo vinculadas, de la misma unidad de origen y la misma medida.
 * Las entregas (salidas) llegan en la I4: hasta entonces la ejecución es 0 y no hay bloqueos por entregas.
 */
final class ServicioProcesos
{
    public function __construct(
        private readonly BaseDatos $bd,
        private readonly RepositorioProcesos $repositorio,
        private readonly AutorizacionRecurso $autorizacion,
        private readonly ReglasDependencias $dependencias,
        private readonly ServicioAuditoria $auditoria,
        private readonly ServicioVersionDatos $versiones,
    ) {
    }

    /**
     * Procesos de la vigencia con su ejecución física y monetaria.
     *
     * @return list<array<string,mixed>>
     */
    public function listar(Solicitud $solicitud, int $vigencia): array
    {
        $filas = $this->repositorio->listar(AutorizacionRecurso::alcance($solicitud), 'p.vigencia = :vigencia', ['vigencia' => $vigencia], 'codigo', 1, 100)['filas'];
        $destinos = [];
        foreach ($this->bd->consultar('SELECT id, nombre FROM destinos_internos WHERE centro_id = :c', ['c' => AutorizacionRecurso::alcance($solicitud)->centroId]) as $d) {
            $destinos[(int) $d['id']] = (string) $d['nombre'];
        }
        foreach ($filas as &$fila) {
            $entregado = $this->dependencias->entregadoProceso((int) $fila['id'], (int) $fila['unidad_id'], (string) $fila['medida']);
            $fila['destino_nombre'] = $fila['destino_id'] === null ? null : ($destinos[(int) $fila['destino_id']] ?? null);
            $fila['entregado_cantidad'] = $entregado['cantidad'];
            $fila['entregado_valor'] = $entregado['valor'];
            $fila['entregas'] = $entregado['entregas'];
            $fila['avance_fisico'] = (float) $fila['cupo_cantidad'] > 0 ? round((float) $entregado['cantidad'] / (float) $fila['cupo_cantidad'] * 100, 1) : 0.0;
            $fila['avance_monetario'] = (float) $fila['cupo_valor'] > 0 ? round((float) $entregado['valor'] / (float) $fila['cupo_valor'] * 100, 1) : 0.0;
        }
        return $filas;
    }

    /** @return array<string,mixed> */
    public function obtener(Solicitud $solicitud, int $id): array
    {
        $fila = $this->repositorio->obtener(AutorizacionRecurso::alcance($solicitud), $id);
        if ($fila['archivado_en'] !== null) {
            throw new ErrorHttp(404);
        }
        $fila['entregas'] = $this->dependencias->entregadoProceso($id, (int) $fila['unidad_id'], (string) $fila['medida'])['entregas'];
        return $fila;
    }

    public function siguienteCodigo(int $centroId, int $vigencia): string
    {
        $maximo = (int) $this->bd->valor(
            "SELECT MAX(CAST(SUBSTRING(codigo, 6) AS UNSIGNED)) FROM procesos_autoconsumo WHERE centro_id = :c AND vigencia = :v AND codigo LIKE 'AUTO\\_%'",
            ['c' => $centroId, 'v' => $vigencia]
        );
        return sprintf('AUTO_%03d', $maximo + 1);
    }

    /** @param array<string,mixed> $datos */
    public function guardar(Solicitud $solicitud, ?int $id, array $datos): int
    {
        $alcance = AutorizacionRecurso::alcance($solicitud);
        $actual = $id === null ? null : $this->obtener($solicitud, $id);
        if ($actual !== null) {
            $this->autorizacion->exigir($solicitud, 'procesos.gestionar', new RecursoUnidad((int) $actual['centro_id'], (int) $actual['unidad_id']));
        }
        $errores = [];
        $codigo = strtoupper(trim((string) ($datos['codigo'] ?? '')));
        if (preg_match('/^AUTO_\d{3,6}$/', $codigo) !== 1) {
            $errores['codigo'] = 'El código tiene el formato AUTO_001.';
        }
        $vigencia = Entrada::entero($datos['vigencia'] ?? '', 2020, 2100);
        if ($vigencia === null) {
            $errores['vigencia'] = 'Escribe el año de la vigencia.';
        }
        $unidad = Entrada::entero($datos['unidad_id'] ?? '', 1);
        if ($unidad === null) {
            $errores['unidad_id'] = 'Elige la unidad de origen.';
        } else {
            $this->autorizacion->exigir($solicitud, 'procesos.gestionar', new RecursoUnidad($alcance->centroId, $unidad));
            if ((int) $this->bd->valor("SELECT COUNT(*) FROM unidades_productivas WHERE id = :u AND centro_id = :c AND activa = 1 AND codigo <> 'SIN'", ['u' => $unidad, 'c' => $alcance->centroId]) === 0) {
                $errores['unidad_id'] = 'Elige una unidad activa.';
            }
        }
        $destino = trim((string) ($datos['destino_id'] ?? '')) === '' ? null : Entrada::entero($datos['destino_id'], 1);
        $destinoTexto = Entrada::texto($datos['destino_texto'] ?? '', 150);
        if ($destino !== null && (int) $this->bd->valor('SELECT COUNT(*) FROM destinos_internos WHERE id = :d AND centro_id = :c AND activo = 1', ['d' => $destino, 'c' => $alcance->centroId]) === 0) {
            $errores['destino_id'] = 'Elige un destino activo del catálogo.';
        } elseif ($destino === null && ($destinoTexto === null || $destinoTexto === '')) {
            $errores['destino_id'] = 'Elige un destino del catálogo o escribe el destino (máximo 150 caracteres).';
        }
        $producto = Entrada::entero($datos['producto_id'] ?? '', 1);
        $filaProducto = $producto === null ? null : $this->bd->fila('SELECT id, nombre, medida FROM productos WHERE id = :p AND centro_id = :c AND archivado_en IS NULL', ['p' => $producto, 'c' => $alcance->centroId]);
        if ($filaProducto === null) {
            $errores['producto_id'] = 'Elige un producto del catálogo.';
        }
        $cantidad = Entrada::decimal($datos['cupo_cantidad'] ?? '', 3);
        if ($cantidad === null || Entrada::comparar($cantidad, '0.000') <= 0) {
            $errores['cupo_cantidad'] = 'Escribe el cupo físico, mayor que 0 (hasta 3 decimales).';
        }
        $valor = Entrada::decimal($datos['cupo_valor'] ?? '');
        if ($valor === null) {
            $errores['cupo_valor'] = 'Escribe el valor del cupo en pesos, sin signo.';
        }
        if ($actual !== null && (int) $actual['entregas'] > 0 && $filaProducto !== null) {
            foreach (['unidad_id' => [$unidad, 'la unidad'], 'vigencia' => [$vigencia, 'la vigencia'], 'producto_id' => [$producto, 'el producto'], 'medida' => [$filaProducto['medida'], 'la medida']] as $campo => [$nuevo, $texto]) {
                if ((string) $nuevo !== (string) $actual[$campo]) {
                    $errores[$campo === 'medida' ? 'producto_id' : $campo] = "Con {$actual['entregas']} entregas registradas, no se puede cambiar {$texto}.";
                }
            }
        }
        if (!isset($errores['codigo']) && !isset($errores['vigencia'])
            && (int) $this->bd->valor('SELECT COUNT(*) FROM procesos_autoconsumo WHERE centro_id = :c AND codigo = :codigo AND vigencia = :v AND id <> :id', ['c' => $alcance->centroId, 'codigo' => $codigo, 'v' => $vigencia, 'id' => $id ?? 0]) > 0) {
            $errores['codigo'] = "Ya existe el proceso {$codigo} en {$vigencia}.";
        }
        if ($errores !== []) {
            throw new ErrorValidacion($errores);
        }
        /** @var array{id:int|string,nombre:string,medida:string} $filaProducto */
        $valores = [
            'codigo' => $codigo,
            'vigencia' => $vigencia,
            'unidad_id' => $unidad,
            'destino_id' => $destino,
            'destino_texto' => $destino === null ? $destinoTexto : null,
            'producto_id' => $producto,
            'producto_texto' => (string) $filaProducto['nombre'],
            'cupo_cantidad' => $cantidad,
            'medida' => (string) $filaProducto['medida'],
            'cupo_valor' => $valor,
        ];
        $usuario = AutorizacionRecurso::usuario($solicitud);
        try {
            return $this->bd->transaccion(function () use ($solicitud, $alcance, $id, $actual, $valores, $datos, $usuario): int {
                if ($actual === null) {
                    $id = $this->repositorio->insertar($valores + ['centro_id' => $alcance->centroId, 'origen_tipo' => 'manual'], $usuario->id);
                    $antes = null;
                } else {
                    $this->repositorio->actualizar($alcance, $id, Entrada::entero($datos['version'] ?? '', 1) ?? 0, $valores, $usuario->id);
                    $antes = ServicioCatalogos::extraer($actual, array_keys($valores));
                }
                $this->auditoria->registrar($solicitud, $antes === null ? 'procesos.crear' : 'procesos.actualizar', 'exito', new RecursoAuditado('procesos_autoconsumo', $id, (int) $valores['unidad_id']), [], $antes, $valores);
                $this->versiones->incrementar($alcance->centroId);
                return $id;
            });
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) === 1062) {
                throw new ErrorValidacion(['codigo' => 'Ese código ya existe en la vigencia.']);
            }
            throw $error;
        }
    }

    public function archivar(Solicitud $solicitud, int $id, int $version, string $motivo): void
    {
        $actual = $this->obtener($solicitud, $id);
        $this->autorizacion->exigir($solicitud, 'procesos.gestionar', new RecursoUnidad((int) $actual['centro_id'], (int) $actual['unidad_id']));
        $motivo = ServicioAsignaciones::motivo($motivo);
        if ((int) $actual['entregas'] > 0) {
            throw new ErrorHttp(409, "El proceso tiene {$actual['entregas']} entregas registradas; no puede archivarse.");
        }
        $usuario = AutorizacionRecurso::usuario($solicitud);
        $this->bd->transaccion(function () use ($solicitud, $id, $version, $motivo, $actual, $usuario): void {
            $this->repositorio->archivar(AutorizacionRecurso::alcance($solicitud), $id, $version, $usuario->id, $motivo);
            $this->auditoria->registrar($solicitud, 'procesos.archivar', 'exito', new RecursoAuditado('procesos_autoconsumo', $id, (int) $actual['unidad_id']), [], ['codigo' => $actual['codigo'], 'vigencia' => $actual['vigencia']], null, $motivo);
            $this->versiones->incrementar((int) $actual['centro_id']);
        });
    }
}
