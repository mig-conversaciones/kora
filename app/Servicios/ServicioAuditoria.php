<?php

declare(strict_types=1);

namespace App\Servicios;

use App\Nucleo\BaseDatos;
use App\Nucleo\Entorno;
use App\Nucleo\GeneradorUlid;
use App\Nucleo\Registro;
use App\Nucleo\Solicitud;
use App\Politicas\UsuarioSesion;
use Throwable;

/**
 * Auditoría funcional de solo inserción (RAU-001 a RAU-007, migración 004; base: 04-07, 4.7.6).
 *
 * - El evento se inserta en la misma transacción del cambio de negocio, si la hay (atomicidad).
 * - El programador lo sella cada minuto en una cadena SHA-256 con secuencia propia (D-39): sin bloqueo global en
 *   cada escritura y tolerando commits fuera de orden. Un evento queda sin sello 3 min como máximo.
 * - La verificación recorre la cadena completa y detecta alteraciones, borrados y reordenamientos.
 * - Nunca guarda secretos (sanitizador central, RAU-007); las IP se guardan tratadas (/24, /48) y con HMAC.
 */
final class ServicioAuditoria
{
    private const CLAVES_SENSIBLES = '/contrasena|password|token|secreto|codigo|otp|cookie|clave|semilla|csrf/i';

    public function __construct(private readonly BaseDatos $bd, private readonly Registro $registro)
    {
    }

