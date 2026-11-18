// Generador del SRS de Produccion CAB: convierte las fuentes Markdown de docs/srs/fuente en un
// documento Word formal. Se usa Markdown como fuente porque el SRS contiene cientos de requisitos y
// bloques de codigo que deben poder revisarse y versionarse como texto; el .docx es el entregable.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { marked } from 'marked';
import {
  AlignmentType, BorderStyle, Document, ExternalHyperlink, Footer, Header, HeadingLevel, ImageRun,
  LevelFormat, Packer, PageNumber, PageOrientation, Paragraph, PositionalTab, PositionalTabAlignment,
  PositionalTabLeader, PositionalTabRelativeTo, ShadingType, Table, TableCell, TableLayoutType,
  TableOfContents, TableRow, TextRun, VerticalAlign, WidthType,
} from 'docx';
import { cargarFuentes, leerRequisitos, evidenciaPorPrueba, ORDEN_FAMILIAS } from './comun.mjs';

const RAIZ = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const META = JSON.parse(fs.readFileSync(path.join(RAIZ, 'fuente', 'metadatos.json'), 'utf8'));
const SALIDA = path.join(RAIZ, META.archivoSalida);

// Geometria: carta (estandar en Colombia) con margenes de 2,5 cm. 1 cm = 567 DXA.
const PAGINA = { ancho: 12240, alto: 15840, margen: 1417 };
const ANCHO_TEXTO = { vertical: PAGINA.ancho - 2 * PAGINA.margen, horizontal: PAGINA.alto - 2 * PAGINA.margen };
const COLOR = {
  verdeOscuro: '14532D', verde: '15803D', ambar: 'B45309', azul: '4A718F', tinta: '1A2938',
  gris: '5F6B76', linea: 'C9D2DA', fondoCodigo: 'F3F5F7', cabeceraCodigo: 'E2E8ED', cabeceraTabla: 'DCEFE3',
};
const RECUADROS = {
  supuesto: { borde: '4A718F', fondo: 'EEF3F7' }, decision: { borde: '15803D', fondo: 'ECF6EF' },
  advertencia: { borde: 'D97706', fondo: 'FFF6E6' }, riesgo: { borde: 'D97706', fondo: 'FFF6E6' },
  pendiente: { borde: 'C34747', fondo: 'FDF0F0' }, importante: { borde: '14532D', fondo: 'E8F3EC' },
  nota: { borde: '8A97A3', fondo: 'F5F7F8' },
};

const desescapar = (t = '') => t.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&');
const sinAcentos = (t = '') => t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

// ---------- Texto en linea ----------
function runs(tokens = [], fmt = {}) {
  const out = [];
  for (const t of tokens) {
    switch (t.type) {
      case 'strong': out.push(...runs(t.tokens, { ...fmt, bold: true })); break;
      case 'em': out.push(...runs(t.tokens, { ...fmt, italics: true })); break;
      case 'del': out.push(...runs(t.tokens, { ...fmt, strike: true })); break;
      case 'codespan':
        out.push(new TextRun({ text: desescapar(t.text), font: 'Consolas', size: fmt.size ? fmt.size - 1 : 19, color: '7A2E0E', bold: fmt.bold, shading: { type: ShadingType.CLEAR, color: 'auto', fill: 'F1F3F5' } }));
        break;
      case 'link':
        out.push(new ExternalHyperlink({ link: t.href, children: runs(t.tokens, { ...fmt, color: '1D6FA5', underline: {} }).filter(r => r instanceof TextRun) }));
        break;
      case 'br': out.push(new TextRun({ break: 1 })); break;
      case 'html':
        if (/^<br\s*\/?>$/i.test(t.text.trim())) out.push(new TextRun({ break: 1 }));
        break;
      case 'escape': case 'text':
        if (t.tokens?.length) out.push(...runs(t.tokens, fmt));
        else out.push(new TextRun({ text: desescapar(t.text), ...fmt }));
        break;
      default:
        if (t.tokens?.length) out.push(...runs(t.tokens, fmt));
        else if (t.text) out.push(new TextRun({ text: desescapar(t.text), ...fmt }));
    }
  }
  return out;
}

