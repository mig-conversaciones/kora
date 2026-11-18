// Rasteriza paginas del PDF para revision visual: node rasterizar-pdf.mjs <pdf> <dirSalida> [paginas]
// paginas: lista separada por comas, rangos con guion (1-3,10) o "todas".
import fs from 'node:fs';
import path from 'node:path';
import * as mupdf from 'mupdf';

const [pdf, salida, seleccion = '1-3'] = process.argv.slice(2);
const doc = mupdf.Document.openDocument(fs.readFileSync(pdf), 'application/pdf');
const total = doc.countPages();
const paginas = seleccion === 'todas'
  ? [...Array(total).keys()].map(i => i + 1)
  : seleccion.split(',').flatMap(p => {
    const [a, b] = p.split('-').map(Number);
    return b ? [...Array(b - a + 1).keys()].map(i => a + i) : [a];
  }).filter(n => n >= 1 && n <= total);
fs.mkdirSync(salida, { recursive: true });
for (const n of paginas) {
  const pix = doc.loadPage(n - 1).toPixmap(mupdf.Matrix.scale(1.1, 1.1), mupdf.ColorSpace.DeviceRGB, false, true);
  fs.writeFileSync(path.join(salida, `pagina-${String(n).padStart(3, '0')}.png`), pix.asPNG());
}
console.log(`Total de paginas: ${total}. Rasterizadas: ${paginas.join(', ')}`);
