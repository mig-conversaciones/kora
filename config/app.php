<?php

declare(strict_types=1);

/**
 * Configuración de la aplicación y fábricas del contenedor. Solo se declaran aquí las clases que necesitan
 * valores escalares o que se resuelven contra una interfaz; el resto se construye por autowiring
 * (App\Nucleo\Contenedor). La CLI (trabajador, programador, mantenimiento) usa este mismo archivo.
 */

use App\Cola\ConductorCola;
use App\Cola\ConductorColaMysql;
use App\Cola\DespachadorCola;
use App\Cola\Trabajador;
use App\Controladores\ControladorExportaciones;
use App\Controladores\ControladorSalud;
use App\Controladores\ControladorSistemaDiseno;
use App\Controladores\ControladorTrabajos;
use App\Importacion\ServicioCargaArchivos;
use App\Intermediarios\Autorizar;
use App\Intermediarios\CabecerasSeguridad;
use App\Intermediarios\VerificarCsrf;
use App\Nucleo\BaseDatos;
use App\Nucleo\Cache;
use App\Nucleo\Cifrador;
use App\Nucleo\Contenedor;
use App\Nucleo\Entorno;
use App\Nucleo\Funcionalidades;
use App\Nucleo\Registro;
use App\Nucleo\Sesion;
use App\Nucleo\Vista;
use App\Operacion\MonitorCertificado;
use App\Operacion\ServicioRespaldo;
use App\Operacion\VerificadorIntegridad;
use App\Politicas\Autorizador;
use App\Politicas\PoliticaAlcanceUnidad;
use App\Politicas\PoliticaRecaudos;
use App\Politicas\PoliticaUsuarios;
use App\Repositorios\RepositorioUsuarios;
use App\Servicios\Programador;
use App\Servicios\ServicioAdjuntos;
use App\Servicios\ServicioAlcance;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioCodigosRespaldo;
use App\Servicios\ServicioContrasenas;
use App\Servicios\ServicioCorreo;
use App\Servicios\ServicioLimiteTasa;
use App\Servicios\ServicioMantenimiento;
use App\Servicios\ServicioMonitorCola;
use App\Servicios\ServicioNotificacionesCuenta;
use App\Servicios\ServicioQr;
use App\Servicios\ServicioSegundoFactor;
use App\Servicios\ServicioTokensCuenta;
use App\Servicios\ServicioTotp;
use App\Tableros\ServicioExportacion;
use App\Tableros\ServicioIndicadores;
use App\Trabajos\ExportarLoteAuditoria;
use App\Trabajos\LimpiezaPeriodica;

$https = str_starts_with(Entorno::texto('APP_URL'), 'https://');
// Última migración que este código espera; /health/ready y la salida del mantenimiento la comparan (06-04).
$versionEsquema = '014';
$cola = static fn (): array => require RAIZ . '/config/cola.php';
$seguridad = static fn (): array => require RAIZ . '/config/seguridad.php';
$claveBinaria = static fn (string $variable): string => (string) base64_decode(Entorno::texto($variable), true);

