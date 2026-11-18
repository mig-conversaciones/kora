// Convierte cada diagrama SVG de docs/srs/diagramas en PNG (respaldo para visores sin soporte SVG)
// y registra sus dimensiones para que el generador conserve la proporcion al insertarlos.
// Se usa sharp (librsvg) porque el build WASM de mupdf no incluye el lector de SVG.
import fs from 'node:fs';
import path from 'node:path';
import sharp from 'sharp';
import { RAIZ } from './comun.mjs';

const DIR = path.join(RAIZ, 'diagramas');
const tamanos = {};
for (const archivo of fs.readdirSync(DIR).filter(f => f.endsWith('.svg')).sort()) {
  const png = archivo.replace(/\.svg$/, '.png');
  const info = await sharp(fs.readFileSync(path.join(DIR, archivo)), { density: 192 }).png().toFile(path.join(DIR, png));
  tamanos[png] = { ancho: info.width, alto: info.height };
  console.log(`${archivo} -> ${png} (${info.width}x${info.height})`);
}
fs.writeFileSync(path.join(DIR, 'tamanos.json'), JSON.stringify(tamanos, null, 2));