// Divide los tokens en linea por <br> para producir parrafos separados dentro de celdas.
function partirPorBr(tokens = []) {
  const grupos = [[]];
  for (const t of tokens) {
    if (t.type === 'html' && /^<br\s*\/?>$/i.test(t.text.trim())) grupos.push([]);
    else grupos.at(-1).push(t);
  }
  return grupos.filter(g => g.length);
}

// ---------- Bloques ----------
class Constructor {
  constructor() {
    this.secciones = [];
    this.nuevaSeccion('vertical');
    this.instanciaLista = 0;
    this.anchosPendientes = null;
    this.tablaDensa = false;
    this.figura = 0;
  }
  nuevaSeccion(orientacion) {
    this.actual = { orientacion, hijos: [] };
    this.secciones.push(this.actual);
  }
  get ancho() { return ANCHO_TEXTO[this.actual.orientacion]; }
  agregar(...elementos) { this.actual.hijos.push(...elementos); }

  bloques(tokens, ctx = {}) {
    for (const t of tokens) this.bloque(t, ctx);
  }

  bloque(t, ctx) {
    switch (t.type) {
      case 'heading': return this.encabezado(t);
      case 'paragraph': return this.parrafo(t, ctx);
      case 'list': return this.lista(t, 0, ctx);
      case 'table': return this.tabla(t);
      case 'code': return this.codigo(t);
      case 'blockquote': return this.recuadro(t);
      case 'hr': return this.agregar(new Paragraph({ border: { bottom: { style: BorderStyle.SINGLE, size: 6, color: COLOR.linea, space: 1 } }, spacing: { after: 160 } }));
      case 'html': return this.marcador(t.text.trim());
      case 'space': return;
      default:
        if (t.tokens) return this.parrafo(t, ctx);
    }
  }

  marcador(html) {
    const m = html.match(/^<!--\s*([\s\S]*?)\s*-->$/);
    if (!m) return;
    const [orden, ...resto] = m[1].split(':');
    const arg = resto.join(':').trim();
    switch (orden.trim()) {
      case 'salto': this.agregar(new Paragraph({ pageBreakBefore: true, spacing: { after: 0 } })); break;
      case 'horizontal': this.nuevaSeccion('horizontal'); break;
      case 'vertical': this.nuevaSeccion('vertical'); break;
      case 'anchos': this.anchosPendientes = arg.split(',').map(Number); break;
      case 'tabla-densa': this.tablaDensa = true; break;
      case 'matriz-trazabilidad': this.matrizTrazabilidad(); break;
      case 'resumen-requisitos': this.resumenRequisitos(); break;
      default: break;
    }
  }

  encabezado(t) {
    const niveles = [HeadingLevel.HEADING_1, HeadingLevel.HEADING_2, HeadingLevel.HEADING_3, HeadingLevel.HEADING_4, HeadingLevel.HEADING_5];
    this.agregar(new Paragraph({ heading: niveles[t.depth - 1], pageBreakBefore: t.depth === 1, children: runs(t.tokens) }));
  }

  parrafo(t, ctx = {}) {
    // Una imagen sola en el parrafo se trata como figura con leyenda.
    if (t.tokens?.length === 1 && t.tokens[0].type === 'image') return this.imagen(t.tokens[0]);
    for (const grupo of partirPorBr(t.tokens)) {
      this.agregar(new Paragraph({ children: runs(grupo), spacing: ctx.espaciado, indent: ctx.sangria }));
    }
  }

  lista(t, nivel, ctx) {
    const referencia = t.ordered ? 'numeros' : 'vinetas';
    const instancia = t.ordered ? ++this.instanciaLista : 0;
    for (const item of t.items) {
      let primero = true;
      for (const sub of item.tokens) {
        if (sub.type === 'list') { this.lista(sub, Math.min(nivel + 1, 2), ctx); continue; }
        if (sub.type === 'space') continue;
        if (sub.type === 'code') { this.codigo(sub); continue; }
        const contenido = sub.tokens ?? [{ type: 'text', text: sub.text ?? '' }];
        this.agregar(new Paragraph({
          children: runs(contenido),
          numbering: primero ? { reference: referencia, level: nivel, instance: instancia } : undefined,
          indent: primero ? undefined : { left: 360 * (nivel + 1) + 240 },
          spacing: { after: 60 },
        }));
        primero = false;
      }
    }
  }

