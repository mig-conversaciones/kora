import { Repository, makeDemo, active, clone, validateBackup, createStorage } from './data.js';
import initialData from '../data/source.json' with { type:'json' };
import { centralMetrics, processMetrics, herdMetrics, rotationMetrics, transformationMetrics, costMetrics, qualityMetrics, reproductiveStatus, matchesProduct, sum, percent } from './metrics.js';
import { schemas, modules, canEdit, optionRows, prepareRecord, archiveBlocker, roleViews, preferredTab, moduleEditable } from './schemas.js';

const $=s=>document.querySelector(s);
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const number=(v,max=1)=>v===null||v===undefined?'—':Number(v).toLocaleString('es-CO',{maximumFractionDigits:max});
const money=v=>v===null||v===undefined?'—':Number(v).toLocaleString('es-CO',{style:'currency',currency:'COP',maximumFractionDigits:0});
const shortMoney=v=>Math.abs(v)>=1e6?`$${number(v/1e6,1)} M`:Math.abs(v)>=1000?`$${number(v/1000,1)} mil`:money(v);
const pct=v=>v===null||v===undefined?'—':`${number(v)} %`;
const dateLabel=v=>v?new Date(v+'T12:00:00').toLocaleDateString('es-CO',{day:'2-digit',month:'short',year:'numeric'}):'Sin fecha';
const paths={
  leaf:'M20 4C10 3 3 8 4 16c8 4 16-2 16-12ZM4 21l10-11',
  grid:'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z',
  cow:'M5 8 3 5M19 8l2-3M7 7h10l3 5-2 8H6l-2-8 3-5ZM9 12h.01M15 12h.01M8 17h8M9 17v2M15 17v2',
  wallet:'M3 6h16v4M3 6V4h14v2M3 6v14h18V10h-6v6h6M17 13h.01',
  receipt:'M6 3h12v18l-3-2-3 2-3-2-3 2V3ZM9 7h6M9 11h6M9 15h3',
  box:'m3 7 9-4 9 4v10l-9 4-9-4V7Zm0 0 9 4 9-4M12 11v10M8 5l9 4',
  arrows:'M4 7h15m-4-4 4 4-4 4M20 17H5m4-4-4 4 4 4',
  repeat:'M3 11V6h15l3 3M21 13v5H6l-3-3M3 6l3-3M21 18l-3 3',
  catalog:'M4 4h7v7H4zM15 4h5v5h-5zM4 15h5v5H4zM13 13h7v7h-7z',
  units:'M12 3v5M4 13V8h16v5M2 14h5v6H2zM10 14h5v6h-5zM18 14h5v6h-5z',
  help:'M9 9a3 3 0 0 1 6 0c0 2-3 2-3 4M12 17h.01M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0',
  chevron:'m9 5 7 7-7 7',arrow:'M4 12h16m-6-6 6 6-6 6',back:'M20 12H4m6-6-6 6 6 6',
  download:'M12 3v12m-5-5 5 5 5-5M4 15v5h16v-5',upload:'M12 16V4m-5 5 5-5 5 5M4 16v5h16v-5',
  plus:'M12 5v14M5 12h14',edit:'m15 4 5 5M4 20l5-1L21 7a2 2 0 0 0-5-5L4 14v6Z',
  eye:'M2 12s3-7 10-7 10 7 10 7-3 7-10 7S2 12 2 12ZM15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0',
  archive:'M3 3h18v5H3zM5 8v13h14V8M9 12h6',restore:'M3 10a9 9 0 1 1 2 8M3 4v6h6',
  info:'M12 11v6M12 7h.01M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0',
  alert:'m12 3 10 18H2L12 3ZM12 9v5M12 17h.01',check:'m5 12 4 4L19 6',
  search:'M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15ZM16 16l5 5',
  calendar:'M5 5h14v16H5zM8 2v6M16 2v6M5 11h14',chart:'M4 3v17h17M8 15v-4M13 15V6M18 15V9',
  target:'M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0ZM17 12a5 5 0 1 1-10 0 5 5 0 0 1 10 0ZM12 12h.01',
  trend:'m3 17 6-6 4 4 8-10M15 5h6v6',flask:'M9 3h6M10 3v6l-6 10c-.6 1 0 2 1 2h14c1 0 1.6-1 1-2L14 9V3M8 14h8',
  document:'M5 3h9l5 5v13H5V3ZM14 3v5h5M9 12h6M9 16h6',close:'m6 6 12 12M6 18 18 6',menu:'M4 6h16M4 12h16M4 18h16',
  shield:'m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Zm-4 9 3 3 5-6',settings:'M4 6h16M4 12h16M4 18h16M8 3v6M16 9v6M10 15v6',print:'M6 9V3h12v6M6 17H3V9h18v8h-3M6 14h12v7H6z'
};
const icon=name=>`<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="${paths[name]||paths.document}"/></svg>`;
const badge=(text,tone='neutral')=>`<span class="badge ${tone}">${esc(text)}</span>`;
const btn=(action,text,ico='',className='',extra='')=>`<button type="button" class="btn ${className}" data-action="${action}" ${extra}>${ico?icon(ico):''}${text}</button>`;
let repository,database,sourceSeed,persistentStorage=true;
const state={route:location.hash.slice(1)||'central',scenario:'source',profile:'admin',tab:'',search:'',status:'active',page:1,processMode:'money',includePractice:false,derivative:'global',health:'all',menu:false,filters:{year:'2026',from:'',to:'',unit:'',product:'',channel:''},techFilters:{year:'2026',from:'',to:'',unit:'GAN'}};
const profiles={admin:'Coordinación administrativa',leader:'Líder de unidad productiva',academic:'Coordinación académica'};
let toastTimer;
function toast(message,error=false){const el=$('#toast');el.textContent=message;el.className='show'+(error?' error':'');clearTimeout(toastTimer);toastTimer=setTimeout(()=>el.className='',4800);}
function commit(next,message='Cambios guardados en este navegador.'){try{repository.save(next);database=next;render();toast(message);return true;}catch(e){toast('No se pudo guardar. '+e.message,true);return false;}}
function unitName(id){return database.units.find(u=>u.id===id)?.name||id;}
function productName(id){return database.products.find(p=>p.id===id)?.name||id;}
function empty(title,description,action=''){return `<div class="empty">${icon('box')}<h3>${esc(title)}</h3><p>${esc(description)}</p>${action}</div>`;}
function pageHeading(eyebrow,title,subtitle,actions=''){return `<div class="page-heading"><div><div class="eyebrow">${eyebrow}</div><h1>${title}</h1><p class="page-subtitle">${subtitle}</p></div><div class="heading-actions">${actions}</div></div>`;}
function cardHead(title,subtitle='',code=''){return `<div class="card-head"><div><h2>${title}</h2>${subtitle?`<p>${subtitle}</p>`:''}</div>${code?`<span class="code">${code}</span>`:''}</div>`;}
function kpi(label,value,foot,ico,style='',tag=''){return `<article class="kpi ${style}"><div class="kpi-top"><div class="kpi-icon">${icon(ico)}</div>${tag?badge(tag):''}</div><div class="kpi-label">${label}</div><div class="kpi-value mono">${value}</div><div class="kpi-foot">${foot}</div></article>`;}
function ring(value,label,subtitle,color='#5b9870',segments=null){const val=Math.min(100,Math.max(0,value||0));let circles='';if(segments){let offset=0;for(const s of segments){circles+=`<circle cx="70" cy="70" r="57" fill="none" stroke="${s.color}" stroke-width="10" pathLength="100" stroke-dasharray="${s.value} ${100-s.value}" stroke-dashoffset="${-offset}"/>`;offset+=s.value;}}else circles=`<circle cx="70" cy="70" r="57" fill="none" stroke="${color}" stroke-width="9" stroke-linecap="round" pathLength="100" stroke-dasharray="${val} 100"/>`;return `<div class="ring"><svg viewBox="0 0 140 140" aria-label="${esc(label+' '+subtitle)}" role="img"><circle cx="70" cy="70" r="57" fill="none" stroke="#eef3ee" stroke-width="10"/>${circles}</svg><div class="ring-label"><strong>${label}</strong><small>${subtitle}</small></div></div>`;}

