<?php
/**
 * Registro manual de un recaudo por el Grupo de Recaudo (RF-029, RF-034). Variables: $unidades, $productos, $valores,
 * $errores.
 *
 * @var App\Nucleo\Vista $this
 */
$v = $valores;
$opcionesUnidades = [];
foreach ($unidades as $u) {
    $opcionesUnidades[(string) $u['id']] = $u['nombre'];
}
$opcionesProductos = [];
foreach ($productos as $p) {
    $opcionesProductos[(string) $p['id']] = $p['nombre'];
}
$pasarelas = array_combine(App\Servicios\ServicioRecaudos::PASARELAS, App\Servicios\ServicioRecaudos::PASARELAS);
$tipos = array_combine(App\Servicios\ServicioRecaudos::TIPOS_INGRESO, App\Servicios\ServicioRecaudos::TIPOS_INGRESO);
$campo = fn (array $d): string => $this->parcial('parciales/campo', $d + ['errores' => $errores]);
?>
<div id="panel-modulo">
    <section class="tarjeta seccion">
        <h2 class="titulo-tarjeta">Alta manual de un recaudo</h2>
        <p class="texto-advertencia">Excepcional: los recaudos llegan con el archivo de la DG. El registro queda marcado como manual y auditado con su motivo.</p>
        <form class="formulario" hx-post="<?= $this->e($this->url('/recaudos')) ?>" hx-target="#panel-modulo" hx-swap="outerHTML" novalidate>
            <?= $this->parcial('parciales/resumen_errores', ['errores' => $errores]) ?>
            <div class="rejilla-campos">
                <?= $campo(['nombre' => 'fecha_pago', 'etiqueta' => 'Fecha de pago', 'tipo' => 'date', 'valor' => (string) ($v['fecha_pago'] ?? ''), 'atributos' => 'required']) ?>
                <?= $campo(['nombre' => 'valor', 'etiqueta' => 'Valor (COP)', 'valor' => (string) ($v['valor'] ?? ''), 'atributos' => 'inputmode="decimal" required']) ?>
                <?= $campo(['nombre' => 'tipo_ingreso', 'etiqueta' => 'Tipo de ingreso', 'tipo' => 'select', 'opciones' => $tipos, 'valor' => (string) ($v['tipo_ingreso'] ?? ''), 'ayuda' => 'Solo «Producción» cuenta en el indicador de ingresos.']) ?>
                <?= $campo(['nombre' => 'pasarela', 'etiqueta' => 'Pasarela o medio', 'tipo' => 'select', 'opciones' => $pasarelas, 'vacio' => 'Elige…', 'valor' => (string) ($v['pasarela'] ?? '')]) ?>
                <?= $campo(['nombre' => 'unidad_id', 'etiqueta' => 'Unidad', 'tipo' => 'select', 'opciones' => $opcionesUnidades, 'vacio' => 'Sin clasificar todavía', 'valor' => (string) ($v['unidad_id'] ?? '')]) ?>
                <?= $campo(['nombre' => 'producto_id', 'etiqueta' => 'Producto', 'tipo' => 'select', 'opciones' => $opcionesProductos, 'vacio' => 'Sin producto', 'valor' => (string) ($v['producto_id'] ?? '')]) ?>
                <?= $campo(['nombre' => 'concepto_fuente', 'etiqueta' => 'Concepto (texto de la fuente)', 'valor' => (string) ($v['concepto_fuente'] ?? ''), 'atributos' => 'maxlength="200"']) ?>
                <?= $campo(['nombre' => 'cupon_pago', 'etiqueta' => 'Cupón de pago', 'valor' => (string) ($v['cupon_pago'] ?? ''), 'atributos' => 'maxlength="60"']) ?>
                <?= $campo(['nombre' => 'prefactura', 'etiqueta' => 'Prefactura', 'valor' => (string) ($v['prefactura'] ?? ''), 'atributos' => 'maxlength="40"']) ?>
                <?= $campo(['nombre' => 'factura_electronica', 'etiqueta' => 'Factura electrónica', 'valor' => (string) ($v['factura_electronica'] ?? ''), 'atributos' => 'maxlength="40"']) ?>
                <?= $campo(['nombre' => 'referencia_soporte', 'etiqueta' => 'Referencia del soporte', 'valor' => (string) ($v['referencia_soporte'] ?? ''), 'ayuda' => 'Comprobante, extracto o correo que respalda el pago.', 'atributos' => 'maxlength="120" required']) ?>
                <?= $campo(['nombre' => 'motivo', 'etiqueta' => 'Motivo del alta manual', 'tipo' => 'textarea', 'valor' => (string) ($v['motivo'] ?? ''), 'ayuda' => 'Por qué este recaudo no llega en el archivo de la DG.', 'atributos' => 'maxlength="500" required']) ?>
                <?= $campo(['nombre' => 'identificacion', 'etiqueta' => 'Identificación del pagador', 'ayuda' => 'No se guarda en claro: solo una huella cifrada y los últimos 4 dígitos.', 'atributos' => 'inputmode="numeric" autocomplete="off" maxlength="20"']) ?>
            </div>
            <div class="grupo-botones">
                <button type="submit" class="boton boton-primario">Registrar recaudo</button>
                <a class="boton boton-texto" href="<?= $this->e($this->url('/recaudos')) ?>">Cancelar</a>
            </div>
        </form>
    </section>
</div>