  anchosColumnas(cabecera, filas, total) {
    if (this.anchosPendientes && this.anchosPendientes.length === cabecera.length) {
      const suma = this.anchosPendientes.reduce((a, b) => a + b, 0);
      const anchos = this.anchosPendientes.map(p => Math.floor(total * p / suma));
      anchos[anchos.length - 1] += total - anchos.reduce((a, b) => a + b, 0);
      return anchos;
    }
    const peso = cabecera.map((c, i) => {
      const largos = [c.text, ...filas.map(f => f[i]?.text ?? '')].map(x => Math.min(desescapar(x).replace(/<br>/g, ' ').length, 70));
      return Math.max(6, Math.max(...largos) * 0.6 + (largos.reduce((a, b) => a + b, 0) / largos.length) * 0.4);
    });
    const suma = peso.reduce((a, b) => a + b, 0);
    const minimo = 850;
    let anchos = peso.map(p => Math.max(minimo, Math.floor(total * p / suma)));
    const exceso = anchos.reduce((a, b) => a + b, 0) - total;
    if (exceso > 0) {
      const ajustables = anchos.map((a, i) => (a > minimo ? i : -1)).filter(i => i >= 0);
      const base = ajustables.reduce((s, i) => s + anchos[i] - minimo, 0) || 1;
      anchos = anchos.map((a, i) => (ajustables.includes(i) ? Math.floor(a - exceso * (a - minimo) / base) : a));
    }
    anchos[anchos.length - 1] += total - anchos.reduce((a, b) => a + b, 0);
    return anchos;
  }

  celda(tokens, ancho, opciones = {}) {
    const tam = opciones.tam ?? 17;
    const parrafos = partirPorBr(tokens).map(grupo => new Paragraph({
      children: runs(grupo, { size: tam, bold: opciones.negrita, color: opciones.color }),
      spacing: { before: 10, after: 10, line: 240 }, alignment: opciones.alineacion,
    }));
    return new TableCell({
      width: { size: ancho, type: WidthType.DXA },
      shading: opciones.fondo ? { type: ShadingType.CLEAR, color: 'auto', fill: opciones.fondo } : undefined,
      margins: { top: 30, bottom: 30, left: 70, right: 70 },
      verticalAlign: VerticalAlign.TOP,
      columnSpan: opciones.columnas,
      children: parrafos.length ? parrafos : [new Paragraph({ children: [] })],
    });
  }

  tablaDesdeDatos(cabecera, filas, anchos, opciones = {}) {
    const tam = opciones.densa ? 15 : 17;
    const bordes = { style: BorderStyle.SINGLE, size: 4, color: COLOR.linea };
    const total = anchos.reduce((a, b) => a + b, 0);
    const filaCabecera = new TableRow({
      tableHeader: true, cantSplit: true,
      children: cabecera.map((c, i) => this.celda(c, anchos[i], { tam, negrita: true, fondo: COLOR.cabeceraTabla })),
    });
    const cuerpo = filas.map((f, n) => new TableRow({
      children: f.map((c, i) => this.celda(c, anchos[i], { tam, fondo: opciones.cebra && n % 2 ? 'F8FAF9' : undefined })),
    }));
    return new Table({
      width: { size: total, type: WidthType.DXA }, columnWidths: anchos, layout: TableLayoutType.FIXED,
      borders: { top: bordes, bottom: bordes, left: bordes, right: bordes, insideHorizontal: bordes, insideVertical: bordes },
      rows: [filaCabecera, ...cuerpo],
    });
  }

