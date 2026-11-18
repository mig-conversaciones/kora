<?php

declare(strict_types=1);

namespace App\Servicios;

use Normalizer;

/**
 * Política de contraseñas NIST SP 800-63B-4 (RAC-002 a RAC-004):
 *   - Mínimo 15 caracteres si la contraseña es el único factor y 8 con 2FA activa; máximo 128. Se admiten espacios
 *     y Unicode, normalizado a NFC; cada punto de código cuenta como un carácter. Sin reglas de composición ni
 *     caducidad periódica.
 *   - Se rechazan las contraseñas comunes (lista local), las contextuales (KORA, SENA, Buga, el nombre o el correo
 *     del usuario) y las repetitivas o secuenciales.
 *   - Hash Argon2id con rehash transparente si cambian los parámetros.
 */
final class ServicioContrasenas
{
    /** @var array<string,true>|null */
    private ?array $bloqueadas = null;

    /** @param array{algoritmo:string,opciones:array<string,int>,minimo_un_factor:int,minimo_con_2fa:int,maximo:int,lista_bloqueada:string,palabras_contexto:list<string>} $config */
    public function __construct(private readonly array $config)
    {
    }

    public static function normalizar(string $contrasena): string
    {
        $normalizada = Normalizer::normalize($contrasena, Normalizer::FORM_C);
        return $normalizada === false ? $contrasena : $normalizada;
    }

    /**
     * @param array{nombre?:string,correo?:string} $contexto
     * @return list<string> mensajes de error (vacío si es válida)
     */
    public function validar(string $contrasena, array $contexto = [], bool $con2fa = false): array
    {
        $contrasena = self::normalizar($contrasena);
        $longitud = mb_strlen($contrasena, 'UTF-8');
        $minimo = $con2fa ? $this->config['minimo_con_2fa'] : $this->config['minimo_un_factor'];
        if ($longitud < $minimo) {
            return ["Usa al menos {$minimo} caracteres. Una frase de varias palabras es fácil de recordar y difícil de adivinar."];
        }
        if ($longitud > $this->config['maximo']) {
            return ["Usa como máximo {$this->config['maximo']} caracteres."];
        }
        $minuscula = mb_strtolower($contrasena, 'UTF-8');
        $errores = [];
        if ($this->esComun($minuscula)) {
            $errores[] = 'Esa contraseña aparece en listas de contraseñas filtradas o muy usadas. Elige otra.';
        }
        if ($this->esContextual($minuscula, $contexto)) {
            $errores[] = 'No uses el nombre del sistema, del centro, tu nombre ni tu correo dentro de la contraseña.';
        }
        if (self::esRepetitivaOSecuencial($minuscula)) {
            $errores[] = 'Evita repeticiones o secuencias como «aaaaaaaa», «12345678» o «qwertyui».';
        }
        return $errores;
    }

    public function hashear(string $contrasena): string
    {
        return password_hash(self::normalizar($contrasena), $this->config['algoritmo'], $this->config['opciones']);
    }

    public function verificar(string $contrasena, string $hash): bool
    {
        return password_verify(self::normalizar($contrasena), $hash);
    }

    public function necesitaRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->config['algoritmo'], $this->config['opciones']);
    }

    /**
     * Hash de una contraseña aleatoria para igualar el tiempo de respuesta cuando el usuario no existe (RAC-005).
     */
    public function hashFicticio(): string
    {
        static $hash = null;
        return $hash ??= $this->hashear(bin2hex(random_bytes(16)));
    }

    private function esComun(string $minuscula): bool
    {
        if ($this->bloqueadas === null) {
            $this->bloqueadas = [];
            $archivo = $this->config['lista_bloqueada'];
            foreach (is_file($archivo) ? (file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $linea) {
                if ($linea[0] !== '#') {
                    $this->bloqueadas[$linea] = true;
                }
            }
        }
        return isset($this->bloqueadas[$minuscula]);
    }

    /** @param array{nombre?:string,correo?:string} $contexto */
    private function esContextual(string $minuscula, array $contexto): bool
    {
        $palabras = $this->config['palabras_contexto'];
        $local = strstr((string) ($contexto['correo'] ?? ''), '@', true);
        if (is_string($local) && mb_strlen($local) >= 4) {
            $palabras[] = mb_strtolower($local);
        }
        foreach (preg_split('/\s+/', mb_strtolower((string) ($contexto['nombre'] ?? ''))) ?: [] as $parte) {
            if (mb_strlen($parte) >= 4) {
                $palabras[] = $parte;
            }
        }
        $sinTildes = self::sinTildes($minuscula);
        // Se retiran juntas todas las palabras de contexto: si lo que queda es menos de la mitad, la contraseña es
        // esencialmente contextual («gloriamartinez2026», «produccioncab2026»). Una frase larga que solo menciona
        // una de ellas («mi finca en buga tiene vacas») sigue siendo válida.
        $palabras = array_values(array_filter(array_map(static fn (string $p): string => self::sinTildes($p), $palabras), static fn (string $p): bool => $p !== ''));
        usort($palabras, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $resto = str_replace($palabras, '', $sinTildes);
        return $resto !== $sinTildes && mb_strlen($resto) * 2 < mb_strlen($sinTildes);
    }

    public static function esRepetitivaOSecuencial(string $texto): bool
    {
        $caracteres = mb_str_split($texto);
        if (count(array_unique($caracteres)) <= 2) {
            return true;
        }
        foreach (['0123456789', 'abcdefghijklmnopqrstuvwxyz', 'qwertyuiopasdfghjklzxcvbnm'] as $serie) {
            if (str_contains($serie, $texto) || str_contains(strrev($serie), $texto)) {
                return true;
            }
        }
        // Un mismo bloque corto repetido («abcabcabc», «1212121212»).
        return preg_match('/^(.{1,4})\1{2,}$/u', $texto) === 1;
    }

    private static function sinTildes(string $texto): string
    {
        return strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    }
}
