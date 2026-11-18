<?php

declare(strict_types=1);

/**
 * Modo mantenimiento (RF-MAN-005, RF-MAN-010). Una ruta exceptuada no concede autorización: los intermediarios
 * de sesión y autorización se siguen aplicando. /health/ready está exceptuada del aviso, pero responde 503
 * (no listo) mientras el modo esté activo.
 */

return [
    'senal' => RAIZ . '/almacenamiento/estado/mantenimiento.json',
    // Latido que exige la salida del mantenimiento (RF-MAN-016): el trabajador debe estar vivo.
    'latido_trabajador' => RAIZ . '/almacenamiento/latidos/trabajador.txt',
    'rutas_exceptuadas' => ['/ingresar', '/2fa', '/2fa/enrolar', '/reautenticar', '/salir', '/sistema/mantenimiento', '/health/live', '/health/ready'],
    'prefijos_exceptuados' => ['/assets/', '/favicon'],
    'permiso_gestion' => 'mantenimiento.gestionar',
    'permiso_acceso_excepcional' => 'mantenimiento.acceso_excepcional',
    'minutos_reanudacion' => 10,
    'retry_after_por_defecto' => 300,
    'tipos_trabajo_permitidos' => ['correo.sistema', 'mantenimiento.limpieza', 'auditoria.verificar_cadena', 'auditoria.exportar_lote', 'auditoria.archivar', 'seguridad.verificar_integridad'],
    'aviso_por_defecto' => [
        'titulo' => 'Estamos mejorando KORA',
        'mensaje' => 'El sistema está en mantenimiento programado. Tus datos están seguros; vuelve a intentarlo más tarde.',
    ],
];
