<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Nucleo\BaseDatos;
use App\Nucleo\Cifrador;
use App\Nucleo\GeneradorUlid;
use App\Nucleo\Migrador;
use App\Operacion\ServicioRecifrado;
use App\Servicios\ServicioNotificacionesCuenta;
use PHPUnit\Framework\TestCase;

/**
 * Recifrado tras una rotación de claves (RS-013, D-15; ASVS V11.1.1): con el llavero «v2,v1», el secreto TOTP, los
 * correos pendientes o fallidos de la cola y los lotes de auditoría pasan a v2 con su mismo contexto; después la clave
 * v1 se puede retirar. Un trabajo reservado no se toca y una segunda ejecución no hace nada.
 */
final class RecifradoTest extends TestCase
{
    private static BaseDatos $bd;
    private string $directorio;

    public static function setUpBeforeClass(): void
    {
        self::$bd = new BaseDatos(require RAIZ . '/config/base_datos.php');
        $migrador = new Migrador(self::$bd, RAIZ . '/bd/migraciones');
        $migrador->aplicar();
        $migrador->sembrar(RAIZ . '/bd/semillas/001_identidad_y_estructura.sql');
    }

    protected function setUp(): void
    {
        foreach (['trabajos_fallidos', 'trabajos', 'codigos_respaldo_2fa', 'sesiones', 'usuarios_roles', 'usuarios_unidades'] as $tabla) {
            self::$bd->ejecutar("DELETE FROM {$tabla}");
        }
        self::$bd->ejecutar('DELETE FROM usuarios');
        $this->directorio = sys_get_temp_dir() . '/kora-recifrado-' . bin2hex(random_bytes(4));
        mkdir($this->directorio);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directorio . '/*') ?: [] as $archivo) {
            unlink($archivo);
        }
        rmdir($this->directorio);
        self::$bd->ejecutar('DELETE FROM trabajos');
        self::$bd->ejecutar('DELETE FROM usuarios');
    }

    public function testRotacionRecifraTodoConLaClaveVigenteYPermiteRetirarLaAnterior(): void
    {
        $v1 = base64_encode(random_bytes(32));
        $v2 = base64_encode(random_bytes(32));
        $anterior = Cifrador::desdeEntorno("v1:{$v1}");
        $rotado = Cifrador::desdeEntorno("v2:{$v2},v1:{$v1}");
        $soloNueva = Cifrador::desdeEntorno("v2:{$v2}");

        $usuario = self::$bd->insertar(
            "INSERT INTO usuarios (ulid, centro_id, correo, nombre, estado) VALUES (:u, (SELECT id FROM centros WHERE codigo = '9124'), 'totp@kora.local', 'Con TOTP', 'activo')",
            ['u' => GeneradorUlid::generar()]
        );
        self::$bd->ejecutar('UPDATE usuarios SET totp_secreto_cifrado = :s WHERE id = :id', ['s' => $anterior->cifrar('SEMILLA-TOTP-123', 'usuarios.totp:' . $usuario), 'id' => $usuario]);
        $correo = static fn (string $estado) => self::$bd->insertar(
            "INSERT INTO trabajos (ulid, cola, tipo, carga, estado, disponible_en) VALUES (:u, 'correo', 'correo.sistema', :c, :e, UTC_TIMESTAMP(3))",
            ['u' => GeneradorUlid::generar(), 'e' => $estado, 'c' => json_encode(['plantilla' => 'invitacion', 'usuario_id' => $usuario,
                'datos_cifrados' => $anterior->cifrar('{"enlace":"https://kora.invalid/cuenta/activar?token=abc"}', ServicioNotificacionesCuenta::CONTEXTO_CIFRADO)])]
        );
        $pendiente = $correo('pendiente');
        $reservado = $correo('reservado');
        $lote = $this->directorio . '/auditoria-2026-11-18.jsonl.cifrado';
        file_put_contents($lote, $anterior->cifrar("{\"fecha\":\"2026-11-18\"}\n{\"evento\":1}", 'auditoria.lote:2026-11-18'));

        $servicio = new ServicioRecifrado(self::$bd, $rotado, $this->directorio);
        self::assertSame(['totp' => 1, 'correos' => 1, 'lotes_auditoria' => 1, 'total' => 3], $servicio->pendientes(), 'El correo reservado no cuenta: termina con la clave anterior.');

        self::assertSame(['totp' => 1, 'correos' => 1, 'lotes_auditoria' => 1, 'pendientes' => 0], $servicio->recifrar());
        self::assertSame(['totp' => 0, 'correos' => 0, 'lotes_auditoria' => 0, 'pendientes' => 0], $servicio->recifrar(), 'Repetirlo no cambia nada.');

        // Ya no hace falta la clave v1 para nada de lo recifrado.
        $secreto = (string) self::$bd->valor('SELECT totp_secreto_cifrado FROM usuarios WHERE id = :id', ['id' => $usuario]);
        self::assertStringStartsWith('v2.', $secreto);
        self::assertSame('SEMILLA-TOTP-123', $soloNueva->descifrar($secreto, 'usuarios.totp:' . $usuario));
        $carga = json_decode((string) self::$bd->valor('SELECT carga FROM trabajos WHERE id = :id', ['id' => $pendiente]), true);
        self::assertSame('invitacion', $carga['plantilla']);
        self::assertStringContainsString('token=abc', $soloNueva->descifrar($carga['datos_cifrados'], ServicioNotificacionesCuenta::CONTEXTO_CIFRADO));
        self::assertStringContainsString('"evento":1', $soloNueva->descifrar((string) file_get_contents($lote), 'auditoria.lote:2026-11-18'));

        // El trabajo reservado conserva su sobre v1, que el llavero rotado todavía descifra.
        $cargaReservada = json_decode((string) self::$bd->valor('SELECT carga FROM trabajos WHERE id = :id', ['id' => $reservado]), true);
        self::assertStringStartsWith('v1.', $cargaReservada['datos_cifrados']);
        self::assertStringContainsString('token=abc', $rotado->descifrar($cargaReservada['datos_cifrados'], ServicioNotificacionesCuenta::CONTEXTO_CIFRADO));
    }

    public function testElTipoDeTrabajoEstaRegistrado(): void
    {
        self::assertArrayHasKey('seguridad.recifrar', (require RAIZ . '/config/cola.php')['tipos']);
    }
}
