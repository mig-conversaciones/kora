<?php

declare(strict_types=1);

namespace App\Controladores;

use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;

/** Inicio: presenta el estado de la plataforma y el avance por iteración. Sin datos de negocio. */
final class ControladorInicio extends ControladorBase
{
    public function mostrar(Solicitud $solicitud): Respuesta
    {
        return $this->renderizar($solicitud, 'inicio/pagina', 'inicio/contenido', [
            'titulo' => 'Inicio',
            'iteraciones' => [
                ['codigo' => 'I0', 'nombre' => 'Fundaciones', 'estado' => 'hecha', 'detalle' => 'Esqueleto MVC, migraciones 001 a 006, sistema de diseño, ADR y fichas de proceso.'],
                ['codigo' => 'I1', 'nombre' => 'Núcleo de plataforma', 'estado' => 'hecha', 'detalle' => 'Cola durable, trabajador por cron, correo, salud completa, caché y mantenimiento.'],
                ['codigo' => 'I2', 'nombre' => 'Identidad y acceso', 'estado' => 'hecha', 'detalle' => 'Invitación, Argon2id, 2FA, sesiones en BD, CSRF, RBAC/ABAC y auditoría sellada.'],
                ['codigo' => 'I3', 'nombre' => 'Maestros y finanzas', 'estado' => 'en_curso', 'detalle' => 'Unidades, productos, derivados, categorías de gasto, asignaciones y cierres.'],
                ['codigo' => 'I4', 'nombre' => 'Operación productiva', 'estado' => 'pendiente', 'detalle' => 'Recepciones, salidas, transformaciones, hato por cortes y bandeja de calidad.'],
                ['codigo' => 'I5', 'nombre' => 'Importación y recaudos', 'estado' => 'pendiente', 'detalle' => 'Carga de las 8 fuentes del piloto, recaudos de la DG y contrato OpenAPI.'],
                ['codigo' => 'I6', 'nombre' => 'Tableros e indicadores', 'estado' => 'pendiente', 'detalle' => 'Tablero central y técnico de Ganadería con oráculos y exportaciones.'],
                ['codigo' => 'I7', 'nombre' => 'Rendimiento y operación', 'estado' => 'pendiente', 'detalle' => 'Índices, pruebas k6, copias cifradas, despliegue y runbooks.'],
                ['codigo' => 'I8', 'nombre' => 'Endurecimiento, UAT y salida', 'estado' => 'pendiente', 'detalle' => 'ZAP, ASVS nivel 2, WCAG 2.2 AA, UAT del piloto y salida a producción.'],
            ],
        ]);
    }
}
