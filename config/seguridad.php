<?php

declare(strict_types=1);

/**
 * Parámetros de seguridad (RAC, RS; Anexo A). Los costos de Argon2id se calibran en el plan para que el inicio de
 * sesión tarde 500 ms o menos (RAC-002); los límites de frecuencia nunca bloquean de forma permanente (RAC-029).
 */

use App\Nucleo\Entorno;
use App\Nucleo\Url;

$url = Entorno::texto('APP_URL');
$https = str_starts_with($url, 'https://');
$partes = parse_url($url) ?: [];
// Origin del navegador: esquema, host y puerto, sin ruta (RS-005).
$origen = ($partes['scheme'] ?? 'https') . '://' . ($partes['host'] ?? '') . (isset($partes['port']) ? ':' . $partes['port'] : '');

return [
    // RAC-016: en producción (HTTPS en la raíz del dominio) se usa el prefijo __Host-, que exige Secure, Path=/ y
    // sin Domain. En MAMP (HTTP en un subdirectorio) el prefijo no es posible: la cookie se limita a la ruta base.
    'cookie_sesion' => [
        'nombre' => $https ? '__Host-kora_sesion' : 'kora_sesion',
        'segura' => $https,
        'solo_http' => true,
        'same_site' => 'Lax',
        'ruta' => $https ? '/' : (Url::base() === '' ? '/' : Url::base() . '/'),
    ],
    'sesion' => [
        'inactividad' => Entorno::entero('SESION_INACTIVIDAD_SEGUNDOS', 1800),
        'inactividad_privilegiados' => Entorno::entero('SESION_INACTIVIDAD_PRIVILEGIADOS_SEGUNDOS', 900),
        'absoluta' => Entorno::entero('SESION_ABSOLUTA_SEGUNDOS', 43200),
        'absoluta_privilegiados' => Entorno::entero('SESION_ABSOLUTA_PRIVILEGIADOS_SEGUNDOS', 28800),
        // Sesiones anónimas (solo llevan el token CSRF de los formularios públicos).
        'inactividad_anonima' => 3600,
        'aviso_antes_segundos' => 120,
    ],
    'contrasenas' => [
        'algoritmo' => PASSWORD_ARGON2ID,
        'opciones' => ['memory_cost' => 65536, 'time_cost' => 3, 'threads' => 1],
        'minimo_un_factor' => 15,
        'minimo_con_2fa' => 8,
        'maximo' => 128,
        'lista_bloqueada' => RAIZ . '/recursos/listas/contrasenas-comunes.txt',
        'palabras_contexto' => ['kora', 'sena', 'cab', 'buga', 'produccion', 'ganaderia', 'milagroso'],
    ],
    'totp' => ['digitos' => 6, 'paso_segundos' => 30, 'ventana' => 1, 'codigos_respaldo' => 10, 'emisor' => 'KORA'],
    'tokens_cuenta' => ['recuperacion_minutos' => 30, 'invitacion_horas' => 72],
    'limites' => [
        'login_cuenta' => ['maximo' => 5, 'ventana' => 900, 'bloqueo' => 900],
        'login_ip' => ['maximo' => 20, 'ventana' => 900, 'bloqueo' => 900],
        'otp' => ['maximo' => 5, 'ventana' => 300, 'bloqueo' => 900],
        'recuperacion' => ['maximo' => 3, 'ventana' => 3600, 'bloqueo' => 3600],
        'activacion_ip' => ['maximo' => 10, 'ventana' => 3600, 'bloqueo' => 3600],
        'administracion' => ['maximo' => 60, 'ventana' => 60, 'bloqueo' => 60],
        'exportaciones' => ['maximo' => 10, 'ventana' => 3600, 'bloqueo' => 600],
        'importaciones' => ['maximo' => 5, 'ventana' => 3600, 'bloqueo' => 600],
    ],
    'reautenticacion_segundos' => 300,
    'roles_2fa_obligatoria' => ['superadministrador', 'administrador'],
    'roles_2fa_recomendada' => ['grupo_recaudo', 'coordinacion_administrativa'],
    'roles_privilegiados' => ['superadministrador', 'administrador'],
    'origen_permitido' => $origen,
    'token_monitoreo' => Entorno::texto('TOKEN_MONITOREO'),
];