function shell(){
  const nav=[['central','grid','Tablero central'],['technical','cow','Unidad de Ganadería']];
  if(state.profile!=='admin')nav.reverse();
  const admin=[['budget','wallet','Presupuesto y unidades'],['receipts','receipt','Recaudos'],['lots','box','Producción y lotes'],['sales','arrows','Ventas y autoconsumos'],['herd','cow','Hato'],['catalogs','catalog','Catálogos y parámetros']];
  const navItem=([route,ico,label])=>`<button class="nav-item ${state.route===route?'active':''}" data-route="${route}" data-tab="${preferredTab(state.profile,route)}" ${state.route===route?'aria-current="page"':''}>${icon(ico)}${state.profile==='leader'&&route==='budget'?'Gastos de la unidad':state.profile==='academic'&&route==='sales'?'Transformaciones lácteas':label}${route==='technical'?'<span class="nav-badge">PILOTO</span>':''}</button>`;
  const current=state.route==='central'?'Tablero central':state.route==='technical'?'Unidad de Ganadería':modules[state.route]?.title||'Tablero central';
  $('#app').innerHTML=`<div class="shell ${state.menu?'menu-open':''}"><div class="mobile-overlay" data-action="menu"></div><aside class="sidebar" aria-label="Navegación principal"><div class="brand"><div class="brand-mark">${icon('leaf')}</div><div><div class="brand-title">Producción CAB<span style="color:#aed48e">.</span></div><div class="brand-sub">GESTIÓN DEL CENTRO</div></div></div><div class="nav-caption">VISTA GENERAL</div><nav>${nav.map(navItem).join('')}</nav><div class="nav-caption">${state.profile==='admin'?'ADMINISTRACIÓN':'MI GESTIÓN'}</div><nav>${admin.filter(([route])=>moduleEditable(state.profile,route)).map(navItem).join('')}</nav>${state.profile!=='admin'?`<div class="nav-caption">CONSULTA DEL CENTRO</div><nav class="consult-nav">${admin.filter(([route])=>!moduleEditable(state.profile,route)).map(navItem).join('')}</nav>`:''}<div class="sidebar-bottom"><div class="unit-note"><span>SENA · REGIONAL VALLE</span><strong>Centro Agropecuario de Buga</strong><p>Código 9124<br>Producción que transforma.</p></div><button class="nav-item" data-action="help">${icon('help')}Guía del prototipo</button><div class="local-state"><i class="dot"></i>Prototipo · almacenamiento local</div></div></aside><div class="workspace"><header class="topbar"><button class="mobile-menu" data-action="menu" aria-label="Abrir o cerrar menú">${icon('menu')}</button><div class="crumb">${icon('grid')}<span>Producción de centros</span>${icon('chevron')}<strong>${esc(current)}</strong></div><div class="top-actions"><div class="scenario-control"><label for="scenario">Escenario</label><select id="scenario" aria-label="Escenario de datos"><option value="source" ${state.scenario==='source'?'selected':''}>Datos de los archivos</option><option value="demo" ${state.scenario==='demo'?'selected':''}>Escenario demostrativo</option></select></div><div class="profile"><div class="avatar">${state.profile==='admin'?'CA':state.profile==='leader'?'LU':'AC'}</div><div><select id="profile" aria-label="Perfil de demostración">${Object.entries(profiles).map(([v,label])=>`<option value="${v}" ${state.profile===v?'selected':''}>${label}</option>`).join('')}</select><small>Perfil de demostración · CAB</small></div></div></div></header><main id="main" class="main" tabindex="-1"></main></div></div>`;
}
function scenarioBanner(){return state.scenario==='demo'?`<div class="banner demo"><div class="banner-copy"><div class="banner-symbol">${icon('flask')}</div><div><strong>Escenario demostrativo · Abril de 2026</strong><p>Operaciones simuladas para explorar todos los indicadores. Presupuestos y hato conservan la base suministrada.</p></div></div>${btn('scenario-source','Ver datos fuente','arrow','btn-light')}</div>`:`<div class="banner"><div class="banner-copy"><div class="banner-symbol">${icon('shield')}</div><div><strong>Información de los archivos · Vigencia 2026</strong><p>Fuentes con cortes diferentes. Los indicadores pendientes conservan su estado sin dato.</p></div></div>${btn('scenario-demo','Explorar demo','arrow','btn-light')}</div>`;}
function filters(technical=false){
  const f=technical?state.techFilters:state.filters;
  const opts=(rows,value)=>rows.map(([v,l])=>`<option value="${esc(v)}" ${v===value?'selected':''}>${esc(l)}</option>`).join('');
  const select=(key,label,options)=>`<div class="filter-field"><label for="filter-${key}">${label}</label><select id="filter-${key}" data-filter="${key}">${opts(options,f[key])}</select></div>`;
  const years=[...new Set(['2025','2026',...database.receipts.map(r=>r.date.slice(0,4)),...database.budgets.map(r=>String(r.year))])].sort().filter(Boolean).map(y=>[y,y]);
  const productIds=new Set([...database.lots.map(l=>l.product),...database.sales.map(s=>s.product)].filter(Boolean));
  const rawProducts=[...new Map([...database.receipts,...database.processes].map(r=>[String(r.product).trim().toLowerCase(),String(r.product).trim()])).values()].filter(name=>name&&!database.products.some(p=>p.name.toLowerCase()===name.toLowerCase())&&!['leche','leche de vaca'].includes(name.toLowerCase())).map(name=>['raw:'+name,name+' · concepto fuente']);
  return `<form class="filter-bar" id="filters" aria-label="Filtros del tablero">${select('year','Vigencia',years)}<div class="filter-field date"><label for="filter-from">Desde</label><input id="filter-from" type="date" data-filter="from" value="${esc(f.from)}" aria-label="Fecha inicial"></div><div class="filter-field date"><label for="filter-to">Hasta</label><input id="filter-to" type="date" data-filter="to" value="${esc(f.to)}" aria-label="Fecha final"></div>${technical?'':select('unit','Unidad productiva',[['','Todas las unidades'],...active(database.units).map(u=>[u.id,u.name])])}${technical?'':select('product','Producto',[['','Todos los productos'],...active(database.products).filter(p=>productIds.has(p.id)).map(p=>[p.id,p.name]),...rawProducts])}${technical?'':select('channel','Canal de venta',[['','Todos los canales'],['TRANSFERENCIA','Venta directa'],['AUTOCONSUMO','Autoconsumo']])}<button type="button" class="filter-reset" data-action="reset-filters" title="Restablecer filtros" aria-label="Restablecer filtros">${icon('restore')}</button></form>`;
}
function budgetChart(f){
  const units=active(database.units).filter(u=>(!f.unit||u.id===f.unit)&&(database.budgets.some(b=>b.unit===u.id)||centralMetrics(database,{...f,unit:u.id}).total>0));
  const rows=units.map(u=>({...centralMetrics(database,{...f,unit:u.id}),unit:u}));
  if(!rows.length)return empty('Sin asignaciones para esta selección','Cambia los filtros o registra una asignación presupuestal.');
  const width=760,height=265,left=55,right=20,top=18,bottom=48,plotH=height-top-bottom;
  const max=Math.max(...rows.map(r=>Math.max(r.budget,r.total)),1)*1.08,step=(width-left-right)/rows.length,bw=Math.min(23,step*.26);
  let svg='';for(let i=0;i<=4;i++){const y=top+plotH-i*plotH/4;svg+=`<line class="chart-grid" x1="${left}" x2="${width-right}" y1="${y}" y2="${y}"/><text x="${left-10}" y="${y+3}" text-anchor="end">${number(max*i/4/1e6,0)} M</text>`;}
  rows.forEach((r,i)=>{const x=left+i*step+step/2,bh=r.budget/max*plotH,dh=r.direct/max*plotH,ah=r.internal/max*plotH;const b=r.budgets[0];const tip=`${r.unit.name}\nAsignación: ${money(r.budget)}\nVenta directa: ${money(r.direct)} (${pct(percent(r.direct,r.total))})\nAutoconsumo: ${money(r.internal)} (${pct(percent(r.internal,r.total))})\nMantenimiento: ${money(b?.maintenance||0)}\nAlimentación: ${money(b?.feed||0)}\nFormación: ${money(b?.academic||0)}`;svg+=`<g tabindex="0" data-tip="${esc(tip)}" aria-label="${esc(tip)}"><rect x="${x-bw-3}" y="${top+plotH-bh}" width="${bw}" height="${bh}" rx="3" fill="#7896a9"/><rect x="${x+3}" y="${top+plotH-dh}" width="${bw}" height="${dh}" fill="#15803d" rx="1"/><rect x="${x+3}" y="${top+plotH-dh-ah}" width="${bw}" height="${ah}" fill="#d97706" rx="1"/><rect x="${x-step/2+5}" y="${top}" width="${step-10}" height="${plotH+29}" fill="transparent"/><text x="${x}" y="${top+plotH+20}" text-anchor="middle">${esc(r.unit.id)}</text></g>`;});
  return `<svg class="bar-chart" viewBox="0 0 ${width} ${height}" role="img" aria-label="Asignación anual y ventas por unidad productiva, en millones de pesos">${svg}</svg>`;
}
function selectedProcesses(f){return active(database.processes).filter(p=>(!f.unit||p.unit===f.unit)&&(!f.year||p.year===Number(f.year))&&matchesProduct(p,f.product,database));}
function processSection(f){
  const processes=selectedProcesses(f),physical=state.processMode==='physical';
  return `<div class="section-heading"><div><h2>Autoconsumo y trazabilidad</h2><p>Seguimiento a los cupos aprobados entre unidades.</p></div><div class="segmented" aria-label="Medida del autoconsumo"><button data-action="process-money" class="${physical?'':'active'}" aria-pressed="${!physical}">Pesos COP</button><button data-action="process-physical" class="${physical?'active':''}" aria-pressed="${physical}">Unidades físicas</button></div></div><div class="grid-main"><section class="card">${cardHead('Ejecución por proceso','Entregas realizadas frente a lo programado','IND_002')}<div class="process-list">${processes.length?processes.map(p=>{const m=processMetrics(database,p,f),rate=physical?m.quantityProgress:m.moneyProgress;return `<div class="progress-item"><div class="progress-title"><strong>${esc(p.id)} <span>· ${esc(p.product)} → ${esc(p.destination)}</span></strong>${badge(pct(rate),rate>=100?'green':'amber')}</div><div class="progress-line" role="progressbar" aria-label="Avance ${esc(p.id)}" aria-valuenow="${Math.max(0,Math.round(rate||0))}" aria-valuemin="0" aria-valuemax="${Math.max(100,Math.ceil(rate||0))}"><div class="progress-fill ${rate>=100?'green':''}" style="width:${Math.min(100,Math.max(0,rate||0))}%"></div></div><div class="progress-caption"><span>${physical?number(m.delivered)+' '+esc(p.measure):money(m.value)} entregados</span><span>Meta: ${physical?number(p.quantity)+' '+esc(p.measure):money(p.amount)}</span></div></div>`;}).join(''):empty('Sin procesos para esta selección','Prueba con otra unidad o producto.')}</div></section>${sourcesCard()}</div><section class="card"><div class="table-top"><div><h2>Detalle de procesos de autoconsumo</h2><p>Cupos anuales y entregas del periodo seleccionado.</p></div><button class="text-link" data-route="sales" data-tab="processes">Administrar ${icon('arrow')}</button></div><div class="table-scroll"><table class="data-table"><thead><tr>${['Proceso','Origen → destino','Producto','Cupo aprobado','Entregado','Saldo','Avance','Lotes vinculados'].map(t=>`<th>${t}</th>`).join('')}</tr></thead><tbody>${processes.map(p=>{const m=processMetrics(database,p,f);return `<tr><td class="strong">${esc(p.id)}</td><td class="wrapped">${esc(unitName(p.unit))} → ${esc(p.destination)}</td><td>${esc(p.product)}</td><td>${physical?number(p.quantity)+' '+esc(p.measure):money(p.amount)}</td><td>${physical?number(m.delivered)+' '+esc(p.measure):money(m.value)}</td><td>${physical?number(m.quantityRemaining)+' '+esc(p.measure):money(m.valueRemaining)}</td><td>${badge(pct(physical?m.quantityProgress:m.moneyProgress),m.quantityProgress>=100?'green':'amber')}</td><td>${esc([...new Set(m.entries.map(s=>s.lotCode))].join(', ')||'Sin entregas vinculadas')}</td></tr>`;}).join('')||'<tr><td colspan="8">Sin procesos para los filtros seleccionados.</td></tr>'}</tbody></table></div><div class="table-foot"><span>${processes.length} procesos · no se suman cantidades de medidas distintas</span><span>GRF-I-005 · Referencia de la guía</span></div></section>`;
}
function range(rows){const dates=active(rows).map(r=>r.date).filter(Boolean).sort();return dates.length?`${dateLabel(dates[0])} – ${dateLabel(dates.at(-1))}`:'Sin registros';}
function sourcesCard(){const q=qualityMetrics(database);return `<section class="card">${cardHead('Estado de la información','Cobertura de las fuentes del escenario completo')}<div class="source-list"><div class="source-item"><div class="source-icon">${icon('receipt')}</div><div><h3>Recaudos externos</h3><p>${esc(range(database.receipts))}<br>${active(database.receipts).length} registros disponibles</p></div>${badge('Recaudo','green')}</div><div class="source-item"><div class="source-icon">${icon('box')}</div><div><h3>Producción y salidas</h3><p>${esc(range(database.sales))}<br>${q.unlinked} salidas sin recepción vinculada</p></div>${badge(q.unlinked?'Revisar':'Vinculado',q.unlinked?'amber':'green')}</div><div class="source-item"><div class="source-icon">${icon('cow')}</div><div><h3>Inventario biológico</h3><p>Corte: ${dateLabel(database.meta.herdCutoff)}<br>${active(database.animals).length} hembras registradas</p></div>${badge('Corte fijo','blue')}</div></div><div class="notice">${icon('info')}<span>${q.pendingProcesses} entregas sin proceso identificado. ${q.unassigned} recaudo(s) sin unidad inequívoca; se conservan sin distribuir.</span></div></section>`;}
function central(){
  const f=state.filters,m=centralMetrics(database,f);
  return pageHeading('PANORAMA DEL CENTRO','Tablero central','Control presupuestal, recaudo y autoconsumo de las unidades productivas.',btn('export','Exportar respaldo','download')+btn('technical','Explorar Ganadería','arrow','btn-primary'))+scenarioBanner()+filters()+`<div class="kpis">${kpi('Asignación presupuestal',shortMoney(m.budget),'Presupuesto anual · '+esc(f.year),'wallet','','ANUAL')}${kpi('Recaudo venta directa',shortMoney(m.direct),`${m.receipts.length} recaudos del periodo`,'receipt','kpi-accent')}${kpi('Ventas por autoconsumo',shortMoney(m.internal),'Transferencias internas ejecutadas','repeat','kpi-amber')}${kpi('Total ventas realizadas',shortMoney(m.total),'Venta directa + autoconsumo','chart','kpi-blue')}${kpi('Cobertura sobre asignación',pct(m.coverage),'Relación comercial / presupuesto','target','kpi-accent')}${kpi('Saldo por cubrir',shortMoney(m.remaining),'Asignación menos ventas realizadas','wallet')}</div><div class="grid-main"><section class="card">${cardHead('Asignación vs. ventas realizadas','Comparativo por unidad productiva · millones de pesos','IND_001')}<div class="legend chart-legend"><span><i style="--color:#7896a9"></i>Asignación anual</span><span><i style="--color:#15803d"></i>Venta directa</span><span><i style="--color:#d97706"></i>Autoconsumo</span></div>${budgetChart(f)}<div class="chart-note">${icon('info')}El presupuesto es anual; las ventas responden a las fechas seleccionadas. Pasa sobre las barras para ver el desglose.</div></section><section class="card">${cardHead('Lectura del periodo','Cobertura comercial sobre el presupuesto')}<div class="coverage">${ring(m.coverage,pct(m.coverage),'de la asignación')}<div class="coverage-side">${badge(m.total>0?'Actividad registrada':'Sin actividad',m.total>0?'green':'neutral')}<p>Total realizado<br>en el periodo</p><strong>${money(m.total)}</strong></div></div><div class="mini-stats"><div class="mini-row"><span>${icon('receipt')}Recaudo externo</span><strong>${pct(percent(m.direct,m.total))}</strong></div><div class="mini-row"><span>${icon('repeat')}Transferencia interna</span><strong>${pct(percent(m.internal,m.total))}</strong></div></div><button class="btn card-action" data-action="technical">Ver el desempeño de Ganadería ${icon('arrow')}</button></section></div>${m.unlinked.length?`<div class="notice info" style="margin:0 0 20px">${icon('info')}<span>${m.unlinked.length} entrega(s) de autoconsumo suman ${money(sum(m.unlinked,'amount'))}, pero están pendientes de vinculación con un proceso. Se incluyen en IND_001; no se asignan a metas de IND_002.</span></div>`:''}${processSection(f)}<div class="notice info" style="margin:18px 0 0">${icon('info')}<span>Conciliación del periodo: ventas por transferencia registradas ${money(m.transfer)} · recaudo externo ${money(m.direct)}. Son fuentes con coberturas distintas; estos valores no se suman. La cobertura comercial no representa ejecución presupuestal CDP/RP ni saldo de caja.</span></div>`;
}
function technical(){
  const f=state.techFilters,h=herdMetrics(database,state.includePractice),cost=costMetrics(database,f),t=transformationMetrics(database,f);
  const rateTone=h.rate>=database.parameters.productiveTarget?'green':'amber';
  return pageHeading('UNIDAD PRODUCTIVA · GANADERÍA','Hato El Milagroso','Gestión técnica, productiva y pedagógica · Centro Agropecuario de Buga.',btn('back-central','Volver al central','back')+btn('go-herd','Administrar hato','plus','btn-primary'))+scenarioBanner()+filters(true)+`<div class="tech-context"><div><strong>${icon('cow')} Ganadería · Piloto de unidad productiva</strong><p>Inventario biológico al ${dateLabel(database.meta.herdCutoff)} · independiente del filtro de fechas.</p></div><label class="switch"><input id="practice-toggle" type="checkbox" ${state.includePractice?'checked':''}><span class="switch-track"></span>Incluir lote de práctica académica</label></div><div class="kpis tech-kpis">${kpi('Vacas en ordeño',pct(h.rate),`${h.production} de ${h.animals.length} hembras · meta ≥ ${number(database.parameters.productiveTarget)} %`,'cow','kpi-accent','IND_003')}${kpi('Tasa de preñez',pct(h.pregnancy),'Sobre la población operativa seleccionada','target','kpi-blue')}${kpi('Disponibilidad didáctica',`${h.practice} animales`,`${pct(percent(h.practice,h.all.length))} del inventario completo`,'units','kpi-amber')}${kpi('Costo operativo por litro',cost.cost===null?'Pendiente':money(cost.cost),cost.cost===null?'Faltan gastos ejecutados o producción':`Meta ≤ ${money(cost.target)} / L`,'wallet',cost.cost!==null&&cost.cost<=cost.target?'kpi-accent':'','IND_17')}</div><div class="grid-half"><section class="card">${cardHead('Composición del hato','Distribución del inventario completo','IND_003')}<div class="donut-layout">${ring(h.fullRate,String(h.all.length),'hembras registradas','#15803d',h.all.length?[{value:h.production/h.all.length*100,color:'#15803d'},{value:h.rest/h.all.length*100,color:'#6c93b1'},{value:h.practice/h.all.length*100,color:'#d9a24f'}]:[])}<div class="donut-key">${[[h.production,'Producción','#15803d'],[h.rest,'Horra','#6c93b1'],[h.practice,'Práctica académica','#d9a24f']].map(([n,l,c])=>`<div><i style="--color:${c}"></i><span>${l}<strong>${n} <small class="muted">· ${pct(percent(n,h.all.length))}</small></strong></span></div>`).join('')}</div></div><div class="chart-note">${badge(h.rate>=database.parameters.productiveTarget?'Meta alcanzada':'Por debajo de la meta',rateTone)}<span>Base completa: ${h.production}/${h.all.length} = ${pct(h.fullRate)}. ${state.includePractice?'Práctica incluida.':`Práctica excluida del KPI: ${h.production}/${h.animals.length}.`}</span></div></section>${transformationCard(t)}</div><div class="grid-half">${rotationCard(f)}${costCard(cost)}</div>${healthTable(h)}`;
}
function transformationCard(t){
  const products=active(database.products).filter(p=>p.milkFactor>0),selected=state.derivative==='global'?null:products.find(p=>p.id===state.derivative),rows=selected?t.rows.filter(r=>r.product.id===selected.id):t.rows;
  const value=selected?(sum(rows,'liters')>0&&sum(rows.map(r=>r.output),'quantity')>0?sum(rows,'liters')/sum(rows.map(r=>r.output),'quantity'):null):t.global;
  const good=value!==null&&(selected?value<=selected.yieldTarget:value>=95),max=selected?Math.max(selected.yieldTarget*1.6,value||0):110;
  const labels=[['global','Global (%)'],...products.map(p=>[p.id,p.name])];
  // Keep the specified selectors available even when the source has no conversion factors.
  if(products.length===0)labels.push(['pending-queso','Queso cuajada'],['pending-yogurt','Yogurt'],['pending-manjar','Manjar blanco'],['pending-cortado','Cortado']);
  const pending=state.derivative.startsWith('pending-');
  const progress=value===null?0:Math.min(1,value/max);
  const values=rows.map(r=>selected?r.ratio:(r.equivalent/r.liters*100));
  const points=values.map((v,i)=>`${25+(i/(values.length-1||1))*350},${48-Math.min(1,v/(selected?max:110))*36}`).join(' ');
  return `<section class="card">${cardHead('Transformación láctea','Aprovechamiento y rendimiento por derivado','IND_08')}<div style="padding:17px 20px 0"><div class="segmented">${labels.map(([id,l])=>`<button data-derivative="${esc(id)}" class="${state.derivative===id?'active':''}" aria-pressed="${state.derivative===id}">${esc(l)}</button>`).join('')}</div></div>${!rows.length||pending?empty('Pendiente de trazabilidad','Vincula entregas de leche con los derivados recibidos y configura sus factores de conversión.',btn('go-transformations','Ver transformaciones','arrow')):`<div class="gauge-wrap"><svg viewBox="0 0 200 110" role="img" aria-label="Rendimiento ${number(value)}"><path d="M20 100a80 80 0 0 1 160 0" fill="none" stroke="#eef2ed" stroke-width="13" stroke-linecap="round"/><path d="M20 100a80 80 0 0 1 160 0" fill="none" stroke="${good?'#5a9870':'#d4a054'}" stroke-width="13" stroke-linecap="round" pathLength="100" stroke-dasharray="${progress*100} 100"/></svg><div class="gauge-value"><strong>${selected?number(value,2):pct(value)}</strong><small>${selected?'L de leche / '+esc(selected.unit):'aprovechamiento global'}</small></div></div><div class="gauge-target">${badge(good?'Dentro de la meta':'Revisar rendimiento',good?'green':'amber')} &nbsp; Meta ${selected?'≤ '+number(selected.yieldTarget,2)+' L/'+esc(selected.unit):'≥ 95 %'}</div><svg class="sparkline" viewBox="0 0 400 55" role="img" aria-label="Tendencia por lote de transformación"><line x1="20" x2="380" y1="50" y2="50" stroke="#edf2ed"/><polyline points="${points}" fill="none" stroke="#77a786" stroke-width="2"/>${values.map((v,i)=>`<circle cx="${25+(i/(values.length-1||1))*350}" cy="${48-Math.min(1,v/(selected?max:110))*36}" r="3" fill="#508d64" data-tip="${esc(rows[i].id+': '+number(v,2)+(selected?' L/'+selected.unit:' %'))}" tabindex="0"/>`).join('')}</svg>`}<div class="chart-note">${icon('info')}Conversión por producto; no se mezclan kilogramos y litros. ${rows.length} lote(s) con vínculo verificable.</div></section>`;
}
function rotationCard(f){
  const outputIds=new Set(active(database.transformations).map(t=>t.outputLot));
  const asOf=f.to||`${f.year}-12-31`;
  const cap=asOf>database.meta.preparedAt?database.meta.preparedAt:asOf;
  const rows=rotationMetrics(database,{...f,unit:''},cap).filter(r=>r.lot.unit==='GAN'||outputIds.has(r.lot.id));
  const closed=rows.filter(r=>r.closed&&r.days!==null),avg=closed.length?sum(closed,'days')/closed.length:null;
  return `<section class="card">${cardHead('Rotación de lotes terminados',`Promedio de lotes cerrados: ${avg===null?'pendiente':number(avg)+' días'}`,'IND_12')}<div class="rotation-list">${rows.length?rows.map(r=>`<div class="rotation-item"><div class="rotation-top"><strong>${esc(r.lot.name)}</strong><span>${r.days===null?'Fecha inconsistente':number(r.days)+' días'} ${badge(r.closed?'Cerrado':'Abierto',r.closed?'green':'amber')}</span></div><div class="progress-line"><div class="progress-fill ${r.days<=r.target?'green':''}" style="width:${Math.min(100,(r.days||0)/Math.max(r.target*2,1)*100)}%"></div></div><div class="progress-caption"><span>${esc(r.lot.code)}</span><span>${r.closed?'Rotación':'Antigüedad'} · referencia ≤ ${r.target} días</span></div></div>`).join(''):empty('Sin lotes en el periodo','Ajusta las fechas o registra una recepción de Ganadería.')}</div><div class="chart-note">${icon('info')}Cierre por agotamiento del lote y producto. Los abiertos no entran en el promedio. Corte: ${dateLabel(cap)}.</div></section>`;
}
function costCard(c){return `<section class="card">${cardHead('Costo operativo y precio de venta','Costos ejecutados con exclusión del componente académico','IND_17')}${c.cost===null?empty('Costo unitario pendiente','Registra gastos ejecutados, su asignación a vacas de práctica y los litros producidos en el mismo periodo.',btn('go-expenses','Gestionar gastos','arrow')):`<div class="cost-detail"><div><p>Costo operativo / L</p><strong class="${c.cost<=c.target?'green-text':'amber-text'}">${money(c.cost)}</strong></div><span class="versus">vs.</span><div><p>Precio sugerido / L</p><strong>${money(c.price)}</strong></div></div><div class="cost-meter"><div class="progress-line"><div class="progress-fill ${c.cost<=c.target?'green':''}" style="width:${Math.min(100,c.cost/c.price*100)}%"></div></div><div class="progress-caption"><span>Margen operativo: ${pct(percent(c.price-c.cost,c.price))}</span><span>Meta costo: ≤ ${money(c.target)}</span></div></div>`}<div class="mini-stats"><div class="mini-row"><span>Litros recibidos de Ganadería</span><strong>${number(c.liters)} L</strong></div><div class="mini-row"><span>Gasto ejecutado · menos académico</span><strong>${money(c.gross)} − ${money(c.academic)}</strong></div></div><div class="chart-note">${icon('info')}Los $125 M de mantenimiento y alimentación de la fuente son presupuesto, no gasto ejecutado. Los cupos no son producción.</div></section>`;}
function healthTable(h){
  let animals=h.animals;
  if(state.health==='alerts')animals=animals.filter(a=>reproductiveStatus(a.iep,'iep').tone==='red'||reproductiveStatus(a.openDays,'open').tone==='red');
  if(state.health==='pregnant')animals=animals.filter(a=>a.reproductive==='PREÑADA');
  if(state.health==='practice')animals=h.all.filter(a=>a.group==='PRACTICA');
  const status=(v,type)=>{const s=reproductiveStatus(v,type);return `${v===null||v===undefined?'—':number(v,0)+' d'} &nbsp;${badge(s.label,s.tone)}`;};
  return `<section class="card" id="reproductive-panel"><div class="table-top"><div><h2>Salud reproductiva y disponibilidad académica</h2><p>Inventario individual · ${dateLabel(database.meta.herdCutoff)}. El filtro «Práctica» consulta el lote didáctico completo.</p></div><button class="text-link" data-route="herd">Ver inventario ${icon('arrow')}</button></div><div class="health-filters">${[['all','Población seleccionada'],['alerts','Con alertas'],['pregnant','Preñadas'],['practice','Práctica académica']].map(([id,l])=>`<button data-health="${id}" class="${state.health===id?'active':''}" aria-pressed="${state.health===id}">${l}</button>`).join('')}</div><div class="table-scroll"><table class="data-table"><thead><tr><th>Animal</th><th>Lote</th><th>Estado reproductivo</th><th>Último parto</th><th>Intervalo entre partos</th><th>Días abiertos</th><th>Detalle</th></tr></thead><tbody>${animals.map(a=>`<tr><td class="strong">${esc(a.id)}</td><td>${badge(a.group==='PRODUCCION'?'Producción':a.group==='HORRA'?'Horra':'Práctica',a.group==='PRODUCCION'?'green':a.group==='HORRA'?'blue':'amber')}</td><td>${esc(a.reproductive||'Sin dato')}</td><td>${dateLabel(a.lastCalving)}</td><td>${status(a.iep,'iep')}</td><td>${status(a.openDays,'open')}</td><td><button class="icon-btn" data-detail="${esc(a.id)}" data-collection="animals" aria-label="Ver animal ${esc(a.id)}">${icon('eye')}</button></td></tr>`).join('')||'<tr><td colspan="7">No hay animales para este filtro.</td></tr>'}</tbody></table></div><div class="table-foot"><span>${animals.length} animales · la tabla no representa historia reproductiva</span><span>IEP ideal ≤ 365 d · días abiertos buenos 91–120 d</span></div></section>`;
}

