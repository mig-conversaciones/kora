<?php

declare(strict_types=1);

namespace App\Cola;

use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Nucleo\Url;
use App\Nucleo\Vista;

/**
 * Despachador de trabajos (equivale a QueueDispatcher, 04-04a). Persiste el trabajo durable y responde 202
 * Accepted con Location y Retry-After solo después de persistirlo. Ante un duplicado válido (misma
 * X-Idempotency-Key) devuelve el trabajo existente en lugar de crear otro (RF-Q-002, RF-Q-003).
 *
 * La verificación del permiso del actor (Autorizador) se incorpora en la I2 con despachar(); en la I1 los
 * controladores validan antes de llamar a encolar().
 */
final class DespachadorCola
{
    /** @param array<string,mixed> $config config/cola.php */
    public function __construct(
        private readonly ConductorCola $conductor,
        private readonly Vista $vista,
        private readonly array $config,
    ) {
    }

    /**
     * Encola sin HTTP. Si hay una transacción abierta, el trabajo se confirma o se revierte con ella (outbox,
     * RF-Q-004). Un tipo no registrado o una carga excesiva es un error definitivo que no se persiste.
     *
     * @param array<string,mixed> $carga
     * @param array<string,mixed> $contexto actor_id, centro_id, unidad_id, alcance, clave_idempotencia, correlacion_id, retardo_segundos
     * @return array{id:int,ulid:string,estado:string,duplicado:bool}
     */
    public function encolar(string $tipo, array $carga, array $contexto = []): array
    {
        $definicion = $this->config['tipos'][$tipo] ?? null;
        if (!is_array($definicion)) {
            throw new ErrorDefinitivo("Tipo de trabajo no registrado: {$tipo}.");
        }
        if (strlen(json_encode($carga, JSON_THROW_ON_ERROR)) > (int) $this->config['carga_max_bytes']) {
            throw new ErrorDefinitivo('La carga del trabajo excede el máximo; guarde los datos y envíe una referencia.');
        }
        return $this->conductor->encolar([
            'tipo' => $tipo,
            'cola' => (string) $definicion['cola'],
            'carga' => $carga,
            'max_intentos' => (int) $definicion['max_intentos'],
            'prioridad' => (int) $definicion['prioridad'],
            'clave_idempotencia' => isset($contexto['clave_idempotencia']) ? (string) $contexto['clave_idempotencia'] : null,
            'actor_id' => isset($contexto['actor_id']) ? (int) $contexto['actor_id'] : null,
            'centro_id' => isset($contexto['centro_id']) ? (int) $contexto['centro_id'] : null,
            'unidad_id' => isset($contexto['unidad_id']) ? (int) $contexto['unidad_id'] : null,
            'alcance' => isset($contexto['alcance']) && is_array($contexto['alcance']) ? $contexto['alcance'] : null,
            'correlacion_id' => isset($contexto['correlacion_id']) ? (string) $contexto['correlacion_id'] : null,
            'retardo_segundos' => (int) ($contexto['retardo_segundos'] ?? 0),
        ]);
    }

    /** Clave de idempotencia de la solicitud, si tiene el formato admitido (RNF-010). */
    public static function claveIdempotencia(Solicitud $solicitud): ?string
    {
        $clave = $solicitud->cabecera('X-Idempotency-Key') ?? $solicitud->cabecera('Idempotency-Key');
        return ($clave !== null && preg_match('/^[A-Za-z0-9_-]{16,120}$/', $clave) === 1) ? $clave : null;
    }

    /**
     * 202 Accepted: fragmento de seguimiento para HTMX o JSON con la URL de estado.
     *
     * @param array{id:int,ulid:string,estado:string,duplicado:bool} $trabajo
     * @param string $rutaEstado ruta de la aplicación que consulta el estado (sin la ruta base)
     */
    public function respuestaAceptada(Solicitud $solicitud, array $trabajo, string $rutaEstado): Respuesta
    {
        $url = Url::a($rutaEstado);
        $espera = (int) $this->config['consulta_periodica_segundos'][0];
        $quiereJson = !$solicitud->esHtmx() && str_contains((string) $solicitud->cabecera('Accept'), 'application/json');
        $respuesta = $quiereJson
            ? Respuesta::json(['trabajo_id' => $trabajo['ulid'], 'estado' => $trabajo['estado'], 'url_estado' => $url, 'duplicado' => $trabajo['duplicado']], 202)
            : Respuesta::html($this->vista->fragmento('trabajos/estado', [
                'trabajo' => $trabajo + ['progreso' => 0, 'mensaje_progreso' => null, 'mensaje_error' => null, 'resultado' => null],
                'rutaEstado' => $rutaEstado,
                'espera' => $espera,
                'intento' => 1,
                'terminal' => false,
            ]), 202);
        return $respuesta
            ->conCabecera('Location', $url)
            ->conCabecera('Retry-After', (string) $espera)
            ->conCabecera('Cache-Control', 'no-store');
    }
}
