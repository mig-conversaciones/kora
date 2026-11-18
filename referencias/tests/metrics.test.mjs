import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { clone, makeDemo, Repository, validateBackup } from '../js/data.js';
import { centralMetrics, processMetrics, herdMetrics, rotationMetrics, transformationMetrics, costMetrics, qualityMetrics, reproductiveStatus } from '../js/metrics.js';
import { prepareRecord, archiveBlocker, canEdit, roleViews, preferredTab, moduleEditable } from '../js/schemas.js';
const source=JSON.parse(readFileSync(new URL('../data/source.json',import.meta.url)));
const gan={year:'2026',unit:'GAN'};
test('La extracción concilia presupuesto, recaudos, hato y trazabilidad con los Excel',()=>{
  assert.equal(centralMetrics(source,{year:'2026'}).budget,1024000000);
  const m=centralMetrics(source,gan);assert.equal(m.budget,185000000);assert.equal(m.direct,3265000);assert.equal(m.receipts.length,61);assert.equal(m.internal,176000);
  assert.equal(source.lots.length,6);assert.equal(source.sales.length,146);assert.equal(source.products.length,190);
  assert.equal(qualityMetrics(source).unlinked,145);assert.equal(qualityMetrics(source).pendingProcesses,17);
});
test('El presupuesto permanece anual al cambiar fechas; el recaudo sí se filtra',()=>{
  const m=centralMetrics(source,{...gan,from:'2026-01-01',to:'2026-01-31'});
  assert.equal(m.budget,185000000);assert.equal(m.direct,0);assert.equal(m.internal,176000);
});
test('Los canales son excluyentes y no se duplican recaudos con transferencias',()=>{
  const a=centralMetrics(source,{...gan,channel:'AUTOCONSUMO'}),t=centralMetrics(source,{...gan,channel:'TRANSFERENCIA'}),all=centralMetrics(source,gan);
  assert.equal(a.direct,0);assert.equal(t.internal,0);assert.equal(all.total,a.total+t.total);assert.equal(all.total,3441000);
});
test('El producto Leche se homologa de manera explícita al catálogo',()=>{
  assert.equal(centralMetrics(source,{...gan,product:'5013170101'}).direct,3045000);
  assert.equal(centralMetrics(source,{...gan,product:'NO-EXISTE'}).direct,0);
});
test('Los autoconsumos sin proceso no se asignan artificialmente a las metas',()=>{
  const p=source.processes.find(p=>p.id==='AUTO_001');assert.equal(processMetrics(source,p,gan).value,0);
  const d=makeDemo(source),m=processMetrics(d,d.processes.find(p=>p.id==='AUTO_001'),gan);
  assert.equal(m.delivered,3200);assert.equal(m.value,5760000);assert.equal(m.quantityProgress,80);
});
test('Una entrega con medida incompatible no suma ejecución física ni monetaria de un proceso',()=>{
  const d=makeDemo(source);d.sales.find(s=>s.id==='DSAL-A-1').measure='kg';
  assert.equal(processMetrics(d,d.processes[0],gan).delivered,2400);
});
test('El interruptor de práctica cambia el denominador y conserva la referencia completa',()=>{
  const commercial=herdMetrics(source),full=herdMetrics(source,true);
  assert.equal(commercial.all.length,35);assert.equal(commercial.animals.length,31);assert.equal(commercial.production,21);assert.equal(commercial.practice,4);assert.equal(commercial.rest,10);
  assert.equal(full.rate,60);assert.equal(commercial.fullRate,60);assert.ok(Math.abs(commercial.rate-67.741935)<.00001);
});
test('Semáforos respetan límites, datos faltantes y periodo sin umbral definido',()=>{
  assert.equal(reproductiveStatus(null,'iep').label,'Sin dato');assert.equal(reproductiveStatus(365,'iep').tone,'green');assert.equal(reproductiveStatus(366,'iep').tone,'amber');assert.equal(reproductiveStatus(401,'iep').tone,'red');
  assert.equal(reproductiveStatus(90,'open').tone,'neutral');assert.equal(reproductiveStatus(91,'open').tone,'green');assert.equal(reproductiveStatus(120,'open').tone,'green');assert.equal(reproductiveStatus(121,'open').tone,'amber');assert.equal(reproductiveStatus(135,'open').tone,'amber');assert.equal(reproductiveStatus(136,'open').tone,'red');
});
test('El aprovechamiento usa equivalentes por derivado y requiere vínculos verificables',()=>{
  assert.equal(transformationMetrics(source,gan).global,null);
  const d=makeDemo(source),m=transformationMetrics(d,gan);assert.equal(m.rows.length,4);assert.equal(m.global,97);
  d.products.find(p=>p.id==='DEMO-QUESO').milkFactor=0;assert.equal(transformationMetrics(d,gan).rows.length,3);
});
test('IND_17 usa gasto ejecutado menos componente académico y litros recibidos',()=>{
  assert.equal(costMetrics(source,gan).cost,null);
  const d=makeDemo(source),m=costMetrics(d,gan);assert.equal(m.liters,16000);assert.equal(m.gross,24400000);assert.equal(m.academic,3000000);assert.equal(m.cost,1337.5);assert.equal(m.target,1650);
  d.processes[0].quantity=100000;assert.equal(costMetrics(d,gan).cost,1337.5);
  assert.equal(costMetrics(d,{...gan,from:'2026-05-01'}).cost,null);
});
test('Rotación cierra únicamente al agotar la cantidad del lote y producto',()=>{
  const d=makeDemo(source),rows=rotationMetrics(d,gan,'2026-04-30');const milk=rows.find(r=>r.lot.id==='DLOT-LECHE-1');assert.equal(milk.days,1);assert.ok(milk.closed);
  d.sales.find(s=>s.id==='DSAL-T-1').quantity=3000;const open=rotationMetrics(d,gan,'2026-04-30').find(r=>r.lot.id===milk.lot.id);assert.equal(open.closed,'');assert.equal(open.remaining,200);assert.equal(open.days,25);
});
test('Una salida parcial anterior al filtro no se pierde al calcular el cierre',()=>{
  const d=makeDemo(source);d.lots[0].date='2026-04-06';d.sales.find(s=>s.id==='DSAL-A-1').date='2026-04-05';
  const r=rotationMetrics(d,{...gan,from:'2026-04-06'},'2026-04-30').find(r=>r.lot.id==='DLOT-LECHE-1');assert.equal(r.sold,4000);assert.ok(r.closed);
});
test('Sin presupuesto ni población no se generan porcentajes infinitos',()=>{
  const d=clone(source);d.animals=[];d.budgets=[];assert.equal(herdMetrics(d).rate,null);assert.equal(centralMetrics(d,gan).coverage,null);
});
test('Los escenarios se guardan y restauran de manera independiente',()=>{
  const storage=new Map();storage.getItem=storage.get.bind(storage);storage.setItem=storage.set.bind(storage);storage.removeItem=storage.delete.bind(storage);
  const repo=new Repository(storage,{source,demo:makeDemo(source)}),s=repo.load('source');s.animals[0].reproductive='VACIA';repo.save(s);assert.equal(repo.load('source').animals[0].reproductive,'VACIA');assert.equal(repo.load('demo').animals[0].reproductive,'PREÑADA');
  repo.reset('source');assert.equal(repo.load('source').animals[0].reproductive,'PREÑADA');
  const d=repo.load('demo');repo.save(d);assert.equal(repo.load('demo').sales.length,d.sales.length);
});
test('Respaldos incompatibles, duplicados o con números negativos se rechazan',()=>{
  assert.equal(validateBackup(source,'source'),true);assert.throws(()=>validateBackup(makeDemo(source),'source'));
  const d=clone(source);d.animals.push(d.animals[0]);assert.throws(()=>validateBackup(d,'source'));
  const n=clone(source);n.budgets[0].amount=-1;assert.throws(()=>validateBackup(n,'source'));
  const date=clone(source);date.receipts[0].date='2026-02-31';assert.throws(()=>validateBackup(date,'source'),/Fecha/);
  const malformed=clone(source);malformed.animals[0].reproductive={value:'VACIA'};assert.throws(()=>validateBackup(malformed,'source'),/Texto/);
  const params=clone(source);params.parameters.milkPrice=0;assert.throws(()=>validateBackup(params,'source'),/precio/);
});
test('Registrar una salida respeta existencias, fechas, canal y proceso',()=>{
  const d=makeDemo(source),sale=d.sales.find(s=>s.id==='DSAL-A-1');assert.equal(prepareRecord('sales',{...sale},d,sale).amount,1440000);
  assert.throws(()=>prepareRecord('sales',{...sale,quantity:900},d,sale),/saldo/);
  assert.throws(()=>prepareRecord('sales',{...sale,date:'2026-01-01'},d,sale),/anterior/);
  assert.throws(()=>prepareRecord('sales',{...sale,process:'AUTO_002'},d,sale),/medida/);
  assert.throws(()=>prepareRecord('sales',{...sale,process:''},d,sale),/proceso/);
});
test('No se puede duplicar la asignación de leche a transformaciones',()=>{
  const d=makeDemo(source),row=d.transformations[0];assert.equal(prepareRecord('transformations',{...row},d,row).liters,800);
  assert.throws(()=>prepareRecord('transformations',{...row,liters:801},d,row),/superan/);
  assert.throws(()=>prepareRecord('transformations',{...row,id:'NEW'},d),/vinculado/);
});
test('El presupuesto y el costo académico tienen validaciones independientes',()=>{
  const d=makeDemo(source),b=d.budgets[0],e=d.expenses[0];assert.throws(()=>prepareRecord('budgets',{...b,maintenance:b.amount+1},d,b),/supera/);assert.throws(()=>prepareRecord('expenses',{...e,academicAmount:e.amount+1},d,e),/superar/);
});
test('Archivar entidades con dependencias activas está bloqueado',()=>{
  const d=makeDemo(source);assert.ok(archiveBlocker('lots',d.lots[0],d));assert.ok(archiveBlocker('processes',d.processes[0],d));assert.ok(archiveBlocker('units',d.units.find(u=>u.id==='GAN'),d));assert.equal(archiveBlocker('animals',d.animals[0],d),'');
});
test('Los perfiles cambian los permisos de demostración por módulo',()=>{
  assert.equal(canEdit('admin','parameters'),true);assert.equal(canEdit('academic','animals'),true);assert.equal(canEdit('academic','budgets'),false);assert.equal(canEdit('leader','lots'),true);assert.equal(canEdit('leader','receipts'),false);
});
test('El espacio de cada rol abre su tablero y su población de trabajo',()=>{
  assert.equal(roleViews.admin.home,'central');assert.equal(roleViews.leader.home,'technical');assert.equal(roleViews.academic.home,'technical');
  assert.equal(herdMetrics(source,roleViews.leader.practice).animals.length,31);
  assert.equal(herdMetrics(source,roleViews.academic.practice).animals.length,35);
  assert.equal(roleViews.academic.health,'practice');assert.equal(roleViews.leader.health,'alerts');
});
test('Los accesos de gestión abren la pestaña editable y distinguen consulta',()=>{
  assert.equal(preferredTab('admin','budget'),'budgets');assert.equal(preferredTab('leader','budget'),'expenses');assert.equal(preferredTab('academic','sales'),'transformations');
  assert.equal(moduleEditable('academic','budget'),false);assert.equal(moduleEditable('leader','receipts'),false);
  for(const [profile,view] of Object.entries(roleViews))for(const [route,tab] of view.actions)if(tab)assert.equal(canEdit(profile,tab),true,`${profile}: ${route}/${tab}`);
});
test('Editar un lote, proceso o entrega no invalida sus movimientos asociados',()=>{
  const d=makeDemo(source),lot=d.lots[0],process=d.processes[0],sale=d.sales.find(s=>s.id==='DSAL-A-1');
  assert.throws(()=>prepareRecord('lots',{...lot,quantity:1000},d,lot),/menor/);
  assert.throws(()=>prepareRecord('lots',{...lot,date:'2026-05-01'},d,lot),/posterior/);
  assert.throws(()=>prepareRecord('processes',{...process,unit:'GRA'},d,process),/Conserva/);
  assert.throws(()=>prepareRecord('sales',{...sale,quantity:700},d,sale),/asignada/);
});
