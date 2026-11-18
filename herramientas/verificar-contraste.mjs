/**
 * Verificación automática del contraste de los pares de tokens (RUI-003, RUI-005): WCAG 2.2 AA, 4,5:1 para texto
 * y 3:1 para componentes y texto grande, en modo claro y oscuro. Lee recursos/css/tokens.css; termina con código 1
 * si algún par no cumple.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const raiz = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const css = fs.readFileSync(path.join(raiz, 'recursos', 'css', 'tokens.css'), 'utf8');

function leerBloque(selector) {
  const inicio = css.indexOf(selector);
  if (inicio < 0) throw new Error(`No se encontró el bloque ${selector}`);
  const apertura = css.indexOf('{', inicio);
  const cierre = css.indexOf('}', apertura);
  const valores = {};
  for (const [, nombre, valor] of css.slice(apertura + 1, cierre).matchAll(/--([a-z0-9-]+)\s*:\s*([^;]+);/g)) valores[nombre] = valor.trim();
  return valores;
}

/** Primer color hexadecimal de un valor; para un gradiente se evalúa el extremo indicado. */
function hex(valor, extremo = 0) {
  const colores = [...valor.matchAll(/#([0-9a-f]{6}|[0-9a-f]{3})\b/gi)].map((m) => m[1]);
  const elegido = colores[Math.min(extremo, colores.length - 1)];
  if (!elegido) throw new Error(`Sin color en ${valor}`);
  return elegido.length === 3 ? elegido.split('').map((c) => c + c).join('') : elegido;
}

function luminancia(codigo) {
  const canales = [0, 2, 4].map((i) => parseInt(codigo.slice(i, i + 2), 16) / 255)
    .map((c) => (c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
  return 0.2126 * canales[0] + 0.7152 * canales[1] + 0.0722 * canales[2];
}

function contraste(a, b) {
  const [l1, l2] = [luminancia(a), luminancia(b)].sort((x, y) => y - x);
  return (l1 + 0.05) / (l2 + 0.05);
}

// [primer plano, fondo, mínimo, descripción]. 'blanco' y los valores #hex son colores fijos del CSS.
const PARES = [
  ['color-texto', 'color-superficie', 4.5, 'Texto sobre superficie'],
  ['color-texto', 'color-fondo', 4.5, 'Texto sobre fondo (canvas)'],
  ['color-texto', 'color-superficie-alt', 4.5, 'Texto sobre superficie alterna'],
  ['color-texto-secundario', 'color-superficie', 4.5, 'Texto secundario sobre superficie'],
  ['color-texto-secundario', 'color-fondo', 4.5, 'Texto secundario sobre fondo'],
  ['color-texto-secundario', 'color-superficie-alt', 4.5, 'Texto secundario sobre superficie alterna (encabezado de tabla)'],
  ['color-accion', 'color-superficie', 4.5, 'Acción: enlaces y botón de texto'],
  ['color-accion', 'color-fondo', 4.5, 'Acción sobre fondo'],
  ['color-accion', 'color-superficie', 3, 'Anillo de foco frente a la superficie'],
  ['color-borde-control', 'color-superficie', 3, 'Borde de controles operables (regla 4 del kit)'],
  ['color-borde-control', 'color-fondo', 3, 'Borde de controles sobre fondo'],
  ['color-texto', 'color-acento-suave', 4.5, 'Texto sobre turquesa pálido (selección)'],
  ['color-exito', 'color-superficie', 4.5, 'Éxito en texto'],
  ['color-advertencia', 'color-superficie', 4.5, 'Advertencia en texto'],
  ['color-error', 'color-superficie', 4.5, 'Error en texto'],
  ['color-info', 'color-superficie', 4.5, 'Información en texto'],
  ['color-exito', 'color-exito-fondo', 4.5, 'Insignia y alerta de éxito'],
  ['color-advertencia', 'color-advertencia-fondo', 4.5, 'Insignia, alerta y franja de advertencia'],
  ['color-error', 'color-error-fondo', 4.5, 'Insignia y alerta de error'],
  ['color-info', 'color-info-fondo', 4.5, 'Insignia y alerta informativa'],
  ['color-texto-secundario', 'color-neutro-fondo', 4.5, 'Insignia neutra'],
  ['color-texto-sobre-accion', 'superficie-boton-primario:0', 4.5, 'Botón primario (extremo claro)'],
  ['color-texto-sobre-accion', 'superficie-boton-primario:1', 4.5, 'Botón primario (extremo oscuro)'],
  ['color-texto-sobre-accion', 'superficie-boton-peligro:0', 4.5, 'Botón de peligro (extremo claro)'],
  ['serie-venta', 'color-superficie', 3, 'Serie venta directa (gráficos)'],
  ['serie-autoconsumo', 'color-superficie', 3, 'Serie autoconsumo (gráficos)'],
  ['serie-presupuesto', 'color-superficie', 3, 'Serie presupuesto (gráficos)'],
  ['serie-asignacion', 'color-superficie', 3, 'Serie asignación (gráficos)'],
  ['color-menu-texto', 'superficie-menu:1', 4.5, 'Texto del menú sobre azul pizarra'],
  ['#8fa3ba', 'superficie-menu:1', 4.5, 'Grupos del menú'],
  ['#b9c8d8', 'superficie-menu:1', 4.5, 'Insignias del menú'],
  ['blanco', '#1a4354', 4.5, 'Entrada activa del menú (turquesa al 16 % sobre azul pizarra)'],
  ['color-acento', 'superficie-menu:1', 3, 'Turquesa de acento sobre azul pizarra (iconos y franja activa)'],
];

function resolver(tokens, referencia) {
  if (referencia === 'blanco') return 'ffffff';
  if (referencia.startsWith('#')) return referencia.slice(1);
  const [nombre, extremo] = referencia.split(':');
  if (!(nombre in tokens)) throw new Error(`Falta el token --${nombre}`);
  return hex(tokens[nombre], Number(extremo ?? 0));
}

const claro = leerBloque(':root {');
const oscuro = { ...claro, ...leerBloque(':root[data-tema="oscuro"] {') };
let fallos = 0;
for (const [modo, tokens] of [['claro', claro], ['oscuro', oscuro]]) {
  process.stdout.write(`\nModo ${modo}\n`);
  for (const [frente, fondo, minimo, descripcion] of PARES) {
    const razon = contraste(resolver(tokens, frente), resolver(tokens, fondo));
    const ok = razon >= minimo;
    fallos += ok ? 0 : 1;
    process.stdout.write(`  ${ok ? '✔' : '✘'} ${razon.toFixed(2).padStart(5)}:1  (mín. ${minimo})  ${descripcion}\n`);
  }
}
process.stdout.write(fallos === 0 ? '\nTodos los pares cumplen.\n' : `\n${fallos} pares no cumplen el mínimo.\n`);
process.exitCode = fallos === 0 ? 0 : 1;
