<?php

declare(strict_types=1);

namespace App\Operacion;

use App\Nucleo\BaseDatos;
use App\Nucleo\Registro;
use DateTimeImmutable;
use DateTimeZone;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Copias de seguridad cifradas (RDL-003, RDL-004, RDL-005; Anexo D, D.2), en PHP para que funcionen igual en Hostinger,
 * en la CI y en el MAMP local, y para que la importación masiva pueda pedir su copia previa sin depender de bash.
 *
 *   - Base de datos: mysqldump --single-transaction (copia coherente de InnoDB sin bloquear), comprimida con gzip.
 *   - Almacenamiento privado: ZIP sin caché, bloqueos, latidos ni las propias copias.
 *   - Ambos se cifran en sobre con la llave pública del custodio (SobreCifrado): el servidor no puede abrirlas.
 *   - Las credenciales viajan en un archivo de opciones temporal con permisos 600, nunca en la línea de comandos.
 *   - Cada copia queda en la tabla `respaldos` (el «registro de copias» de RDL-003) y en un manifiesto con SHA-256.
 *   - Retención: 30 diarias y la última de cada uno de los 12 meses anteriores; las de cambios de riesgo, 30 días.
 */
final class ServicioRespaldo
{
    public const TIPOS = ['diaria', 'predespliegue', 'preimportacion', 'manual'];
    private const EXCLUIDOS = ['cache', 'bloqueos', 'latidos', 'respaldos'];

    /**
     * @param array{
     *     directorio:string, almacenamiento:string, mysqldump:string, mysql:string, llave_publica:string,
     *     destino_externo:string, diarias:int, mensuales:int, umbral_importacion:int, entorno:string,
     *     bd:array{host:string,puerto:int,usuario:string,contrasena:string,nombre:string}
     * } $config
     */
    public function __construct(
        private readonly BaseDatos $bd,
        private readonly array $config,
        private readonly ?Registro $registro = null,
    ) {
    }

    public function configurado(): bool
    {
        return trim($this->config['llave_publica']) !== '';
    }

    /**
     * Copia previa de un cambio de riesgo (RDL-004): un despliegue con migraciones, o aplicar o revertir una importación
     * masiva (al menos RESPALDO_IMPORTACION_FILAS filas). Devuelve el identificador, o null si el cambio no la exige o,
     * fuera de producción, si las copias no están configuradas. En producción, sin llave no hay cambio de riesgo.
     */
    public function copiaPrevia(string $tipo, string $motivo, int $filas = PHP_INT_MAX): ?string
    {
        if ($tipo === 'preimportacion' && $filas < $this->config['umbral_importacion']) {
            return null;
        }
        if (!$this->configurado()) {
            if ($this->config['entorno'] === 'produccion') {
                throw new RuntimeException('Falta RESPALDO_LLAVE_PUBLICA: un cambio de riesgo no se hace sin copia previa (RDL-004).');
            }
            $this->registro?->info('respaldo.omitido', ['tipo' => $tipo, 'motivo' => 'copias sin configurar fuera de producción']);
            return null;
        }
        if (!self::procesosDisponibles()) {
            // PHP web de Hostinger: proc_open está deshabilitado y mysqldump no se puede lanzar desde la solicitud. La
            // copia la hace la CLI (cron diario, despliegue, trabajador); aquí vale la completa más reciente de 24 h.
            $reciente = $this->bd->valor(
                "SELECT identificador FROM respaldos WHERE estado = 'completado' AND eliminado_en IS NULL AND creado_en >= UTC_TIMESTAMP(3) - INTERVAL 24 HOUR ORDER BY id DESC LIMIT 1"
            );
            if ($reciente === null) {
                throw new RuntimeException('No hay una copia de las últimas 24 h y este proceso no puede tomarla: ejecuta «scripts/backup.sh manual» y vuelve a intentarlo (RDL-004).');
            }
            $this->registro?->info('respaldo.copia_reciente', ['tipo' => $tipo, 'motivo' => $motivo, 'copia' => $reciente]);
            return (string) $reciente;
        }
        return $this->crear($tipo, $motivo)['identificador'];
    }

    /** mysqldump y mysql se lanzan con proc_open, que el PHP web de algunos hostings deshabilita (disable_functions). */
    public static function procesosDisponibles(): bool
    {
        return function_exists('proc_open');
    }

    public function baseActiva(): string
    {
        return $this->config['bd']['nombre'];
    }

