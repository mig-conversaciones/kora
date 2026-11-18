import { validateRecordShape } from './schemas.js';
export const collections = ['units','budgets','receipts','lots','sales','processes','animals','products','expenses','transformations'];
export const clone = value => JSON.parse(JSON.stringify(value));
export const active = rows => rows.filter(r => !r.archived);
const demoOrigin = { file: 'Escenario demostrativo', sheet: 'Simulación', row: null };
export function makeDemo(source) {
  const d = clone(source); d.scenario = 'demo';
  d.receipts = []; d.sales = []; d.lots = []; d.expenses = []; d.transformations = [];
  const milk = d.products.find(p => p.id === '5013170101');
  if (milk) { milk.price = 2200; milk.unit = 'L'; }
  const derivatives = [
    ['DEMO-QUESO','Queso cuajada','kg',8,8.5,19000],
    ['DEMO-YOGURT','Yogurt','L',1,1.05,8500],
    ['DEMO-MANJAR','Manjar blanco','kg',3.4,3.6,18000],
    ['DEMO-CORTADO','Cortado','kg',4,4.2,14000],
  ];
  d.products.push(...derivatives.map(([id,name,unit,milkFactor,yieldTarget,price]) => ({ id,name,unit,milkFactor,yieldTarget,price,archived:false,origin:demoOrigin })));
  const factors = { GAN: 1, GRA: .56, AGR: .8, GOU: .42, CAF: .65, INV: .16, HOR: .12, LAM: .07, API: .09 };
  for (const b of d.budgets) {
    for (let i=1;i<=4;i++) d.receipts.push({ id:`DREC-${b.unit}-${i}`,unit:b.unit,date:`2026-04-${String(i*6).padStart(2,'0')}`,product:b.unit==='GAN'?'Leche':'Producción de la unidad',amount:Math.round(b.amount * .036 * factors[b.unit]),gateway:i%2?'Wompi':'Ecollect',invoice:`DEMO-${b.unit}-${i}`,reference:`SIM-${b.unit}-${i}`,archived:false,origin:demoOrigin });
  }
  for (let i=1;i<=4;i++) {
    const date=`2026-04-${String(i*5).padStart(2,'0')}`;
    const id=`DLOT-LECHE-${i}`, code=`GAN-REC-DEMO-00${i}`;
    d.lots.push({id,code,unit:'GAN',date,product:'5013170101',name:'Leche de vaca',quantity:4000,measure:'L',price:2200,receiver:'Responsable Ganadería',archived:false,origin:demoOrigin});
    d.sales.push({id:`DSAL-A-${i}`,lot:id,lotCode:code,unit:'GAN',date,product:'5013170101',name:'Leche de vaca',quantity:800,measure:'L',price:1800,amount:1440000,method:'AUTOCONSUMO',process:'AUTO_001',destination:'Agroindustria',invoice:'',reference:`DEMO-A-${i}`,archived:false,origin:demoOrigin});
    d.sales.push({id:`DSAL-T-${i}`,lot:id,lotCode:code,unit:'GAN',date:`2026-04-${String(i*5+1).padStart(2,'0')}`,product:'5013170101',name:'Leche de vaca',quantity:3200,measure:'L',price:2200,amount:7040000,method:'TRANSFERENCIA',process:'',destination:'Venta externa',invoice:`DEMO-T-${i}`,reference:`DEMO-T-${i}`,archived:false,origin:demoOrigin});
    const [pid,pname,measure,milkFactor,,price]=derivatives[i-1];
    const quantity=800 * .97 / milkFactor;
    const output=`DLOT-DERIVADO-${i}`, outputDate=`2026-04-${String(i*5+1).padStart(2,'0')}`;
    d.lots.push({id:output,code:`IND-REC-DEMO-00${i}`,unit:'IND',date:outputDate,product:pid,name:pname,quantity,measure,price,receiver:'Taller de lácteos',archived:false,origin:demoOrigin});
    d.transformations.push({id:`TRA-${i}`,unit:'GAN',date:outputDate,inputSale:`DSAL-A-${i}`,outputLot:output,liters:800,archived:false,origin:demoOrigin});
    d.sales.push({id:`DSAL-D-${i}`,lot:output,lotCode:`IND-REC-DEMO-00${i}`,unit:'IND',date:`2026-04-${String(i*5+5).padStart(2,'0')}`,product:pid,name:pname,quantity,measure,price,amount:Math.round(quantity*price),method:'TRANSFERENCIA',process:'',destination:'Venta de derivados',invoice:`DEMO-D-${i}`,reference:`SIM-D-${i}`,archived:false,origin:demoOrigin});
  }
  const bId='DLOT-BOV';
  const bovine=d.products.find(p=>p.name.toLowerCase().includes('bovino'));
  const product=bovine?.id || 'DEMO-BOV';
  if(!bovine)d.products.push({id:product,name:'Bovino en pie',unit:'kg',price:8000,milkFactor:0,yieldTarget:0,archived:false,origin:demoOrigin});
  d.lots.push({id:bId,code:'GAN-REC-DEMO-BOV',unit:'GAN',date:'2026-04-01',product,name:'Bovino en pie',quantity:650,measure:'kg',price:8000,receiver:'Responsable Ganadería',archived:false,origin:demoOrigin});
  d.sales.push({id:'DSAL-BOV',lot:bId,lotCode:'GAN-REC-DEMO-BOV',unit:'GAN',date:'2026-04-19',product,name:'Bovino en pie',quantity:650,measure:'kg',price:8000,amount:5200000,method:'AUTOCONSUMO',process:'AUTO_002',destination:'Agroindustria',invoice:'',reference:'SIM-BOV',archived:false,origin:demoOrigin});
  d.expenses.push({id:'GAS-001',unit:'GAN',date:'2026-04-30',category:'Alimentación y vacunas',amount:18000000,academicAmount:2200000,description:'Gasto mensual simulado',archived:false,origin:demoOrigin},{id:'GAS-002',unit:'GAN',date:'2026-04-30',category:'Mantenimiento',amount:6400000,academicAmount:800000,description:'Gasto mensual simulado',archived:false,origin:demoOrigin});
  d.meta = {...d.meta, demoPeriod:'Abril de 2026', preparedAt:'2026-11-16'};
  return d;
}
export function createStorage(provider){
  try{
    const storage=provider(),key='cab-storage-probe';storage.setItem(key,'1');storage.removeItem(key);
    return {storage,persistent:true};
  }catch{
    const rows=new Map();
    return {storage:{getItem:key=>rows.get(key)??null,setItem:(key,value)=>rows.set(key,String(value)),removeItem:key=>rows.delete(key)},persistent:false};
  }
}
export class Repository {
  constructor(storage, seeds) { this.storage=storage; this.seeds=seeds; }
  key(scenario) { return `cab-produccion-v1-${scenario}`; }
  load(scenario) {
    const stored=this.storage.getItem(this.key(scenario));
    if(!stored)return clone(this.seeds[scenario]);
    const data=JSON.parse(stored); validateBackup(data,scenario);return data;
  }
  save(data) { validateBackup(data,data.scenario);this.storage.setItem(this.key(data.scenario),JSON.stringify(data)); }
  reset(scenario) { this.storage.removeItem(this.key(scenario));return clone(this.seeds[scenario]); }
}
export function validateBackup(d,scenario) {
  if(!d || d.schemaVersion!==1 || !['source','demo'].includes(d.scenario) || d.scenario!==scenario)throw Error('El respaldo no pertenece al escenario activo o su versión no es compatible.');
  if(!d.meta || typeof d.meta.herdCutoff!=='string' || !d.parameters || !Number.isFinite(d.parameters.productiveTarget))throw Error('Faltan metadatos o parámetros del respaldo.');
  for(const name of collections){
    if(!Array.isArray(d[name]) || d[name].length>10000)throw Error(`La colección ${name} no es válida.`);
    const ids=new Set();
    for(const row of d[name]){
      if(!row || typeof row.id!=='string' || !row.id || ids.has(row.id))throw Error(`Identificadores vacíos o repetidos en ${name}.`);
      ids.add(row.id);
      if(typeof row.archived!=='boolean')throw Error(`Estado inválido en ${name}.`);
      for(const [key,v] of Object.entries(row)){
        if(['__proto__','constructor','prototype'].includes(key))throw Error('El respaldo contiene campos no permitidos.');
        if(typeof v==='number' && (!Number.isFinite(v) || v<0))throw Error(`Valor numérico inválido en ${name}.`);
      }
    }
  }
  validateRecordShape(d);
  return true;
}
