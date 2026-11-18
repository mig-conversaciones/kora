<?php

declare(strict_types=1);

/**
 * Administración de cuentas por SSH (solo el titular de la cuenta del servidor). La interfaz de usuarios llega en
 * la I3; estas operaciones existen desde la I2 para arrancar el sistema y recuperar el acceso:
 *
 *   php cli/usuarios.php crear-superadministrador --correo=persona@dominio --nombre="Nombre Apellido"
 *   php cli/usuarios.php invitar --correo=... --nombre="..." --rol=lider_unidad --unidades=GAN,GRA
 *   php cli/usuarios.php restablecer-2fa --correo=... --motivo="Teléfono perdido; identidad verificada por llamada"
 *
 * Nunca se escriben contraseñas en la consola: la cuenta se activa con el enlace de invitación (RAC-007), que se
 * envía por correo (en local, almacenamiento/correos/*.eml tras ejecutar el trabajador).
 */

use App\Nucleo\BaseDatos;
use App\Nucleo\ErrorHttp;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\Solicitud;
use App\Repositorios\RepositorioUsuarios;
use App\Servicios\ServicioCuentas;
use App\Servicios\ServicioSegundoFactor;

require __DIR__ . '/arranque.php';

$opciones = opcionesCli($argv);
$texto = static fn (string $clave): string => isset($opciones[$clave]) && is_string($opciones[$clave]) ? trim($opciones[$clave]) : '';
$accion = $argv[1] ?? '';
$contenedor = contenedorCli();
$solicitud = (new Solicitud('CLI', '/cli/usuarios', [], [], [], [], ''))
    ->conAtributo('request_id', bin2hex(random_bytes(16)))
    ->conAtributo('origen', 'cli');

try {
    if ($accion === 'crear-superadministrador' || $accion === 'invitar') {
        $bd = $contenedor->obtener(BaseDatos::class);
        $rol = $accion === 'crear-superadministrador' ? 'superadministrador' : $texto('rol');
        $centro = null;
        $unidades = [];
        if ($rol !== 'superadministrador') {
            $centro = (int) $bd->valor('SELECT id FROM centros WHERE codigo = :codigo', ['codigo' => $texto('centro') !== '' ? $texto('centro') : '9124']);
            foreach (array_filter(explode(',', $texto('unidades'))) as $codigo) {
                $unidad = $bd->valor('SELECT id FROM unidades_productivas WHERE centro_id = :centro AND codigo = :codigo', ['centro' => $centro, 'codigo' => strtoupper(trim($codigo))]);
                if ($unidad === null) {
                    throw new ErrorValidacion(['unidades' => "La unidad {$codigo} no existe."]);
                }
                $unidades[] = (int) $unidad;
            }
        }
        $id = $contenedor->obtener(ServicioCuentas::class)->invitar($solicitud, $texto('correo'), $texto('nombre'), $rol, $centro === 0 ? null : $centro, $unidades);
        escribirLinea("Invitación creada para el usuario {$id} ({$rol}). El enlace de activación (72 h) sale por correo al ejecutar el trabajador.");
        exit(0);
    }
    if ($accion === 'restablecer-2fa') {
        $usuario = $contenedor->obtener(RepositorioUsuarios::class)->porCorreo($texto('correo'));
        if ($usuario === null) {
            throw new ErrorHttp(404, 'No existe una cuenta con ese correo.');
        }
        $contenedor->obtener(ServicioSegundoFactor::class)->restablecerPorAdministrador($solicitud, (int) $usuario['id'], $texto('motivo'));
        escribirLinea('Verificación en dos pasos restablecida y sesiones cerradas. La persona deberá configurarla de nuevo al ingresar.');
        exit(0);
    }
    fwrite(STDERR, "Uso: php cli/usuarios.php crear-superadministrador|invitar|restablecer-2fa --correo=... [--nombre=...] [--rol=...] [--unidades=GAN] [--motivo=...]\n");
    exit(2);
} catch (ErrorValidacion $error) {
    foreach ($error->errores() as $campo => $mensaje) {
        fwrite(STDERR, "{$campo}: {$mensaje}\n");
    }
    exit(2);
} catch (ErrorHttp $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
