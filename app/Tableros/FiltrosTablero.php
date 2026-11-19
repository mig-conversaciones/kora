<?php

declare(strict_types=1);

namespace App\Tableros;

use App\Nucleo\Entrada;

/**
 * Filtros globales de los tableros (RF-062), validados en el servidor y reflejados en la URL:
 *   - vigencia (año), desde y hasta, que se recortan a la vigencia (2.10: el periodo es la intersección);
 *   - unidad: solo una del alcance; cualquier otra se descarta con un aviso, nunca amplía el alcance (Obs. 4);
 *   - producto: «p<id>» del catálogo o «c:<texto>» de un concepto fuente sin homologar;
 *   - canal: todos, venta directa o autoconsumo (RF-065: canales excluyentes).
 * Un rango invertido no se calcula: deja el error en línea y conserva lo escrito.
 */
final class FiltrosTablero
{
    public const CANALES = ['todos' => 'Todos', 'venta_directa' => 'Venta directa', 'autoconsumo' => 'Autoconsumo'];

    /** @param array<string,string> $errores */
    private function __construct(
        public readonly int $vigencia,
        public readonly ?string $desde,
        public readonly ?string $hasta,
        public readonly ?int $unidadId,
        public readonly ?int $productoId,
        public readonly ?string $concepto,
        public readonly string $canal,
        public readonly array $errores,
    ) {
    }

    /**
     * @param array<string,mixed> $consulta parámetros de la URL
     * @param list<int> $unidadesPermitidas unidades del alcance
     */
    public static function desdeConsulta(array $consulta, array $unidadesPermitidas, int $vigenciaPorDefecto): self
    {
        $errores = [];
        $vigencia = Entrada::entero($consulta['vigencia'] ?? '', 2000, 2100) ?? $vigenciaPorDefecto;
        $desde = self::fecha($consulta['desde'] ?? '', 'desde', $errores);
        $hasta = self::fecha($consulta['hasta'] ?? '', 'hasta', $errores);
        if ($desde !== null && $hasta !== null && $desde > $hasta) {
            $errores['hasta'] = 'La fecha «hasta» no puede ser anterior a «desde».';
        }
        $unidad = Entrada::entero($consulta['unidad'] ?? '', 1);
        if ($unidad !== null && !in_array($unidad, $unidadesPermitidas, true)) {
            $errores['unidad'] = 'Esa unidad no está en tu alcance; se muestran tus unidades.';
            $unidad = null;
        }
        $productoId = null;
        $concepto = null;
        $producto = trim((string) ($consulta['producto'] ?? ''));
        if (preg_match('/^p(\d{1,10})$/', $producto, $m) === 1) {
            $productoId = (int) $m[1];
        } elseif (str_starts_with($producto, 'c:') && mb_strlen($producto) <= 202) {
            $concepto = Entrada::texto(substr($producto, 2), 200);
        }
        $canal = (string) ($consulta['canal'] ?? 'todos');
        if (!isset(self::CANALES[$canal])) {
            $canal = 'todos';
        }
        return new self($vigencia, $desde, $hasta, $unidad, $productoId, $concepto, $canal, $errores);
    }

    /** Con un rango inválido no se calcula nada: se muestra el error (RF-062). */
    public function esValido(): bool
    {
        return !isset($this->errores['desde']) && !isset($this->errores['hasta']);
    }

    /** Primer día del periodo: el mayor entre el inicio de la vigencia y «desde». */
    public function inicio(): string
    {
        $inicio = sprintf('%04d-01-01', $this->vigencia);
        return $this->desde !== null && $this->desde > $inicio ? $this->desde : $inicio;
    }

    /** Último día del periodo: el menor entre el fin de la vigencia y «hasta». */
    public function fin(): string
    {
        $fin = sprintf('%04d-12-31', $this->vigencia);
        return $this->hasta !== null && $this->hasta < $fin ? $this->hasta : $fin;
    }

    /** ¿Se consulta la vigencia completa? (D-20: el costo presupuestado solo se rotula con la vigencia completa). */
    public function vigenciaCompleta(): bool
    {
        return $this->inicio() === sprintf('%04d-01-01', $this->vigencia) && $this->fin() === sprintf('%04d-12-31', $this->vigencia);
    }

    public function producto(): string
    {
        return $this->productoId !== null ? 'p' . $this->productoId : ($this->concepto !== null ? 'c:' . $this->concepto : '');
    }

    public function conUnidad(?int $unidadId): self
    {
        return new self($this->vigencia, $this->desde, $this->hasta, $unidadId, $this->productoId, $this->concepto, $this->canal, $this->errores);
    }

    /**
     * Parámetros para la URL: solo los que se apartan del valor por defecto, para que la vista se pueda compartir.
     *
     * @return array<string,string>
     */
    public function aConsulta(): array
    {
        return array_filter([
            'vigencia' => (string) $this->vigencia,
            'desde' => (string) $this->desde,
            'hasta' => (string) $this->hasta,
            'unidad' => $this->unidadId === null ? '' : (string) $this->unidadId,
            'producto' => $this->producto(),
            'canal' => $this->canal === 'todos' ? '' : $this->canal,
        ], static fn (string $v): bool => $v !== '');
    }

    /** Huella de los filtros para la clave de caché (sin los errores, que no cambian el cálculo). */
    public function huella(): string
    {
        return hash('sha256', json_encode([$this->vigencia, $this->inicio(), $this->fin(), $this->unidadId, $this->productoId, $this->concepto, $this->canal], JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed> filtros guardados con una exportación */
    public function aArreglo(): array
    {
        return ['vigencia' => $this->vigencia, 'desde' => $this->desde, 'hasta' => $this->hasta, 'unidad' => $this->unidadId, 'producto' => $this->producto(), 'canal' => $this->canal];
    }

    /** @param array<string,string> $errores */
    private static function fecha(mixed $valor, string $campo, array &$errores): ?string
    {
        $texto = trim((string) $valor);
        if ($texto === '') {
            return null;
        }
        $fecha = Entrada::fecha($texto);
        if ($fecha === null) {
            $errores[$campo] = 'Escribe la fecha como AAAA-MM-DD.';
        }
        return $fecha;
    }
}
