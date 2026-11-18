<?php

declare(strict_types=1);

namespace App\Nucleo;

use InvalidArgumentException;
use RuntimeException;

/**
 * Cifrado autenticado XChaCha20-Poly1305 (libsodium) con llavero versionado (RS-013, D-15). El sobre cifrado
 * lleva la versión de la clave: "v2.<base64url(nonce || texto cifrado)>". El contexto (por ejemplo
 * "usuarios.totp:42") se autentica como dato asociado para impedir mover un cifrado de una fila a otra.
 */
final class Cifrador
{
    /** @param array<string,string> $llavero versión => clave de 32 bytes; la primera es la vigente */
    public function __construct(private readonly array $llavero)
    {
        if ($llavero === []) {
            throw new InvalidArgumentException('El llavero de cifrado está vacío.');
        }
    }

    /** Formato del entorno: CLAVES_CIFRADO="v2:BASE64,v1:BASE64" (la primera cifra; todas descifran). */
    public static function desdeEntorno(string $valor): self
    {
        $llavero = [];
        foreach (explode(',', $valor) as $par) {
            [$version, $clave] = array_pad(explode(':', trim($par), 2), 2, '');
            $binaria = base64_decode($clave, true);
            if ($version === '' || $binaria === false || strlen($binaria) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                throw new InvalidArgumentException('Hay una clave inválida en el llavero de cifrado.');
            }
            $llavero[$version] = $binaria;
        }
        return new self($llavero);
    }

    public function cifrar(string $textoPlano, string $contexto): string
    {
        $version = (string) array_key_first($this->llavero);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cifrado = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($textoPlano, $contexto, $nonce, $this->llavero[$version]);
        return $version . '.' . sodium_bin2base64($nonce . $cifrado, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    public function descifrar(string $sobre, string $contexto): string
    {
        [$version, $contenido] = array_pad(explode('.', $sobre, 2), 2, '');
        $clave = $this->llavero[$version] ?? throw new RuntimeException('Versión de clave desconocida.');
        $binario = sodium_base642bin($contenido, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        $nonce = substr($binario, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cifrado = substr($binario, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $texto = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cifrado, $contexto, $nonce, $clave);
        if ($texto === false) {
            throw new RuntimeException('El dato cifrado fue alterado o la clave no corresponde.');
        }
        return $texto;
    }

    /** El trabajo seguridad.recifrar usa esta comprobación para migrar a la clave vigente tras una rotación. */
    public function necesitaRecifrado(string $sobre): bool
    {
        return !str_starts_with($sobre, array_key_first($this->llavero) . '.');
    }
}
