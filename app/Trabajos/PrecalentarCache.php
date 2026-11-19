<?php

declare(strict_types=1);

namespace App\Trabajos;

use App\Cola\ContextoTrabajo;
use App\Cola\ManejadorTrabajo;
use App\Nucleo\BaseDatos;
use App\Politicas\IdentidadActor;
use App\Tableros\FiltrosTablero;
use App\Tableros\ServicioIndicadores;
use App\Tableros\ServicioTableros;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Precalentamiento de la caché de tableros (tipo cache.precalentar; RNF-PERF-012, RF-Q-019; tabla D.4: días hábiles a
 * las 06:30). Calcula la vista por defecto de cada alcance distinto de las cuentas activas con permiso de tablero, de
 * modo que la primera consulta del día no pague la caché fría:
 *   - tablero central con los filtros por defecto (vigencia actual) y las opciones de producto;
 *   - tablero técnico de Ganadería, con o sin el lote de práctica según el valor por defecto del rol (RF-073).
 * Las claves son exactamente las que arma ControladorTableros, así que la entrada calentada es la que se consulta. Cada
 * alcance se relee de la base (IdentidadActor, RAC-024): una cuenta desactivada no calienta nada. Es idempotente.
 */
final class PrecalentarCache implements ManejadorTrabajo
{
    public function __construct(
        private readonly BaseDatos $bd,
        private readonly ServicioTableros $tableros,
        private readonly ServicioIndicadores $indicadores,
    ) {
    }

    public function ejecutar(array $trabajo, ContextoTrabajo $contexto): array
    {
        $vigencia = (int) (new DateTimeImmutable('now', new DateTimeZone('America/Bogota')))->format('Y');
        $cuentas = $this->bd->consultar(
            "SELECT DISTINCT u.id, u.centro_id FROM usuarios u
               JOIN usuarios_roles ur ON ur.usuario_id = u.id
               JOIN roles_permisos rp ON rp.rol_id = ur.rol_id
               JOIN permisos p ON p.id = rp.permiso_id AND p.codigo IN ('tablero_central.consultar', 'tablero_unidad.consultar')
              WHERE u.estado = 'activo' AND u.anonimizado_en IS NULL AND u.centro_id IS NOT NULL
              ORDER BY u.centro_id, u.id"
        );
        $hechos = [];
        $calentados = ['central' => 0, 'tecnico' => 0];
        foreach ($cuentas as $i => $cuenta) {
            $actor = IdentidadActor::cargar($this->bd, (int) $cuenta['id'], (int) $cuenta['centro_id']);
            if ($actor->alcance->estaVacio()) {
                continue;
            }
            $unidades = $this->indicadores->unidadesDelAlcance($actor->alcance);
            $ids = array_map(static fn (array $u): int => (int) $u['id'], $unidades);
            if ($actor->tienePermiso('tablero_central.consultar') && !isset($hechos['c' . $actor->alcance->huella()])) {
                $hechos['c' . $actor->alcance->huella()] = true;
                $this->tableros->central($actor->alcance, FiltrosTablero::desdeConsulta([], $ids, $vigencia));
                $this->tableros->opcionesProducto($actor->alcance, $vigencia);
                $calentados['central']++;
            }
            $ganaderia = array_values(array_filter($unidades, static fn (array $u): bool => strtoupper((string) $u['codigo']) === 'GAN'))[0] ?? null;
            $practica = in_array('coordinacion_academica', $actor->roles, true);
            $clave = 't' . $actor->alcance->huella() . ($practica ? '1' : '0');
            if ($ganaderia !== null && $actor->tienePermiso('tablero_unidad.consultar') && !isset($hechos[$clave])) {
                $hechos[$clave] = true;
                $gan = (int) $ganaderia['id'];
                $this->tableros->unidad($actor->alcance, $gan, FiltrosTablero::desdeConsulta(['unidad' => ''], [$gan], $vigencia)->conUnidad($gan), $practica);
                $calentados['tecnico']++;
            }
            if ($i % 10 === 0) {
                $contexto->latido(min(95, (int) (($i + 1) / max(1, count($cuentas)) * 100)), "Alcances calentados: {$calentados['central']} centrales y {$calentados['tecnico']} técnicos.");
            }
        }
        return $calentados + ['cuentas' => count($cuentas)];
    }
}