  tabla(t) {
    const anchos = this.anchosColumnas(t.header, t.rows, this.ancho);
    const tabla = this.tablaDesdeDatos(t.header.map(c => c.tokens), t.rows.map(f => f.map(c => c.tokens)), anchos, { densa: this.tablaDensa, cebra: t.rows.length > 6 });
    this.agregar(tabla, new Paragraph({ spacing: { after: 120 }, children: [] }));
    this.anchosPendientes = null;
    this.tablaDensa = false;
  }

  codigo(t) {
    const info = (t.lang || '').trim();
    if (info.startsWith('gantt-tabla')) return this.gantt(JSON.parse(t.text));
    const lenguaje = info.split(/\s+/)[0] || '';
    const archivo = (info.match(/archivo=(\S+)/) || [])[1];
    const borde = { style: BorderStyle.SINGLE, size: 4, color: 'C3CCD4', space: 3 };
    const bordes = { top: borde, bottom: borde, left: { ...borde, size: 12, color: COLOR.azul }, right: borde };
    if (archivo || lenguaje) {
      this.agregar(new Paragraph({
        keepNext: true, spacing: { before: 120, after: 0 }, border: bordes,
        shading: { type: ShadingType.CLEAR, color: 'auto', fill: COLOR.cabeceraCodigo },
        children: [new TextRun({ text: archivo ? `Archivo: ${archivo}` : lenguaje.toUpperCase(), font: 'Consolas', size: 16, bold: true, color: COLOR.tinta }),
          ...(archivo && lenguaje ? [new TextRun({ text: `  ·  ${lenguaje.toUpperCase()}`, font: 'Consolas', size: 16, color: COLOR.gris })] : [])],
      }));
    }
    const lineas = t.text.replace(/\t/g, '    ').split('\n');
    lineas.forEach((linea, i) => this.agregar(new Paragraph({
      border: bordes, shading: { type: ShadingType.CLEAR, color: 'auto', fill: COLOR.fondoCodigo },
      spacing: { before: 0, after: i === lineas.length - 1 ? 160 : 0, line: 228 },
      keepLines: true,
      children: [new TextRun({ text: linea.length ? linea : ' ', font: 'Consolas', size: 15, color: '24313D' })],
    })));
  }

  recuadro(t) {
    const primero = t.tokens.find(x => x.type === 'paragraph');
    const etiqueta = primero?.tokens?.[0]?.type === 'strong' ? sinAcentos(primero.tokens[0].tokens?.[0]?.text ?? primero.tokens[0].text) : '';
    const clave = Object.keys(RECUADROS).find(k => etiqueta.startsWith(k)) ?? 'nota';
    const { borde, fondo } = RECUADROS[clave];
    const bordes = {
      top: { style: BorderStyle.SINGLE, size: 2, color: fondo, space: 4 }, bottom: { style: BorderStyle.SINGLE, size: 2, color: fondo, space: 4 },
      left: { style: BorderStyle.SINGLE, size: 24, color: borde, space: 8 }, right: { style: BorderStyle.SINGLE, size: 2, color: fondo, space: 4 },
    };
    const estilo = { border: bordes, shading: { type: ShadingType.CLEAR, color: 'auto', fill: fondo }, indent: { left: 200, right: 120 } };
    const partes = t.tokens.filter(x => x.type !== 'space');
    partes.forEach((p, i) => {
      const ultimo = i === partes.length - 1;
      if (p.type === 'list') {
        for (const item of p.items) {
          this.agregar(new Paragraph({ ...estilo, spacing: { before: 0, after: 0 }, children: [new TextRun({ text: p.ordered ? '' : '•  ' }), ...runs(item.tokens.flatMap(x => x.tokens ?? [x]))] }));
        }
        if (ultimo) this.agregar(new Paragraph({ spacing: { after: 120 }, children: [] }));
        return;
      }
      if (p.type === 'code') { this.codigo(p); return; }
      for (const grupo of partirPorBr(p.tokens ?? [])) {
        this.agregar(new Paragraph({ ...estilo, spacing: { before: 0, after: ultimo ? 160 : 40 }, children: runs(grupo, { size: 20 }) }));
      }
    });
  }

