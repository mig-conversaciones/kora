<?php

declare(strict_types=1);

namespace App\Trabajos;

use App\Cola\ContextoTrabajo;
use App\Cola\ManejadorTrabajo;
use App\Nucleo\BaseDatos;
use App\Nucleo\Registro;
use App\Servicios\ServicioAuditoria;

/**
 * Verificación diaria de la cadena de auditoría (tipo auditoria.verificar_cadena; RAU-004). Antes de verificar
 * sella lo pendiente. Si la cadena no es íntegra o quedan eventos sin sellar con más de 3 min, registra un error
 * crítico y notifica a los superadministradores (RF-Q-016, RAC-031).
 */
final class VerificarCadena implements ManejadorTrabajo
{
    public function __construct(private readonly ServicioAuditoria $auditoria, private readonly BaseDatos $bd, private readonly Registro $registro)
    {
    }

    public function ejecutar(array $trabajo, ContextoTrabajo $contexto): array
    {
        $sellados = $this->auditoria->sellarPendientes();
        $contexto->latido(30, 'Eventos pendientes sellados.');
        $resultado = $this->auditoria->verificarCadena();
        if (!$resultado['integra'] || $resultado['sin_sellar'] > 0) {
            $detalle = $resultado['integra'] ? "{$resultado['sin_sellar']} eventos llevan más de 3 minutos sin sellar." : $resultado['detalle'];
            $this->registro->registrar('critico', 'alerta.auditoria_cadena', ['detalle' => $detalle, 'primera_falla' => $resultado['primera_falla']]);
            $this->bd->ejecutar(
                "INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje)
                 SELECT DISTINCT ur.usuario_id, 'alerta_seguridad', 'Alerta: cadena de auditoría', :mensaje
                   FROM usuarios_roles ur JOIN roles r ON r.id = ur.rol_id JOIN usuarios u ON u.id = ur.usuario_id
                  WHERE r.codigo = 'superadministrador' AND u.estado = 'activo'",
                ['mensaje' => mb_substr($detalle, 0, 500)]
            );
        }
        return ['sellados' => $sellados] + $resultado;
    }
}
