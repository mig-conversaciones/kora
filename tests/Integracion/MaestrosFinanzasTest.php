<?php

declare(strict_types=1);

namespace Pruebas\Integracion;

use App\Nucleo\BaseDatos;
use App\Nucleo\Contenedor;
use App\Nucleo\Migrador;
use App\Servicios\ServicioAdjuntos;
use App\Servicios\ServicioAuditoria;
use App\Servicios\ServicioMantenimiento;
use PHPUnit\Framework\TestCase;

/**
 * Iteración 3 «Maestros y finanzas» de extremo a extremo, con la aplicación completa y la base de pruebas:
 * alcance por unidad (Obs. 5), reglas de asignaciones y gastos, cierre de periodo con reapertura reautenticada,
 * soportes con verificación de firma, catálogos y parámetros, gestión de usuarios con separación de funciones,
 * matriz de permisos y consulta de auditoría.
 */
final class MaestrosFinanzasTest extends TestCase
{
    use AyudasCuentas;

    private static BaseDatos $bd;
    private string $senal;
    private string $adjuntos;

    public static function setUpBeforeClass(): void
    {
        self::$bd = new BaseDatos(require RAIZ . '/config/base_datos.php');
        $migrador = new Migrador(self::$bd, RAIZ . '/bd/migraciones');
        $migrador->aplicar();
        $migrador->sembrar(RAIZ . '/bd/semillas/001_identidad_y_estructura.sql');
    }

