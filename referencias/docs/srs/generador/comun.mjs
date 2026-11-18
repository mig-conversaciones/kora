// Utilidades compartidas por el generador y el verificador del SRS.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { marked } from 'marked';

export const RAIZ = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
export const FUENTE = path.join(RAIZ, 'fuente');

// Patron de identificadores de requisito. Las familias compuestas van primero para que la
// alternancia no capture "RF" dentro de "RF-Q-001".
export const PATRON_ID = /^(RF-Q|RF-MAN|RNF-PERF|RF|RNF|RI|RS|RAC|RAU|RDI|RDOC|RUI|RBD|RDL|ROB|RGO)-(\d{3})$/;

export const ORDEN_FAMILIAS = [
  { codigo: 'RF', nombre: 'Requisitos funcionales de negocio' },
  { codigo: 'RF-Q', nombre: 'Procesamiento asíncrono y colas' },
  { codigo: 'RF-MAN', nombre: 'Modo mantenimiento administrable' },
  { codigo: 'RNF', nombre: 'No funcionales: usabilidad, confiabilidad, mantenibilidad y portabilidad' },
  { codigo: 'RNF-PERF', nombre: 'Rendimiento, capacidad, SQL, caché y pruebas de carga' },
  { codigo: 'RI', nombre: 'Interfaces de usuario, software y comunicaciones' },
  { codigo: 'RUI', nombre: 'Sistema visual, favicon, estados de carga y skeleton' },
  { codigo: 'RS', nombre: 'Seguridad detallada' },
  { codigo: 'RAC', nombre: 'Autenticación, autorización, sesiones, 2FA y recuperación' },
  { codigo: 'RAU', nombre: 'Auditoría funcional, registro técnico y trazabilidad' },
  { codigo: 'RDI', nombre: 'Aislamiento de datos y políticas a nivel de fila' },
  { codigo: 'RDOC', nombre: 'Documentación de código, procesos y evidencia IA' },
  { codigo: 'RBD', nombre: 'Esquema de base de datos MariaDB/MySQL' },
  { codigo: 'RDL', nombre: 'Ciclo de vida de datos, migraciones y retención' },
  { codigo: 'ROB', nombre: 'Operaciones, despliegue, rollback y feature flags' },
  { codigo: 'RGO', nombre: 'Gobernanza: ADR, DoR/DoD y runbooks' },
];

const EVIDENCIA = {
  PU: 'Reporte PHPUnit unitario y cobertura',
  PI: 'Reporte PHPUnit de integración sobre MariaDB',
  PE: 'Reporte Playwright (HTML, trazas y capturas)',
  PC: 'Reporte de pruebas de contrato',
  PCA: 'Informe k6 versionado (tests/carga/resultados)',
  PS: 'Informe de seguridad (SAST, DAST o revisión)',
  PA: 'Informe de accesibilidad (axe y revisión manual)',
  PR: 'Acta de revisión documental',
  PO: 'Registro del ejercicio operativo',
};
export const evidenciaPorPrueba = (tipo) => EVIDENCIA[tipo] ?? 'Por definir';
export const TIPOS_PRUEBA = Object.keys(EVIDENCIA);

export function cargarFuentes() {
  return fs.readdirSync(FUENTE).filter(f => /^\d{2}-.*\.md$/.test(f)).sort().map(f => path.join(FUENTE, f));
}

const limpiar = (t = '') => t.replace(/`/g, '').replace(/\*\*/g, '').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'").trim();

let cache = null;
export function leerRequisitos() {
  if (cache) return cache;
  const lista = [];
  for (const archivo of cargarFuentes()) {
    const tokens = marked.lexer(fs.readFileSync(archivo, 'utf8'));
    const recorrer = (ts) => {
      for (const t of ts) {
        if (t.type === 'table' && limpiar(t.header[0]?.text) === 'ID') {
          for (const fila of t.rows) {
            const id = limpiar(fila[0]?.text);
            const m = id.match(PATRON_ID);
            if (!m) continue;
            const descripcion = fila[1]?.text ?? '';
            const titulo = limpiar((descripcion.match(/\*\*(.+?)\*\*/) || [, descripcion.slice(0, 80)])[1]).replace(/\.$/, '');
            const verif = fila[3]?.text ?? '';
            const impl = limpiar((verif.match(/Impl\.?:\s*(.+?)(?:<br>|$)/i) || [, ''])[1]);
            const pruebas = ((verif.match(/Verif\.?:\s*([A-Z, ]+)/) || [, ''])[1]).split(',').map(s => s.trim()).filter(Boolean);
            lista.push({ id, familia: m[1], titulo, prioridad: limpiar(fila[2]?.text), implementacion: impl, pruebas, archivo: path.basename(archivo) });
          }
        }
        if (t.tokens && t.type !== 'table') recorrer(t.tokens);
      }
    };
    recorrer(tokens);
  }
  cache = lista;
  return lista;
}