const fieldLabels={code:'Lote',date:'Fecha',unit:'Unidad',name:'Producto',quantity:'Cantidad',amount:'Valor total',method:'Canal',process:'Proceso',lotCode:'Número interno',milkFactor:'Factor L / unidad',yieldTarget:'Meta L / unidad',academicAmount:'Costo académico',inputSale:'Entrega de leche',outputLot:'Lote derivado',liters:'Leche asignada (L)'};
function formatCell(collection,key,row){
  const value=row[key];
  if(value===null||value===undefined||value==='')return '<span class="muted">—</span>';
  if(key==='unit'&&collection!=='products')return esc(unitName(value));
  if(['amount','maintenance','feed','academic','price','academicAmount'].includes(key))return money(value);
  if(key==='date'||key==='lastCalving')return dateLabel(value);
  if(key==='method')return badge(value==='AUTOCONSUMO'?'Autoconsumo':'Venta directa',value==='AUTOCONSUMO'?'amber':'green');
  if(key==='group')return badge(value==='PRODUCCION'?'Producción':value==='HORRA'?'Horra':'Práctica',value==='PRODUCCION'?'green':value==='HORRA'?'blue':'amber');
  if(key==='iep'||key==='openDays'){const s=reproductiveStatus(value,key==='iep'?'iep':'open');return number(value,0)+' '+badge(s.label,s.tone);}
  if(key==='quantity')return number(value,2)+' '+esc(row.measure||'');
  if(typeof value==='number')return number(value,2);
  return esc(value);
}
function collectionForRoute(){const tabs=modules[state.route]?.tabs||[];return tabs.includes(state.tab)?state.tab:preferredTab(state.profile,state.route);}
function adminView(){
  const config=modules[state.route],collection=collectionForRoute();state.tab=collection;
  const editable=canEdit(state.profile,collection),schema=schemas[collection];
  let out=pageHeading('ADMINISTRACIÓN',config.title,config.subtitle,btn('export','Exportar respaldo','download')+(schema&&editable?btn('new-record','Nuevo registro','plus','btn-primary',`data-collection="${collection}"`):''))+scenarioBanner();
  if(config.tabs.length>1)out+=`<div class="tabs" role="tablist" aria-label="Secciones de ${esc(config.title)}">${config.tabs.map(t=>`<button class="tab ${collection===t?'active':''}" role="tab" aria-selected="${collection===t}" data-tab="${t}">${schemas[t]?.title||({parameters:'Metas y parámetros',sources:'Fuentes y respaldos'}[t])}</button>`).join('')}</div>`;
  if(collection==='parameters')return out+parametersView();
  if(collection==='sources')return out+sourcesView();
  const search=state.search.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
  const rows=database[collection].filter(r=>(state.status==='all'||(state.status==='archived'?r.archived:!r.archived))&&(!search||Object.values(r).filter(v=>typeof v!=='object').join(' ').concat(' ',unitName(r.unit)).normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().includes(search)));
  const pageSize=12,pages=Math.max(1,Math.ceil(rows.length/pageSize));state.page=Math.min(state.page,pages);const shown=rows.slice((state.page-1)*pageSize,state.page*pageSize);
  out+=`<div class="toolbar"><label class="search-box">${icon('search')}<input id="admin-search" type="search" placeholder="Buscar en ${esc(schema.title.toLowerCase())}…" aria-label="Buscar registros" value="${esc(state.search)}"></label><div class="toolbar-controls"><span class="muted small">${rows.length} registros</span><select id="record-status" aria-label="Estado de los registros"><option value="active" ${state.status==='active'?'selected':''}>Activos</option><option value="archived" ${state.status==='archived'?'selected':''}>Archivados</option><option value="all" ${state.status==='all'?'selected':''}>Todos los estados</option></select></div></div>`;
  if(!editable)out+=`<div class="notice info" style="margin:0 0 16px">${icon('eye')}<span>Vista de consulta para ${esc(profiles[state.profile])}. Puedes abrir el detalle y consultar la procedencia de cada registro.</span></div>`;
  out+=`<section class="card"><div class="table-scroll"><table class="data-table"><thead><tr>${schema.columns.map(key=>`<th>${esc(fieldLabels[key]||schema.fields.find(f=>f.key===key)?.label||key)}</th>`).join('')}<th>Estado</th><th>Acciones</th></tr></thead><tbody>${shown.map(r=>`<tr>${schema.columns.map(key=>`<td class="${['amount','maintenance','feed','academic','price','quantity','academicAmount'].includes(key)?'num':''} ${key==='id'||key==='code'?'strong':''}" title="${esc(typeof r[key]==='number'?number(r[key],3):r[key])}">${formatCell(collection,key,r)}</td>`).join('')}<td>${badge(r.archived?'Archivado':'Activo',r.archived?'neutral':'green')}</td><td class="no-wrap"><button class="icon-btn" data-detail="${esc(r.id)}" data-collection="${collection}" aria-label="Ver detalle ${esc(r.id)}">${icon('eye')}</button>${editable?`<button class="icon-btn" data-edit="${esc(r.id)}" data-collection="${collection}" aria-label="Editar ${esc(r.id)}" ${r.archived?'disabled':''}>${icon('edit')}</button><button class="icon-btn" data-archive="${esc(r.id)}" data-collection="${collection}" aria-label="${r.archived?'Restaurar':'Archivar'} ${esc(r.id)}">${icon(r.archived?'restore':'archive')}</button>`:''}</td></tr>`).join('')||`<tr><td colspan="${schema.columns.length+2}">${empty('No hay registros para esta búsqueda','Cambia la búsqueda o el estado de los registros.')}</td></tr>`}</tbody></table></div><div class="table-foot"><span>${rows.length?`${(state.page-1)*pageSize+1}–${Math.min(state.page*pageSize,rows.length)} de ${rows.length}`:'0 registros'} · ${esc(schema.title)}</span><div>${btn('page-prev','Anterior','', '',state.page<=1?'disabled':'')} <span style="margin:0 10px">${state.page} / ${pages}</span> ${btn('page-next','Siguiente','','',state.page>=pages?'disabled':'')}</div></div></section>`;
  if(collection==='sales')out+=`<div class="notice info" style="margin:17px 0">${icon('info')}<span>Las salidas históricas incompletas conservan su información original. Al editar, selecciona una recepción válida y un proceso cuando corresponda. Una transferencia registrada no genera automáticamente un recaudo.</span></div>`;
  if(collection==='expenses')out+=`<div class="notice info" style="margin:17px 0">${icon('info')}<span>Registra gastos ejecutados y sustentados. La asignación presupuestal de mantenimiento y alimentación se administra por separado.</span></div>`;
  return out;
}
function parametersView(){const p=database.parameters;const labels={productiveTarget:'Meta de vacas en ordeño (%)',milkPrice:'Precio de referencia de leche (COP/L)',costTarget:'Costo máximo / precio de venta (%)',freshDays:'Rotación de lácteos frescos (días)',animalDays:'Rotación de bovinos (días)'};return `<section class="card">${cardHead('Metas de seguimiento','Parámetros iniciales tomados de la guía de especificaciones')}<form id="parameters-form"><div class="param-grid">${Object.entries(labels).map(([key,label])=>`<label>${label}<input name="${key}" type="number" value="${p[key]}" min="${key==='milkPrice'?1:0}" ${['productiveTarget','costTarget'].includes(key)?'max="100"':''} step="1" required ${canEdit(state.profile,'parameters')?'':'disabled'}></label>`).join('')}</div><div class="param-actions">${canEdit(state.profile,'parameters')?'<button class="btn btn-primary" type="submit">Guardar parámetros</button>':badge('Consulta según perfil')}</div></form></section><div class="notice info" style="margin:17px 0">${icon('info')}<span>Los factores y metas de transformación se configuran por producto en el catálogo. El precio de referencia de leche permite comparar periodos con un criterio explícito.</span></div>`;}
function sourcesView(){const q=qualityMetrics(database);return `<section class="card">${cardHead('Fuentes y respaldo del escenario','Los archivos originales permanecen intactos. La edición se guarda en este navegador.')}<div class="meta-grid"><div class="meta-box">${icon('wallet')}<h3>Administración</h3><p>BBDD_DATOS ADMON.xlsx<br>Presupuestos, recaudos y cupos.<br>${database.budgets.length} asignaciones · ${database.receipts.length} recaudos.</p></div><div class="meta-box">${icon('box')}<h3>Producción de centro</h3><p>BBDD_Produccion de Centro.xlsx<br>Catálogo, recepciones y salidas.<br>${q.unlinked} salidas sin recepción enlazada.</p></div><div class="meta-box">${icon('cow')}<h3>Inventario biológico</h3><p>INFO_Hato.xlsx · ESTADO REP<br>Identificación y estado reproductivo.<br>Corte al ${dateLabel(database.meta.herdCutoff)}.</p></div></div><div class="card-pad" style="padding-top:0"><h3>Respaldo local</h3><p class="page-subtitle">El respaldo incluye el escenario completo y los cambios. Solo puede importarse en el mismo escenario.</p><div class="storage-tools">${btn('export','Exportar JSON','download')}${canEdit(state.profile,'sources')?btn('import','Importar respaldo','upload')+btn('reset-data','Restaurar datos iniciales','restore','btn-danger'):''}</div><div class="notice info" style="margin:10px 0 0">${icon('info')}<span>No hay sincronización entre equipos. Exporta un respaldo antes de borrar los datos del navegador. Las referencias y estados de datos heredados se conservan para su revisión.</span></div></div></section>`;}
function footer(){return `<footer class="footer"><span><i class="dot"></i>${state.scenario==='demo'?'Escenario demostrativo':'Datos de los archivos'} · Cambios locales</span><span>SENA · Centro Agropecuario de Buga · 9124</span></footer>`;}
function rolePanel(){const role=roleViews[state.profile];return `<section class="role-panel role-${state.profile}" aria-label="Espacio de ${esc(profiles[state.profile])}"><div><span class="role-label">${icon(state.profile==='academic'?'units':state.profile==='leader'?'cow':'shield')}${esc(profiles[state.profile])}</span><h2>${role.title}</h2><p>${role.description}</p></div><div class="role-actions">${role.actions.map(([route,tab,label])=>`<button class="btn" data-route="${route}" data-tab="${tab}">${label}${icon('arrow')}</button>`).join('')}</div></section>`;}
function render(){
  shell();$('#main').innerHTML=(state.route==='technical'?technical():modules[state.route]?adminView():central())+footer();
  $('#main .page-heading')?.insertAdjacentHTML('afterend',rolePanel());
  if(!persistentStorage)$('#main .page-heading')?.insertAdjacentHTML('afterend',`<div class="notice error" style="margin:0 0 20px">${icon('alert')}<span>El navegador no permite guardar datos en esta ubicación. Los cambios se conservan solo durante esta sesión: exporta un respaldo JSON antes de cerrar.</span></div>`);
  for(const tab of document.querySelectorAll('.tabs .tab')){
    const label=document.createElement('span');label.className='tab-permission';label.textContent=canEdit(state.profile,tab.dataset.tab)?'Edición':'Consulta';tab.append(label);
  }
  if(state.route==='technical'&&state.profile==='academic'){
    const kpis=$('.tech-kpis'),panel=$('#reproductive-panel');
    if(kpis){kpis.prepend(kpis.children[2]);if(panel){kpis.after(panel);panel.classList.add('academic-priority');}}
  }
}
function navigate(route,tab=''){state.route=route;state.tab=tab||preferredTab(state.profile,route);state.search='';state.page=1;state.status='active';state.menu=false;location.hash=route;render();window.scrollTo({top:0,behavior:'instant'});}
function applyProfile(profile){
  if(!roleViews[profile])return;
  state.profile=profile;const role=roleViews[profile];state.includePractice=role.practice;state.health=role.health;
  state.derivative='global';$('#modal').close();
  try{localStorage.setItem('cab-profile',profile);}catch{}
  navigate(role.home);toast(`Perfil activo: ${profiles[profile]}.`);
}
function switchScenario(scenario){try{const data=repository.load(scenario);state.scenario=scenario;database=data;state.page=1;state.derivative='global';state.search='';if(state.filters.product&&!state.filters.product.startsWith('raw:')&&!data.products.some(p=>p.id===state.filters.product))state.filters.product='';if(state.filters.unit&&!active(data.units).some(u=>u.id===state.filters.unit))state.filters.unit='';try{localStorage.setItem('cab-scenario',scenario);}catch{}render();toast(scenario==='demo'?'Escenario demostrativo activado. Las operaciones están simuladas.':'Datos de los archivos activados.');}catch(e){toast('No se pudo abrir el escenario: '+e.message,true);}}

