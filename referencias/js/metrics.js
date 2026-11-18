import { active } from './data.js';
export const sum = (rows, field) => rows.reduce((total,r)=>total+(Number(r[field])||0),0);
export const percent = (value,total) => total>0 ? value/total*100 : null;
export const normalize = value => String(value??'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim();
export function inPeriod(row, filters={}) { return Boolean(row.date) && (!filters.year || row.date.slice(0,4)===String(filters.year)) && (!filters.from || row.date>=filters.from) && (!filters.to || row.date<=filters.to); }
export function matchesProduct(row,product,d) {
  if(!product)return true;
  if(product.startsWith('raw:'))return normalize(row.name||row.product)===normalize(product.slice(4));
  const target=d.products.find(p=>p.id===product);
  if(!target)return false;
  if(row.product===product)return true;
  const name=normalize(row.name || row.product);
  if(target.id==='5013170101')return ['leche','leche de vaca'].includes(name);
  return name===normalize(target.name);
}
export function filtered(rows,f={},d) { return active(rows).filter(r=>(!f.unit || r.unit===f.unit) && inPeriod(r,f) && (!f.product || matchesProduct(r,f.product,d))); }
export function centralMetrics(d,f={}) {
  const budgets=active(d.budgets).filter(b=>(!f.unit || b.unit===f.unit) && (!f.year || b.year===Number(f.year)));
  const receipts=f.channel==='AUTOCONSUMO'?[]:filtered(d.receipts,f,d);
  const sales=filtered(d.sales,f,d);
  const autos=f.channel==='TRANSFERENCIA'?[]:sales.filter(s=>s.method==='AUTOCONSUMO');
  const budget=sum(budgets,'amount'),direct=sum(receipts,'amount'),internal=sum(autos,'amount');
  return {budget,direct,internal,total:direct+internal,coverage:percent(direct+internal,budget),remaining:budget-direct-internal,receipts,autos,unlinked:autos.filter(s=>!s.process),transfer:sum(sales.filter(s=>s.method==='TRANSFERENCIA'),'amount'),budgets};
}
export function processMetrics(d,p,f={}) {
  const entries=f.channel==='TRANSFERENCIA'?[]:filtered(d.sales,f,d).filter(s=>s.method==='AUTOCONSUMO' && s.process===p.id && s.unit===p.unit && normalize(s.measure)===normalize(p.measure));
  const delivered=sum(entries,'quantity'),value=sum(entries,'amount');
  return {entries,delivered,value,quantityProgress:percent(delivered,p.quantity),moneyProgress:percent(value,p.amount),quantityRemaining:p.quantity-delivered,valueRemaining:p.amount-value};
}
export function herdMetrics(d,includePractice=false) {
  const all=active(d.animals),animals=includePractice?all:all.filter(a=>a.group!=='PRACTICA');
  const production=all.filter(a=>a.group==='PRODUCCION').length,rest=all.filter(a=>a.group==='HORRA').length,practice=all.filter(a=>a.group==='PRACTICA').length;
  return {all,animals,production,rest,practice,rate:percent(production,animals.length),fullRate:percent(production,all.length),pregnancy:percent(animals.filter(a=>normalize(a.reproductive)==='prenada').length,animals.length)};
}
export function reproductiveStatus(value,type) {
  if(value===null || value===undefined || value==='')return {label:'Sin dato',tone:'neutral'};
  if(type==='iep')return value<=365?{label:'Ideal',tone:'green'}:value<=400?{label:'Aceptable',tone:'amber'}:{label:'Alerta',tone:'red'};
  return value<91?{label:'Sin umbral',tone:'neutral'}:value<=120?{label:'Bueno',tone:'green'}:value<=135?{label:'Regular',tone:'amber'}:{label:'Alerta',tone:'red'};
}
export function rotationMetrics(d,f={},asOf='2026-11-16') {
  return filtered(d.lots,f,d).map(lot=>{
    const sales=active(d.sales).filter(s=>s.lot===lot.id && s.product===lot.product && s.measure===lot.measure && s.date<=asOf).sort((a,b)=>a.date.localeCompare(b.date));
    let sold=0,closed='';for(const s of sales){sold+=s.quantity||0;if(!closed&&sold>=lot.quantity)closed=s.date;}
    const end=closed||asOf,days=Math.floor((Date.parse(end)-Date.parse(lot.date))/864e5);
    return {lot,sold,closed,days:days>=0?days:null,remaining:Math.max(0,lot.quantity-sold),target:/bovino|animal/i.test(lot.name)?d.parameters.animalDays:d.parameters.freshDays};
  });
}
export function transformationMetrics(d,f={}) {
  const rows=filtered(d.transformations,f,d).map(t=>{
    const output=active(d.lots).find(l=>l.id===t.outputLot),input=active(d.sales).find(s=>s.id===t.inputSale),product=d.products.find(p=>p.id===output?.product);
    if(!output || !input || input.method!=='AUTOCONSUMO' || input.measure!=='L' || !product?.milkFactor || !(t.liters>0) || !output.quantity)return null;
    return {...t,output,input,product,ratio:t.liters/output.quantity,equivalent:output.quantity*product.milkFactor};
  }).filter(Boolean);
  return {rows,global:percent(sum(rows,'equivalent'),sum(rows,'liters'))};
}
export function costMetrics(d,f={}) {
  const expenses=filtered(d.expenses,{...f,product:''},d),milk=filtered(d.lots,{...f,product:''},d).filter(l=>l.product==='5013170101' && l.measure==='L');
  const liters=sum(milk,'quantity'),gross=sum(expenses,'amount'),academic=sum(expenses,'academicAmount'),operating=gross-academic;
  const price=d.parameters.milkPrice;
  return {liters,gross,academic,operating,cost:expenses.length&&liters>0?operating/liters:null,price,target:price*d.parameters.costTarget/100};
}
export function qualityMetrics(d) {
  return {unlinked:active(d.sales).filter(s=>!active(d.lots).some(l=>l.id===s.lot)).length,pendingProcesses:active(d.sales).filter(s=>s.method==='AUTOCONSUMO'&&!active(d.processes).some(p=>p.id===s.process)).length,unassigned:active(d.receipts).filter(r=>r.unit==='SIN').length};
}
