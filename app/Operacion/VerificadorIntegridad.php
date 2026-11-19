<?php

declare(strict_types=1);

namespace App\Operacion;

/**
 * Verificación diaria de integridad (RS-012, RS-022; Anexo D, D.3): la alternativa a auditd en Hostinger Cloud (D-13).
 *   - La versión activa conserva los SHA-256 de su manifiesto (manifiesto.sha256, que escribe scripts/empaquetar.sh):
 *     un archivo cambiado, agregado al manifiesto o borrado es una alerta.
 *   - El .env tiene permisos 600 (no aplica en Windows, donde PHP no expone permisos POSIX).
 *   - El .env conserva la huella registrada tras la última rotación (runbook «Rotación de claves»): un cambio sin
 *     registro es una alerta. Registrar la huella es el último paso de cada rotación.
 * Nunca lee ni registra el contenido del .env: solo su hash.
 */
final class VerificadorIntegridad
{
    public function __construct(
        private readonly string $raiz,
        private readonly string $archivoEnv,
        private readonly string $directorioEstado,
        private readonly bool $exigirManifiesto,
    ) {
    }

    /** @return array{alertas:list<string>,archivos:int} */
    public function verificar(): array
    {
        $alertas = [];
        $archivos = 0;
        $manifiesto = $this->raiz . '/manifiesto.sha256';
        if (is_file($manifiesto)) {
            foreach (file($manifiesto, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
                if (preg_match('/^([0-9a-f]{64}) [ *](.+)$/', $linea, $m) !== 1) {
                    $alertas[] = 'El manifiesto de la versión activa tiene una línea no válida.';
                    break;
                }
                $archivos++;
                $ruta = $this->raiz . '/' . preg_replace('#^\./#', '', str_replace('\\', '/', $m[2]));
                if (str_contains($m[2], '..') || !is_file($ruta)) {
                    $alertas[] = "Falta un archivo de la versión activa: {$m[2]}.";
                } elseif (!hash_equals($m[1], (string) hash_file('sha256', $ruta))) {
                    $alertas[] = "Un archivo de la versión activa no coincide con el manifiesto: {$m[2]}.";
                }
                if (count($alertas) >= 20) {
                    $alertas[] = 'Hay más diferencias: revisa la versión activa completa.';
                    break;
                }
            }
        } elseif ($this->exigirManifiesto) {
            $alertas[] = 'La versión activa no tiene manifiesto.sha256: no se puede verificar su integridad.';
        }
        if (is_file($this->archivoEnv)) {
            if (DIRECTORY_SEPARATOR === '/') {
                $permisos = fileperms($this->archivoEnv) & 0777;
                if ($permisos !== 0600) {
                    $alertas[] = sprintf('Permisos del .env: %o (se esperaba 600).', $permisos);
                }
            }
            $registrada = is_file($this->huellaEnv()) ? trim((string) file_get_contents($this->huellaEnv())) : '';
            if ($registrada !== '' && !hash_equals($registrada, (string) hash_file('sha256', $this->archivoEnv))) {
                $alertas[] = 'El .env cambió sin registro de rotación (runbook «Rotación de claves»).';
            }
        } else {
            $alertas[] = 'No está el .env de la versión activa.';
        }
        return ['alertas' => $alertas, 'archivos' => $archivos];
    }

    /** Registra la huella actual del .env: último paso de una rotación de claves. */
    public function registrarHuellaEnv(): string
    {
        $huella = (string) hash_file('sha256', $this->archivoEnv);
        if (!is_dir($this->directorioEstado)) {
            mkdir($this->directorioEstado, 0750, true);
        }
        file_put_contents($this->huellaEnv(), $huella . PHP_EOL);
        return $huella;
    }

    private function huellaEnv(): string
    {
        return $this->directorioEstado . '/env.sha256';
    }
}