    protected function setUp(): void
    {
        foreach (['archivos_adjuntos', 'gastos_ejecutados', 'asignaciones_presupuestales', 'procesos_autoconsumo', 'periodos_cierre',
            'homologaciones_producto', 'productos', 'parametros_indicadores', 'categorias_gasto',
            'claves_idempotencia', 'codigos_respaldo_2fa', 'tokens_cuenta', 'sesiones', 'notificaciones', 'usuarios_unidades', 'usuarios_roles',
            'trabajos_fallidos', 'trabajos', 'limites_tasa', 'eventos_auditoria'] as $tabla) {
            self::$bd->ejecutar("DELETE FROM {$tabla}");
        }
        self::$bd->ejecutar('DELETE FROM usuarios');
        self::$bd->ejecutar('UPDATE unidades_productivas SET activa = 1');
        self::$bd->ejecutar("UPDATE cadena_auditoria SET ultima_secuencia = 0, ultimo_hash = REPEAT('0', 64) WHERE id = 1");
        // La matriz de autorización vuelve a la de la semilla (una prueba concede un permiso).
        self::$bd->ejecutar('DELETE FROM roles_permisos');
        $migrador = new Migrador(self::$bd, RAIZ . '/bd/migraciones');
        $migrador->sembrar(RAIZ . '/bd/semillas/001_identidad_y_estructura.sql');
        $migrador->sembrar(RAIZ . '/bd/semillas/002_catalogos_finanzas.sql');
        $this->senal = sys_get_temp_dir() . '/kora-senal-i3-' . bin2hex(random_bytes(4)) . '.json';
        $this->adjuntos = sys_get_temp_dir() . '/kora-adjuntos-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_file($this->senal)) {
            unlink($this->senal);
        }
        if (is_dir($this->adjuntos)) {
            $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->adjuntos, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterador as $archivo) {
                $archivo->isDir() ? rmdir($archivo->getPathname()) : unlink($archivo->getPathname());
            }
            rmdir($this->adjuntos);
        }
    }

    public function testElLiderSoloVeYGestionaLasFinanzasDeSusUnidades(): void
    {
        $coordinacion = $this->navegador('190.85.12.10');
        $this->cuentaActiva(self::$bd, $coordinacion, 'coordinacion@kora.local', 'Carla Coordinación', 'coordinacion_administrativa');
        $lider = $this->navegador('190.85.12.11');
        $this->cuentaActiva(self::$bd, $lider, 'lider.gan@kora.local', 'Luis Ganadería', 'lider_unidad', ['GAN']);

        $coordinacion->get('/finanzas/asignaciones?vigencia=2026');
        $base = ['vigencia' => '2026', 'valor_total' => '12.500.000', 'mantenimiento' => '4000000', 'alimentacion' => '6000000', 'materiales_formacion' => '2500000'];
        self::assertSame(200, $coordinacion->postHtmx('/finanzas/asignaciones', ['unidad_id' => (string) $this->unidad('GAN')] + $base)->estado);
        self::assertSame(200, $coordinacion->postHtmx('/finanzas/asignaciones', ['unidad_id' => (string) $this->unidad('AGR')] + $base)->estado);
        self::assertSame('12500000.00', (string) self::$bd->valor('SELECT valor_total FROM asignaciones_presupuestales WHERE unidad_id = :u', ['u' => $this->unidad('GAN')]), 'Acepta el formato local con puntos de miles.');

        $excedida = $coordinacion->postHtmx('/finanzas/asignaciones', ['unidad_id' => (string) $this->unidad('IND'), 'mantenimiento' => '13000000'] + $base);
        self::assertSame(422, $excedida->estado);
        self::assertStringContainsString('supera el valor total', $excedida->cuerpo);
        $duplicada = $coordinacion->postHtmx('/finanzas/asignaciones', ['unidad_id' => (string) $this->unidad('GAN')] + $base);
        self::assertSame(422, $duplicada->estado);
        self::assertStringContainsString('ya tiene una asignación activa para 2026', $duplicada->cuerpo);

        // Obs. 5: el líder de Ganadería solo ve la asignación de GAN.
        $vista = $lider->get('/finanzas/asignaciones?vigencia=2026');
        self::assertSame(200, $vista->estado);
        self::assertStringContainsString('Ganadería', $vista->cuerpo);
        self::assertStringNotContainsString('Agrícola', $vista->cuerpo);
        self::assertSame(403, $lider->postHtmx('/finanzas/asignaciones', ['unidad_id' => (string) $this->unidad('GAN')] + $base)->estado, 'El líder consulta pero no edita asignaciones.');

        // RF-025: el líder registra gastos de su unidad, nunca de otra (403 auditado).
        $lider->get('/finanzas/gastos');
        $gasto = ['fecha' => $this->fechaMesAnterior(), 'categoria_id' => (string) $this->categoria('ALIMENTACION'), 'valor' => '850000', 'valor_componente_academico' => '120000', 'descripcion' => 'Concentrado para el hato'];
        self::assertSame(200, $lider->postHtmx('/finanzas/gastos', ['unidad_id' => (string) $this->unidad('GAN')] + $gasto)->estado);
        self::assertSame(403, $lider->postHtmx('/finanzas/gastos', ['unidad_id' => (string) $this->unidad('AGR')] + $gasto)->estado);
        self::assertSame(1, (int) self::$bd->valor("SELECT COUNT(*) FROM eventos_auditoria WHERE accion = 'gastos.gestionar' AND resultado = 'denegado'"));

        $coordinacion->get('/finanzas/gastos');
        self::assertSame(200, $coordinacion->postHtmx('/finanzas/gastos', ['unidad_id' => (string) $this->unidad('AGR'), 'descripcion' => 'Semillas de maíz'] + $gasto)->estado);
        $gastoAgr = (int) self::$bd->valor('SELECT id FROM gastos_ejecutados WHERE unidad_id = :u', ['u' => $this->unidad('AGR')]);
        self::assertSame(404, $lider->get('/finanzas/gastos/' . $gastoAgr . '/editar')->estado, 'Un gasto fuera del alcance no se revela (RAC-028).');
        $listado = $lider->get('/finanzas/gastos?vigencia=' . substr($this->fechaMesAnterior(), 0, 4))->cuerpo;
        self::assertStringContainsString('Concentrado para el hato', $listado);
        self::assertStringNotContainsString('Semillas de maíz', $listado);

        // RF-026: la ejecución por línea suma los gastos de cada categoría en su línea.
        $ejecucion = $coordinacion->get('/finanzas/ejecucion?vigencia=' . substr($this->fechaMesAnterior(), 0, 4));
        self::assertSame(200, $ejecucion->estado);
        self::assertStringContainsString('No corresponde a ejecución CDP/RP', $ejecucion->cuerpo);
        self::assertStringContainsString('$ 850.000', $ejecucion->cuerpo);
    }

    public function testGastoConComponenteAcademicoYPeriodoCerradoConReaperturaAuditada(): void
    {
        $coordinacion = $this->navegador('190.85.12.20');
        $this->cuentaActiva(self::$bd, $coordinacion, 'coordinacion@kora.local', 'Carla Coordinación', 'coordinacion_administrativa');
        $lider = $this->navegador('190.85.12.21');
        $this->cuentaActiva(self::$bd, $lider, 'lider.gan@kora.local', 'Luis Ganadería', 'lider_unidad', ['GAN']);
        $fecha = $this->fechaMesAnterior();
        [$anio, $mes] = [(int) substr($fecha, 0, 4), (int) substr($fecha, 5, 2)];

        $lider->get('/finanzas/gastos');
        $gasto = ['unidad_id' => (string) $this->unidad('GAN'), 'fecha' => $fecha, 'categoria_id' => (string) $this->categoria('MANTENIMIENTO'), 'valor' => '300000'];
        $invalido = $lider->postHtmx('/finanzas/gastos', $gasto + ['valor_componente_academico' => '300001']);
        self::assertSame(422, $invalido->estado);
        self::assertStringContainsString('no puede superar el valor del gasto', $invalido->cuerpo);
        self::assertStringContainsString('value="300000"', $invalido->cuerpo, 'El formulario conserva lo escrito (RNF-005).');
        self::assertSame(200, $lider->postHtmx('/finanzas/gastos', $gasto + ['valor_componente_academico' => '50000'])->estado);
        $id = (int) self::$bd->valor('SELECT id FROM gastos_ejecutados');

        // Cierre del mes por la Coordinación administrativa.
        $coordinacion->get('/finanzas/periodos?vigencia=' . $anio);
        self::assertSame(200, $coordinacion->postHtmx("/finanzas/periodos/{$anio}/{$mes}/cerrar")->estado);
        $futuro = new \DateTimeImmutable('first day of next month', new \DateTimeZone('America/Bogota'));
        $cierreFuturo = $coordinacion->postHtmx('/finanzas/periodos/' . $futuro->format('Y/n') . '/cerrar');
        self::assertSame(422, $cierreFuturo->estado);
        self::assertStringContainsString('aún no comienza', $cierreFuturo->cuerpo);

        // RF-028: en un mes cerrado no se edita, archiva ni crea; responde 409 «Periodo cerrado» en la región de errores.
        $edicion = $lider->postHtmx("/finanzas/gastos/{$id}", ['valor' => '310000', 'version' => '1'] + $gasto);
        self::assertSame(409, $edicion->estado);
        self::assertStringContainsString('Periodo cerrado', $edicion->cuerpo);
        self::assertSame('#contenedor-errores', $edicion->cabeceras['HX-Retarget'] ?? null, 'El error no reemplaza el formulario.');
        self::assertSame(409, $lider->postHtmx("/finanzas/gastos/{$id}/archivar", ['version' => '1', 'motivo' => 'Duplicado'])->estado);
        self::assertSame(409, $lider->postHtmx('/finanzas/gastos', $gasto)->estado);
        self::assertSame('300000.00', (string) self::$bd->valor('SELECT valor FROM gastos_ejecutados WHERE id = :id', ['id' => $id]));

        // La reapertura exige reautenticación reciente y un motivo, y queda auditada con él.
        $sinReautenticar = $coordinacion->postHtmx("/finanzas/periodos/{$anio}/{$mes}/reabrir", ['motivo' => 'Corrección de un gasto mal digitado']);
        self::assertStringStartsWith('/reautenticar', $coordinacion->destino(), (string) $sinReautenticar->estado);
        $coordinacion->get('/reautenticar');
        $coordinacion->post('/reautenticar', ['secreto' => self::$contrasenaPrueba]);
        $coordinacion->get('/finanzas/periodos?vigencia=' . $anio);
        self::assertSame(422, $coordinacion->postHtmx("/finanzas/periodos/{$anio}/{$mes}/reabrir", ['motivo' => 'corto'])->estado);
        self::assertSame(200, $coordinacion->postHtmx("/finanzas/periodos/{$anio}/{$mes}/reabrir", ['motivo' => 'Corrección de un gasto mal digitado'])->estado);
        self::assertSame('Corrección de un gasto mal digitado', (string) self::$bd->valor("SELECT motivo FROM eventos_auditoria WHERE accion = 'periodos.reabrir'"));

        self::assertSame(200, $lider->postHtmx("/finanzas/gastos/{$id}", ['valor' => '310000', 'version' => '1'] + $gasto)->estado);
        self::assertSame(409, $lider->postHtmx("/finanzas/gastos/{$id}", ['valor' => '320000', 'version' => '1'] + $gasto)->estado, 'Una versión vieja choca con el bloqueo optimista.');
        $evento = self::$bd->fila("SELECT valores_antes, valores_despues, unidad_id FROM eventos_auditoria WHERE accion = 'gastos.actualizar'");
        self::assertNotNull($evento);
        self::assertStringContainsString('300000.00', (string) $evento['valores_antes']);
        self::assertStringContainsString('310000.00', (string) $evento['valores_despues']);
        self::assertSame($this->unidad('GAN'), (int) $evento['unidad_id']);
    }

    public function testSoporteVerificaLaFirmaYSeDescargaSoloConAcceso(): void
    {
        $lider = $this->navegador('190.85.12.30');
        $this->cuentaActiva(self::$bd, $lider, 'lider.gan@kora.local', 'Luis Ganadería', 'lider_unidad', ['GAN']);
        $otro = $this->navegador('190.85.12.31');
        $this->cuentaActiva(self::$bd, $otro, 'lider.agr@kora.local', 'Ana Agrícola', 'lider_unidad', ['AGR']);

        $gasto = ['unidad_id' => (string) $this->unidad('GAN'), 'fecha' => $this->fechaMesAnterior(), 'categoria_id' => (string) $this->categoria('MANTENIMIENTO'), 'valor' => '150000'];
        $lider->get('/finanzas/gastos/nuevo');
        $falso = $this->archivo('soporte.pdf', "MZ\x90\x00 ejecutable disfrazado");
        $rechazado = $lider->postHtmx('/finanzas/gastos', $gasto, [], ['soporte' => $falso]);
        self::assertSame(422, $rechazado->estado);
        self::assertStringContainsString('no corresponde a un PDF', $rechazado->cuerpo);
        self::assertSame(0, (int) self::$bd->valor('SELECT COUNT(*) FROM gastos_ejecutados'), 'Un soporte inválido no deja el gasto a medias.');

        $png = $this->archivo('factura.png', "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 64));
        self::assertSame(200, $lider->postHtmx('/finanzas/gastos', $gasto, [], ['soporte' => $png])->estado);
        $adjunto = self::$bd->fila('SELECT id, entidad_id, ruta_privada, tipo_mime FROM archivos_adjuntos');
        self::assertNotNull($adjunto);
        self::assertSame('image/png', $adjunto['tipo_mime']);
        self::assertFileExists($this->adjuntos . '/' . $adjunto['ruta_privada']);
        self::assertStringNotContainsString('factura', (string) $adjunto['ruta_privada'], 'El nombre en disco es aleatorio (RS-011).');

        $ruta = '/finanzas/gastos/' . $adjunto['entidad_id'] . '/soportes/' . $adjunto['id'];
        $descarga = $lider->get($ruta);
        self::assertSame(200, $descarga->estado);
        self::assertStringStartsWith("\x89PNG", $descarga->cuerpo);
        self::assertStringContainsString('attachment', $descarga->cabeceras['Content-Disposition'] ?? '');
        self::assertSame(404, $otro->get($ruta)->estado, 'Sin acceso al gasto, la descarga responde 404 (RF-027).');
    }

    public function testCatalogosYParametrosValidanYConservanElHistorial(): void
    {
        $admin = $this->navegador('190.85.12.40');
        $this->cuentaActiva(self::$bd, $admin, 'admin@kora.local', 'Andrea Administradora', 'administrador');

        // RF-021: rangos por campo e historial por vigencia.
        $admin->get('/catalogos/parametros');
        $hoy = (new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota')))->format('Y-m-d');
        $precioCero = $admin->postHtmx('/catalogos/parametros', ['clave' => 'precio_referencia_leche', 'valor' => '0', 'vigente_desde' => $hoy]);
        self::assertSame(422, $precioCero->estado);
        self::assertStringContainsString('debe ser mayor que 0', $precioCero->cuerpo);
        self::assertSame(422, $admin->postHtmx('/catalogos/parametros', ['clave' => 'meta_vacas_ordeno', 'valor' => '101', 'vigente_desde' => $hoy])->estado);
        $vigente = $admin->postHtmx('/catalogos/parametros', ['clave' => 'meta_vacas_ordeno', 'valor' => '80', 'vigente_desde' => $hoy]);
        self::assertSame(200, $vigente->estado);
        self::assertStringContainsString('80,00 %', $vigente->cuerpo);
        self::assertSame(2, (int) self::$bd->valor("SELECT COUNT(*) FROM parametros_indicadores WHERE clave = 'meta_vacas_ordeno'"), 'El valor anterior se conserva.');
        self::assertSame(1, (int) self::$bd->valor("SELECT COUNT(*) FROM eventos_auditoria WHERE accion = 'parametros.registrar'"));

        // RF-017 y RF-018: productos, código único y factores de derivados.
        $admin->get('/catalogos/productos');
        $leche = ['codigo_sgac' => '5013170101', 'nombre' => 'Leche', 'medida' => 'L', 'categoria_rotacion' => 'fresco', 'precio_venta' => '2200'];
        self::assertSame(200, $admin->postHtmx('/catalogos/productos', $leche)->estado);
        self::assertStringContainsString('Ya existe un producto con ese código SGAC', $admin->postHtmx('/catalogos/productos', $leche)->cuerpo);
        $queso = ['codigo_sgac' => '5013170301', 'nombre' => 'Queso cuajada', 'medida' => 'kg', 'categoria_rotacion' => 'fresco', 'es_derivado_lacteo' => '1'];
        $sinFactor = $admin->postHtmx('/catalogos/productos', $queso + ['factor_leche' => '0', 'meta_rendimiento_min' => '8', 'meta_rendimiento_max' => '8.5']);
        self::assertSame(422, $sinFactor->estado);
        self::assertStringContainsString('factor de leche', $sinFactor->cuerpo);
        $listado = $admin->postHtmx('/catalogos/productos', $queso + ['factor_leche' => '8', 'meta_rendimiento_min' => '8', 'meta_rendimiento_max' => '8.5']);
        self::assertSame(200, $listado->estado);
        self::assertStringContainsString('«Yogurt» no está en el catálogo', $listado->cuerpo, 'El panel informa los derivados que faltan (RF-018).');
        self::assertSame('8.0000', (string) self::$bd->valor("SELECT factor_leche FROM productos WHERE codigo_sgac = '5013170301'"));

        // RF-019: el concepto se normaliza sin tildes, en minúsculas y con espacios simples.
        $admin->get('/catalogos/homologaciones');
        $lecheId = (string) self::$bd->valor("SELECT id FROM productos WHERE codigo_sgac = '5013170101'");
        self::assertSame(200, $admin->postHtmx('/catalogos/homologaciones', ['texto' => '  Léche   de VACA ', 'producto_id' => $lecheId])->estado);
        self::assertSame('leche de vaca', (string) self::$bd->valor('SELECT texto_normalizado FROM homologaciones_producto'));
        self::assertSame(200, $admin->postHtmx('/catalogos/homologaciones', ['texto' => 'Queso', 'producto_id' => ''])->estado);
        self::assertNull(self::$bd->valor("SELECT producto_id FROM homologaciones_producto WHERE texto_normalizado = 'queso'"), 'Un genérico queda como concepto fuente.');

        // RF-020: «incluye en IND_17» solo en Mantenimiento y Alimentación.
        $admin->get('/catalogos/categorias');
        self::assertSame(200, $admin->postHtmx('/catalogos/categorias', ['codigo' => 'COMBUSTIBLE', 'nombre' => 'Combustible', 'linea_presupuestal' => 'otra', 'incluye_ind17' => '1'])->estado);
        self::assertSame(0, (int) self::$bd->valor("SELECT incluye_ind17 FROM categorias_gasto WHERE codigo = 'COMBUSTIBLE'"));

        // RF-016: una unidad con registros activos no se inactiva.
        self::$bd->ejecutar(
            "INSERT INTO gastos_ejecutados (centro_id, unidad_id, fecha, categoria_id, valor) SELECT u.centro_id, u.id, '2026-03-01', c.id, 1000 FROM unidades_productivas u JOIN categorias_gasto c ON c.codigo = 'MANTENIMIENTO' WHERE u.codigo = 'GAN'"
        );
        $gan = $this->unidad('GAN');
        $admin->get("/catalogos/unidades/{$gan}/editar");
        $bloqueo = $admin->postHtmx("/catalogos/unidades/{$gan}", ['nombre' => 'Ganadería', 'prefijo_lote' => 'GAN', 'tipo' => 'pecuaria']);
        self::assertSame(422, $bloqueo->estado);
        self::assertStringContainsString('No se puede inactivar: tiene 1 gastos activos', $bloqueo->cuerpo);
        $agr = $this->unidad('AGR');
        self::assertSame(200, $admin->postHtmx("/catalogos/unidades/{$agr}", ['nombre' => 'Agrícola', 'prefijo_lote' => 'AGR', 'tipo' => 'agricola'])->estado);
        self::assertSame(0, (int) self::$bd->valor('SELECT activa FROM unidades_productivas WHERE id = :id', ['id' => $agr]));
    }

    public function testGestionDeUsuariosConSeparacionDeFunciones(): void
    {
        $admin = $this->navegador('190.85.12.50');
        $this->cuentaActiva(self::$bd, $admin, 'admin@kora.local', 'Andrea Administradora', 'administrador');
        $lider = $this->navegador('190.85.12.51');
        $this->cuentaActiva(self::$bd, $lider, 'lider.gan@kora.local', 'Luis Ganadería', 'lider_unidad', ['GAN']);
        $adminId = (int) self::$bd->valor("SELECT id FROM usuarios WHERE correo = 'admin@kora.local'");
        $liderId = (int) self::$bd->valor("SELECT id FROM usuarios WHERE correo = 'lider.gan@kora.local'");

        self::assertSame(200, $admin->get('/sistema/usuarios')->estado);
        // RF-012: un administrador no crea superadministradores; queda auditado como denegado.
        self::assertSame(403, $admin->postHtmx('/sistema/usuarios', ['correo' => 'otro.super@kora.local', 'nombre' => 'Otro Super', 'rol' => 'superadministrador'])->estado);
        self::assertSame(1, (int) self::$bd->valor("SELECT COUNT(*) FROM eventos_auditoria WHERE accion = 'usuarios.gestionar' AND resultado = 'denegado'"));
        // RF-010: el líder de unidad exige al menos una unidad.
        $sinUnidades = $admin->postHtmx('/sistema/usuarios', ['correo' => 'nuevo.lider@kora.local', 'nombre' => 'Nuevo Líder', 'rol' => 'lider_unidad']);
        self::assertSame(422, $sinUnidades->estado);
        self::assertStringContainsString('exige al menos una unidad', $sinUnidades->cuerpo);
        self::assertSame(200, $admin->postHtmx('/sistema/usuarios', ['correo' => 'nuevo.lider@kora.local', 'nombre' => 'Nuevo Líder', 'rol' => 'lider_unidad', 'unidades' => [(string) $this->unidad('HOR')]])->estado);
        self::assertSame('invitado', (string) self::$bd->valor("SELECT estado FROM usuarios WHERE correo = 'nuevo.lider@kora.local'"));

        // Nadie modifica su propia cuenta desde la gestión.
        self::assertSame(403, $admin->get("/sistema/usuarios/{$adminId}")->estado);
        self::assertSame(403, $admin->postHtmx("/sistema/usuarios/{$adminId}/acceso", ['nombre' => 'Andrea', 'rol' => 'administrador'])->estado);

        // Cambio de unidades con antes y después; el líder ve el alcance nuevo en su siguiente solicitud.
        $admin->get("/sistema/usuarios/{$liderId}");
        $acceso = $admin->postHtmx("/sistema/usuarios/{$liderId}/acceso", ['nombre' => 'Luis Ganadería', 'rol' => 'lider_unidad', 'unidades' => [(string) $this->unidad('GAN'), (string) $this->unidad('AGR')]]);
        self::assertSame(200, $acceso->estado, $acceso->cuerpo);
        $evento = self::$bd->fila("SELECT valores_antes, valores_despues FROM eventos_auditoria WHERE accion = 'usuarios.asignar_acceso'");
        self::assertNotNull($evento);
        self::assertSame([$this->unidad('GAN')], json_decode((string) $evento['valores_antes'], true)['unidades']);
        self::assertCount(2, json_decode((string) $evento['valores_despues'], true)['unidades']);
        self::assertStringContainsString('Agrícola', $lider->get('/finanzas/gastos')->cuerpo, 'El selector de unidad ya incluye AGR.');

        // Desactivar revoca las sesiones: la siguiente solicitud del líder vuelve al ingreso.
        self::assertSame(422, $admin->postHtmx("/sistema/usuarios/{$liderId}/desactivar", ['motivo' => ''])->estado);
        self::assertSame(200, $admin->postHtmx("/sistema/usuarios/{$liderId}/desactivar", ['motivo' => 'Terminó su contrato'])->estado);
        $lider->get('/finanzas/gastos');
        self::assertStringStartsWith('/ingresar', $lider->destino());

        // La auditoría solo la consulta el superadministrador.
        self::assertSame(403, $admin->get('/sistema/auditoria')->estado);
        $super = $this->navegador('190.85.12.52');
        $this->cuentaActiva(self::$bd, $super, 'super@kora.local', 'Sara Superadmin', 'superadministrador');
        $auditoria = $super->get('/sistema/auditoria');
        self::assertSame(200, $auditoria->estado);
        self::assertStringContainsString('usuarios.desactivar', $auditoria->cuerpo);
        self::assertStringContainsString('Terminó su contrato', $auditoria->cuerpo);
    }

    public function testMatrizDeRolesSeConsultaPorTodosYSoloLaCambiaElSuperadministrador(): void
    {
        $lider = $this->navegador('190.85.12.60');
        $this->cuentaActiva(self::$bd, $lider, 'lider.gan@kora.local', 'Luis Ganadería', 'lider_unidad', ['GAN']);
        $super = $this->navegador('190.85.12.61');
        $this->cuentaActiva(self::$bd, $super, 'super@kora.local', 'Sara Superadmin', 'superadministrador');
        $rolLider = (int) self::$bd->valor("SELECT id FROM roles WHERE codigo = 'lider_unidad'");
        $rolSuper = (int) self::$bd->valor("SELECT id FROM roles WHERE codigo = 'superadministrador'");
        $permiso = (int) self::$bd->valor("SELECT id FROM permisos WHERE codigo = 'procesos.gestionar'");
        $version = (int) self::$bd->valor("SELECT version_permisos FROM usuarios WHERE correo = 'lider.gan@kora.local'");

        $matriz = $lider->get('/sistema/roles');
        self::assertSame(200, $matriz->estado);
        self::assertStringContainsString('procesos.gestionar', $matriz->cuerpo);
        self::assertSame(403, $lider->postHtmx("/sistema/roles/{$rolLider}/{$permiso}/conceder")->estado);

        $super->get('/sistema/roles');
        self::assertSame(200, $super->postHtmx("/sistema/roles/{$rolLider}/{$permiso}/conceder")->estado);
        self::assertSame($version + 1, (int) self::$bd->valor("SELECT version_permisos FROM usuarios WHERE correo = 'lider.gan@kora.local'"), 'Los usuarios del rol reciben el cambio en su siguiente solicitud (RF-011).');
        self::assertSame(200, $lider->get('/finanzas/procesos/nuevo')->estado, 'El permiso nuevo rige de inmediato.');
        self::assertSame(403, $super->postHtmx("/sistema/roles/{$rolSuper}/{$permiso}/conceder")->estado, 'El rol superadministrador está protegido (RF-012).');
    }

    private function navegador(string $ip): NavegadorPrueba
    {
        $senal = $this->senal;
        $adjuntos = $this->adjuntos;
        return new NavegadorPrueba(static function (Contenedor $c) use ($senal, $adjuntos): void {
            $c->establecer(ServicioMantenimiento::class, new ServicioMantenimiento(
                $c->obtener(BaseDatos::class),
                $c->obtener(ServicioAuditoria::class),
                ['senal' => $senal, 'latido_trabajador' => sys_get_temp_dir() . '/kora-latido-inexistente.txt'] + require RAIZ . '/config/mantenimiento.php',
                (require RAIZ . '/config/app.php')['version_esquema']
            ));
            // Las pruebas no llegan por HTTP: se omite is_uploaded_file y se guarda en un directorio temporal.
            $c->establecer(ServicioAdjuntos::class, new ServicioAdjuntos($c->obtener(BaseDatos::class), $adjuntos, false));
        }, $ip);
    }

    private function unidad(string $codigo): int
    {
        return (int) self::$bd->valor('SELECT id FROM unidades_productivas WHERE codigo = :c', ['c' => $codigo]);
    }

    private function categoria(string $codigo): int
    {
        return (int) self::$bd->valor('SELECT id FROM categorias_gasto WHERE codigo = :c', ['c' => $codigo]);
    }

    /** Día 15 del mes anterior (nunca futuro, para la regla de fecha del gasto). */
    private function fechaMesAnterior(): string
    {
        return (new \DateTimeImmutable('first day of last month', new \DateTimeZone('America/Bogota')))->format('Y-m-15');
    }

    /** @return array{nombre:string,ruta:string,tamano:int,error:int} */
    private function archivo(string $nombre, string $contenido): array
    {
        $ruta = (string) tempnam(sys_get_temp_dir(), 'kora-subida-');
        file_put_contents($ruta, $contenido);
        return ['nombre' => $nombre, 'ruta' => $ruta, 'tamano' => strlen($contenido), 'error' => UPLOAD_ERR_OK];
    }
}
