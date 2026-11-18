<?php

declare(strict_types=1);

namespace Pruebas\Unitarias;

use App\Intermediarios\Autorizar;
use App\Nucleo\Cifrador;
use App\Politicas\AlcanceDatos;
use App\Politicas\Autorizador;
use App\Politicas\UsuarioSesion;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioContrasenas;
use App\Servicios\ServicioTotp;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Piezas de seguridad sin base de datos (RAC-002 a RAC-012, RS-013, RDI-004, RAU-007). */
final class SeguridadTest extends TestCase
{
    /** RFC 6238, anexo B (SHA-1), truncado a 6 dígitos. */
    public function testTotpCumpleLosVectoresDeLaRfc6238(): void
    {
        $clave = '12345678901234567890';
        foreach ([59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037'] as $tiempo => $codigo) {
            self::assertSame($codigo, ServicioTotp::codigoParaPaso($clave, intdiv($tiempo, 30)), "T = {$tiempo}");
        }
    }

    public function testTotpVentanaYAntiReutilizacion(): void
    {
        $totp = new ServicioTotp();
        $semilla = $totp->generarSemilla();
        self::assertSame(32, strlen($semilla));
        $clave = (new \ReflectionMethod(ServicioTotp::class, 'desdeBase32'))->invoke(null, $semilla);
        $ahora = 1_800_000_000;
        $paso = intdiv($ahora, 30);
        self::assertSame($paso + 1, $totp->verificar($semilla, ServicioTotp::codigoParaPaso($clave, $paso + 1), null, $ahora), 'Tolerancia de +1 paso.');
        self::assertNull($totp->verificar($semilla, ServicioTotp::codigoParaPaso($clave, $paso + 2), null, $ahora), 'Fuera de la ventana.');
        self::assertNull($totp->verificar($semilla, ServicioTotp::codigoParaPaso($clave, $paso), $paso, $ahora), 'Mismo paso ya usado (RAC-012).');
        self::assertNull($totp->verificar($semilla, '12345', null, $ahora));
        self::assertStringStartsWith('otpauth://totp/KORA:', $totp->uriAprovisionamiento($semilla, 'a@b.co'));
    }

    public function testCifradorAutenticaElContextoYAdmiteRotacion(): void
    {
        $v1 = base64_encode(random_bytes(32));
        $v2 = base64_encode(random_bytes(32));
        $antiguo = Cifrador::desdeEntorno("v1:{$v1}");
        $sobre = $antiguo->cifrar('JBSWY3DPEHPK3PXP', 'usuarios.totp:42');
        self::assertStringStartsWith('v1.', $sobre);
        $rotado = Cifrador::desdeEntorno("v2:{$v2},v1:{$v1}");
        self::assertSame('JBSWY3DPEHPK3PXP', $rotado->descifrar($sobre, 'usuarios.totp:42'), 'Las claves anteriores siguen descifrando.');
        self::assertTrue($rotado->necesitaRecifrado($sobre));
        $this->expectException(RuntimeException::class);
        $rotado->descifrar($sobre, 'usuarios.totp:43');
    }

    public function testPoliticaDeContrasenasNist(): void
    {
        $servicio = new ServicioContrasenas((require RAIZ . '/config/seguridad.php')['contrasenas']);
        self::assertSame([], $servicio->validar('mi finca tiene vacas lecheras felices'));
        self::assertSame([], $servicio->validar('ñandú café 26', [], true), 'Unicode y espacios; 8 o más con 2FA.');
        self::assertNotSame([], $servicio->validar('ñandú café 26'), 'Sin 2FA el mínimo es 15 (13 puntos de código aquí).');
        self::assertNotSame([], $servicio->validar('passwordpassword'), 'Lista de contraseñas comunes.');
        self::assertNotSame([], $servicio->validar('aaaaaaaaaaaaaaaaaaa'), 'Repetitiva.');
        self::assertNotSame([], $servicio->validar('abcabcabcabcabcabc'), 'Bloque repetido.');
        self::assertNotSame([], $servicio->validar('produccioncab2026x', [], false), 'Contextual.');
        self::assertNotSame([], $servicio->validar('gloriamartinez2026', ['nombre' => 'Gloria Martínez']), 'Nombre del usuario.');
        self::assertSame([], $servicio->validar('mi finca en buga tiene vacas'), 'Una palabra de contexto dentro de una frase larga es válida.');
        self::assertNotSame([], $servicio->validar(str_repeat('x', 129) . 'abc'));
        $hash = $servicio->hashear('Café con leche y pan de bono');
        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue($servicio->verificar("Cafe\u{0301} con leche y pan de bono", $hash), 'Normalización NFC.');
    }

    public function testAutorizadorDeniegaPorDefecto(): void
    {
        $autorizador = new Autorizador();
        $usuario = new UsuarioSesion(1, 1, 'lider_unidad', ['hato.consultar']);
        self::assertTrue($autorizador->decidir($usuario, 'hato.consultar')->permitida());
        self::assertFalse($autorizador->decidir($usuario, 'hato.gestionar')->permitida());
        self::assertFalse($autorizador->decidir(null, 'hato.consultar')->permitida());
        self::assertFalse($autorizador->decidir($usuario, '')->permitida());
    }

    public function testAlcanceVacioNuncaSignificaTodos(): void
    {
        $vacio = AlcanceDatos::deUsuario(0, [], false);
        self::assertSame(['1 = 0', []], $vacio->condicionSql('t'));
        $unidad = AlcanceDatos::deUsuario(1, [7], false);
        [$sql, $parametros] = $unidad->condicionSql('r');
        self::assertSame('r.centro_id = :alcance_centro AND r.unidad_id IN (:alcance_unidad_0)', $sql);
        self::assertSame(['alcance_centro' => 1, 'alcance_unidad_0' => 7], $parametros);
        self::assertFalse($unidad->permiteUnidad(null), 'Un registro sin unidad solo lo ve el alcance de centro.');
    }

    public function testSanitizadorDeAuditoriaYRedireccionesSeguras(): void
    {
        $limpio = ServicioAuditoria::sanitizar(['contrasena' => 'x', 'datos' => ['token_csrf' => 'y', 'nombre' => 'Ana'], 'codigo' => '123456']);
        self::assertSame(['contrasena' => '[omitido]', 'datos' => ['token_csrf' => '[omitido]', 'nombre' => 'Ana'], 'codigo' => '[omitido]'], $limpio);
        self::assertSame('/sistema/colas', Autorizar::rutaInternaSegura('/sistema/colas'));
        foreach (['//malicioso.example', 'https://malicioso.example', '/a?b=1', '/../x', 'javascript:alert(1)'] as $peligrosa) {
            self::assertSame('', Autorizar::rutaInternaSegura($peligrosa), $peligrosa);
        }
    }
}
