<?php
/**
 * Contenido del inicio en la I0. Variables: $iteraciones.
 *
 * @var App\Nucleo\Vista $this
 */
$etiquetas = ['en_curso' => ['En curso', 'insignia-info'], 'pendiente' => ['Planeada', 'insignia-neutra'], 'hecha' => ['Entregada', 'insignia-exito']];
?>
<header class="encabezado-pagina">
    <div>
        <p class="sobretitulo">Centro Agropecuario de Buga · SENA</p>
        <h1 class="titulo-pagina">Bienvenido a KORA</h1>
        <p class="texto-secundario">Gestión de la producción, la trazabilidad y los tableros de mando del centro. El piloto es la unidad de Ganadería, Hato El Milagroso.</p>
    </div>
    <a class="boton boton-primario" href="<?= $this->url('/sistema-diseno') ?>">Ver el sistema de diseño</a>
</header>

<div class="rejilla-bento">
    <section class="tarjeta tarjeta-bento bento-2x1" aria-labelledby="titulo-estado">
        <h2 id="titulo-estado" class="titulo-tarjeta">Estado de la plataforma</h2>
        <p class="texto-secundario">Iteración 2 «Identidad y acceso»: acceso seguro con verificación en dos pasos.</p>
        <ul class="lista-comprobaciones">
            <li><?= $this->parcial('parciales/icono', ['nombre' => 'ok', 'clase' => 'texto-exito']) ?> Arquitectura MVC en PHP nativo con HTMX y Alpine (CSP estricta) y sistema de diseño KORA</li>
            <li><?= $this->parcial('parciales/icono', ['nombre' => 'ok', 'clase' => 'texto-exito']) ?> Cola durable con trabajador y programador por cron, reintentos y alertas</li>
            <li><?= $this->parcial('parciales/icono', ['nombre' => 'ok', 'clase' => 'texto-exito']) ?> Correo con cortacircuitos, salud completa, caché versionada y modo mantenimiento</li>
            <li><?= $this->parcial('parciales/icono', ['nombre' => 'ok', 'clase' => 'texto-exito']) ?> Ingreso con 2FA, sesiones en el servidor, permisos por rol y alcance, y auditoría sellada</li>
            <li><?= $this->parcial('parciales/icono', ['nombre' => 'reloj', 'clase' => 'texto-advertencia']) ?> Siguiente: maestros, presupuesto y gestión de usuarios desde la interfaz (I3)</li>
        </ul>
    </section>
    <section class="tarjeta tarjeta-bento tarjeta-kpi" aria-labelledby="titulo-kpi-iteraciones">
        <h2 id="titulo-kpi-iteraciones" class="kpi-etiqueta">Iteraciones planeadas</h2>
        <p class="kpi-valor">9</p>
        <p class="kpi-nota">Salida a producción prevista: 29-abr-2027</p>
    </section>
    <section class="tarjeta tarjeta-bento tarjeta-kpi" aria-labelledby="titulo-kpi-requisitos">
        <h2 id="titulo-kpi-requisitos" class="kpi-etiqueta">Requisitos del SRS</h2>
        <p class="kpi-valor">357</p>
        <p class="kpi-nota">Trazables a pruebas y fichas de proceso</p>
    </section>
</div>

<section class="tarjeta seccion" aria-labelledby="titulo-hoja-ruta">
    <h2 id="titulo-hoja-ruta" class="titulo-tarjeta">Hoja de ruta</h2>
    <ol class="linea-tiempo">
        <?php foreach ($iteraciones as $iteracion): ?>
            <?php [$texto, $clase] = $etiquetas[$iteracion['estado']] ?? $etiquetas['pendiente']; ?>
            <li class="linea-tiempo-elemento<?= $iteracion['estado'] === 'en_curso' ? ' linea-tiempo-actual' : '' ?>">
                <span class="linea-tiempo-codigo"><?= $this->e($iteracion['codigo']) ?></span>
                <div>
                    <p class="linea-tiempo-titulo"><?= $this->e($iteracion['nombre']) ?> <span class="insignia <?= $this->e($clase) ?>"><?= $this->e($texto) ?></span></p>
                    <p class="texto-secundario"><?= $this->e($iteracion['detalle']) ?></p>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>
</section>