    /**
     * Crea una copia cifrada de la base y del almacenamiento privado.
     *
     * @return array{identificador:string,archivos:list<array{nombre:string,bytes:int,sha256:string}>,bytes:int,duracion_ms:int}
     */
    public function crear(string $tipo, ?string $motivo = null, ?string $llavePublica = null): array
    {
        if (!in_array($tipo, self::TIPOS, true)) {
            throw new RuntimeException("Tipo de copia no válido: {$tipo}.");
        }
        $llave = $llavePublica ?? $this->config['llave_publica'];
        if (trim($llave) === '') {
            throw new RuntimeException('Falta RESPALDO_LLAVE_PUBLICA: genera las llaves con «php cli/respaldo.php llaves».');
        }
        $inicio = microtime(true);
        $identificador = sprintf('kora-%s-%s-%s', $tipo, gmdate('Ymd\THis\Z'), bin2hex(random_bytes(2)));
        $directorio = $this->directorio();
        $cifras = (new CifrasControl($this->bd))->calcular(false);
        $this->bd->ejecutar(
            "INSERT INTO respaldos (identificador, tipo, estado, motivo, cifras_control) VALUES (:i, :t, 'en_curso', :m, :c)",
            ['i' => $identificador, 't' => $tipo, 'm' => $motivo === null ? null : mb_substr($motivo, 0, 255), 'c' => json_encode($cifras, JSON_THROW_ON_ERROR)]
        );
        $temporales = [];
        try {
            $sql = $temporales[] = "{$directorio}/{$identificador}.sql.gz";
            $this->volcar($this->config['bd']['nombre'], $sql);
            $zip = $temporales[] = "{$directorio}/{$identificador}.almacenamiento.zip";
            $this->comprimirAlmacenamiento($zip);
            $archivos = [];
            foreach ([$sql, $zip] as $claro) {
                $cifrado = $claro . '.kora';
                SobreCifrado::cifrar($claro, $cifrado, $llave);
                @unlink($claro);
                $archivos[] = ['nombre' => basename($cifrado), 'bytes' => (int) filesize($cifrado), 'sha256' => (string) hash_file('sha256', $cifrado)];
            }
            $bytes = array_sum(array_column($archivos, 'bytes'));
            $manifiesto = [
                'identificador' => $identificador, 'tipo' => $tipo, 'creado_en' => gmdate('c'),
                'migracion' => $cifras['migracion'], 'motor' => (string) $this->bd->valor('SELECT VERSION()'), 'archivos' => $archivos,
            ];
            file_put_contents("{$directorio}/{$identificador}.manifiesto.json", json_encode($manifiesto, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $duracion = (int) round((microtime(true) - $inicio) * 1000);
            $externo = $this->copiarExterno($identificador);
            $this->bd->ejecutar(
                "UPDATE respaldos SET estado = 'completado', archivos = :a, bytes = :b, duracion_ms = :d, externo = :e, terminado_en = UTC_TIMESTAMP(3) WHERE identificador = :i",
                ['a' => json_encode($archivos, JSON_THROW_ON_ERROR), 'b' => $bytes, 'd' => $duracion, 'e' => $externo ? 1 : 0, 'i' => $identificador]
            );
            $this->registro?->info('respaldo.completado', ['identificador' => $identificador, 'tipo' => $tipo, 'bytes' => $bytes, 'duracion_ms' => $duracion]);
            return ['identificador' => $identificador, 'archivos' => $archivos, 'bytes' => $bytes, 'duracion_ms' => $duracion];
        } catch (Throwable $error) {
            foreach ($temporales as $temporal) {
                @unlink($temporal);
                @unlink($temporal . '.kora');
            }
            $this->bd->ejecutar(
                "UPDATE respaldos SET estado = 'fallido', error = :e, terminado_en = UTC_TIMESTAMP(3) WHERE identificador = :i",
                ['e' => mb_substr($error->getMessage(), 0, 500), 'i' => $identificador]
            );
            $this->registro?->error('respaldo.fallido', ['identificador' => $identificador, 'tipo' => $tipo, 'error' => mb_substr($error->getMessage(), 0, 300)]);
            throw $error;
        }
    }

    /**
     * Comprueba que los archivos de una copia existen y conservan su SHA-256 (sin descifrar).
     *
     * @return list<string> problemas encontrados; vacío si la copia está completa
     */
    public function verificar(string $identificador): array
    {
        $manifiesto = $this->manifiesto($identificador);
        $problemas = [];
        foreach ($manifiesto['archivos'] as $archivo) {
            $ruta = $this->directorio() . '/' . $archivo['nombre'];
            if (!is_file($ruta)) {
                $problemas[] = "Falta {$archivo['nombre']}.";
            } elseif (!hash_equals((string) $archivo['sha256'], (string) hash_file('sha256', $ruta))) {
                $problemas[] = "{$archivo['nombre']} no conserva su SHA-256: la copia está dañada.";
            }
        }
        return $problemas;
    }

    /**
     * Restaura la base de una copia en $baseDestino (y, si se indica, el almacenamiento en un directorio vacío).
     * Restaurar sobre la base activa exige $aceptoPerdida: se pierde lo escrito después de la copia (ROB-004, D-14).
     *
     * @return array{segundos:float}
     */
    public function restaurar(string $identificador, string $baseDestino, string $parLlaves, bool $aceptoPerdida = false, ?string $almacenamientoDestino = null): array
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $baseDestino) !== 1) {
            throw new RuntimeException('Nombre de base de destino no válido.');
        }
        if ($baseDestino === $this->config['bd']['nombre'] && !$aceptoPerdida) {
            throw new RuntimeException('Restaurar sobre la base activa pierde lo escrito después de la copia. Repite con --acepto-perdida.');
        }
        $problemas = $this->verificar($identificador);
        if ($problemas !== []) {
            throw new RuntimeException(implode(' ', $problemas) . ' Usa la copia anterior (runbook «Backup corrupto»).');
        }
        $inicio = microtime(true);
        $directorio = $this->directorio();
        $temporal = $directorio . '/restaurar-' . bin2hex(random_bytes(6)) . '.sql.gz';
        try {
            SobreCifrado::descifrar("{$directorio}/{$identificador}.sql.gz.kora", $temporal, $parLlaves);
            $this->importar($baseDestino, $temporal);
        } finally {
            @unlink($temporal);
        }
        if ($almacenamientoDestino !== null) {
            $this->restaurarAlmacenamiento($identificador, $almacenamientoDestino, $parLlaves);
        }
        return ['segundos' => round(microtime(true) - $inicio, 1)];
    }

    /**
     * Retención (RDL-003): conserva las 30 diarias más recientes y la última diaria de cada uno de los 12 meses
     * anteriores; las de cambios de riesgo y las manuales, 30 días. Borra los archivos y marca el registro.
     *
     * @return list<string> identificadores podados
     */
    public function podar(?DateTimeImmutable $ahora = null): array
    {
        $ahora ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $copias = $this->bd->consultar(
            "SELECT identificador, tipo, creado_en FROM respaldos WHERE estado = 'completado' AND eliminado_en IS NULL ORDER BY creado_en DESC, id DESC"
        );
        $conservar = [];
        $diarias = 0;
        $meses = [];
        $limiteMeses = $ahora->modify('first day of this month')->modify('-' . $this->config['mensuales'] . ' months')->format('Y-m');
        $limiteDias = $ahora->modify('-' . $this->config['diarias'] . ' days')->format('Y-m-d H:i:s');
        foreach ($copias as $copia) {
            $id = (string) $copia['identificador'];
            if ($copia['tipo'] !== 'diaria') {
                if ((string) $copia['creado_en'] >= $limiteDias) {
                    $conservar[$id] = true;
                }
                continue;
            }
            if ($diarias < $this->config['diarias']) {
                $diarias++;
                $conservar[$id] = true;
            }
            $mes = substr((string) $copia['creado_en'], 0, 7);
            if ($mes > $limiteMeses && !isset($meses[$mes])) {
                $meses[$mes] = true;
                $conservar[$id] = true;
            }
        }
        $podados = [];
        foreach ($copias as $copia) {
            $id = (string) $copia['identificador'];
            if (isset($conservar[$id])) {
                continue;
            }
            foreach (glob($this->directorio() . '/' . $id . '.*') ?: [] as $archivo) {
                @unlink($archivo);
            }
            $this->bd->ejecutar('UPDATE respaldos SET eliminado_en = UTC_TIMESTAMP(3) WHERE identificador = :i', ['i' => $id]);
            $podados[] = $id;
        }
        return $podados;
    }

    /** @return list<array<string,mixed>> */
    public function listar(int $limite = 20): array
    {
        return $this->bd->consultar(
            'SELECT identificador, tipo, estado, bytes, externo, duracion_ms, error, creado_en, eliminado_en FROM respaldos ORDER BY id DESC LIMIT ' . max(1, min(200, $limite))
        );
    }

    /** Horas desde la última copia diaria completada, o null si nunca hubo una (panel de salud). */
    public function horasDesdeUltimaDiaria(): ?float
    {
        $segundos = $this->bd->valor(
            "SELECT TIMESTAMPDIFF(SECOND, MAX(creado_en), UTC_TIMESTAMP()) FROM respaldos WHERE tipo = 'diaria' AND estado = 'completado'"
        );
        return $segundos === null ? null : round((int) $segundos / 3600, 1);
    }

    public function marcarExterno(string $identificador): void
    {
        $this->bd->ejecutar('UPDATE respaldos SET externo = 1 WHERE identificador = :i', ['i' => $identificador]);
    }

    public function directorio(): string
    {
        $directorio = $this->config['directorio'];
        if (!is_dir($directorio) && !mkdir($directorio, 0700, true) && !is_dir($directorio)) {
            throw new RuntimeException('No se pudo crear el directorio de copias.');
        }
        return $directorio;
    }

    /** @return array{identificador:string,archivos:list<array{nombre:string,bytes:int,sha256:string}>} */
    private function manifiesto(string $identificador): array
    {
        if (preg_match('/^kora-[a-z]+-\d{8}T\d{6}Z-[0-9a-f]{4}$/', $identificador) !== 1) {
            throw new RuntimeException('Identificador de copia no válido.');
        }
        $ruta = $this->directorio() . "/{$identificador}.manifiesto.json";
        $datos = is_file($ruta) ? json_decode((string) file_get_contents($ruta), true) : null;
        if (!is_array($datos) || !is_array($datos['archivos'] ?? null)) {
            throw new RuntimeException("No está el manifiesto de la copia {$identificador}.");
        }
        /** @var array{identificador:string,archivos:list<array{nombre:string,bytes:int,sha256:string}>} $datos */
        return $datos;
    }

    private function volcar(string $base, string $destino): void
    {
        $opciones = $this->archivoOpciones();
        $comando = [$this->config['mysqldump'], '--defaults-extra-file=' . $opciones, '--single-transaction', '--quick', '--triggers',
            '--hex-blob', '--no-tablespaces', '--default-character-set=utf8mb4'];
        if ($this->admiteOpcion($this->config['mysqldump'], 'column-statistics')) {
            // mysqldump 8 contra MariaDB o MySQL 5.7: sin esta opción consulta tablas de histogramas que no existen.
            $comando[] = '--column-statistics=0';
        }
        $comando[] = $base;
        $errores = tempnam(sys_get_temp_dir(), 'kora');
        $salida = gzopen($destino, 'wb6');
        try {
            $proceso = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['file', (string) $errores, 'w']], $tuberias);
            if (!is_resource($proceso) || $salida === false) {
                throw new RuntimeException('No se pudo ejecutar mysqldump (revisa RESPALDO_MYSQLDUMP).');
            }
            while (!feof($tuberias[1])) {
                $bloque = fread($tuberias[1], 1048576);
                if ($bloque !== false && $bloque !== '') {
                    gzwrite($salida, $bloque);
                }
            }
            fclose($tuberias[1]);
            $codigo = proc_close($proceso);
            if ($codigo !== 0) {
                throw new RuntimeException('mysqldump terminó con código ' . $codigo . ': ' . self::limpiarError((string) file_get_contents((string) $errores)));
            }
        } finally {
            if ($salida !== false) {
                gzclose($salida);
            }
            @unlink((string) $errores);
            @unlink($opciones);
        }
        chmod($destino, 0600);
    }

    private function importar(string $base, string $origenGz): void
    {
        $opciones = $this->archivoOpciones();
        $errores = tempnam(sys_get_temp_dir(), 'kora');
        $entrada = gzopen($origenGz, 'rb');
        try {
            $proceso = proc_open(
                [$this->config['mysql'], '--defaults-extra-file=' . $opciones, '--default-character-set=utf8mb4', $base],
                [0 => ['pipe', 'r'], 1 => ['file', (string) $errores, 'a'], 2 => ['file', (string) $errores, 'a']],
                $tuberias
            );
            if (!is_resource($proceso) || $entrada === false) {
                throw new RuntimeException('No se pudo ejecutar el cliente mysql (revisa RESPALDO_MYSQL).');
            }
            while (!gzeof($entrada)) {
                $bloque = gzread($entrada, 1048576);
                if ($bloque === false || $bloque === '') {
                    break;
                }
                fwrite($tuberias[0], $bloque);
            }
            fclose($tuberias[0]);
            $codigo = proc_close($proceso);
            if ($codigo !== 0) {
                throw new RuntimeException('La restauración terminó con código ' . $codigo . ': ' . self::limpiarError((string) file_get_contents((string) $errores)));
            }
        } finally {
            if ($entrada !== false) {
                gzclose($entrada);
            }
            @unlink((string) $errores);
            @unlink($opciones);
        }
    }

    private function comprimirAlmacenamiento(string $destino): void
    {
        $raiz = rtrim(str_replace('\\', '/', $this->config['almacenamiento']), '/');
        $zip = new ZipArchive();
        if ($zip->open($destino, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('No se pudo crear el ZIP del almacenamiento.');
        }
        $zip->addFromString('LEEME.txt', "Almacenamiento privado de KORA (sin caché, bloqueos, latidos ni copias).\n");
        if (is_dir($raiz)) {
            $iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS));
            foreach ($iterador as $archivo) {
                /** @var \SplFileInfo $archivo */
                $ruta = str_replace('\\', '/', $archivo->getPathname());
                $relativa = ltrim(substr($ruta, strlen($raiz)), '/');
                $primera = explode('/', $relativa)[0];
                if (!$archivo->isFile() || in_array($primera, self::EXCLUIDOS, true) || str_ends_with($relativa, '.lock')) {
                    continue;
                }
                $zip->addFile($archivo->getPathname(), $relativa);
            }
        }
        if (!$zip->close()) {
            throw new RuntimeException('No se pudo cerrar el ZIP del almacenamiento.');
        }
        chmod($destino, 0600);
    }

    private function restaurarAlmacenamiento(string $identificador, string $destino, string $parLlaves): void
    {
        if (is_dir($destino) && (new FilesystemIterator($destino))->valid()) {
            throw new RuntimeException('El directorio de destino del almacenamiento no está vacío.');
        }
        $temporal = $this->directorio() . '/restaurar-' . bin2hex(random_bytes(6)) . '.zip';
        try {
            SobreCifrado::descifrar($this->directorio() . "/{$identificador}.almacenamiento.zip.kora", $temporal, $parLlaves);
            $zip = new ZipArchive();
            if ($zip->open($temporal) !== true || !$zip->extractTo($destino)) {
                throw new RuntimeException('No se pudo extraer el almacenamiento.');
            }
            $zip->close();
        } finally {
            @unlink($temporal);
        }
    }

    private function copiarExterno(string $identificador): bool
    {
        $destino = trim($this->config['destino_externo']);
        if ($destino === '' || !is_dir($destino)) {
            // Destino por SSH: lo copia scripts/backup.sh con rsync y luego marca la copia como externa.
            return false;
        }
        foreach (glob($this->directorio() . '/' . $identificador . '.*') ?: [] as $archivo) {
            if (!copy($archivo, rtrim($destino, '/\\') . '/' . basename($archivo))) {
                return false;
            }
        }
        return true;
    }

    /** Archivo [client] temporal con permisos 600: la contraseña nunca va en la línea de comandos (ps, registros). */
    private function archivoOpciones(): string
    {
        $bd = $this->config['bd'];
        $ruta = (string) tempnam(sys_get_temp_dir(), 'kora');
        chmod($ruta, 0600);
        $escapar = static fn (string $valor): string => '"' . addcslashes($valor, '\\"') . '"';
        file_put_contents($ruta, "[client]\nuser={$escapar($bd['usuario'])}\npassword={$escapar($bd['contrasena'])}\nhost={$bd['host']}\nport={$bd['puerto']}\n");
        return $ruta;
    }

    private function admiteOpcion(string $binario, string $opcion): bool
    {
        $proceso = proc_open([$binario, '--help'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias);
        if (!is_resource($proceso)) {
            return false;
        }
        $ayuda = (string) stream_get_contents($tuberias[1]);
        fclose($tuberias[1]);
        fclose($tuberias[2]);
        proc_close($proceso);
        return str_contains($ayuda, '--' . $opcion);
    }

    private static function limpiarError(string $texto): string
    {
        // Sin rutas ni credenciales en el registro: solo la primera línea útil del cliente.
        $lineas = array_values(array_filter(array_map('trim', explode("\n", $texto)), static fn (string $l): bool => $l !== '' && !str_contains($l, 'Using a password')));
        return mb_substr($lineas[0] ?? 'sin detalle', 0, 200);
    }
}
