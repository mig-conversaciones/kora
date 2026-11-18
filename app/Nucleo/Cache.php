<?php

declare(strict_types=1);

namespace App\Nucleo;

/**
 * Caché de aplicación en archivos privados (cache-aside, ADR-010). La invalidación se resuelve con claves
 * versionadas: quien llama incluye la versión de datos del centro en la clave. Escritura atómica (temporal +
 * rename), JSON en lugar de unserialize (sin inyección de objetos) y protección de estampida con flock.
 * La caché nunca es fuente de verdad: cualquier fallo de almacenamiento cae al cálculo directo.
 */
final class Cache
{
    private const GRACIA_SEGUNDOS = 60;
    private const ESPERA_MAXIMA_SEGUNDOS = 2.0;

    public function __construct(private readonly string $directorio, private readonly ?Registro $registro = null)
    {
    }

    /**
     * @template T
     * @param callable():T $calcular debe devolver datos serializables en JSON (agregados, no objetos)
     * @return T
     */
    public function recordar(string $clave, int $ttlSegundos, callable $calcular): mixed
    {
        $ruta = $this->ruta($clave);
        $entrada = $this->leer($ruta);
        if ($entrada !== null && $entrada['expira'] > time()) {
            $this->metrica($clave, 'acierto');
            return $entrada['valor'];
        }
        $this->metrica($clave, 'fallo');
        $bloqueo = fopen($ruta . '.lock', 'c');
        if ($bloqueo === false) {
            return $calcular();
        }
        try {
            if (!flock($bloqueo, LOCK_EX | LOCK_NB)) {
                // Otro proceso está calculando: se sirve el valor vencido dentro de la gracia o se espera poco.
                if ($entrada !== null && $entrada['expira'] + self::GRACIA_SEGUNDOS > time()) {
                    return $entrada['valor'];
                }
                $limite = microtime(true) + self::ESPERA_MAXIMA_SEGUNDOS;
                while (microtime(true) < $limite) {
                    usleep(100000);
                    $nueva = $this->leer($ruta);
                    if ($nueva !== null && $nueva['expira'] > time()) {
                        return $nueva['valor'];
                    }
                }
                return $calcular();
            }
            // Doble verificación: otro proceso pudo escribir mientras se obtenía el bloqueo.
            $entrada = $this->leer($ruta);
            if ($entrada !== null && $entrada['expira'] > time()) {
                return $entrada['valor'];
            }
            $valor = $calcular();
            $variacion = (int) round($ttlSegundos * mt_rand(-10, 10) / 100);
            $this->escribirAtomico($ruta, ['expira' => time() + $ttlSegundos + $variacion, 'valor' => $valor]);
            return $valor;
        } finally {
            flock($bloqueo, LOCK_UN);
            fclose($bloqueo);
        }
    }

    /** Limpieza programada: elimina vencidos (fuera de gracia) y aplica el tamaño máximo (RNF-PERF-012). */
    public function limpiar(int $tamanoMaximoBytes): int
    {
        $archivos = glob($this->directorio . '/*/*.json') ?: [];
        $eliminados = 0;
        $vigentes = [];
        foreach ($archivos as $archivo) {
            $entrada = $this->leer($archivo);
            if ($entrada === null || $entrada['expira'] + self::GRACIA_SEGUNDOS < time()) {
                $eliminados += unlink($archivo) ? 1 : 0;
                continue;
            }
            $vigentes[$archivo] = (int) filemtime($archivo);
        }
        asort($vigentes);
        $tamano = array_sum(array_map('filesize', array_keys($vigentes)));
        foreach (array_keys($vigentes) as $archivo) {
            if ($tamano <= $tamanoMaximoBytes) {
                break;
            }
            $tamano -= (int) filesize($archivo);
            $eliminados += unlink($archivo) ? 1 : 0;
        }
        return $eliminados;
    }

    /** @return array{expira:int,valor:mixed}|null */
    private function leer(string $ruta): ?array
    {
        if (!is_file($ruta)) {
            return null;
        }
        $contenido = file_get_contents($ruta);
        $datos = $contenido === false ? null : json_decode($contenido, true);
        return is_array($datos) && isset($datos['expira']) && array_key_exists('valor', $datos) ? $datos : null;
    }

    /** @param array{expira:int,valor:mixed} $datos */
    private function escribirAtomico(string $ruta, array $datos): void
    {
        $temporal = $ruta . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temporal, json_encode($datos, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            return;
        }
        chmod($temporal, 0600);
        if (!rename($temporal, $ruta)) {
            unlink($temporal);
        }
    }

    private function ruta(string $clave): string
    {
        $hash = hash('sha256', $clave);
        $directorio = $this->directorio . '/' . substr($hash, 0, 2);
        if (!is_dir($directorio)) {
            mkdir($directorio, 0700, true);
        }
        return $directorio . '/' . $hash . '.json';
    }

    private function metrica(string $clave, string $resultado): void
    {
        $this->registro?->info('cache.' . $resultado, ['tipo' => strstr($clave, ':', true) ?: $clave]);
    }
}