  imagen(t) {
    const ruta = path.resolve(RAIZ, 'fuente', t.href);
    const png = ruta.replace(/\.svg$/i, '.png');
    const svg = ruta.toLowerCase().endsWith('.svg') ? ruta : null;
    const tamanos = JSON.parse(fs.readFileSync(path.join(RAIZ, 'diagramas', 'tamanos.json'), 'utf8'));
    const dim = tamanos[path.basename(png)] ?? { ancho: 1200, alto: 800 };
    const maxAncho = this.actual.orientacion === 'horizontal' ? 860 : 610;
    const ancho = Math.min(maxAncho, dim.ancho / 2);
    const alto = Math.round(ancho * dim.alto / dim.ancho);
    const datosPng = fs.readFileSync(png);
    const imagen = svg
      ? new ImageRun({ type: 'svg', data: fs.readFileSync(svg), transformation: { width: ancho, height: alto }, fallback: { type: 'png', data: datosPng }, altText: { title: t.text, description: t.text, name: path.basename(svg) } })
      : new ImageRun({ type: 'png', data: datosPng, transformation: { width: ancho, height: alto }, altText: { title: t.text, description: t.text, name: path.basename(png) } });
    this.agregar(
      new Paragraph({ alignment: AlignmentType.CENTER, keepNext: true, spacing: { before: 120, after: 60 }, children: [imagen] }),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { after: 200 }, children: [new TextRun({ text: desescapar(t.text), italics: true, size: 18, color: COLOR.gris })] }),
    );
  }

  gantt(datos) {
    const semanas = datos.semanas;
    const anchoTarea = 3000, anchoResp = 1300;
    const anchoSemana = Math.floor((this.ancho - anchoTarea - anchoResp) / semanas.length);
    const anchos = [anchoTarea, ...semanas.map(() => anchoSemana), anchoResp];
    anchos[anchos.length - 1] += this.ancho - anchos.reduce((a, b) => a + b, 0);
    const bordes = { style: BorderStyle.SINGLE, size: 2, color: 'D7DEE4' };
    const texto = (s, opc = {}) => [new Paragraph({ alignment: opc.centro ? AlignmentType.CENTER : AlignmentType.LEFT, spacing: { before: 0, after: 0, line: 220 }, children: [new TextRun({ text: s, size: opc.tam ?? 14, bold: opc.negrita, color: opc.color })] })];
    const celda = (ancho, hijos, fondo) => new TableCell({ width: { size: ancho, type: WidthType.DXA }, margins: { top: 20, bottom: 20, left: 30, right: 30 }, verticalAlign: VerticalAlign.CENTER, shading: fondo ? { type: ShadingType.CLEAR, color: 'auto', fill: fondo } : undefined, children: hijos });
    const cab = new TableRow({ tableHeader: true, children: [celda(anchoTarea, texto('Iteración / actividad', { negrita: true, tam: 15 }), COLOR.cabeceraTabla), ...semanas.map(s => celda(anchoSemana, texto(s, { centro: true, tam: 12, negrita: true }), COLOR.cabeceraTabla)), celda(anchos.at(-1), texto('Responsable', { negrita: true, tam: 15 }), COLOR.cabeceraTabla)] });
    const filas = datos.filas.map(f => new TableRow({ cantSplit: true, children: [
      celda(anchoTarea, texto(f.tarea, { negrita: f.grupo, tam: 14 })),
      ...semanas.map((_, i) => {
        const s = i + 1;
        const activa = f.inicio && s >= f.inicio && s < f.inicio + f.duracion;
        const hito = f.hito && f.semana === s;
        return celda(anchoSemana, texto(hito ? '◆' : activa && f.marca ? f.marca : '', { centro: true, tam: 13, negrita: true, color: hito ? 'C34747' : 'FFFFFF' }), activa ? (f.color || COLOR.verde) : undefined);
      }),
      celda(anchos.at(-1), texto(f.responsable ?? '', { tam: 13 })),
    ] }));
    this.agregar(new Table({ width: { size: this.ancho, type: WidthType.DXA }, columnWidths: anchos, layout: TableLayoutType.FIXED, borders: { top: bordes, bottom: bordes, left: bordes, right: bordes, insideHorizontal: bordes, insideVertical: bordes }, rows: [cab, ...filas] }), new Paragraph({ spacing: { after: 120 }, children: [] }));
  }

  matrizTrazabilidad() {
    const reqs = leerRequisitos();
    const p = (s) => [{ type: 'text', text: s }];
    const filas = reqs.map(r => [p(r.id), p(r.titulo), p(r.prioridad), p(r.implementacion || '—'), p(r.pruebas.map(x => `${x}-${r.id}`).join(', ') || '—'), p([...new Set(r.pruebas.map(evidenciaPorPrueba))].join('; ') || '—'), p('PENDIENTE DE EJECUCIÓN')]);
    const cab = ['ID', 'Requisito', 'Prioridad', 'Implementación técnica', 'Pruebas', 'Evidencia esperada', 'Estado'].map(p);
    this.agregar(this.tablaDesdeDatos(cab, filas, this.anchosColumnas(cab.map(c => ({ text: c[0].text })), [], this.ancho).map((_, i, a) => Math.floor(this.ancho * [8, 24, 7, 21, 15, 16, 9][i] / 100)), { densa: true, cebra: true }));
  }

  resumenRequisitos() {
    const reqs = leerRequisitos();
    const p = (s) => [{ type: 'text', text: String(s) }];
    const filas = ORDEN_FAMILIAS.map(f => {
      const de = reqs.filter(r => r.familia === f.codigo);
      const n = (pr) => de.filter(r => r.prioridad.startsWith(pr)).length;
      return [p(f.codigo), p(f.nombre), p(de.length), p(n('Debe')), p(n('Debería')), p(n('Podría')), p(n('Futuro'))];
    }).filter(f => f[2][0].text !== '0');
    const total = reqs.length;
    filas.push([p('Total'), p(''), p(total), p(reqs.filter(r => r.prioridad.startsWith('Debe')).length), p(reqs.filter(r => r.prioridad.startsWith('Debería')).length), p(reqs.filter(r => r.prioridad.startsWith('Podría')).length), p(reqs.filter(r => r.prioridad.startsWith('Futuro')).length)]);
    const cab = ['Familia', 'Alcance', 'Total', 'Debe', 'Debería', 'Podría', 'Futuro'].map(p);
    const anchos = [12, 48, 8, 8, 8, 8, 8].map(x => Math.floor(this.ancho * x / 100));
    anchos[6] += this.ancho - anchos.reduce((a, b) => a + b, 0);
    this.agregar(this.tablaDesdeDatos(cab, filas, anchos, { cebra: true }), new Paragraph({ spacing: { after: 120 }, children: [] }));
  }
}

