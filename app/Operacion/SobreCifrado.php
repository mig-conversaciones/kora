<?php

declare(strict_types=1);

namespace App\Operacion;

use RuntimeException;

/**
 * Cifrado de las copias de seguridad en sobre (RS-019, RDL-003): el servidor solo tiene la llave PÚBLICA del custodio,
 * así que una copia robada del servidor no se puede abrir con nada que esté en él.
 *
 *   - Cada archivo se cifra en bloques de 64 KiB con XChaCha20-Poly1305 (secretstream de libsodium): memoria constante y
 *     autenticación por bloque; un archivo truncado o alterado se rechaza (se exige la marca final).
 *   - La clave simétrica, aleatoria por archivo, va sellada con crypto_box_seal para la llave pública del custodio.
 *
 * Formato: "KORA-RESPALDO-1\n" | longitud de la clave sellada (2 bytes, big endian) | clave sellada | cabecera del
 * secretstream | bloques (4 bytes de longitud + bloque cifrado).
 */
final class SobreCifrado
{
    private const MAGIA = "KORA-RESPALDO-1\n";
    private const BLOQUE = 65536;

    /** @return array{publica:string,par:string} llaves en base64: la pública va al .env; el par lo guarda el custodio */
    public static function generarLlaves(): array
    {
        $par = sodium_crypto_box_keypair();
        return ['publica' => base64_encode(sodium_crypto_box_publickey($par)), 'par' => base64_encode($par)];
    }

    public static function publicaDelPar(string $parBase64): string
    {
        return base64_encode(sodium_crypto_box_publickey(self::decodificar($parBase64, SODIUM_CRYPTO_BOX_KEYPAIRBYTES, 'par de llaves')));
    }

    public static function cifrar(string $origen, string $destino, string $llavePublicaBase64): void
    {
        $publica = self::decodificar($llavePublicaBase64, SODIUM_CRYPTO_BOX_PUBLICKEYBYTES, 'llave pública');
        $clave = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        $sellada = sodium_crypto_box_seal($clave, $publica);
        [$estado, $cabecera] = sodium_crypto_secretstream_xchacha20poly1305_init_push($clave);
        sodium_memzero($clave);

        $entrada = self::abrir($origen, 'rb');
        $salida = self::abrir($destino, 'xb');
        try {
            fwrite($salida, self::MAGIA . pack('n', strlen($sellada)) . $sellada . $cabecera);
            $actual = (string) fread($entrada, self::BLOQUE);
            do {
                $siguiente = feof($entrada) ? '' : (string) fread($entrada, self::BLOQUE);
                $final = $siguiente === '' && feof($entrada);
                $cifrado = sodium_crypto_secretstream_xchacha20poly1305_push(
                    $estado,
                    $actual,
                    '',
                    $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE
                );
                fwrite($salida, pack('N', strlen($cifrado)) . $cifrado);
                $actual = $siguiente;
            } while (!$final);
        } finally {
            fclose($entrada);
            fclose($salida);
        }
        chmod($destino, 0600);
    }

    /** Descifra con el par de llaves del custodio. Lanza una excepción si el archivo está alterado o truncado. */
    public static function descifrar(string $origen, string $destino, string $parBase64): void
    {
        $par = self::decodificar($parBase64, SODIUM_CRYPTO_BOX_KEYPAIRBYTES, 'par de llaves');
        $entrada = self::abrir($origen, 'rb');
        $salida = self::abrir($destino, 'xb');
        $completo = false;
        try {
            if (fread($entrada, strlen(self::MAGIA)) !== self::MAGIA) {
                throw new RuntimeException('El archivo no es una copia cifrada de KORA.');
            }
            $longitud = unpack('n', (string) fread($entrada, 2));
            $sellada = (string) fread($entrada, (int) ($longitud[1] ?? 0));
            $clave = sodium_crypto_box_seal_open($sellada, $par);
            if ($clave === false) {
                throw new RuntimeException('La llave del custodio no corresponde a esta copia.');
            }
            $estado = sodium_crypto_secretstream_xchacha20poly1305_init_pull((string) fread($entrada, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES), $clave);
            sodium_memzero($clave);
            while (!feof($entrada)) {
                $prefijo = (string) fread($entrada, 4);
                if ($prefijo === '') {
                    break;
                }
                $tamano = unpack('N', $prefijo);
                $resultado = sodium_crypto_secretstream_xchacha20poly1305_pull($estado, (string) fread($entrada, (int) ($tamano[1] ?? 0)));
                if ($resultado === false) {
                    throw new RuntimeException('La copia está alterada o dañada (falla la autenticación de un bloque).');
                }
                [$claro, $marca] = $resultado;
                fwrite($salida, $claro);
                if ($marca === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    $completo = true;
                    break;
                }
            }
            if (!$completo) {
                throw new RuntimeException('La copia está truncada: falta el bloque final.');
            }
        } finally {
            fclose($entrada);
            fclose($salida);
            if (!$completo) {
                @unlink($destino);
            }
        }
    }

    private static function decodificar(string $base64, int $longitud, string $que): string
    {
        $binario = base64_decode(trim($base64), true);
        if ($binario === false || strlen($binario) !== $longitud) {
            throw new RuntimeException("La {$que} de las copias no es válida.");
        }
        return $binario;
    }

    /** @return resource */
    private static function abrir(string $ruta, string $modo)
    {
        $recurso = @fopen($ruta, $modo);
        if ($recurso === false) {
            throw new RuntimeException('No se pudo abrir ' . basename($ruta) . ($modo === 'xb' ? ' (¿ya existe?).' : '.'));
        }
        return $recurso;
    }
}
