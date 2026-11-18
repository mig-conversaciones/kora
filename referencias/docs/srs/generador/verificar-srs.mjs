// Verificador estructural del SRS. Falla (codigo 1) si encuentra errores que harian el documento
// incoherente: IDs duplicados, requisitos sin prueba, secciones obligatorias ausentes, marcadores sin
// completar o bloques de codigo con errores de sintaxis.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { marked } from 'marked';
import { cargarFuentes, leerRequisitos, ORDEN_FAMILIAS, TIPOS_PRUEBA, PATRON_ID } from './comun.mjs';

const errores = [];
const avisos = [];
const reqs = leerRequisitos();
const definidos = new Set();

// 1. Requisitos: unicidad, prioridad, implementacion y pruebas validas.
for (const r of reqs) {
  if (definidos.has(r.id)) errores.push(`ID duplicado: ${r.id} (${r.archivo})`);
  definidos.add(r.id);
  if (!/^(Debe|Debería|Podría|Futuro)$/.test(r.prioridad)) errores.push(`${r.id}: prioridad invalida «${r.prioridad}»`);
  if (!r.implementacion) errores.push(`${r.id}: falta «Impl.:»`);
  if (!r.pruebas.length) errores.push(`${r.id}: falta «Verif.:»`);
  for (const p of r.pruebas) if (!TIPOS_PRUEBA.includes(p)) errores.push(`${r.id}: tipo de prueba desconocido «${p}»`);
}
for (const f of ORDEN_FAMILIAS) {
  const n = reqs.filter(r => r.familia === f.codigo).length;
  if (n < 5) errores.push(`Familia ${f.codigo} con solo ${n} requisitos (minimo 5).`);
}

// 2. Recorrido de todas las fuentes: referencias, marcadores, encabezados y codigo.
const texto = cargarFuentes().map(a => fs.readFileSync(a, 'utf8')).join('\n');
const referencias = texto.match(/\b(?:RF-Q|RF-MAN|RNF-PERF|RF|RNF|RI|RS|RAC|RAU|RDI|RDOC|RUI|RBD|RDL|ROB|RGO)-\d{3}\b/g) ?? [];
for (const ref of new Set(referencias)) if (!definidos.has(ref)) errores.push(`Referencia a requisito inexistente: ${ref}`);
for (const marca of [/\bTODO\b/, /\bFIXME\b/, /CONTINUAR CON SECCI/, /\[X\]/, /\bXXX\b/, /lorem ipsum/i]) {
  if (marca.test(texto)) errores.push(`Marcador sin completar: ${marca}`);
}

const encabezados = [];
const bloques = [];
for (const archivo of cargarFuentes()) {
  const recorrer = (ts) => {
    for (const t of ts) {
      if (t.type === 'heading') encabezados.push(t.text);
      if (t.type === 'code') bloques.push({ ...t, archivoFuente: path.basename(archivo) });
      if (t.type === 'list') t.items.forEach(i => recorrer(i.tokens));
      else if (t.tokens && t.type !== 'table') recorrer(t.tokens);
    }
  };
  recorrer(marked.lexer(fs.readFileSync(archivo, 'utf8')));
}
const OBLIGATORIOS = [
  'Introducción', 'Propósito', 'Alcance', 'Interesados', 'Definiciones', 'Acrónimos', 'Referencias',
  'Descripción general', 'Perspectiva del producto', 'Funciones del producto', 'Características de los usuarios',
  'Restricciones', 'Variables del proyecto', 'Inconsistencias', 'Requisitos específicos', 'Matriz de trazabilidad',
  'Arquitectura técnica', 'MVC', 'Arquitectura de Colas Asíncronas & Cron Jobs', 'Sistema de mensajería UI',
  'Checklist de migración a Hostinger', 'Planificación de iteraciones y recursos humanos', 'Cronograma detallado',
  'Asignación de recursos por iteración', 'Cálculo de costos', 'Gantt simplificado', 'Anexos', 'env.example',
  'Scripts de base de datos', 'Plantillas de correo', 'Checklist OWASP ASVS', 'Linus Torvalds', 'cron en Hostinger',
  'Modo mantenimiento', 'Fichas técnicas de procesos', 'Observaciones de la revisión del mockup',
];
const normal = (s) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
for (const o of OBLIGATORIOS) if (!encabezados.some(h => normal(h).includes(normal(o)))) errores.push(`Encabezado obligatorio ausente: «${o}»`);

