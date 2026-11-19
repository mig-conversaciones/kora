<?php

declare(strict_types=1);

namespace App\Trabajos;

use App\Cola\ContextoTrabajo;
use App\Cola\ManejadorTrabajo;
use App\Nucleo\BaseDatos;
use App\Nucleo\Registro;
use App\Operacion\VerificadorIntegridad;

/**
 * Verificación diaria de integridad (tipo seguridad.verificar_integridad; RS-012, RS-022; tabla D.4: 03:15). Si hay
 * alertas, las registra y notifica a los superadministradores en la aplicación; el runbook «Incidente de seguridad»
 * indica qué hacer. No falla el trabajo por una alerta: el resultado queda en el trabajo y en la notificación.
 */
final class VerificarIntegridad implements ManejadorTrabajo
{
    public function __construct(
        private readonly VerificadorIntegridad $verificador,
        private readonly BaseDatos $bd,
        private readonly Registro $registro,
    ) {
    }

    public function ejecutar(array $trabajo, ContextoTrabajo $contexto): array
    {
        $contexto->latido(10, 'Comparando la versión activa con su manifiesto.');
        $resultado = $this->verificador->verificar();
        if ($resultado['alertas'] !== []) {
            $this->registro->error('alerta.integridad', ['alertas' => $resultado['alertas']]);
            $this->bd->ejecutar(
                "INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje)
                 SELECT DISTINCT ur.usuario_id, 'alerta_operativa', 'Alerta de integridad', :mensaje
                   FROM usuarios_roles ur JOIN roles r ON r.id = ur.rol_id JOIN usuarios u ON u.id = ur.usuario_id
                  WHERE r.codigo = 'superadministrador' AND u.estado = 'activo'",
                ['mensaje' => mb_substr(implode(' ', $resultado['alertas']) . ' Sigue el runbook «Incidente de seguridad».', 0, 500)]
            );
        }
        return ['integra' => $resultado['alertas'] === [], 'alertas' => count($resultado['alertas']), 'archivos' => $resultado['archivos']];
    }
}