function openModal(html){const m=$('#modal');m.innerHTML=html;if(!m.open)m.showModal();}
const modalHead=(title,subtitle='')=>`<div class="modal-header"><div><h2 id="modal-title">${title}</h2>${subtitle?`<p>${subtitle}</p>`:''}</div><button type="button" class="icon-btn" data-action="close-modal" aria-label="Cerrar ventana">${icon('close')}</button></div>`;
function detail(collection,id){
  const row=database[collection].find(r=>r.id===id),schema=schemas[collection];if(!row)return;
  const keys=[...new Set([...schema.fields.map(f=>f.key),...schema.columns])];
  const o=row.origin;let related='';
  if(collection==='lots'){const sales=database.sales.filter(s=>!s.archived&&s.lot===id);related=`<div class="provenance"><strong>Salidas vinculadas</strong>${sales.length} salida(s) · ${number(sum(sales,'quantity'),2)} ${esc(row.measure)} de ${number(row.quantity,2)} recibidos.</div>`;}
  if(collection==='processes'){const m=processMetrics(database,row,{year:String(row.year),unit:row.unit});related=`<div class="provenance"><strong>Ejecución del proceso</strong>${number(m.delivered)} ${esc(row.measure)} · ${money(m.value)} · ${m.entries.length} entrega(s) vinculadas.</div>`;}
  openModal(modalHead(`Detalle de ${esc(schema.singular)}`,esc(row.id))+`<div class="modal-body"><dl class="detail-grid">${keys.map(key=>`<div><dt>${esc(schema.fields.find(f=>f.key===key)?.label||fieldLabels[key]||key)}</dt><dd>${formatCell(collection,key,row)}</dd></div>`).join('')}</dl>${related}<div class="provenance"><strong>Procedencia del registro</strong>${o?`${esc(o.file)}<br>Hoja: ${esc(o.sheet)}${o.row?' · Fila '+o.row:''}`:'Catálogo auxiliar de homologación'}${row.updatedAt?`<br>Última edición local: ${esc(new Date(row.updatedAt).toLocaleString('es-CO'))}`:''}</div></div><div class="modal-footer">${btn('close-modal','Cerrar')}${canEdit(state.profile,collection)&&!row.archived?`<button class="btn btn-primary" data-edit="${esc(id)}" data-collection="${collection}">${icon('edit')}Editar registro</button>`:''}</div>`);
}
function recordForm(collection,id=''){
  if(!canEdit(state.profile,collection))return toast('Este perfil solo permite consultar esta sección.',true);
  const schema=schemas[collection],existing=id?database[collection].find(r=>r.id===id):null;
  if(existing?.archived)return toast('Restaura el registro antes de editarlo.',true);
  const initial=existing||{id:collection==='animals'?'':`${({units:'UP',budgets:'PRE',receipts:'REC',lots:'LOT',sales:'SAL',processes:'AUTO',products:'PRO',expenses:'GAS',transformations:'TRA'})[collection]}-${Date.now().toString(36).toUpperCase()}`,unit:collection==='products'?'ud':'GAN',year:2026,date:'2026-04-30',measure:'L',method:'TRANSFERENCIA',group:'PRODUCCION',services:0,academicAmount:0};
  const fields=schema.fields.map(field=>{
    const value=initial[field.key]??'',required=field.required?'required':'',locked=field.key==='id'&&existing?'readonly':'',range=`${field.min!==undefined?'min="'+field.min+'"':''} ${field.max!==undefined?'max="'+field.max+'"':''}`;
    let input='';
    if(field.type==='select'){
      let options=field.ref?optionRows(field.ref,database):field.options.map(o=>Array.isArray(o)?o:[o,o]);
      if(value&&!options.some(([v])=>v===value))options=[[value,`${value} · valor original`],...options];
      input=`<select name="${field.key}" ${required}><option value="">Seleccionar…</option>${options.map(([v,label])=>`<option value="${esc(v)}" ${v===value?'selected':''}>${esc(label)}</option>`).join('')}</select>`;
    }else if(field.type==='textarea')input=`<textarea name="${field.key}" maxlength="2000">${esc(value)}</textarea>`;
    else input=`<input name="${field.key}" type="${field.type}" value="${esc(value)}" ${required} ${locked} ${range} ${field.type==='number'?`step="${field.step||'any'}"`:'maxlength="2000"'}>`;
    return `<label class="form-field ${field.type==='textarea'?'full':''}">${esc(field.label)}${field.required?' *':''}${input}${field.hint?`<small>${esc(field.hint)}</small>`:''}</label>`;
  }).join('');
  openModal(`<form id="record-form" method="dialog" data-collection="${collection}" data-existing="${esc(id)}">${modalHead((existing?'Editar ':'Crear ')+esc(schema.singular),'Los cambios se guardarán únicamente en el escenario activo.')}<div class="modal-body"><div class="form-grid">${fields}</div><div class="form-error" role="alert" id="form-error"></div></div><div class="modal-footer">${btn('close-modal','Cancelar')}<button type="submit" class="btn btn-primary">${icon('check')}Guardar registro</button></div></form>`);
}
function confirmDialog(title,text,action,extra='',danger=false){openModal(modalHead(title)+`<div class="modal-body"><p style="font-size:12px;line-height:1.9;color:#728278">${text}</p><div id="form-error" class="form-error" role="alert"></div></div><div class="modal-footer">${btn('close-modal','Cancelar')}${btn(action,'Confirmar',danger?'archive':'check',danger?'btn-danger':'btn-primary',extra)}</div>`);}
function archive(collection,id){
  if(!canEdit(state.profile,collection))return;
  const row=database[collection].find(r=>r.id===id);if(!row)return;
  const blocker=!row.archived?archiveBlocker(collection,row,database):'';
  if(blocker)return toast(blocker+' Archiva primero sus registros asociados.',true);
  confirmDialog(row.archived?'Restaurar registro':'Archivar registro',`${row.archived?'El registro volverá a estar activo.':'El registro dejará de participar en los indicadores y se conservará para consulta.'} Identificador: <strong>${esc(id)}</strong>.`,'confirm-archive',`data-collection="${collection}" data-id="${esc(id)}"`,!row.archived);
}
function exportBackup(){const blob=new Blob([JSON.stringify(database,null,2)],{type:'application/json'});const url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download=`CAB-${state.scenario}-${new Date().toISOString().slice(0,10)}.json`;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);toast('Respaldo del escenario preparado.');}
function help(){openModal(modalHead('Guía del prototipo','Producción CAB · Centro Agropecuario de Buga')+`<div class="modal-body"><div class="provenance" style="margin-top:0"><strong>1. Explora los dos tableros</strong>El central consolida presupuesto, recaudos y autoconsumos. «Explorar Ganadería» abre el desempeño técnico y conserva vigencia y fechas.</div><div class="provenance"><strong>2. Cambia el escenario</strong>«Datos de los archivos» contiene la base suministrada y sus vacíos. «Escenario demostrativo» incluye operaciones simuladas de abril de 2026 para explorar todos los componentes.</div><div class="provenance"><strong>3. Administra los registros</strong>Busca, abre el detalle, edita y archiva desde el menú. El perfil modifica las acciones disponibles como demostración; no es un control de acceso real.</div><div class="provenance"><strong>4. Conserva tus cambios</strong>Los datos se guardan en este navegador y dirección web. Exporta un JSON y restáuralo en «Catálogos y parámetros → Fuentes y respaldos». Los Excel originales no se modifican.</div><div class="provenance"><strong>Lectura de indicadores</strong>El presupuesto es anual. Los recaudos y salidas usan las fechas seleccionadas. El hato es una fotografía al ${dateLabel(database.meta.herdCutoff)}. Los datos sin vínculo verificable permanecen pendientes.</div></div><div class="modal-footer">${btn('close-modal','Entendido','','btn-primary')}</div>`);}