    /**
     * @param array<string,mixed> $metadatos
     * @param array<string,mixed>|null $antes
     * @param array<string,mixed>|null $despues
     */
    public function registrar(
        Solicitud $solicitud,
        string $accion,
        string $resultado,
        ?object $recurso = null,
        array $metadatos = [],
        ?array $antes = null,
        ?array $despues = null,
        ?string $motivo = null,
    ): void {
        $usuario = $solicitud->atributo('usuario');
        $actor = $usuario instanceof UsuarioSesion ? $usuario : null;
        $this->bd->ejecutar(
            'INSERT INTO eventos_auditoria (evento_id, ocurrido_en, request_id, actor_id, actor_rol, centro_id, accion, recurso, recurso_id,
                                            resultado, origen, ip_tratada, ip_hmac, agente, valores_antes, valores_despues, motivo, metadatos)
             VALUES (:evento, UTC_TIMESTAMP(3), :request, :actor, :rol, :centro, :accion, :recurso, :recurso_id,
                     :resultado, :origen, :ip, :ip_hmac, :agente, :antes, :despues, :motivo, :metadatos)',
            [
                'evento' => GeneradorUlid::generar(),
                'request' => $this->idSolicitud($solicitud),
                'actor' => $actor?->id,
                'rol' => $actor?->rol,
                'centro' => $actor?->centroId,
                'accion' => mb_substr($accion, 0, 80),
                'recurso' => $recurso === null ? null : (new \ReflectionClass($recurso))->getShortName(),
                'recurso_id' => $recurso !== null && property_exists($recurso, 'id') ? (string) $recurso->id : null,
                'resultado' => $resultado,
                'origen' => $this->origen($solicitud),
                'ip' => self::tratarIp($solicitud->ip),
                'ip_hmac' => $this->hmacPii($solicitud->ip),
                'agente' => mb_substr((string) $solicitud->cabecera('User-Agent'), 0, 255) ?: null,
                'antes' => $antes === null ? null : json_encode(self::sanitizar($antes), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'despues' => $despues === null ? null : json_encode(self::sanitizar($despues), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'motivo' => $motivo === null ? null : mb_substr($motivo, 0, 500),
                'metadatos' => $metadatos === [] ? null : json_encode(self::sanitizar($metadatos), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]
        );
    }

    /**
     * Sella en lote los eventos con más de $antiguedadSegundos (cierra la ventana de commits fuera de orden).
     */
    public function sellarPendientes(int $lote = 500, int $antiguedadSegundos = 120): int
    {
        return $this->bd->transaccion(function (BaseDatos $bd) use ($lote, $antiguedadSegundos): int {
            $control = $bd->fila('SELECT ultima_secuencia, ultimo_hash FROM cadena_auditoria WHERE id = 1 FOR UPDATE');
            if ($control === null) {
                return 0;
            }
            $secuencia = (int) $control['ultima_secuencia'];
            $hash = (string) $control['ultimo_hash'];
            $eventos = $bd->consultar(
                'SELECT * FROM eventos_auditoria
                  WHERE hash_evento IS NULL AND ocurrido_en <= UTC_TIMESTAMP(3) - INTERVAL ' . max(0, $antiguedadSegundos) . ' SECOND
                  ORDER BY id LIMIT ' . max(1, min(2000, $lote))
            );
            foreach ($eventos as $evento) {
                $secuencia++;
                $nuevo = hash('sha256', $hash . '|' . $secuencia . '|' . self::canonico($evento));
                $bd->ejecutar(
                    'UPDATE eventos_auditoria SET secuencia_sello = :secuencia, hash_anterior = :anterior, hash_evento = :hash
                      WHERE id = :id AND hash_evento IS NULL',
                    ['secuencia' => $secuencia, 'anterior' => $hash, 'hash' => $nuevo, 'id' => (int) $evento['id']]
                );
                $hash = $nuevo;
            }
            if ($eventos !== []) {
                $bd->ejecutar(
                    'UPDATE cadena_auditoria SET ultima_secuencia = :secuencia, ultimo_hash = :hash WHERE id = 1',
                    ['secuencia' => $secuencia, 'hash' => $hash]
                );
            }
            return count($eventos);
        });
    }

    /**
     * Recorre la cadena completa y recalcula cada hash (RAU-004): detecta eventos alterados, borrados (hueco en la
     * secuencia) o reordenados, un control de cadena que no coincide y eventos sin sellar con más de 3 min.
     *
     * @return array{integra:bool,eventos:int,primera_falla:?int,detalle:string,sin_sellar:int}
     */
    public function verificarCadena(int $lote = 1000): array
    {
        $hash = str_repeat('0', 64);
        $esperada = 1;
        $total = 0;
        do {
            $eventos = $this->bd->consultar(
                'SELECT * FROM eventos_auditoria WHERE secuencia_sello >= :desde ORDER BY secuencia_sello LIMIT ' . max(1, $lote),
                ['desde' => $esperada]
            );
            foreach ($eventos as $evento) {
                $secuencia = (int) $evento['secuencia_sello'];
                if ($secuencia !== $esperada) {
                    return self::falla($total, $esperada, "Falta el evento con secuencia {$esperada}: la cadena tiene un hueco.");
                }
                $calculado = hash('sha256', $hash . '|' . $secuencia . '|' . self::canonico($evento));
                if ($evento['hash_anterior'] !== $hash || !hash_equals($calculado, (string) $evento['hash_evento'])) {
                    return self::falla($total, $secuencia, "El evento con secuencia {$secuencia} fue alterado.");
                }
                $hash = $calculado;
                $esperada++;
                $total++;
            }
        } while (count($eventos) === $lote);
        $control = $this->bd->fila('SELECT ultima_secuencia, ultimo_hash FROM cadena_auditoria WHERE id = 1');
        if ($control === null || (int) $control['ultima_secuencia'] !== $esperada - 1 || !hash_equals((string) $control['ultimo_hash'], $hash)) {
            return self::falla($total, $esperada, 'El control de la cadena no coincide con el último evento sellado.');
        }
        $sinSellar = (int) $this->bd->valor('SELECT COUNT(*) FROM eventos_auditoria WHERE hash_evento IS NULL AND ocurrido_en < UTC_TIMESTAMP(3) - INTERVAL 180 SECOND');
        return ['integra' => true, 'eventos' => $total, 'primera_falla' => null, 'detalle' => 'Cadena íntegra.', 'sin_sellar' => $sinSellar];
    }

    /**
     * Representación canónica estable del evento (sin las columnas del sello).
     *
     * @param array<string,mixed> $evento
     */
    public static function canonico(array $evento): string
    {
        $campos = ['id', 'evento_id', 'ocurrido_en', 'request_id', 'actor_id', 'actor_rol', 'centro_id', 'unidad_id', 'accion',
            'recurso', 'recurso_id', 'resultado', 'origen', 'ip_tratada', 'ip_hmac', 'agente', 'valores_antes',
            'valores_despues', 'motivo', 'metadatos'];
        $datos = [];
        foreach ($campos as $campo) {
            $datos[$campo] = ($evento[$campo] ?? null) === null ? null : (string) $evento[$campo];
        }
        return json_encode($datos, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Elimina secretos de los valores auditados (RAU-007).
     *
     * @param array<string,mixed> $valores
     * @return array<string,mixed>
     */
    public static function sanitizar(array $valores): array
    {
        foreach ($valores as $clave => $valor) {
            if (preg_match(self::CLAVES_SENSIBLES, (string) $clave) === 1) {
                $valores[$clave] = '[omitido]';
            } elseif (is_array($valor)) {
                $valores[$clave] = self::sanitizar($valor);
            }
        }
        return $valores;
    }

    /** HMAC de un dato personal (correo, IP) para correlacionar eventos sin guardarlo en claro (RAU-007). */
    public function hmacPii(string $valor): ?string
    {
        if ($valor === '') {
            return null;
        }
        $clave = base64_decode(Entorno::texto('CLAVE_HMAC_PII', ''), true);
        return $clave === false || $clave === '' ? null : hash_hmac('sha256', mb_strtolower($valor), $clave);
    }

    /**
     * Vuelca a la auditoría los eventos que la CLI dejó en un archivo mientras la base no estaba disponible
     * (RF-MAN-012). Renombra el archivo antes de leerlo para no perder eventos que lleguen durante el volcado.
     */
    public function volcarPendientes(string $archivo): int
    {
        if (!is_file($archivo)) {
            return 0;
        }
        $enProceso = $archivo . '.' . bin2hex(random_bytes(4)) . '.procesando';
        if (!rename($archivo, $enProceso)) {
            return 0;
        }
        $volcados = 0;
        foreach (file($enProceso, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
            $evento = json_decode($linea, true);
            if (!is_array($evento) || !isset($evento['accion'])) {
                continue;
            }
            try {
                $solicitud = (new Solicitud('CLI', '/cli', [], [], [], [], ''))->conAtributo('origen', 'cli');
                $this->registrar($solicitud, (string) $evento['accion'], 'exito', null, [
                    'sin_bd' => true,
                    'operador' => $evento['operador'] ?? null,
                    'ocurrido_en_cli' => $evento['ocurrido_en'] ?? null,
                ], null, null, isset($evento['motivo']) ? (string) $evento['motivo'] : null);
                $volcados++;
            } catch (Throwable $error) {
                $this->registro->error('auditoria.volcado_fallido', ['clase' => $error::class]);
                file_put_contents($archivo, $linea . PHP_EOL, FILE_APPEND | LOCK_EX);
            }
        }
        unlink($enProceso);
        return $volcados;
    }

    /** IPv4 → /24 (a.b.c.0); IPv6 → /48. Otra cosa → null. */
    public static function tratarIp(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return preg_replace('/\.\d+$/', '.0', $ip);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $binario = (string) inet_pton($ip);
            return (string) inet_ntop(substr($binario, 0, 6) . str_repeat("\0", 10)) . '/48';
        }
        return null;
    }

    /** @return array{integra:bool,eventos:int,primera_falla:?int,detalle:string,sin_sellar:int} */
    private static function falla(int $eventos, int $secuencia, string $detalle): array
    {
        return ['integra' => false, 'eventos' => $eventos, 'primera_falla' => $secuencia, 'detalle' => $detalle, 'sin_sellar' => 0];
    }

    private function idSolicitud(Solicitud $solicitud): ?string
    {
        $id = (string) $solicitud->atributo('request_id', '');
        return preg_match('/^[a-f0-9]{32}$/', $id) === 1 ? $id : null;
    }

    private function origen(Solicitud $solicitud): string
    {
        $origen = (string) $solicitud->atributo('origen', '');
        if (in_array($origen, ['web', 'htmx', 'cli', 'cron', 'api'], true)) {
            return $origen;
        }
        return PHP_SAPI === 'cli' && $solicitud->metodo === 'CLI' ? 'cli' : ($solicitud->esHtmx() ? 'htmx' : 'web');
    }
}