// ---------- Portada, control del documento e indice ----------
function portada(c) {
  const linea = (texto, tam, opc = {}) => new Paragraph({ alignment: AlignmentType.LEFT, spacing: { before: opc.antes ?? 0, after: opc.despues ?? 120 }, children: [new TextRun({ text: texto, size: tam, bold: opc.negrita, color: opc.color ?? COLOR.tinta, allCaps: opc.mayus, characterSpacing: opc.espaciado })] });
  const datos = META.portada.datos.map(([k, v]) => [[{ type: 'text', text: k }], [{ type: 'text', text: v }]]);
  const anchos = [Math.floor(ANCHO_TEXTO.vertical * 0.32), ANCHO_TEXTO.vertical - Math.floor(ANCHO_TEXTO.vertical * 0.32)];
  const filas = datos.map(([k, v]) => new TableRow({ children: [c.celda(k, anchos[0], { negrita: true, fondo: 'F1F5F2', tam: 18 }), c.celda(v, anchos[1], { tam: 18 })] }));
  const bordes = { style: BorderStyle.SINGLE, size: 4, color: COLOR.linea };
  return [
    new Paragraph({ spacing: { before: 1400, after: 0 }, border: { left: { style: BorderStyle.SINGLE, size: 48, color: COLOR.verde, space: 12 } }, children: [new TextRun({ text: META.portada.entidad, size: 20, bold: true, color: COLOR.verde, allCaps: true, characterSpacing: 40 })] }),
    new Paragraph({ spacing: { after: 600 }, border: { left: { style: BorderStyle.SINGLE, size: 48, color: COLOR.verde, space: 12 } }, children: [new TextRun({ text: META.portada.dependencia, size: 20, color: COLOR.gris })] }),
    linea(META.portada.titulo, 56, { negrita: true, color: COLOR.verdeOscuro, despues: 200 }),
    linea(META.portada.subtitulo, 30, { color: COLOR.tinta, despues: 160 }),
    linea(META.portada.piloto, 22, { color: COLOR.azul, despues: 700 }),
    new Table({ width: { size: ANCHO_TEXTO.vertical, type: WidthType.DXA }, columnWidths: anchos, layout: TableLayoutType.FIXED, borders: { top: bordes, bottom: bordes, left: bordes, right: bordes, insideHorizontal: bordes, insideVertical: bordes }, rows: filas }),
    new Paragraph({ spacing: { before: 500, after: 0 }, children: [new TextRun({ text: META.portada.nota, size: 16, italics: true, color: COLOR.gris })] }),
  ];
}

