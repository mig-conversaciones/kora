/**
 * Deriva los recursos web de la marca KORA desde el kit de identidad (referencias/KORA_kit_identidad) hacia
 * public/marca y public/favicon.svg. El kit es la fuente de verdad: si cambia, se vuelve a ejecutar
 * `npm run marca` y luego `npm run construir`, que genera los PNG e ICO.
 *
 * Transformaciones (sin alterar trazados ni colores del kit):
 *   - Lienzo recortado al contenido; los SVG del kit traen márgenes amplios pensados para piezas impresas.
 *   - Fondo transparente en la versión para fondos oscuros: el menú lateral pone el azul pizarra.
 *   - Versión sin lema para tamaños pequeños (menú lateral), donde el lema quedaría ilegible.
 *   - Símbolo suelto («K» con la flecha) para el menú colapsado.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const raiz = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const kit = path.join(raiz, 'referencias', 'KORA_kit_identidad');
const destino = path.join(raiz, 'public', 'marca');
fs.mkdirSync(destino, { recursive: true });

const leer = (nombre) => fs.readFileSync(path.join(kit, nombre), 'utf8');

/** Grupos <g> de primer nivel del SVG del kit: [símbolo, palabra KORA, lema]. */
function grupos(svg) {
  return [...svg.matchAll(/<g transform="[^"]*"[^>]*>[\s\S]*?<\/g>/g)].map((m) => m[0]);
}

function componer({ viewBox, titulo, contenido }) {
  const [, , ancho, alto] = viewBox.split(' ').map(Number);
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="${viewBox}" width="${ancho}" height="${alto}" role="img" aria-label="${titulo}">\n  <title>${titulo}</title>\n  ${contenido.join('\n  ')}\n</svg>\n`;
}

const TITULO = 'KORA · Sistema de gestión productiva';
const CAJA_HORIZONTAL = '204 110 1028 264';
const CAJA_VERTICAL = '170 130 660 610';

const claro = grupos(leer('KORA_logo_horizontal_claro.svg'));
const oscuro = grupos(leer('KORA_logo_horizontal_oscuro.svg'));
const vertical = grupos(leer('KORA_logo_vertical.svg'));
const mono = grupos(leer('KORA_logo_monocromatico.svg'));

const salidas = {
  'kora-logo-claro.svg': componer({ viewBox: CAJA_HORIZONTAL, titulo: TITULO, contenido: claro }),
  'kora-logo-oscuro.svg': componer({ viewBox: CAJA_HORIZONTAL, titulo: TITULO, contenido: oscuro }),
  'kora-logo-oscuro-sin-lema.svg': componer({ viewBox: '204 120 1028 244', titulo: 'KORA', contenido: oscuro.slice(0, 2) }),
  'kora-logo-claro-sin-lema.svg': componer({ viewBox: '204 120 1028 244', titulo: 'KORA', contenido: claro.slice(0, 2) }),
  'kora-logo-vertical.svg': componer({ viewBox: CAJA_VERTICAL, titulo: TITULO, contenido: vertical }),
  'kora-logo-monocromatico.svg': componer({ viewBox: CAJA_HORIZONTAL, titulo: TITULO, contenido: mono }),
  // Símbolo: el trazado de la K ocupa 18–105 × 14–106 en su sistema propio.
  'kora-simbolo-oscuro.svg': componer({ viewBox: '10 6 103 108', titulo: 'KORA', contenido: [oscuro[0].replace(/<g transform="[^"]*"/, '<g')] }),
  'kora-simbolo-claro.svg': componer({ viewBox: '10 6 103 108', titulo: 'KORA', contenido: [claro[0].replace(/<g transform="[^"]*"/, '<g')] }),
  'kora-icono-movil.svg': leer('KORA_icono_movil.svg'),
};
for (const [nombre, svg] of Object.entries(salidas)) fs.writeFileSync(path.join(destino, nombre), svg);
fs.writeFileSync(path.join(raiz, 'public', 'favicon.svg'), leer('KORA_favicon.svg'));
process.stdout.write(`Marca preparada: ${Object.keys(salidas).length} archivos en public/marca y public/favicon.svg\n`);
