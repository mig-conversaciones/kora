import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
const raw = JSON.parse(execFileSync('powershell', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/read-workbooks.ps1'], { encoding: 'utf8', maxBuffer: 8e6 }).replace(/^\uFEFF/, ''));
const admin = 'BBDD_DATOS ADMON.xlsx', production = 'BBDD_Produccion de Centro.xlsx', herd = 'INFO_Hato.xlsx';
const clean = v => String(v ?? '').trim();
const number = v => v === undefined || v === '' || !Number.isFinite(Number(v)) ? null : Number(v);
const date = v => number(v) === null ? '' : new Date(Date.UTC(1899, 11, 30) + Number(v) * 864e5).toISOString().slice(0, 10);
const origin = (file, sheet, row) => ({ file, sheet, row });
const key = v => clean(v).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/^up_/, '').replace(/\s+/g, ' ');
const unitId = v => ({ 'ganaderia': 'GAN', 'granja': 'GRA', 'agricola': 'AGR', 'agroindustria': 'IND', 'gourmet - cafe': 'GOU', 'invernadero': 'INV', 'hortalizas': 'HOR', 'cafeteria aprendices': 'CAF', 'lamfa': 'LAM', 'apicultura': 'API', 'comercio': 'COM' })[key(v)] || 'SIN';
const unit = v => ({ litro: 'L', litros: 'L', kilogramo: 'kg', kilos: 'kg', libra: 'lb', 'cubeta 30u': 'cubeta', unidad: 'ud', unidades: 'ud' })[key(v)] || clean(v);
const rows = (file, sheet) => raw[file][sheet];
const d = {
  schemaVersion: 1, scenario: 'source',
  meta: { center: 'Centro Agropecuario de Buga', code: '9124', herdCutoff: '2026-11-09', preparedAt: '2026-11-16', sourceFiles: [admin, production, herd, 'Guia_Tableros_Mando.docx'] },
  units: [], budgets: [], receipts: [], lots: [], sales: [], processes: [], animals: [], products: [], expenses: [], transformations: [],
  parameters: { productiveTarget: 75, milkPrice: 2200, costTarget: 75, freshDays: 15, animalDays: 30 },
};
for (const r of rows(admin, 'Ingresos - Gastos').filter(r => /^UP_/.test(r.A))) {
  const id = unitId(r.A); d.units.push({ id, name: clean(r.A).replace('UP_', '').replace('Ganaderia', 'Ganadería').replace('Agricola', 'Agrícola'), archived: false, origin: origin(admin, 'Ingresos - Gastos', r.row) });
  d.budgets.push({ id: `PRE-${id}`, unit: id, year: 2026, amount: number(r.H) || 0, maintenance: number(r.I) || 0, feed: number(r.J) || 0, academic: number(r.K) || 0, archived: false, origin: origin(admin, 'Ingresos - Gastos', r.row) });
}
for (const [id, name] of [['IND','Agroindustria'],['COM','Comercio'],['SIN','Sin clasificar']]) if (!d.units.some(u => u.id === id)) d.units.push({ id, name, archived: false });
for (const r of rows(production, 'CatalogoProductos_CAB').filter(r => r.B && r.row > 2)) d.products.push({ id: clean(r.B), name: clean(r.A), unit: unit(r.G), price: number(r.F) || 0, milkFactor: 0, yieldTarget: 0, archived: false, origin: origin(production, 'CatalogoProductos_CAB', r.row) });
for (const r of rows(admin, 'Ingresos_Centro Agropecuario de').filter(r => key(r.M) === 'produccion')) d.receipts.push({ id: `REC-${r.row}`, unit: unitId(r.K), sourceUnit: clean(r.K), date: date(r.I), product: clean(r.J), amount: number(r.G) || 0, gateway: clean(r.N), invoice: clean(r.P), reference: clean(r.H), archived: false, origin: origin(admin, 'Ingresos_Centro Agropecuario de', r.row) });
for (const r of rows(admin, 'Autoconsumo_2026').filter(r => /^AUTO_\d/.test(r.A))) d.processes.push({ id: clean(r.A), unit: unitId(r.B), destination: clean(r.C), product: clean(r.D), quantity: number(r.E), measure: unit(r.F), amount: number(r.G), year: 2026, archived: false, origin: origin(admin, 'Autoconsumo_2026', r.row) });
for (const r of rows(production, 'Base Produccion Centro').filter(r => r.A && r.row > 1)) d.lots.push({ id: `LOT-${r.row}`, code: clean(r.A), unit: unitId(r.C), date: date(r.B), product: clean(r.E), name: clean(r.D), quantity: number(r.F), measure: unit(r.G), price: number(r.H), receiver: clean(r.I), archived: false, origin: origin(production, 'Base Produccion Centro', r.row) });
for (const r of rows(production, 'Base Ventas').filter(r => r.A && r.row > 1)) {
  const matches = d.lots.filter(l => l.code === clean(r.A) && (!r.D || l.product === clean(r.D)));
  const lot = matches.length === 1 ? matches[0] : null;
  d.sales.push({ id: `SAL-${r.row}`, lot: lot?.id || '', lotCode: clean(r.A), unit: clean(r.A).slice(0,3), date: date(r.C), product: clean(r.D) || lot?.product || '', name: clean(r.E) || lot?.name || 'Producto sin identificar', quantity: number(r.G), measure: unit(r.F), price: number(r.H), amount: number(r.I), method: key(r.J) === 'autoconsumo' ? 'AUTOCONSUMO' : 'TRANSFERENCIA', process: /^AUTO_\d+$/.test(clean(r.K)) ? clean(r.K) : '', destination: clean(r.M), invoice: clean(r.B), reference: clean(r.L), archived: false, origin: origin(production, 'Base Ventas', r.row) });
}
for (const r of rows(herd, 'ESTADO REP').filter(r => /VACA|PRACTICA/i.test(r.B || '') && r.A)) d.animals.push({ id: clean(r.A), group: /PRACTICA/i.test(r.B) ? 'PRACTICA' : /HORRA/i.test(r.B) ? 'HORRA' : 'PRODUCCION', birth: date(r.C), previousCalving: date(r.H), lastCalving: date(r.I), iep: number(r.J), services: number(r.K) || 0, lastService: date(r.L), palpation: date(r.M), reproductive: clean(r.N), gestation: clean(r.O), openDays: number(r.P), archived: false, origin: origin(herd, 'ESTADO REP', r.row) });
mkdirSync('data', { recursive: true });
writeFileSync('data/source.json', JSON.stringify(d, null, 2) + '\n');
console.log(JSON.stringify({ budgets: d.budgets.length, receipts: d.receipts.length, lots: d.lots.length, sales: d.sales.length, animals: d.animals.length, products: d.products.length, budget: d.budgets.reduce((s,r)=>s+r.amount,0), ganReceipts: d.receipts.filter(r=>r.unit==='GAN').reduce((s,r)=>s+r.amount,0), unlinkedSales: d.sales.filter(r=>!r.lot).length }, null, 2));