function controlDocumento(c) {
  const titulo = (t) => new Paragraph({ spacing: { before: 240, after: 120 }, keepNext: true, children: [new TextRun({ text: t, bold: true, size: 26, color: COLOR.verdeOscuro })] });
  const tabla = (cab, filas, pct) => {
    const anchos = pct.map(x => Math.floor(ANCHO_TEXTO.vertical * x / 100));
    anchos[anchos.length - 1] += ANCHO_TEXTO.vertical - anchos.reduce((a, b) => a + b, 0);
    const p = (s) => [{ type: 'text', text: s }];
    return c.tablaDesdeDatos(cab.map(p), filas.map(f => f.map(p)), anchos);
  };
  const nota = (t) => new Paragraph({ spacing: { before: 80, after: 160 }, children: [new TextRun({ text: t, size: 18, italics: true, color: COLOR.gris })] });
  return [
    new Paragraph({ pageBreakBefore: true, spacing: { after: 200 }, border: { bottom: { style: BorderStyle.SINGLE, size: 8, color: COLOR.verde, space: 4 } }, children: [new TextRun({ text: 'Control del documento', bold: true, size: 34, color: COLOR.verdeOscuro })] }),
    titulo('Historial de versiones'), tabla(['Versión', 'Fecha', 'Descripción del cambio', 'Elaboró'], META.control.versiones, [11, 14, 55, 20]),
    titulo('Revisión y aprobación'), tabla(['Rol', 'Nombre', 'Firma', 'Fecha'], META.control.aprobaciones, [36, 28, 18, 18]),
    nota(META.control.notaAprobacion),
    titulo('Lista de distribución'), tabla(['Destinatario (rol)', 'Propósito'], META.control.distribucion, [40, 60]),
    titulo('Convenciones de lectura'), ...META.control.convenciones.map(t => new Paragraph({ numbering: { reference: 'vinetas', level: 0 }, spacing: { after: 60 }, children: [new TextRun({ text: t, size: 20 })] })),
  ];
}

function indice() {
  return [
    new Paragraph({ pageBreakBefore: true, spacing: { after: 200 }, border: { bottom: { style: BorderStyle.SINGLE, size: 8, color: COLOR.verde, space: 4 } }, children: [new TextRun({ text: 'Contenido', bold: true, size: 34, color: COLOR.verdeOscuro })] }),
    new TableOfContents('Contenido', { hyperlink: true, headingStyleRange: '1-3' }),
  ];
}

