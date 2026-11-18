<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Nucleo\BaseDatos;
use App\Nucleo\Cifrador;
use App\Nucleo\Contenedor;
use App\Nucleo\Entorno;
use App\Nucleo\Migrador;
use App\Nucleo\Registro;
use App\Nucleo\Solicitud;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioCuentas;
use App\Servicios\ServicioMantenimiento;
use App\Servicios\ServicioNotificacionesCuenta;
use App\Servicios\ServicioTotp;
use PHPUnit\Framework\TestCase;

/**
 * Identidad y acceso de extremo a extremo (FP-01; RAC-001 a RAC-029, RS-005, RAU-004, RF-MAN-001 a 003), con la
 * aplicación completa y la base de pruebas.
 */
final class IdentidadTest extends TestCase
{
    private const CONTRASENA = 'mi finca tiene vacas lecheras felices';

    private static BaseDatos $bd;
    private string $senal;

    public static function setUpBeforeClass(): void
    {
        self::$bd = new BaseDatos(require RAIZ . '/config/base_datos.php');
        $migrador = new Migrador(self::$bd, RAIZ . '/bd/migraciones');
        $migrador->aplicar();
        $migrador->sembrar(RAIZ . '/bd/semillas/001_identidad_y_estructura.sql');
    }

    protected function setUp(): void
    {
        foreach (['claves_idempotencia', 'codigos_respaldo_2fa', 'tokens_cuenta', 'sesiones', 'notificaciones', 'usuarios_unidades', 'usuarios_roles', 'trabajos_fallidos', 'trabajos', 'limites_tasa', 'eventos_auditoria'] as $tabla) {
            self::$bd->ejecutar("DELETE FROM {$tabla}");
        }
        self::$bd->ejecutar('DELETE FROM usuarios');
        self::$bd->ejecutar("UPDATE cadena_auditoria SET ultima_secuencia = 0, ultimo_hash = REPEAT('0', 64) WHERE id = 1");
        $this->senal = sys_get_temp_dir() . '/kora-senal-identidad-' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->senal)) {
            unlink($this->senal);
        }
    }

    public function testSuperadministradorActivaEnrolaY2faYAccedeAlSistema(): void
    {
        $navegador = $this->navegador();
        $token = $this->invitar('super@kora.local', 'Ana Superadmin', 'superadministrador', null, []);

        self::assertSame(200, $navegador->get('/cuenta/activar?token=' . $token)->estado);
        $navegador->post('/cuenta/activar', ['token' => $token, 'contrasena' => self::CONTRASENA, 'confirmacion' => self::CONTRASENA]);
        self::assertSame('/2fa/enrolar', $navegador->destino(), 'El rol exige 2FA antes de entrar (RAC-007, RAC-009).');

        // Sin completar la 2FA, el sistema no está disponible.
        $navegador->get('/sistema/colas');
        self::assertSame('/2fa/enrolar', $navegador->destino());

        $clave = $this->enrolar($navegador);
        self::assertStringContainsString('Guarda tus códigos de respaldo', (string) $navegador->ultima?->cuerpo);
        self::assertSame(10, preg_match_all('/<li><code>[A-Z2-9]{5}-[A-Z2-9]{5}<\/code><\/li>/', (string) $navegador->ultima?->cuerpo));
        self::assertNotNull(self::$bd->valor("SELECT totp_secreto_cifrado FROM usuarios WHERE correo = 'super@kora.local'"));
        self::assertStringStartsWith('v1.', (string) self::$bd->valor("SELECT totp_secreto_cifrado FROM usuarios WHERE correo = 'super@kora.local'"), 'La semilla se guarda cifrada (RS-013).');

        self::assertSame(200, $navegador->get('/sistema/colas')->estado);
        self::assertSame(200, $navegador->get('/sistema/mantenimiento')->estado);
        self::assertStringContainsString('Colas de trabajos', (string) $navegador->get('/')->cuerpo, 'El menú muestra lo que permiten sus permisos (RF-002).');

        // Activación del mantenimiento dentro de la ventana de reautenticación (RF-MAN-002).
        $panel = $navegador->get('/sistema/mantenimiento')->cuerpo;
        self::assertSame(1, preg_match('/name="version" value="(\d+)"/', $panel, $version));
        $respuesta = $navegador->postHtmx('/sistema/mantenimiento/activar', ['motivo' => 'Prueba', 'titulo' => 'Volvemos pronto', 'mensaje' => 'Actualizando.', 'version' => $version[1]]);
        self::assertSame(200, $respuesta->estado, $respuesta->cuerpo);
        self::assertFileExists($this->senal);
        self::assertSame('superadministrador', self::$bd->valor("SELECT actor_rol FROM eventos_auditoria WHERE accion = 'mantenimiento.activar'"));

        // Acceso excepcional: el superadministrador con 2FA sigue operando; queda auditado una vez (RF-MAN-009).
        self::assertSame(200, $navegador->get('/cuenta')->estado);
        self::assertSame(1, (int) self::$bd->valor("SELECT COUNT(*) FROM eventos_auditoria WHERE accion = 'mantenimiento.acceso_excepcional'"));
        unset($clave);
    }

    public function testIngresoCon2faRechazaCodigoReutilizadoYAceptaElSiguiente(): void
    {
        $navegador = $this->navegador();
        $this->crearActivo($navegador, 'admin@kora.local', 'Bruno Admin', 'administrador');
        $clave = $this->enrolar($navegador);
        $navegador->post('/salir');
        self::assertSame('/ingresar?aviso=salida', $navegador->destino());

        $navegador->get('/ingresar');
        $navegador->post('/ingresar', ['correo' => 'ADMIN@kora.local ', 'contrasena' => self::CONTRASENA]);
        self::assertSame('/2fa', $navegador->destino(), 'El correo se normaliza y se pide el segundo factor.');
        $navegador->get('/2fa');

        $paso = intdiv(time(), 30);
        $reutilizado = $navegador->post('/2fa', ['codigo' => ServicioTotp::codigoParaPaso($clave, $paso)]);
        self::assertSame(422, $reutilizado->estado, 'El código del paso ya usado en el enrolamiento se rechaza (RAC-012).');

        $navegador->post('/2fa', ['codigo' => ServicioTotp::codigoParaPaso($clave, $paso + 1)]);
        self::assertSame('/', $navegador->destino());
        self::assertSame('2fa', self::$bd->valor('SELECT nivel_autenticacion FROM sesiones WHERE revocada_en IS NULL AND usuario_id IS NOT NULL ORDER BY creada_en DESC LIMIT 1'));
    }

    public function testCodigoDeRespaldoSirveUnaSolaVez(): void
    {
        $navegador = $this->navegador();
        $this->crearActivo($navegador, 'admin2@kora.local', 'Carla Admin', 'administrador');
        $this->enrolar($navegador);
        preg_match('/<li><code>([A-Z2-9]{5}-[A-Z2-9]{5})<\/code><\/li>/', (string) $navegador->ultima?->cuerpo, $m);
        $codigo = $m[1];
        $navegador->post('/salir');

        $navegador->get('/ingresar');
        $navegador->post('/ingresar', ['correo' => 'admin2@kora.local', 'contrasena' => self::CONTRASENA]);
        $navegador->get('/2fa');
        $navegador->post('/2fa', ['codigo' => $codigo]);
        self::assertSame('/', $navegador->destino());
        self::assertSame(9, (int) self::$bd->valor('SELECT COUNT(*) FROM codigos_respaldo_2fa WHERE usado_en IS NULL'));

        $navegador->post('/salir');
        $navegador->get('/ingresar');
        $navegador->post('/ingresar', ['correo' => 'admin2@kora.local', 'contrasena' => self::CONTRASENA]);
        $navegador->get('/2fa');
        self::assertSame(422, $navegador->post('/2fa', ['codigo' => $codigo])->estado);
    }

    public function testMensajeGenericoYLimiteDeIntentosPorCuenta(): void
    {
        $navegador = $this->navegador();
        $this->crearActivo($navegador, 'lider@kora.local', 'Diana Líder', 'lider_unidad', ['GAN']);
        $navegador->post('/salir');

        $navegador->get('/ingresar');
        $inexistente = $navegador->post('/ingresar', ['correo' => 'nadie@kora.local', 'contrasena' => 'lo que sea']);
        $incorrecta = $navegador->post('/ingresar', ['correo' => 'lider@kora.local', 'contrasena' => 'equivocada']);
        self::assertSame(422, $inexistente->estado);
        self::assertStringContainsString('Correo o contraseña incorrectos', $inexistente->cuerpo);
        self::assertStringContainsString('Correo o contraseña incorrectos', $incorrecta->cuerpo, 'Mismo mensaje exista o no la cuenta (RAC-005).');

        // Tras 5 fallos, la cuenta queda en espera temporal aunque luego llegue la contraseña correcta (RAC-029).
        self::$bd->ejecutar('UPDATE limites_tasa SET bloqueado_hasta = NULL');
        for ($i = 0; $i < 4; $i++) {
            self::$bd->ejecutar('UPDATE limites_tasa SET bloqueado_hasta = NULL');
            $navegador->post('/ingresar', ['correo' => 'lider@kora.local', 'contrasena' => 'equivocada']);
        }
        $bloqueada = $navegador->post('/ingresar', ['correo' => 'lider@kora.local', 'contrasena' => self::CONTRASENA]);
        self::assertSame(429, $bloqueada->estado);
        self::assertStringContainsString('Demasiados intentos', $bloqueada->cuerpo);
    }

    public function testCsrfObligatorioEnLasEscrituras(): void
    {
        $navegador = $this->navegador();
        $navegador->get('/ingresar');
        $sinToken = $navegador->solicitar('POST', '/ingresar', ['correo' => 'a@b.co', 'contrasena' => 'x']);
        self::assertSame(403, $sinToken->estado);
        $origenAjeno = $navegador->post('/ingresar', ['correo' => 'a@b.co', 'contrasena' => 'x'], ['origin' => 'https://sitio-malicioso.example']);
        self::assertSame(403, $origenAjeno->estado, 'Origin distinto se rechaza aunque el token sea válido (RS-005).');
        self::assertSame(2, (int) self::$bd->valor("SELECT COUNT(*) FROM eventos_auditoria WHERE accion = 'seguridad.csrf' AND resultado = 'denegado'"));
    }

    public function testLiderDeUnidadNoAccedeAlSistemaYSuAlcanceEsSuUnidad(): void
    {
        $navegador = $this->navegador();
        $this->crearActivo($navegador, 'lider2@kora.local', 'Elena Líder', 'lider_unidad', ['GAN']);
        self::assertSame(403, $navegador->get('/sistema/colas')->estado, 'Sin el permiso sistema.colas (RAC-028).');
        self::assertSame('denegado', self::$bd->valor("SELECT resultado FROM eventos_auditoria WHERE accion = 'sistema.colas'"));
        self::assertStringNotContainsString('Colas de trabajos', (string) $navegador->get('/')->cuerpo, 'El menú no muestra lo que no puede usar.');

        $usuario = (new \App\Repositorios\RepositorioUsuarios(self::$bd))->identidad((int) self::$bd->valor("SELECT id FROM usuarios WHERE correo = 'lider2@kora.local'"));
        self::assertNotNull($usuario);
        $alcance = (new \App\Servicios\ServicioAlcance())->paraUsuario($usuario);
        $ganaderia = (int) self::$bd->valor("SELECT id FROM unidades_productivas WHERE codigo = 'GAN'");
        $granja = (int) self::$bd->valor("SELECT id FROM unidades_productivas WHERE codigo = 'GRA'");
        self::assertTrue($alcance->permiteUnidad($ganaderia));
        self::assertFalse($alcance->permiteUnidad($granja), 'Otra unidad queda fuera del alcance (RDI-003).');
    }

    public function testRecuperacionCambiaLaContrasenaYCierraTodasLasSesiones(): void
    {
        $navegador = $this->navegador();
        $this->crearActivo($navegador, 'recaudo@kora.local', 'Fabio Recaudo', 'grupo_recaudo');
        $otro = $this->navegador();
        $otro->get('/ingresar');
        $otro->post('/ingresar', ['correo' => 'recaudo@kora.local', 'contrasena' => self::CONTRASENA]);
        self::assertSame('/', $otro->destino());

        $anonimo = $this->navegador('190.85.12.99');
        $anonimo->get('/cuenta/recuperar');
        $respuesta = $anonimo->post('/cuenta/recuperar', ['correo' => 'recaudo@kora.local']);
        self::assertStringContainsString('Si la cuenta existe', $respuesta->cuerpo);
        $token = $this->tokenDelCorreo('recuperacion');
        $nueva = 'el cafe de la tarde en el centro de buga es excelente';
        $anonimo->get('/cuenta/restablecer?token=' . $token);
        $anonimo->post('/cuenta/restablecer', ['token' => $token, 'contrasena' => $nueva, 'confirmacion' => $nueva]);
        self::assertSame('/ingresar?aviso=restablecida', $anonimo->destino());

        $otro->get('/cuenta');
        self::assertSame('/ingresar', explode('?', $otro->destino())[0], 'Las sesiones abiertas se cerraron (RAC-006).');
        self::assertSame(410, $anonimo->get('/cuenta/restablecer?token=' . $token)->estado, 'El enlace es de un solo uso.');
    }

    public function testContrasenaDebilSeRechazaConMotivo(): void
    {
        $navegador = $this->navegador();
        $token = $this->invitar('coord@kora.local', 'Gloria Coordinación', 'coordinacion_administrativa', 1, []);
        $navegador->get('/cuenta/activar?token=' . $token);
        $corta = $navegador->post('/cuenta/activar', ['token' => $token, 'contrasena' => 'corta', 'confirmacion' => 'corta']);
        self::assertSame(422, $corta->estado);
        self::assertStringContainsString('Usa al menos 15 caracteres', $corta->cuerpo);
        $comun = $navegador->post('/cuenta/activar', ['token' => $token, 'contrasena' => 'passwordpassword', 'confirmacion' => 'passwordpassword']);
        self::assertSame(422, $comun->estado);
        self::assertSame('invitado', self::$bd->valor("SELECT estado FROM usuarios WHERE correo = 'coord@kora.local'"));
    }

    public function testSesionVencidaPorInactividad(): void
    {
        $navegador = $this->navegador();
        $this->crearActivo($navegador, 'academica@kora.local', 'Hugo Académico', 'coordinacion_academica');
        self::assertSame(200, $navegador->get('/cuenta')->estado);
        self::$bd->ejecutar('UPDATE sesiones SET ultima_actividad_en = UTC_TIMESTAMP(3) - INTERVAL 31 MINUTE WHERE usuario_id IS NOT NULL');
        $navegador->get('/cuenta');
        self::assertStringStartsWith('/ingresar', $navegador->destino(), 'A los 30 min de inactividad la sesión vence (RAC-019).');
    }

    public function testIdempotenciaRepiteLaRespuestaConLaMismaClave(): void
    {
        $navegador = $this->navegador();
        $this->crearActivo($navegador, 'prod@kora.local', 'Iván Producción', 'lider_produccion_centro');
        $navegador->get('/cuenta');
        $clave = str_repeat('k', 24);
        $primera = $navegador->postHtmx('/cuenta/sesiones/cerrar-otras', [], ['x-idempotency-key' => $clave]);
        $segunda = $navegador->postHtmx('/cuenta/sesiones/cerrar-otras', [], ['x-idempotency-key' => $clave]);
        self::assertSame(200, $primera->estado);
        self::assertSame('true', $segunda->cabeceras['Idempotent-Replayed'] ?? null, 'El reintento repite la respuesta sin ejecutar de nuevo (RNF-010).');
        self::assertSame(1, (int) self::$bd->valor("SELECT COUNT(*) FROM eventos_auditoria WHERE accion = 'sesion.revocar_otras'"));
    }

    public function testCadenaDeAuditoriaDetectaManipulacion(): void
    {
        $navegador = $this->navegador();
        $this->crearActivo($navegador, 'academica2@kora.local', 'Julia Académica', 'coordinacion_academica');
        $auditoria = new ServicioAuditoria(self::$bd, new Registro(sys_get_temp_dir() . '/kora-registros-pruebas'));
        self::assertGreaterThan(0, $auditoria->sellarPendientes(500, 0));
        $verificacion = $auditoria->verificarCadena();
        self::assertTrue($verificacion['integra'], $verificacion['detalle']);
        self::assertNull(self::$bd->valor("SELECT id FROM eventos_auditoria WHERE metadatos LIKE '%contrasena\":\"%' AND metadatos NOT LIKE '%[omitido]%'"), 'Sin secretos en la auditoría (RAU-007).');

        self::$bd->ejecutar("UPDATE eventos_auditoria SET resultado = 'denegado' WHERE secuencia_sello = 2");
        $alterada = $auditoria->verificarCadena();
        self::assertFalse($alterada['integra']);
        self::assertSame(2, $alterada['primera_falla']);
    }

    public function testMatrizDeAutorizacionDeLasRutasDelSistema(): void
    {
        // RAC-025: cada rol de la semilla frente a las rutas protegidas registradas.
        $rutas = ['sistema.colas' => '/sistema/colas', 'mantenimiento.gestionar' => '/sistema/mantenimiento'];
        $roles = array_column(self::$bd->consultar('SELECT codigo FROM roles'), 'codigo');
        foreach ($roles as $rol) {
            if ($rol === 'superadministrador' || $rol === 'administrador') {
                continue; // exigen 2FA: cubiertos por testSuperadministradorActivaEnrolaY2faYAccedeAlSistema
            }
            $navegador = $this->navegador('190.85.12.' . random_int(100, 250));
            $this->crearActivo($navegador, "{$rol}@matriz.local", "Rol {$rol}", $rol, $rol === 'lider_unidad' ? ['GAN'] : []);
            foreach ($rutas as $permiso => $ruta) {
                $esperado = (int) self::$bd->valor(
                    'SELECT COUNT(*) FROM roles_permisos rp JOIN roles r ON r.id = rp.rol_id JOIN permisos p ON p.id = rp.permiso_id WHERE r.codigo = :rol AND p.codigo = :permiso',
                    ['rol' => $rol, 'permiso' => $permiso]
                ) === 1 ? 200 : 403;
                self::assertSame($esperado, $navegador->get($ruta)->estado, "{$rol} → {$ruta}");
            }
        }
        // Todo permiso declarado por una ruta existe en el catálogo (sin erratas que denegarían en silencio).
        foreach (['sistema.colas', 'mantenimiento.gestionar', 'trabajos.consultar_propios'] as $permiso) {
            self::assertSame(1, (int) self::$bd->valor('SELECT COUNT(*) FROM permisos WHERE codigo = :p', ['p' => $permiso]));
        }
    }

    private function navegador(string $ip = '190.85.12.34'): NavegadorPrueba
    {
        $senal = $this->senal;
        return new NavegadorPrueba(static function (Contenedor $c) use ($senal): void {
            $c->establecer(ServicioMantenimiento::class, new ServicioMantenimiento(
                $c->obtener(BaseDatos::class),
                $c->obtener(ServicioAuditoria::class),
                ['senal' => $senal, 'latido_trabajador' => sys_get_temp_dir() . '/kora-latido-inexistente.txt'] + require RAIZ . '/config/mantenimiento.php',
                '006'
            ));
        }, $ip);
    }

    /** @param list<string> $unidades códigos de unidad */
    private function invitar(string $correo, string $nombre, string $rol, ?int $centro, array $unidades): string
    {
        $contenedor = \App\Nucleo\Contenedor::desdeConfiguracion(require RAIZ . '/config/app.php');
        $centroId = $centro === null ? null : (int) self::$bd->valor("SELECT id FROM centros WHERE codigo = '9124'");
        $ids = array_map(static fn (string $c): int => (int) self::$bd->valor('SELECT id FROM unidades_productivas WHERE codigo = :c', ['c' => $c]), $unidades);
        $solicitud = (new Solicitud('CLI', '/cli', [], [], [], [], ''))->conAtributo('origen', 'cli');
        $contenedor->obtener(ServicioCuentas::class)->invitar($solicitud, $correo, $nombre, $rol, $centroId, $ids);
        return $this->tokenDelCorreo('invitacion');
    }

    /** @param list<string> $unidades */
    private function crearActivo(NavegadorPrueba $navegador, string $correo, string $nombre, string $rol, array $unidades = []): void
    {
        $token = $this->invitar($correo, $nombre, $rol, $rol === 'superadministrador' ? null : 1, $unidades);
        $navegador->get('/cuenta/activar?token=' . $token);
        $navegador->post('/cuenta/activar', ['token' => $token, 'contrasena' => self::CONTRASENA, 'confirmacion' => self::CONTRASENA]);
        if ($navegador->destino() === '/2fa/enrolar') {
            return;
        }
        self::assertSame('/', $navegador->destino(), (string) $navegador->ultima?->cuerpo);
    }

    /** Enrola la 2FA desde la página y devuelve la clave TOTP en binario. */
    private function enrolar(NavegadorPrueba $navegador): string
    {
        $pagina = $navegador->get('/2fa/enrolar')->cuerpo;
        self::assertSame(1, preg_match('/<code class="clave">([A-Z2-7 ]+)<\/code>/', $pagina, $m), 'La página muestra la clave manual.');
        $clave = self::desdeBase32(str_replace(' ', '', $m[1]));
        $navegador->post('/2fa/enrolar', ['codigo' => ServicioTotp::codigoParaPaso($clave, intdiv(time(), 30))]);
        return $clave;
    }

    /** Token del último correo de cuenta encolado (los datos van cifrados en la carga, RAU-007). */
    private function tokenDelCorreo(string $plantilla): string
    {
        $cargas = self::$bd->consultar("SELECT carga FROM trabajos WHERE tipo = 'correo.sistema' ORDER BY id DESC");
        $cifrador = Cifrador::desdeEntorno(Entorno::texto('CLAVES_CIFRADO'));
        foreach ($cargas as $fila) {
            $carga = json_decode((string) $fila['carga'], true);
            if (($carga['plantilla'] ?? '') === $plantilla) {
                self::assertArrayNotHasKey('datos', $carga, 'El enlace con token no viaja en claro.');
                $datos = json_decode($cifrador->descifrar($carga['datos_cifrados'], ServicioNotificacionesCuenta::CONTEXTO_CIFRADO), true);
                parse_str((string) parse_url($datos['enlace'], PHP_URL_QUERY), $consulta);
                return (string) $consulta['token'];
            }
        }
        self::fail("No se encoló el correo {$plantilla}.");
    }

    private static function desdeBase32(string $texto): string
    {
        $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($texto) as $c) {
            $bits .= str_pad(decbin((int) strpos($alfabeto, $c)), 5, '0', STR_PAD_LEFT);
        }
        $binario = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $binario .= chr((int) bindec($byte));
            }
        }
        return $binario;
    }
}