return [
    'nombre' => Entorno::texto('APP_NOMBRE', 'KORA'),
    'entorno' => Entorno::texto('APP_ENTORNO', 'produccion'),
    'depuracion' => Entorno::booleano('APP_DEPURACION', false),
    'zona_horaria' => Entorno::texto('APP_ZONA_HORARIA', 'America/Bogota'),
    'version_esquema' => $versionEsquema,
    'servicios' => [
        BaseDatos::class => static fn (): BaseDatos => new BaseDatos(require RAIZ . '/config/base_datos.php'),
        Registro::class => static fn (): Registro => new Registro(RAIZ . '/almacenamiento/registros'),
        Funcionalidades::class => static fn (): Funcionalidades => new Funcionalidades(require RAIZ . '/config/funcionalidades.php'),
        Cache::class => static fn (Contenedor $c): Cache => new Cache(RAIZ . '/almacenamiento/cache', $c->obtener(Registro::class)),

        // Identidad y acceso (I2)
        Cifrador::class => static fn (): Cifrador => Cifrador::desdeEntorno(Entorno::texto('CLAVES_CIFRADO')),
        Sesion::class => static fn (Contenedor $c): Sesion => new Sesion(
            $c->obtener(BaseDatos::class),
            $seguridad()['sesion'],
            $seguridad()['cookie_sesion'],
            $claveBinaria('CLAVE_HMAC_PII'),
        ),
        ServicioContrasenas::class => static fn (): ServicioContrasenas => new ServicioContrasenas($seguridad()['contrasenas']),
        ServicioLimiteTasa::class => static fn (Contenedor $c): ServicioLimiteTasa => new ServicioLimiteTasa(
            $c->obtener(BaseDatos::class),
            $seguridad()['limites'],
            $claveBinaria('CLAVE_HMAC_PII'),
        ),
        ServicioTokensCuenta::class => static fn (Contenedor $c): ServicioTokensCuenta => new ServicioTokensCuenta($c->obtener(BaseDatos::class), $seguridad()['tokens_cuenta']),
        ServicioCodigosRespaldo::class => static fn (Contenedor $c): ServicioCodigosRespaldo => new ServicioCodigosRespaldo(
            $c->obtener(BaseDatos::class),
            $claveBinaria('CLAVE_HMAC_CODIGOS'),
            (int) $seguridad()['totp']['codigos_respaldo'],
        ),
        ServicioNotificacionesCuenta::class => static fn (Contenedor $c): ServicioNotificacionesCuenta => new ServicioNotificacionesCuenta(
            $c->obtener(DespachadorCola::class),
            $c->obtener(Cifrador::class),
            (string) $seguridad()['origen_permitido'],
        ),
        ServicioSegundoFactor::class => static fn (Contenedor $c): ServicioSegundoFactor => new ServicioSegundoFactor(
            $c->obtener(BaseDatos::class),
            $c->obtener(RepositorioUsuarios::class),
            $c->obtener(ServicioTotp::class),
            $c->obtener(ServicioCodigosRespaldo::class),
            $c->obtener(ServicioQr::class),
            $c->obtener(ServicioAuditoria::class),
            $c->obtener(ServicioNotificacionesCuenta::class),
            $c->obtener(Cifrador::class),
            (string) $seguridad()['totp']['emisor'],
        ),
        // Políticas por recurso, por prefijo del permiso (las de la operación productiva y los recaudos, desde la I4).
        Autorizador::class => static fn (): Autorizador => new Autorizador([
            'usuarios' => new PoliticaUsuarios(),
            'asignaciones' => new PoliticaAlcanceUnidad(),
            'gastos' => new PoliticaAlcanceUnidad(),
            'procesos' => new PoliticaAlcanceUnidad(),
            'recepciones' => new PoliticaAlcanceUnidad(),
            'salidas' => new PoliticaAlcanceUnidad(),
            'transformaciones' => new PoliticaAlcanceUnidad(),
            'hato' => new PoliticaAlcanceUnidad(),
            'calidad' => new PoliticaAlcanceUnidad(),
            'recaudos' => new PoliticaRecaudos(),
        ]),
        VerificarCsrf::class => static fn (Contenedor $c): VerificarCsrf => new VerificarCsrf(
            $c->obtener(Sesion::class),
            $c->obtener(ServicioAuditoria::class),
            (string) $seguridad()['origen_permitido'],
        ),
        Autorizar::class => static fn (Contenedor $c): Autorizar => new Autorizar(
            $c->obtener(Autorizador::class),
            $c->obtener(ServicioAlcance::class),
            $c->obtener(Funcionalidades::class),
            $c->obtener(ServicioAuditoria::class),
            $c->obtener(Sesion::class),
            (int) $seguridad()['reautenticacion_segundos'],
        ),
        // Soportes documentales en almacenamiento privado, fuera de public/ (RF-027, RS-011).
        ServicioAdjuntos::class => static fn (Contenedor $c): ServicioAdjuntos => new ServicioAdjuntos(
            $c->obtener(BaseDatos::class),
            RAIZ . '/almacenamiento/adjuntos',
        ),
        // Copias cifradas con la llave pública del custodio (RDL-003, RS-019; ADR-021).
        ServicioRespaldo::class => static fn (Contenedor $c): ServicioRespaldo => new ServicioRespaldo(
            $c->obtener(BaseDatos::class),
            [
                'directorio' => Entorno::texto('RESPALDO_DIRECTORIO', '') !== '' ? Entorno::texto('RESPALDO_DIRECTORIO') : RAIZ . '/almacenamiento/respaldos',
                'almacenamiento' => RAIZ . '/almacenamiento',
                'mysqldump' => Entorno::texto('RESPALDO_MYSQLDUMP', 'mysqldump'),
                'mysql' => Entorno::texto('RESPALDO_MYSQL', 'mysql'),
                'llave_publica' => Entorno::texto('RESPALDO_LLAVE_PUBLICA', ''),
                'destino_externo' => Entorno::texto('RESPALDO_DESTINO_EXTERNO', ''),
                'diarias' => Entorno::entero('RESPALDO_DIARIAS', 30),
                'mensuales' => Entorno::entero('RESPALDO_MENSUALES', 12),
                'umbral_importacion' => Entorno::entero('RESPALDO_IMPORTACION_FILAS', 500),
                'entorno' => Entorno::texto('APP_ENTORNO', 'produccion'),
                'bd' => [
                    'host' => Entorno::texto('BD_HOST', '127.0.0.1'),
                    'puerto' => Entorno::entero('BD_PUERTO', 3306),
                    'usuario' => Entorno::texto('BD_USUARIO'),
                    'contrasena' => Entorno::texto('BD_CONTRASENA', ''),
                    'nombre' => Entorno::texto('BD_NOMBRE'),
                ],
            ],
            $c->obtener(Registro::class),
        ),
        // Integridad de la versión activa y del .env (RS-012, RS-022): el manifiesto es obligatorio fuera del entorno local.
        VerificadorIntegridad::class => static fn (): VerificadorIntegridad => new VerificadorIntegridad(
            RAIZ,
            RAIZ . '/.env',
            RAIZ . '/almacenamiento/estado',
            Entorno::texto('APP_ENTORNO', 'produccion') !== 'local',
        ),
        // Libros de importación en almacenamiento privado, fuera de public/ (RF-084, RS-011).
        ServicioCargaArchivos::class => static fn (): ServicioCargaArchivos => new ServicioCargaArchivos(RAIZ . '/almacenamiento/importaciones'),
        ServicioExportacion::class => static fn (Contenedor $c): ServicioExportacion => new ServicioExportacion(
            $c->obtener(BaseDatos::class),
            $c->obtener(ServicioIndicadores::class),
            $c->obtener(DespachadorCola::class),
            $c->obtener(ServicioAuditoria::class),
            $c->obtener(Vista::class),
            RAIZ . '/almacenamiento/exportaciones',
        ),
        ExportarLoteAuditoria::class => static fn (Contenedor $c): ExportarLoteAuditoria => new ExportarLoteAuditoria(
            $c->obtener(BaseDatos::class),
            $c->obtener(Cifrador::class),
            RAIZ . '/almacenamiento/exportaciones/auditoria',
        ),
        ServicioMantenimiento::class => static fn (Contenedor $c): ServicioMantenimiento => new ServicioMantenimiento(
            $c->obtener(BaseDatos::class),
            $c->obtener(ServicioAuditoria::class),
            require RAIZ . '/config/mantenimiento.php',
            $versionEsquema,
        ),
        CabecerasSeguridad::class => static fn (): CabecerasSeguridad => new CabecerasSeguridad($https),
        Vista::class => static fn (): Vista => new Vista([
            'directorio' => RAIZ . '/app/Vistas',
            'manifiesto' => RAIZ . '/public/assets/manifest.json',
            'nombre_app' => Entorno::texto('APP_NOMBRE', 'KORA'),
            'entorno' => Entorno::texto('APP_ENTORNO', 'produccion'),
            'menu' => require RAIZ . '/config/menu.php',
            'config_htmx' => require RAIZ . '/config/htmx.php',
        ]),

        // Cola (ADR-003, RF-Q-001 a RF-Q-019)
        ConductorCola::class => static fn (Contenedor $c): ConductorCola => new ConductorColaMysql(
            $c->obtener(BaseDatos::class),
            (string) $cola()['estrategia_reclamo'],
        ),
        DespachadorCola::class => static fn (Contenedor $c): DespachadorCola => new DespachadorCola(
            $c->obtener(ConductorCola::class),
            $c->obtener(Vista::class),
            $cola(),
        ),
        Trabajador::class => static fn (Contenedor $c): Trabajador => new Trabajador(
            $c->obtener(ConductorCola::class),
            $c,
            $c->obtener(Registro::class),
            $c->obtener(ServicioMantenimiento::class),
            $cola(),
        ),
        ServicioMonitorCola::class => static fn (Contenedor $c): ServicioMonitorCola => new ServicioMonitorCola(
            $c->obtener(BaseDatos::class),
            RAIZ . '/almacenamiento/latidos',
        ),
        Programador::class => static fn (Contenedor $c): Programador => new Programador(
            $c->obtener(BaseDatos::class),
            $c->obtener(DespachadorCola::class),
            $c->obtener(ServicioMonitorCola::class),
            $c->obtener(ServicioAuditoria::class),
            $c->obtener(Registro::class),
            $cola(),
            RAIZ . '/almacenamiento/estado',
            RAIZ . '/almacenamiento/registros',
            Entorno::texto('APP_ZONA_HORARIA', 'America/Bogota'),
            Entorno::texto('CORREO_ALERTAS', '') !== '',
            Entorno::texto('APP_URL'),
            Entorno::entero('SSL_AVISO_DIAS', 15),
            new MonitorCertificado(),
        ),
        ServicioCorreo::class => static fn (Contenedor $c): ServicioCorreo => new ServicioCorreo(
            $c->obtener(BaseDatos::class),
            require RAIZ . '/config/correo.php',
        ),
        LimpiezaPeriodica::class => static fn (Contenedor $c): LimpiezaPeriodica => new LimpiezaPeriodica(
            $c->obtener(BaseDatos::class),
            $c->obtener(Cache::class),
            $cola(),
            Entorno::entero('CACHE_TAMANO_MAXIMO_MB', 200) * 1048576,
            RAIZ . '/almacenamiento/correos',
            $c->obtener(ServicioExportacion::class),
        ),

        // Controladores con valores escalares
        ControladorSalud::class => static fn (Contenedor $c): ControladorSalud => new ControladorSalud(
            $c->obtener(BaseDatos::class),
            $c->obtener(ServicioMantenimiento::class),
            $c->obtener(ServicioMonitorCola::class),
            Entorno::texto('TOKEN_MONITOREO'),
            $versionEsquema,
            $cola()['alertas'],
            $c->obtener(ServicioRespaldo::class),
        ),
        ControladorExportaciones::class => static fn (Contenedor $c): ControladorExportaciones => new ControladorExportaciones(
            $c->obtener(Vista::class),
            $c->obtener(ServicioExportacion::class),
            $c->obtener(ServicioIndicadores::class),
            $cola()['consulta_periodica_segundos'],
        ),
        ControladorTrabajos::class => static fn (Contenedor $c): ControladorTrabajos => new ControladorTrabajos(
            $c->obtener(Vista::class),
            $c->obtener(BaseDatos::class),
            $cola()['consulta_periodica_segundos'],
        ),
        ControladorSistemaDiseno::class => static fn (Contenedor $c): ControladorSistemaDiseno => new ControladorSistemaDiseno(
            $c->obtener(Vista::class),
            $c->obtener(DespachadorCola::class),
            $c->obtener(BaseDatos::class),
            Entorno::texto('APP_ENTORNO', 'produccion'),
            $cola()['consulta_periodica_segundos'],
        ),
    ],
];