// ---------- Encabezado y pie ----------
const encabezado = () => new Header({ children: [new Paragraph({
  border: { bottom: { style: BorderStyle.SINGLE, size: 4, color: COLOR.linea, space: 4 } },
  children: [new TextRun({ text: META.encabezado.izquierda, size: 16, color: COLOR.gris }), new TextRun({ children: [new PositionalTab({ alignment: PositionalTabAlignment.RIGHT, relativeTo: PositionalTabRelativeTo.MARGIN, leader: PositionalTabLeader.NONE }), META.encabezado.derecha], size: 16, color: COLOR.gris })],
})] });
const pie = () => new Footer({ children: [new Paragraph({
  border: { top: { style: BorderStyle.SINGLE, size: 4, color: COLOR.linea, space: 4 } },
  children: [new TextRun({ text: META.pie.izquierda, size: 16, color: COLOR.gris }), new TextRun({ children: [new PositionalTab({ alignment: PositionalTabAlignment.RIGHT, relativeTo: PositionalTabRelativeTo.MARGIN, leader: PositionalTabLeader.NONE }), 'Página ', PageNumber.CURRENT, ' de ', PageNumber.TOTAL_PAGES], size: 16, color: COLOR.gris })],
})] });

// ---------- Ensamblado ----------
function construir() {
  const c = new Constructor();
  c.agregar(...portada(c), ...controlDocumento(c), ...indice());
  for (const archivo of cargarFuentes()) {
    const tokens = marked.lexer(fs.readFileSync(archivo, 'utf8'));
    c.bloques(tokens);
  }
  const tamano = (o) => ({ width: PAGINA.ancho, height: PAGINA.alto, orientation: o === 'horizontal' ? PageOrientation.LANDSCAPE : PageOrientation.PORTRAIT });
  const margen = { top: PAGINA.margen, right: PAGINA.margen, bottom: PAGINA.margen, left: PAGINA.margen, header: 709, footer: 709 };
  const secciones = c.secciones.filter(s => s.hijos.length).map((s, i) => ({
    properties: { page: { size: tamano(s.orientacion), margin: margen }, titlePage: i === 0 },
    headers: { default: encabezado(), ...(i === 0 ? { first: new Header({ children: [] }) } : {}) },
    footers: { default: pie(), ...(i === 0 ? { first: new Footer({ children: [] }) } : {}) },
    children: s.hijos,
  }));

  const h = (tam, color, antes, despues, extra = {}) => ({ run: { font: 'Calibri', size: tam, bold: true, color }, paragraph: { spacing: { before: antes, after: despues }, keepNext: true, keepLines: true, ...extra } });
  return new Document({
    creator: META.documento.autor, title: META.documento.titulo, description: META.documento.descripcion,
    subject: META.documento.asunto, keywords: META.documento.palabrasClave, lastModifiedBy: META.documento.autor,
    styles: {
      default: {
        document: { run: { font: 'Calibri', size: 21, color: COLOR.tinta }, paragraph: { spacing: { after: 120, line: 264 } } },
        title: { run: { font: 'Calibri', size: 56, bold: true, color: COLOR.verdeOscuro }, paragraph: { spacing: { before: 0, after: 200 } } },
        heading1: h(34, COLOR.verdeOscuro, 0, 240, { border: { bottom: { style: BorderStyle.SINGLE, size: 8, color: COLOR.verde, space: 4 } } }),
        heading2: h(28, COLOR.verde, 360, 140),
        heading3: h(24, COLOR.tinta, 260, 100),
        heading4: h(22, COLOR.azul, 200, 80),
        heading5: h(21, COLOR.gris, 160, 60),
        hyperlink: { run: { color: '1D6FA5', underline: {} } },
      },
    },
    numbering: { config: [
      { reference: 'vinetas', levels: ['•', '◦', '▪'].map((s, n) => ({ level: n, format: LevelFormat.BULLET, text: s, alignment: AlignmentType.LEFT, style: { paragraph: { indent: { left: 360 * (n + 1), hanging: 240 } } } })) },
      { reference: 'numeros', levels: [[LevelFormat.DECIMAL, '%1.'], [LevelFormat.LOWER_LETTER, '%2)'], [LevelFormat.LOWER_ROMAN, '%3.']].map(([f, s], n) => ({ level: n, format: f, text: s, alignment: AlignmentType.LEFT, style: { paragraph: { indent: { left: 360 * (n + 1) + 60, hanging: 300 } } } })) },
    ] },
    sections: secciones,
  });
}

const doc = construir();
fs.writeFileSync(SALIDA, await Packer.toBuffer(doc));
console.log(`Documento generado: ${SALIDA}`);
