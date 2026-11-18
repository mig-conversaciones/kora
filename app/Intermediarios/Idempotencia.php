<?php

declare(strict_types=1);

namespace App\Intermediarios;

use App\Nucleo\BaseDatos;
use App\Nucleo\ErrorHttp;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Politicas\UsuarioSesion;
use PDOException;

/**
 * Idempotencia de las escrituras autenticadas (RNF-010; tabla claves_idempotencia). retroalimentacion-ui.js envía
 * una X-Idempotency-Key por formulario y la reutiliza en los reintentos:
 *   - Mismo usuario, misma clave y misma solicitud ya completada → se repite la respuesta guardada.
 *   - Misma clave con otra solicitud (otra ruta o cuerpo) → 422.
 *   - Misma clave aún en curso → 409.
 * Las respuestas 5xx no se guardan (pueden reintentarse). Las claves vencen a las 24 h.
 */
final class Idempotencia implements Intermediario
{
    private const CABECERAS_GUARDADAS = ['Content-Type', 'HX-Trigger', 'HX-Redirect', 'HX-Retarget', 'HX-Reswap', 'Location', 'X-Toast-Type', 'Retry-After'];

    public function __construct(private readonly BaseDatos $bd)
    {
    }

    public function procesar(Solicitud $solicitud, callable $siguiente): Respuesta
    {
        $usuario = $solicitud->atributo('usuario');
        $clave = (string) $solicitud->cabecera('X-Idempotency-Key');
        if (!$solicitud->esEscritura() || !$usuario instanceof UsuarioSesion || preg_match('/^[A-Za-z0-9_-]{16,120}$/', $clave) !== 1) {
            return $siguiente($solicitud);
        }
        $cuerpo = $solicitud->cuerpo;
        unset($cuerpo['_csrf']);
        ksort($cuerpo);
        $hash = hash('sha256', $solicitud->metodo . ' ' . $solicitud->ruta . ' ' . json_encode($cuerpo, JSON_THROW_ON_ERROR));
        try {
            $this->bd->ejecutar(
                'INSERT INTO claves_idempotencia (usuario_id, clave, metodo, ruta, hash_solicitud, expira_en)
                 VALUES (:usuario, :clave, :metodo, :ruta, :hash, UTC_TIMESTAMP(3) + INTERVAL 24 HOUR)',
                ['usuario' => $usuario->id, 'clave' => $clave, 'metodo' => $solicitud->metodo, 'ruta' => mb_substr($solicitud->ruta, 0, 255), 'hash' => $hash]
            );
        } catch (PDOException $error) {
            if ($error->getCode() !== '23000') {
                throw $error;
            }
            return $this->repetir($usuario->id, $clave, $hash);
        }
        $respuesta = $siguiente($solicitud);
        if ($respuesta->estado >= 500) {
            $this->bd->ejecutar('DELETE FROM claves_idempotencia WHERE usuario_id = :usuario AND clave = :clave', ['usuario' => $usuario->id, 'clave' => $clave]);
            return $respuesta;
        }
        $this->bd->ejecutar(
            "UPDATE claves_idempotencia SET estado = 'completada', codigo_respuesta = :codigo, cabeceras_respuesta = :cabeceras, cuerpo_respuesta = :cuerpo
              WHERE usuario_id = :usuario AND clave = :clave",
            [
                'codigo' => $respuesta->estado,
                'cabeceras' => json_encode(array_intersect_key($respuesta->cabeceras, array_flip(self::CABECERAS_GUARDADAS)), JSON_THROW_ON_ERROR),
                'cuerpo' => mb_strcut($respuesta->cuerpo, 0, 1_000_000),
                'usuario' => $usuario->id,
                'clave' => $clave,
            ]
        );
        return $respuesta;
    }

    private function repetir(int $usuarioId, string $clave, string $hash): Respuesta
    {
        $fila = $this->bd->fila(
            'SELECT hash_solicitud, estado, codigo_respuesta, cabeceras_respuesta, cuerpo_respuesta FROM claves_idempotencia
              WHERE usuario_id = :usuario AND clave = :clave AND expira_en > UTC_TIMESTAMP(3)',
            ['usuario' => $usuarioId, 'clave' => $clave]
        );
        if ($fila === null) {
            throw new ErrorHttp(409, 'La operación anterior con esta clave venció. Recarga el formulario e intenta de nuevo.');
        }
        if (!hash_equals((string) $fila['hash_solicitud'], $hash)) {
            throw new ErrorHttp(422, 'Esta clave de operación ya se usó con otros datos. Recarga el formulario.');
        }
        if ($fila['estado'] !== 'completada') {
            throw new ErrorHttp(409, 'La operación anterior sigue en curso. Espera un momento.');
        }
        $cabeceras = json_decode((string) $fila['cabeceras_respuesta'], true);
        $respuesta = new Respuesta((string) $fila['cuerpo_respuesta'], (int) $fila['codigo_respuesta'], is_array($cabeceras) ? array_map('strval', $cabeceras) : []);
        return $respuesta->conCabecera('Idempotent-Replayed', 'true');
    }
}