document.addEventListener('click',e=>{
  if(e.target.closest('.skip')){e.preventDefault();$('#main').focus();$('#main').scrollIntoView();return;}
  const el=e.target.closest('button,[data-action]');if(!el)return;
  if(el.dataset.route){navigate(el.dataset.route,el.dataset.tab||'');return;}
  if(el.dataset.tab){state.tab=el.dataset.tab;state.search='';state.page=1;state.status='active';render();return;}
  if(el.dataset.detail){detail(el.dataset.collection,el.dataset.detail);return;}
  if(el.dataset.edit){recordForm(el.dataset.collection,el.dataset.edit);return;}
  if(el.dataset.archive){archive(el.dataset.collection,el.dataset.archive);return;}
  if(el.dataset.derivative){state.derivative=el.dataset.derivative;render();return;}
  if(el.dataset.health){state.health=el.dataset.health;render();return;}
  const action=el.dataset.action;
  if(action==='menu'){state.menu=!state.menu;$('.shell').classList.toggle('menu-open',state.menu);return;}
  if(action==='close-modal'){$('#modal').close();return;}
  if(action==='technical'){state.techFilters={year:state.filters.year,from:state.filters.from,to:state.filters.to,unit:'GAN'};navigate('technical');return;}
  if(action==='back-central'){navigate('central');return;}
  if(action==='go-herd'){navigate('herd');return;}
  if(action==='go-transformations'){navigate('sales','transformations');return;}
  if(action==='go-expenses'){navigate('budget','expenses');return;}
  if(action==='scenario-demo'||action==='scenario-source'){switchScenario(action==='scenario-demo'?'demo':'source');return;}
  if(action==='reset-filters'){const f=state.route==='technical'?state.techFilters:state.filters;Object.assign(f,{year:'2026',from:'',to:'',product:'',channel:'',unit:state.route==='technical'?'GAN':''});render();return;}
  if(action==='process-money'||action==='process-physical'){state.processMode=action==='process-money'?'money':'physical';render();return;}
  if(action==='new-record'){recordForm(el.dataset.collection);return;}
  if(action==='page-prev'||action==='page-next'){state.page+=action==='page-next'?1:-1;render();return;}
  if(action==='help'){help();const link=document.createElement('a');link.className='btn';link.href='./output/pdf/Guia_de_uso_Produccion_CAB.pdf';link.download='Guia_de_uso_Produccion_CAB.pdf';link.textContent='Descargar guía PDF';$('#modal .modal-footer').prepend(link);return;}
  if(action==='export'){exportBackup();return;}
  if(action==='import'){if(canEdit(state.profile,'sources'))$('#backup-file').click();return;}
  if(action==='reset-data'){if(!canEdit(state.profile,'sources'))return;confirmDialog('Restaurar el escenario inicial','Se eliminarán las ediciones locales del escenario activo. El otro escenario y los Excel permanecerán intactos. Exporta un respaldo si necesitas conservar tus cambios.','confirm-reset','',true);return;}
  if(action==='confirm-reset'){if(!canEdit(state.profile,'sources'))return;try{database=repository.reset(state.scenario);$('#modal').close();state.page=1;render();toast('Escenario restaurado a sus datos iniciales.');}catch(error){toast(error.message,true);}return;}
  if(action==='confirm-archive'){
    const collection=el.dataset.collection,id=el.dataset.id;if(!canEdit(state.profile,collection))return;
    const next=clone(database),row=next[collection].find(r=>r.id===id);if(!row)return;
    try{
      if(row.archived){
        // Legacy incomplete records must remain recoverable after archiving.
        // Validate linked entities that actually exist without requiring repair of inherited gaps.
        for(const field of schemas[collection].fields.filter(f=>f.ref)){
          const target=next[field.ref]?.find(r=>r.id===row[field.key]);
          if(target?.archived)throw Error('Restaura primero los registros asociados.');
        }
        if(collection==='budgets'&&next.budgets.some(b=>!b.archived&&b.unit===row.unit&&b.year===row.year))throw Error('Ya hay una asignación activa para esa unidad y vigencia.');
      }
      else {const blocker=archiveBlocker(collection,row,next);if(blocker)throw Error(blocker);}
      row.archived=!row.archived;row.updatedAt=new Date().toISOString();
      if(commit(next,row.archived?'Registro archivado.':'Registro restaurado.'))$('#modal').close();
    }catch(error){$('#form-error').textContent=error.message;}
    return;
  }
  if(action==='confirm-import'){
    if(!canEdit(state.profile,'sources'))return;
    if(pendingImport&&commit(pendingImport,'Respaldo importado en el escenario activo.')){$('#modal').close();pendingImport=null;}
  }
});
document.addEventListener('change',e=>{
  const el=e.target;
  if(el.id==='scenario'){switchScenario(el.value);return;}
  if(el.id==='profile'){applyProfile(el.value);return;}
  if(el.dataset.filter){
    const f=state.route==='technical'?state.techFilters:state.filters,next={...f,[el.dataset.filter]:el.value};
    if(next.from&&next.to&&next.from>next.to){toast('La fecha inicial debe ser anterior a la final.',true);el.value=f[el.dataset.filter];return;}
    Object.assign(f,next);render();return;
  }
  if(el.id==='practice-toggle'){state.includePractice=el.checked;render();return;}
  if(el.id==='record-status'){state.status=el.value;state.page=1;render();}
});
document.addEventListener('input',e=>{
  if(e.target.id==='admin-search'){state.search=e.target.value;state.page=1;const pos=e.target.selectionStart;render();const input=$('#admin-search');input.focus();input.setSelectionRange(pos,pos);}
});
document.addEventListener('submit',e=>{
  // Named controls such as name="id" shadow HTMLFormElement.id.
  const formId=e.target.getAttribute('id');
  if(formId==='filters'){e.preventDefault();return;}
  if(formId==='record-form'){
    e.preventDefault();const form=e.target,collection=form.dataset.collection;if(!canEdit(state.profile,collection))return;
    const values={};for(const field of schemas[collection].fields){const value=new FormData(form).get(field.key);values[field.key]=field.type==='number'?(value===''?null:Number(value)):String(value??'').trim();}
    try{const existing=database[collection].find(r=>r.id===form.dataset.existing);const record=prepareRecord(collection,values,database,existing);const next=clone(database);if(existing)next[collection][next[collection].findIndex(r=>r.id===existing.id)]=record;else next[collection].unshift(record);if(commit(next))$('#modal').close();}catch(error){$('#form-error').textContent=error.message;}
    return;
  }
  if(formId==='parameters-form'){
    e.preventDefault();if(!canEdit(state.profile,'parameters'))return;const next=clone(database),form=new FormData(e.target);
    for(const key of Object.keys(next.parameters)){const value=Number(form.get(key));if(!Number.isFinite(value)||value<0||(['productiveTarget','costTarget'].includes(key)&&value>100)||key==='milkPrice'&&value<=0){toast('Revisa las metas y el precio de referencia.',true);return;}next.parameters[key]=value;}
    commit(next,'Parámetros actualizados.');
  }
});
let pendingImport=null;
$('#backup-file').addEventListener('change',async e=>{
  const file=e.target.files[0];e.target.value='';if(!file)return;
  if(file.size>8e6)return toast('El respaldo supera el máximo de 8 MB.',true);
  try{const data=JSON.parse(await file.text());validateBackup(data,state.scenario);pendingImport=data;confirmDialog('Importar respaldo',`Se reemplazarán los datos del escenario activo con <strong>${esc(file.name)}</strong>. Contiene ${data.receipts.length} recaudos, ${data.sales.length} salidas y ${data.animals.length} animales. Las referencias incompletas heredadas seguirán pendientes.`,'confirm-import');}catch(error){pendingImport=null;toast('No se importó el archivo. '+error.message,true);}
});
window.addEventListener('hashchange',()=>{const route=location.hash.slice(1);if(route!==state.route){state.route=route;state.tab='';state.search='';state.page=1;render();}});
document.addEventListener('keydown',e=>{if(e.key==='Escape'){state.menu=false;$('.shell')?.classList.remove('menu-open');$('#chart-tooltip').hidden=true;}});
function tooltip(el,x,y){const t=$('#chart-tooltip');t.textContent=el.dataset.tip;t.hidden=false;const bounds=t.getBoundingClientRect();t.style.left=Math.min(Math.max(8,x+12),innerWidth-bounds.width-12)+'px';t.style.top=Math.min(Math.max(8,y+12),innerHeight-bounds.height-12)+'px';}
document.addEventListener('pointermove',e=>{const el=e.target.closest('[data-tip]');if(el)tooltip(el,e.clientX,e.clientY);else $('#chart-tooltip').hidden=true;});
document.addEventListener('focusin',e=>{const el=e.target.closest('[data-tip]');if(el){const r=el.getBoundingClientRect();tooltip(el,r.left,r.top);}});
document.addEventListener('focusout',()=>$('#chart-tooltip').hidden=true);
try{
  sourceSeed=initialData;
  const persistence=createStorage(()=>window.localStorage);persistentStorage=persistence.persistent;
  repository=new Repository(persistence.storage,{source:sourceSeed,demo:makeDemo(sourceSeed)});
  try{state.scenario=localStorage.getItem('cab-scenario')==='demo'?'demo':'source';const profile=localStorage.getItem('cab-profile');if(profiles[profile])state.profile=profile;}catch{}
  state.includePractice=roleViews[state.profile].practice;state.health=roleViews[state.profile].health;
  if(!location.hash)state.route=roleViews[state.profile].home;
  try{database=repository.load(state.scenario);}catch(error){database=clone(repository.seeds[state.scenario]);setTimeout(()=>toast('El respaldo local no es compatible. Se muestra la base inicial; puedes restaurarla en Fuentes y respaldos.',true),400);}
  render();
}catch(error){$('#app').innerHTML=`<div class="boot">${icon('alert')}<h1>No se pudo abrir el tablero</h1><p>${esc(error.message)}</p><p>Abre el prototipo mediante MAMP o ejecuta «npm start» y visita http://127.0.0.1:4173.</p><button class="btn" onclick="location.reload()">Reintentar</button></div>`;}