const ARCHIVOS_CRITICOS = [
  'public/assets/js/retroalimentacion-ui.js', 'public/.htaccess', 'app/Controladores/ControladorBase.php',
  'config/base_datos.php', 'public/index.php', 'cli/trabajador_cron.php', 'app/Cola/DespachadorCola.php',
  'app/Cola/ConductorCola.php', 'app/Controladores/ControladorTrabajos.php', 'config/cola.php',
  'config/funcionalidades.php', 'app/Controladores/SaludControlador.php', 'scripts/backup.sh', 'scripts/rollback.sh',
  'app/Intermediarios/Mantenimiento.php', 'cli/mantenimiento.php', '.env.example',
];
const archivosEnBloques = bloques.map(b => (b.lang?.match(/archivo=(\S+)/) || [])[1]).filter(Boolean);
for (const a of ARCHIVOS_CRITICOS) if (!archivosEnBloques.includes(a)) errores.push(`Archivo critico sin bloque de codigo: ${a}`);
const repetidos = archivosEnBloques.filter((a, i) => archivosEnBloques.indexOf(a) !== i);
for (const a of new Set(repetidos)) avisos.push(`Archivo con mas de un bloque (revisar coherencia): ${a}`);
if (!archivosEnBloques.some(a => a.startsWith('bd/migraciones/'))) errores.push('No hay migraciones SQL.');
if (!archivosEnBloques.some(a => a.startsWith('tests/carga/'))) errores.push('No hay scripts de carga en tests/carga/.');

// 3. Sintaxis de los bloques de codigo.
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'srs-codigo-'));
const ejecutar = (cmd, args) => { try { execFileSync(cmd, args, { stdio: 'pipe', encoding: 'utf8' }); return null; } catch (e) { return (e.stdout || '') + (e.stderr || e.message); } };
let revisados = 0;
bloques.forEach((b, i) => {
  const lang = (b.lang || '').split(/\s+/)[0];
  const nombre = (b.lang?.match(/archivo=(\S+)/) || [])[1] ?? `${b.archivoFuente}#${i}`;
  let error = null;
  if (lang === 'php') {
    const f = path.join(tmp, `b${i}.php`);
    fs.writeFileSync(f, b.text.trimStart().startsWith('<?php') ? b.text : `<?php\n${b.text}`);
    error = ejecutar('php', ['-l', f]); revisados++;
    if (error && /No syntax errors/.test(error)) error = null;
  } else if (['js', 'javascript'].includes(lang)) {
    const f = path.join(tmp, `b${i}.mjs`);
    fs.writeFileSync(f, b.text);
    error = ejecutar('node', ['--check', f]); revisados++;
  } else if (['bash', 'sh'].includes(lang)) {
    const f = path.join(tmp, `b${i}.sh`);
    fs.writeFileSync(f, b.text.replace(/\r/g, ''));
    error = ejecutar('bash', ['-n', f]); revisados++;
  } else if (lang === 'json') {
    try { JSON.parse(b.text); } catch (e) { error = e.message; }
    revisados++;
  } else if (lang === 'sql') {
    const abiertos = (b.text.match(/\(/g) || []).length, cerrados = (b.text.match(/\)/g) || []).length;
    if (abiertos !== cerrados) error = `parentesis desbalanceados (${abiertos} vs ${cerrados})`;
    revisados++;
  }
  if (error) errores.push(`Sintaxis en ${nombre} [${lang}]: ${error.split('\n').filter(Boolean).slice(0, 3).join(' | ')}`);
});
fs.rmSync(tmp, { recursive: true, force: true });

// 4. Resumen.
console.log(`Requisitos: ${reqs.length} en ${new Set(reqs.map(r => r.familia)).size} familias`);
for (const f of ORDEN_FAMILIAS) console.log(`  ${f.codigo.padEnd(9)} ${reqs.filter(r => r.familia === f.codigo).length}`);
console.log(`Bloques de codigo: ${bloques.length} (revisados sintacticamente: ${revisados})`);
console.log(`Encabezados: ${encabezados.length}`);
for (const a of avisos) console.log(`AVISO: ${a}`);
if (errores.length) {
  for (const e of errores) console.error(`ERROR: ${e}`);
  console.error(`\n${errores.length} error(es).`);
  process.exit(1);
}
console.log('Verificacion superada.');
