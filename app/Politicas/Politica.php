<?php

declare(strict_types=1);

namespace App\Politicas;

/**
 * Política de un recurso (RAC-023): combina el permiso RBAC con las condiciones ABAC (centro, unidad, estado del
 * registro, periodo abierto, propiedad). Cada módulo de negocio agrega la suya (PoliticaRecaudos, PoliticaHato…)
 * en su iteración y la registra en el Autorizador por el prefijo del permiso.
 */
interface Politica
{
    public function decidir(UsuarioSesion $usuario, string $accion, ?object $recurso, AlcanceDatos $alcance): Decision;
}
