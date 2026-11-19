<?php

declare(strict_types=1);

namespace App\Controladores;

use App\Importacion\ServicioCargaInicial;
use App\Nucleo\ErrorHttp;
use App\Nucleo\ErrorValidacion;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Nucleo\Vista;
use App\Servicios\AutorizacionRecurso;

/**
 * Carga inicial guiada del piloto (RF-094, RDL-011): pasos en orden, verificación de las cifras de control, acta y
 * firma. Cada fuente se importa con el flujo normal de importaciones.
 */
final class ControladorCargaInicial extends ControladorModulo
{
    public function __construct(Vista $vista, private readonly ServicioCargaInicial $cargas)
    {
        parent::__construct($vista);
    }

    public function inicio(Solicitud $solicitud): Respuesta
    {
        $this->exigirAcceso($solicitud);
        return $this->pantalla($solicitud, 'importaciones/carga_inicial', $this->datos($solicitud), $this->meta('Carga inicial del piloto', 'Carga guiada de los libros actualizados, con cifras de control y acta firmada.'));
    }

    public function iniciar(Solicitud $solicitud): Respuesta
    {
        try {
            $this->cargas->iniciar($solicitud, (string) ($solicitud->cuerpo['vigencia'] ?? ''));
        } catch (ErrorValidacion $error) {
            return $this->validacion($solicitud, $error, 'importaciones/carga_inicial', $this->datos($solicitud), $this->meta('Carga inicial del piloto'));
        }
        return $this->trasGuardar($this->inicio($solicitud), '/importaciones/carga-inicial', 'Carga inicial iniciada. Importa las fuentes en el orden indicado.');
    }

    public function verificar(Solicitud $solicitud, string $ulid): Respuesta
    {
        try {
            $carga = $this->cargas->verificar($solicitud, $ulid);
        } catch (ErrorValidacion $error) {
            return $this->validacion($solicitud, $error, 'importaciones/carga_inicial', $this->datos($solicitud), $this->meta('Carga inicial del piloto'));
        }
        $verificada = $carga['estado'] === 'verificada';
        return $this->conToast(
            $this->pantalla($solicitud, 'importaciones/carga_inicial', $this->datos($solicitud), $this->meta('Carga inicial del piloto')),
            $verificada ? 'success' : 'warning',
            $verificada ? 'Cifras de control verificadas: la carga se puede firmar.' : 'Hay diferencias en las cifras de control. Revísalas en el acta.'
        );
    }

    public function firmar(Solicitud $solicitud, string $ulid): Respuesta
    {
        try {
            $this->cargas->firmar($solicitud, $ulid, (string) ($solicitud->cuerpo['observaciones'] ?? ''), ($solicitud->cuerpo['declaracion'] ?? '') === '1');
        } catch (ErrorValidacion $error) {
            return $this->validacion($solicitud, $error, 'importaciones/carga_inicial', $this->datos($solicitud), $this->meta('Carga inicial del piloto'));
        }
        return $this->trasGuardar($this->inicio($solicitud), '/importaciones/carga-inicial', 'Acta de la carga inicial firmada.');
    }

    public function anular(Solicitud $solicitud, string $ulid): Respuesta
    {
        try {
            $this->cargas->anular($solicitud, $ulid, (string) ($solicitud->cuerpo['motivo'] ?? ''));
        } catch (ErrorValidacion $error) {
            return $this->validacion($solicitud, $error, 'importaciones/carga_inicial', $this->datos($solicitud), $this->meta('Carga inicial del piloto'));
        }
        return $this->trasGuardar($this->inicio($solicitud), '/importaciones/carga-inicial', 'Carga inicial anulada; el motivo quedó en la auditoría.');
    }

    /** Acta imprimible: fuentes, archivos con su huella, cifras, diferencias, filas rechazadas y firma. */
    public function acta(Solicitud $solicitud, string $ulid): Respuesta
    {
        $this->exigirAcceso($solicitud);
        $carga = $this->cargas->carga($solicitud, $ulid);
        $resultado = json_decode((string) ($carga['resultado'] ?? '{}'), true) ?: [];
        return $this->pantalla($solicitud, 'importaciones/carga_inicial_acta', ['carga' => $carga, 'resultado' => $resultado], $this->meta('Acta de la carga inicial'));
    }

    /** @return array<string,mixed> */
    private function datos(Solicitud $solicitud): array
    {
        $usuario = AutorizacionRecurso::usuario($solicitud);
        $centro = AutorizacionRecurso::alcance($solicitud)->centroId;
        $carga = $this->cargas->vigente($centro);
        return [
            'carga' => $carga,
            'pasos' => $carga === null ? [] : $this->cargas->pasos($carga),
            'resultado' => $carga === null ? [] : (json_decode((string) ($carga['resultado'] ?? '{}'), true) ?: []),
            'historial' => $this->cargas->historial($centro),
            'puedeGestionar' => $usuario->tienePermiso('carga_inicial.gestionar'),
            'puedeFirmar' => $usuario->tienePermiso('carga_inicial.firmar'),
            'vigenciaSugerida' => self::anioActual(),
        ];
    }

    private function exigirAcceso(Solicitud $solicitud): void
    {
        $usuario = AutorizacionRecurso::usuario($solicitud);
        if (!$usuario->tienePermiso('carga_inicial.gestionar') && !$usuario->tienePermiso('carga_inicial.firmar')) {
            throw new ErrorHttp(403);
        }
    }

    /** @return array<string,mixed> */
    private function meta(string $titulo, string $descripcion = ''): array
    {
        return [
            'titulo' => $titulo, 'sobretitulo' => 'Importación de BBDD', 'descripcion' => $descripcion,
            'pestanas' => [['texto' => 'Lotes', 'ruta' => '/importaciones'], ['texto' => 'Acta de carga', 'ruta' => '/importaciones/acta'], ['texto' => 'Carga inicial', 'ruta' => '/importaciones/carga-inicial', 'permiso' => ['carga_inicial.gestionar', 'carga_inicial.firmar']]],
            'migas' => [['texto' => 'Importaciones', 'url' => '/importaciones'], ['texto' => $titulo]],
        ];
    }
}
