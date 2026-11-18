/**
 * Construcción de activos de KORA (RNF-019, RNF-PERF-013). Se ejecuta en local y en la CI; el servidor solo
 * recibe el resultado en public/ (sin node_modules):
 *   1. CSS: Tailwind CLI 3.4 con los tokens → minificado.
 *   2. JS propio: retroalimentacion-ui.js y componentes-alpine.js minificados con esbuild.
 *   3. Proveedores fijados: htmx 2.0, idiomorph 0.7 (extensión morph) y Alpine CSP 3.14.
 *   4. Huellas SHA-256 en el nombre y public/assets/manifest.json (lo lee App\Nucleo\Vista::asset).
 *   5. Fuentes Inter (latín) y variantes del favicon generadas desde public/favicon.svg con sharp.
 *   6. Presupuesto de peso (RNF-PERF-020): JS ≤ 80 KB y CSS ≤ 60 KB comprimidos con gzip.
 */
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { gzipSync } from 'node:zlib';
import { transform } from 'esbuild';
import sharp from 'sharp';

const raiz = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const publico = path.join(raiz, 'public');
const activos = path.join(publico, 'assets');
const temporal = path.join(raiz, 'almacenamiento', 'construccion');
const modulos = path.join(raiz, 'node_modules');
const PRESUPUESTO = { js: 80 * 1024, css: 60 * 1024 };

const huella = (contenido) => createHash('sha256').update(contenido).digest('hex').slice(0, 10);
const asegurarDirectorio = (directorio) => fs.mkdirSync(directorio, { recursive: true });

function limpiarConHuella(directorio) {
  if (!fs.existsSync(directorio)) return;
  for (const nombre of fs.readdirSync(directorio)) {
    if (/\.[0-9a-f]{10}\.(css|js)$/.test(nombre)) fs.rmSync(path.join(directorio, nombre));
  }
}

/** Escribe el contenido con huella en assets/<subdirectorio>/ y devuelve la ruta relativa para el manifiesto. */
function publicarConHuella(rutaLogica, contenido) {
  const { dir, name, ext } = path.parse(rutaLogica);
  const final = `${dir}/${name}.${huella(contenido)}${ext}`;
  asegurarDirectorio(path.join(activos, dir));
  fs.writeFileSync(path.join(activos, final), contenido);
  return final;
}

async function construirCss() {
  asegurarDirectorio(temporal);
  const salida = path.join(temporal, 'app.css');
  const cli = path.join(modulos, 'tailwindcss', 'lib', 'cli.js');
  execFileSync(process.execPath, [cli, '-c', path.join(raiz, 'tailwind.config.cjs'), '-i', path.join(raiz, 'recursos', 'css', 'app.css'), '-o', salida, '--minify'], {
    cwd: raiz,
    stdio: ['ignore', 'ignore', 'inherit'],
  });
  return fs.readFileSync(salida);
}

async function minificarJs(archivo) {
  const codigo = fs.readFileSync(archivo, 'utf8');
  const resultado = await transform(codigo, { minify: true, target: 'es2020', legalComments: 'none' });
  return Buffer.from(resultado.code);
}

function copiarFuentes() {
  const destino = path.join(activos, 'fuentes');
  asegurarDirectorio(destino);
  for (const peso of [400, 500, 600, 700]) {
    const nombre = `inter-latin-${peso}-normal.woff2`;
    fs.copyFileSync(path.join(modulos, '@fontsource', 'inter', 'files', nombre), path.join(destino, nombre));
  }
}

/** ICO con imágenes PNG incrustadas (formato admitido desde Windows Vista y por todos los navegadores). */
function crearIco(imagenes) {
  const cabecera = Buffer.alloc(6);
  cabecera.writeUInt16LE(0, 0);
  cabecera.writeUInt16LE(1, 2);
  cabecera.writeUInt16LE(imagenes.length, 4);
  const entradas = [];
  let desplazamiento = 6 + 16 * imagenes.length;
  for (const { tamano, datos } of imagenes) {
    const entrada = Buffer.alloc(16);
    entrada.writeUInt8(tamano >= 256 ? 0 : tamano, 0);
    entrada.writeUInt8(tamano >= 256 ? 0 : tamano, 1);
    entrada.writeUInt16LE(1, 4);
    entrada.writeUInt16LE(32, 6);
    entrada.writeUInt32LE(datos.length, 8);
    entrada.writeUInt32LE(desplazamiento, 12);
    desplazamiento += datos.length;
    entradas.push(entrada);
  }
  return Buffer.concat([cabecera, ...entradas, ...imagenes.map((i) => i.datos)]);
}

