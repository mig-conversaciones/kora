<?php
/**
 * Listado de usuarios (RF-009). Variables: $filas, $hayMas, $pagina, $filtros, $roles, $actorId.
 *
 * @var App\Nucleo\Vista $this
 */
$estados = App\Servicios\ServicioUsuarios::ESTADOS;
$opcionesRoles = [];
foreach ($roles as $r) {
    $opcionesRoles[$r['codigo']] = $r['nombre'];
}
$insignia = ['invitado' => 'insignia-info', 'activo' => 'insignia-exito', 'desactivado' => 'insignia-neutra'];
$invitar = $this->url('/sistema/usuarios/invitar');
?>
<div id="panel-modulo">
    <div class="barra-acciones">
        <form class="barra-filtros" role="search" hx-get="<?= $this->e($this->url('/sistema/usuarios')) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" hx-push-url="true">
            <div class="campo-grupo">
                <label for="campo-buscar-usuario">Nombre o correo</label>
                <input id="campo-buscar-usuario" class="campo" type="search" name="buscar" value="<?= $this->e($filtros['buscar']) ?>" maxlength="100">
            </div>
            <?= $this->parcial('parciales/campo', ['nombre' => 'estado', 'etiqueta' => 'Estado', 'tipo' => 'select', 'opciones' => $estados, 'vacio' => 'Todos', 'valor' => $filtros['estado']]) ?>
            <?= $this->parcial('parciales/campo', ['nombre' => 'rol', 'etiqueta' => 'Rol', 'tipo' => 'select', 'opciones' => $opcionesRoles, 'vacio' => 'Todos', 'valor' => $filtros['rol']]) ?>
            <button type="submit" class="boton">Filtrar</button>
        </form>
        <a class="boton boton-primario" href="<?= $this->e($invitar) ?>" hx-get="<?= $this->e($invitar) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" hx-push-url="true">Invitar usuario</a>
    </div>
    <section class="tarjeta seccion" aria-labelledby="titulo-usuarios">
        <h2 id="titulo-usuarios" class="titulo-tarjeta">Cuentas</h2>
        <?php if ($filas === []): ?>
            <?= $this->parcial('parciales/estado_vacio', ['titulo' => 'Ninguna cuenta coincide', 'descripcion' => 'Prueba con otro filtro o invita a una persona.', 'accion' => ['texto' => 'Invitar usuario', 'url' => $invitar]]) ?>
        <?php else: ?>
            <div class="contenedor-tabla" tabindex="0" role="region" aria-labelledby="titulo-usuarios">
                <table class="tabla">
                    <thead><tr><th scope="col">Nombre</th><th scope="col">Correo</th><th scope="col">Rol</th><th scope="col">Unidades</th><th scope="col">Estado</th><th scope="col">2FA</th><th scope="col">Último acceso (UTC)</th><th scope="col"><span class="sr-only">Acciones</span></th></tr></thead>
                    <tbody>
                        <?php foreach ($filas as $u): ?>
                            <tr>
                                <td><?= $this->e($u['nombre']) ?><?= (int) $u['id'] === (int) $actorId ? ' <span class="insignia insignia-neutra">Tú</span>' : '' ?></td>
                                <td><?= $this->e($u['correo']) ?></td>
                                <td><?= $this->e($u['roles'] ?? '—') ?></td>
                                <td><?= $this->e($u['unidades'] ?? '—') ?></td>
                                <td><span class="insignia <?= $insignia[$u['estado']] ?? 'insignia-neutra' ?>"><?= $this->e($estados[$u['estado']] ?? $u['estado']) ?></span></td>
                                <td><?= $u['totp_activado_en'] === null ? 'No' : 'Sí' ?></td>
                                <td class="numero"><?= $u['ultimo_acceso_en'] === null ? '—' : $this->e(substr((string) $u['ultimo_acceso_en'], 0, 16)) ?></td>
                                <td>
                                    <?php if ((int) $u['id'] === (int) $actorId): ?>
                                        <a class="boton boton-pequeno boton-texto" href="<?= $this->e($this->url('/cuenta')) ?>">Mi cuenta</a>
                                    <?php else: ?>
                                        <?php $ver = $this->url('/sistema/usuarios/' . (int) $u['id']); ?>
                                        <a class="boton boton-pequeno boton-texto" href="<?= $this->e($ver) ?>" hx-get="<?= $this->e($ver) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" hx-push-url="true">Gestionar<span class="sr-only"> a <?= $this->e($u['nombre']) ?></span></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= $this->parcial('parciales/paginacion', ['ruta' => '/sistema/usuarios', 'consulta' => $filtros, 'pagina' => $pagina, 'hayMas' => $hayMas, 'objetivo' => '#panel-modulo']) ?>
        <?php endif; ?>
    </section>
</div>
