<?php

declare(strict_types=1);

namespace App\Controladores;

use App\Cola\DespachadorCola;
use App\Nucleo\BaseDatos;
use App\Nucleo\ErrorHttp;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\GeneradorUlid;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Nucleo\Vista;

/**
 * Vitrina del sistema de diseño para el hito M1 (RUI-001 a RUI-009). Cada demostración ejercita el contrato real
 * entre el servidor y retroalimentacion-ui.js: HX-Trigger, 204 con estado vacío, esqueleto con espera, 422 con
 * resumen de errores y error controlado con código de soporte. Las demostraciones de mensajes son GET y no
 * escriben nada; la de la cola (I1) encola un trabajo inofensivo y solo existe fuera de producción.
 */
final class ControladorSistemaDiseno extends ControladorBase
{
    use RespuestaEstadoTrabajo;

    /** Máximo de trabajos de demostración pendientes a la vez (evita llenar la cola desde la vitrina). */
    private const MAX_DEMOSTRACIONES_PENDIENTES = 20;

    /** @param list<int> $esperas */
    public function __construct(
        Vista $vista,
        private readonly DespachadorCola $despachador,
        private readonly BaseDatos $bd,
        private readonly string $entorno,
        private readonly array $esperas,
    ) {
        parent::__construct($vista);
    }

    /**
     * Encola un trabajo de demostración y responde 202 con el fragmento de seguimiento (RF-Q-002). Solo fuera de
     * producción. La clave de idempotencia del formulario evita duplicados por doble clic (RF-Q-003).
     */
    public function encolarTrabajo(Solicitud $solicitud): Respuesta
    {
        $this->exigirFueraDeProduccion();
        $pendientes = (int) $this->bd->valor("SELECT COUNT(*) FROM trabajos WHERE tipo = 'demostracion.progreso' AND estado IN ('pendiente', 'reservado')");
        if ($pendientes >= self::MAX_DEMOSTRACIONES_PENDIENTES) {
            throw new ErrorHttp(429, 'Ya hay muchas demostraciones en cola. Ejecuta el trabajador o espera a que terminen.');
        }
        $pasos = max(1, min(20, (int) ($solicitud->cuerpo['pasos'] ?? 8)));
        $trabajo = $this->despachador->encolar('demostracion.progreso', ['pasos' => $pasos, 'ms_por_paso' => 500], [
            'clave_idempotencia' => DespachadorCola::claveIdempotencia($solicitud),
            'correlacion_id' => $solicitud->atributo('request_id'),
        ]);
        return $this->despachador->respuestaAceptada($solicitud, $trabajo, "/sistema-diseno/demostracion/trabajos/{$trabajo['ulid']}");
    }

    /** Estado de un trabajo de demostración (solo de ese tipo y fuera de producción). */
    public function estadoTrabajo(Solicitud $solicitud, string $ulid): Respuesta
    {
        $this->exigirFueraDeProduccion();
        $trabajo = GeneradorUlid::esValido($ulid)
            ? $this->bd->fila("SELECT * FROM trabajos WHERE ulid = :ulid AND tipo = 'demostracion.progreso'", ['ulid' => $ulid])
            : null;
        if ($trabajo === null) {
            throw new ErrorHttp(404);
        }
        return $this->respuestaEstado($solicitud, $trabajo, "/sistema-diseno/demostracion/trabajos/{$ulid}", $this->esperas);
    }

    private function exigirFueraDeProduccion(): void
    {
        if ($this->entorno === 'produccion') {
            throw new ErrorHttp(404);
        }
    }

    public function mostrar(Solicitud $solicitud): Respuesta
    {
        return $this->renderizar($solicitud, 'sistema_diseno/pagina', 'sistema_diseno/contenido', [
            'titulo' => 'Sistema de diseño',
            'migas' => [['texto' => 'Plataforma'], ['texto' => 'Sistema de diseño']],
            'demostracionCola' => $this->entorno !== 'produccion',
        ]);
    }

    public function demostracion(Solicitud $solicitud, string $caso): Respuesta
    {
        return match ($caso) {
            'toast-exito' => $this->conToast(new Respuesta('', 204), 'success', 'Cambios guardados. Ya cuentan en los indicadores de la unidad.'),
            'toast-info' => $this->conToast(new Respuesta('', 204), 'info', 'La exportación quedó en cola; te avisaremos cuando esté lista.'),
            'toast-advertencia' => $this->conToast(new Respuesta('', 204), 'warning', 'El periodo de octubre está por cerrar: revisa los gastos pendientes.'),
            'toast-error' => $this->conToast(new Respuesta('', 204), 'error', 'No se pudo guardar el gasto: el componente académico supera el valor total. Corrige el valor e intenta de nuevo.'),
            'lento' => $this->lento($solicitud),
            'vacio' => new Respuesta('', 204),
            'validacion' => $this->validacion($solicitud),
            'conflicto' => throw new ErrorHttp(409),
            'error' => throw new \RuntimeException('Error de demostración del sistema de diseño.'),
            'archivar' => $this->conToast(new Respuesta('', 204), 'success', 'Registro de demostración archivado. Podrás restaurarlo desde el filtro de estado.'),
            default => throw new ErrorHttp(404),
        };
    }

    /** Espera 1,2 s para que el esqueleto (umbral de 300 ms) sea visible (RUI-016). */
    private function lento(Solicitud $solicitud): Respuesta
    {
        usleep(1_200_000);
        return $this->renderizar($solicitud, 'sistema_diseno/tabla_demostracion', 'sistema_diseno/tabla_demostracion', [
            'filas' => [
                ['lote' => 'GAN-2026-041', 'producto' => 'Leche cruda', 'cantidad' => 412.5, 'medida' => 'L', 'estado' => 'Abierto'],
                ['lote' => 'GAN-2026-040', 'producto' => 'Queso campesino', 'cantidad' => 38.0, 'medida' => 'kg', 'estado' => 'Cerrado'],
                ['lote' => 'GAN-2026-039', 'producto' => 'Yogur natural', 'cantidad' => 96.0, 'medida' => 'L', 'estado' => 'Cerrado'],
            ],
        ]);
    }

    private function validacion(Solicitud $solicitud): Respuesta
    {
        $valores = [
            'valor' => (string) ($solicitud->consulta['valor'] ?? ''),
            'componente_academico' => (string) ($solicitud->consulta['componente_academico'] ?? ''),
        ];
        $errores = [];
        $valor = filter_var($valores['valor'], FILTER_VALIDATE_FLOAT);
        $academico = filter_var($valores['componente_academico'], FILTER_VALIDATE_FLOAT);
        if ($valor === false || $valor < 0) {
            $errores['valor'] = 'Escribe un valor mayor o igual a cero, sin puntos de miles.';
        }
        if ($academico === false || $academico < 0) {
            $errores['componente_academico'] = 'Escribe un valor mayor o igual a cero.';
        } elseif ($valor !== false && $academico > $valor) {
            $errores['componente_academico'] = 'El componente académico no puede superar el valor total del gasto.';
        }
        if ($errores !== []) {
            return $this->respuestaValidacion($solicitud, new ErrorValidacion($errores), 'sistema_diseno/formulario_demostracion', [], $valores);
        }
        $respuesta = $this->renderizar($solicitud, 'sistema_diseno/formulario_demostracion', 'sistema_diseno/formulario_demostracion', ['valores' => [], 'errores' => [], 'exito' => true]);
        return $this->conToast($respuesta, 'success', 'Los valores son válidos. En la I3 este formulario registrará el gasto.');
    }
}