/**
 * Íconos desde la marca KORA (ADR-016; `npm run marca` deriva los SVG del kit de identidad):
 *   - favicon.svg (K blanca con flecha turquesa sobre azul pizarra) → favicon-32.png y favicon.ico (16, 32, 48)
 *   - marca/kora-icono-movil.svg → icono-192/512 y apple-touch-icon
 *   - marca/kora-simbolo-oscuro.svg → ícono adaptable con el símbolo dentro de la zona segura del 80 %
 */
async function generarIconos() {
  const rasterizar = (archivo) => {
    const svg = fs.readFileSync(path.join(publico, archivo));
    return (tamano) => sharp(svg, { density: 384 }).resize(tamano, tamano, { fit: 'contain', background: { r: 0, g: 0, b: 0, alpha: 0 } }).png().toBuffer();
  };
  const favicon = rasterizar('favicon.svg');
  const movil = rasterizar('marca/kora-icono-movil.svg');
  const simbolo = rasterizar('marca/kora-simbolo-oscuro.svg');
  fs.writeFileSync(path.join(publico, 'favicon-32.png'), await favicon(32));
  fs.writeFileSync(path.join(publico, 'favicon.ico'), crearIco([
    { tamano: 16, datos: await favicon(16) },
    { tamano: 32, datos: await favicon(32) },
    { tamano: 48, datos: await favicon(48) },
  ]));
  fs.writeFileSync(path.join(publico, 'icono-192.png'), await movil(192));
  fs.writeFileSync(path.join(publico, 'icono-512.png'), await movil(512));
  fs.writeFileSync(path.join(publico, 'apple-touch-icon.png'), await movil(180));
  const mascara = await sharp({ create: { width: 512, height: 512, channels: 4, background: '#182b43' } })
    .composite([{ input: await simbolo(280), top: 116, left: 116 }])
    .png()
    .toBuffer();
  fs.writeFileSync(path.join(publico, 'icono-512-mascara.png'), mascara);
}

function informar(manifiesto) {
  const filas = [];
  const totales = { js: 0, css: 0 };
  for (const [logica, final] of Object.entries(manifiesto)) {
    const contenido = fs.readFileSync(path.join(activos, final));
    const comprimido = gzipSync(contenido).length;
    const tipo = logica.endsWith('.css') ? 'css' : 'js';
    totales[tipo] += comprimido;
    filas.push(`  ${final.padEnd(48)} ${(contenido.length / 1024).toFixed(1).padStart(7)} KB  (${(comprimido / 1024).toFixed(1)} KB gzip)`);
  }
  process.stdout.write(`Activos publicados:\n${filas.join('\n')}\n`);
  let fallo = false;
  for (const tipo of ['js', 'css']) {
    const ok = totales[tipo] <= PRESUPUESTO[tipo];
    fallo ||= !ok;
    process.stdout.write(`Presupuesto ${tipo.toUpperCase()}: ${(totales[tipo] / 1024).toFixed(1)} KB de ${PRESUPUESTO[tipo] / 1024} KB gzip ${ok ? '✔' : '✘'}\n`);
  }
  if (fallo) {
    process.stderr.write('Se superó el presupuesto de peso (RNF-PERF-020).\n');
    process.exitCode = 1;
  }
}

async function principal() {
  for (const sub of ['css', 'js', 'vendor']) limpiarConHuella(path.join(activos, sub));
  const manifiesto = {};
  manifiesto['css/app.css'] = publicarConHuella('css/app.css', await construirCss());
  for (const nombre of ['retroalimentacion-ui.js', 'componentes-alpine.js']) {
    manifiesto[`js/${nombre}`] = publicarConHuella(`js/${nombre}`, await minificarJs(path.join(raiz, 'recursos', 'js', nombre)));
  }
  const proveedores = {
    'vendor/htmx.min.js': path.join(modulos, 'htmx.org', 'dist', 'htmx.min.js'),
    'vendor/idiomorph-ext.min.js': path.join(modulos, 'idiomorph', 'dist', 'idiomorph-ext.min.js'),
    'vendor/alpine-csp.min.js': path.join(modulos, '@alpinejs', 'csp', 'dist', 'cdn.min.js'),
  };
  for (const [logica, origen] of Object.entries(proveedores)) {
    manifiesto[logica] = publicarConHuella(logica, fs.readFileSync(origen));
  }
  fs.writeFileSync(path.join(activos, 'manifest.json'), `${JSON.stringify(manifiesto, null, 2)}\n`);
  copiarFuentes();
  await generarIconos();
  informar(manifiesto);
}

principal().catch((error) => {
  process.stderr.write(`${error.stack ?? error}\n`);
  process.exit(1);
});
