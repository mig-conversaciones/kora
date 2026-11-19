<?php
/**
 * Estado de un lote para la consulta periódica (RF-085): mientras se valida o aplica, se vuelve a pedir cada 3 s; al
 * terminar, deja de consultar. Variables: $lote, $progreso.
 *
 * @var App\Nucleo\Vista $this
 */
$enCurso = in_array($lote['estado'], ['validando', 'aplicando', 'revirtiendo'], true);
$textos = ['validando' => 'Validando', 'aplicando' => 'Aplicando', 'revirtiendo' => 'Revirtiendo', 'validado' => 'Listo para aplicar', 'con_errores' => 'Con errores', 'aplicado' => 'Aplicado', 'revertido' => 'Revertido', 'fallido' => 'Fallido', 'cargado' => 'Cargado'];
$tonos = ['validado' => 'exito', 'aplicado' => 'exito', 'con_errores' => 'advertencia', 'fallido' => 'error', 'revertido' => 'neutra', 'cargado' => 'neutra'];
?>
<?php if ($enCurso): ?>
    <span hx-get="<?= $this->e($this->url('/importaciones/' . $lote['ulid'] . '/estado')) ?>" hx-trigger="every 3s" hx-swap="outerHTML">
        <span class="insignia insignia-info"><?= $this->e($textos[$lote['estado']]) ?><?= $progreso !== null ? ' ' . (int) $progreso['progreso'] . ' %' : '' ?></span>
        <?php if ($progreso !== null): ?><meter class="medidor-avance" min="0" max="100" value="<?= (int) $progreso['progreso'] ?>" aria-label="Avance"></meter><?php endif; ?>
    </span>
<?php else: ?>
    <span class="insignia insignia-<?= $this->e($tonos[$lote['estado']] ?? 'neutra') ?>"><?= $this->e($textos[$lote['estado']] ?? $lote['estado']) ?></span>
<?php endif; ?>
