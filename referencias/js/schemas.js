const f = (key,label,type='text',extra={}) => ({key,label,type,...extra});
const id = f('id','Identificador','text',{required:true});
const unit = f('unit','Unidad productiva','select',{ref:'units',required:true});
const date = f('date','Fecha','date',{required:true});
const amount = f('amount','Valor total (COP)','number',{required:true,min:0});
const product = f('product','Producto','select',{ref:'products',required:true});
const measure = f('measure','Unidad de medida','select',{options:[['L','Litros'],['kg','Kilogramos'],['ud','Unidades'],['lb','Libras'],['cubeta','Cubeta 30 unidades']],required:true});
export const schemas={
  units:{title:'Unidades productivas',singular:'unidad productiva',fields:[id,f('name','Nombre','text',{required:true})],columns:['id','name'],icon:'units'},
  budgets:{title:'Asignaciones presupuestales',singular:'asignación',fields:[id,unit,f('year','Vigencia','number',{required:true,min:2020,max:2100,step:1}),amount,f('maintenance','Mantenimiento (COP)','number',{min:0}),f('feed','Alimentación y vacunas (COP)','number',{min:0}),f('academic','Materiales de formación (COP)','number',{min:0})],columns:['unit','year','amount','maintenance','feed','academic'],icon:'wallet'},
  receipts:{title:'Recaudos de venta directa',singular:'recaudo',fields:[id,unit,date,f('product','Producto / concepto','text',{required:true}),amount,f('gateway','Pasarela','select',{options:['Wompi','Ecollect','QR','Otro']}),f('invoice','Factura electrónica'),f('reference','Cupón / referencia')],columns:['date','unit','product','amount','gateway','invoice'],icon:'receipt'},
  lots:{title:'Producción y lotes',singular:'recepción de lote',fields:[id,f('code','Número interno del lote','text',{required:true}),unit,date,product,f('quantity','Cantidad recibida','number',{required:true,min:0.001}),measure,f('price','Precio sugerido (COP)','number',{min:0}),f('receiver','Responsable que recibe','text',{required:true})],columns:['code','date','unit','name','quantity','price'],icon:'box'},
  sales:{title:'Ventas y entregas',singular:'salida',fields:[id,f('lot','Lote y producto de origen','select',{ref:'lots',required:true,hint:'La unidad, el producto y la medida se toman de la recepción.'}),date,f('quantity','Cantidad entregada','number',{required:true,min:0.001}),f('price','Precio unitario (COP)','number',{required:true,min:0}),f('method','Canal','select',{options:[['TRANSFERENCIA','Venta directa'],['AUTOCONSUMO','Autoconsumo']],required:true}),f('process','Proceso de autoconsumo','select',{ref:'processes',hint:'Obligatorio para las nuevas entregas de autoconsumo.'}),f('destination','Destino / cliente'),f('invoice','Factura'),f('reference','Referencia de transacción')],columns:['date','lotCode','name','quantity','amount','method','process'],icon:'arrows'},
  processes:{title:'Procesos de autoconsumo',singular:'proceso',fields:[id,unit,f('destination','Unidad destino / cliente','text',{required:true}),f('product','Producto','text',{required:true}),f('quantity','Cupo físico aprobado','number',{required:true,min:0.001}),measure,amount,f('year','Vigencia','number',{required:true,min:2020,max:2100,step:1})],columns:['id','unit','destination','product','quantity','amount'],icon:'repeat'},
  animals:{title:'Inventario del hato',singular:'animal',fields:[id,f('group','Lote del hato','select',{options:[['PRODUCCION','Producción'],['HORRA','Horra'],['PRACTICA','Práctica académica']],required:true}),f('birth','Fecha de nacimiento','date'),f('previousCalving','Penúltimo parto','date'),f('lastCalving','Último parto','date'),f('iep','Intervalo entre partos (días)','number',{min:0}),f('services','Servicios posparto','number',{min:0,step:1}),f('lastService','Servicio efectivo','date'),f('palpation','Última palpación','date'),f('reproductive','Estado reproductivo','text',{required:true}),f('gestation','Edad de gestación'),f('openDays','Días abiertos','number',{min:0})],columns:['id','group','reproductive','lastCalving','iep','openDays'],icon:'cow'},
  products:{title:'Catálogo de productos',singular:'producto',fields:[id,f('name','Nombre del producto','text',{required:true}),f('unit','Medida','select',{...measure, key:'unit',label:'Medida',required:true}),f('price','Precio sugerido (COP)','number',{min:0}),f('milkFactor','Factor equivalente de leche (L/unidad)','number',{min:0,hint:'Solo para derivados. No se asigna a partir del nombre.'}),f('yieldTarget','Meta máxima de rendimiento (L/unidad)','number',{min:0})],columns:['id','name','unit','price','milkFactor','yieldTarget'],icon:'catalog'},
  expenses:{title:'Gastos operativos ejecutados',singular:'gasto',fields:[id,unit,date,f('category','Categoría','select',{options:['Mantenimiento','Alimentación y vacunas'],required:true}),amount,f('academicAmount','Costo de vacas de práctica incluido (COP)','number',{required:true,min:0,hint:'Se descuenta del total para IND_17. Debe estar sustentado.'}),f('description','Descripción','textarea')],columns:['date','unit','category','amount','academicAmount','description'],icon:'wallet'},
  transformations:{title:'Transformaciones lácteas',singular:'transformación',fields:[id,unit,date,f('inputSale','Entrega de leche de origen','select',{ref:'milkSales',required:true}),f('outputLot','Lote del derivado recibido','select',{ref:'derivativeLots',required:true}),f('liters','Litros de leche asignados','number',{required:true,min:0.001})],columns:['id','date','inputSale','outputLot','liters'],icon:'flask'}
};
export const modules={
  budget:{title:'Presupuesto y unidades',subtitle:'Asignaciones anuales, unidades productivas y gastos ejecutados.',tabs:['budgets','units','expenses']},
  receipts:{title:'Recaudos',subtitle:'Ingresos externos y referencias de las pasarelas de pago.',tabs:['receipts']},
  lots:{title:'Producción y lotes',subtitle:'Recepciones de productos y trazabilidad de las existencias.',tabs:['lots']},
  sales:{title:'Ventas y autoconsumos',subtitle:'Salidas, cupos aprobados y transformación de productos.',tabs:['sales','processes','transformations']},
  herd:{title:'Administración del hato',subtitle:'Inventario biológico y datos reproductivos del Hato El Milagroso.',tabs:['animals']},
  catalogs:{title:'Catálogos y parámetros',subtitle:'Productos, metas de los indicadores y respaldos del escenario.',tabs:['products','parameters','sources']}
};
export function canEdit(profile,collection){return profile==='admin' || (profile==='leader'&&['lots','sales','animals','expenses','transformations'].includes(collection)) || (profile==='academic'&&['animals','transformations'].includes(collection));}
export const roleViews={
  admin:{home:'central',practice:false,health:'all',title:'Control administrativo del centro',description:'Consolida el presupuesto, revisa el recaudo y administra los procesos de autoconsumo.',actions:[['budget','budgets','Gestionar presupuesto'],['receipts','receipts','Revisar recaudos'],['sales','processes','Gestionar procesos']]},
  leader:{home:'technical',practice:false,health:'alerts',title:'Operación de la unidad productiva',description:'Revisa las alertas del hato, registra producción y salidas, y controla los gastos ejecutados.',actions:[['lots','lots','Registrar producción'],['sales','sales','Gestionar salidas'],['budget','expenses','Registrar gastos']]},
  academic:{home:'technical',practice:true,health:'practice',title:'Formación y disponibilidad académica',description:'Consulta los animales de práctica, actualiza el hato y vincula las transformaciones del taller de lácteos.',actions:[['herd','animals','Gestionar hato'],['sales','transformations','Gestionar transformaciones'],['technical','','Ver disponibilidad']]}
};
export function preferredTab(profile,route){
  const tabs=modules[route]?.tabs||[];
  return tabs.find(tab=>canEdit(profile,tab))||tabs[0]||'';
}
export function moduleEditable(profile,route){return (modules[route]?.tabs||[]).some(tab=>canEdit(profile,tab));}
export function optionRows(ref,d){
  let rows=d[ref];
  if(ref==='milkSales')rows=d.sales.filter(s=>s.method==='AUTOCONSUMO'&&s.product==='5013170101'&&s.measure==='L');
  if(ref==='derivativeLots')rows=d.lots.filter(l=>d.products.some(p=>p.id===l.product&&p.milkFactor>0));
  return (rows||[]).filter(r=>!r.archived).map(r=>[r.id,r.name ? `${r.code||r.id} · ${r.name}` : r.code ? `${r.code} · ${r.date}` : r.id+(r.product?` · ${r.product}`:'')]);
}
export function prepareRecord(collection,values,d,existing){
  const schema=schemas[collection],record={...existing,...values,archived:existing?.archived||false};
  for(const field of schema.fields){
    const v=record[field.key];
    if(field.required&&(v===''||v===null||v===undefined))throw Error(`Completa el campo «${field.label}».`);
    if(field.type==='number'&&v!==null&&v!==undefined&&v!==''){
      if(!Number.isFinite(v)||(field.min!==undefined&&v<field.min)||(field.max!==undefined&&v>field.max)||(field.step===1&&!Number.isInteger(v)))throw Error(`Revisa el valor de «${field.label}».`);
    }
    if(field.type==='date'&&v&&(!/^\d{4}-\d{2}-\d{2}$/.test(v)||new Date(v).toISOString().slice(0,10)!==v))throw Error(`Revisa la fecha de «${field.label}».`);
    if(field.ref&&v&&!optionRows(field.ref,d).some(([id])=>id===v))throw Error(`Selecciona una referencia activa en «${field.label}».`);
    if(field.options&&v&&!field.options.some(o=>(Array.isArray(o)?o[0]:o)===v)&&v!==existing?.[field.key])throw Error(`Opción inválida en «${field.label}».`);
    if(typeof v==='string'&&v.length>2000)throw Error('El texto supera el máximo de 2.000 caracteres.');
  }
  if(d[collection].some(r=>r.id===record.id&&r.id!==existing?.id))throw Error('Este identificador ya existe, incluso entre los archivados.');
  if(collection==='budgets'){
    if((record.maintenance||0)+(record.feed||0)+(record.academic||0)>record.amount)throw Error('El desglose de gastos supera la asignación.');
    if(d.budgets.some(r=>!r.archived&&r.id!==existing?.id&&r.unit===record.unit&&r.year===record.year))throw Error('Ya existe una asignación activa para esa unidad y vigencia.');
  }
  if(collection==='lots'){
    const p=d.products.find(p=>p.id===record.product);record.name=p.name;
    if(record.measure!==p.unit)throw Error('La medida de la recepción debe coincidir con la del catálogo.');
    if(d.lots.some(l=>!l.archived&&l.id!==existing?.id&&l.code===record.code&&l.product===record.product))throw Error('El lote ya contiene una recepción de este producto.');
    if(existing&&d.sales.some(s=>!s.archived&&s.lot===existing.id)&&(record.product!==existing.product||record.measure!==existing.measure||record.unit!==existing.unit||record.code!==existing.code))throw Error('Este lote tiene salidas. Conserva su producto, unidad, medida y número interno.');
    const linked=d.sales.filter(s=>!s.archived&&s.lot===record.id);
    if(linked.reduce((n,s)=>n+s.quantity,0)>record.quantity+.000001)throw Error('La recepción no puede ser menor que las cantidades ya entregadas.');
    if(linked.some(s=>s.date<record.date))throw Error('La recepción no puede ser posterior a sus salidas.');
  }
  if(collection==='sales'){
    const lot=d.lots.find(l=>l.id===record.lot);record.lotCode=lot.code;record.product=lot.product;record.name=lot.name;record.unit=lot.unit;record.measure=lot.measure;record.amount=Math.round(record.quantity*record.price*100)/100;
    if(record.date<lot.date)throw Error('La salida no puede ser anterior a la recepción.');
    const other=d.sales.filter(s=>!s.archived&&s.lot===lot.id&&s.id!==existing?.id).reduce((n,s)=>n+s.quantity,0);
    if(other+record.quantity>lot.quantity+.000001)throw Error(`La entrega supera el saldo del lote (${Math.max(0,lot.quantity-other).toLocaleString('es-CO')} ${lot.measure}).`);
    if(record.method==='AUTOCONSUMO'){
      const process=d.processes.find(p=>p.id===record.process&&!p.archived);
      if(!process)throw Error('Vincula la entrega con un proceso de autoconsumo.');
      if(process.unit!==lot.unit||process.measure!==lot.measure||process.year!==Number(record.date.slice(0,4)))throw Error('El proceso debe coincidir con la unidad origen, medida y vigencia de la entrega.');
      const norm=v=>v.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim();
      if(norm(process.product)!==norm(lot.name)&&!(lot.product==='5013170101'&&norm(process.product)==='leche'))throw Error('El producto de la entrega no coincide con el del proceso.');
      record.destination=process.destination;
    }else record.process='';
    if(existing&&d.transformations.some(t=>!t.archived&&t.inputSale===existing.id)&&(record.method!=='AUTOCONSUMO'||record.product!=='5013170101'||record.measure!=='L'))throw Error('Esta entrega alimenta una transformación láctea. Conserva su origen de leche.');
    const transforms=d.transformations.filter(t=>!t.archived&&t.inputSale===record.id);
    if(transforms.reduce((n,t)=>n+t.liters,0)>record.quantity+.000001)throw Error('La cantidad es menor que la leche ya asignada a transformaciones.');
    if(transforms.some(t=>t.date<record.date))throw Error('La entrega no puede ser posterior a sus transformaciones.');
  }
  if(collection==='processes'&&existing&&d.sales.some(s=>!s.archived&&s.process===existing.id)&&['unit','measure','year','product'].some(k=>record[k]!==existing[k]))throw Error('El proceso tiene entregas. Conserva su unidad, medida, producto y vigencia.');
  if(collection==='products'&&existing&&record.unit!==existing.unit&&d.lots.some(l=>!l.archived&&l.product===existing.id))throw Error('El producto tiene recepciones. Conserva la medida registrada.');
  if(collection==='expenses'&&record.academicAmount>record.amount)throw Error('El costo académico no puede superar el gasto total.');
  if(collection==='animals'){
    for(const k of ['previousCalving','lastCalving','lastService','palpation'])if(record[k]&&record.birth&&record[k]<record.birth)throw Error('Los eventos reproductivos deben ser posteriores al nacimiento.');
    if(record.previousCalving&&record.lastCalving&&record.previousCalving>record.lastCalving)throw Error('El penúltimo parto debe ser anterior al último.');
  }
  if(collection==='transformations'){
    const input=d.sales.find(s=>s.id===record.inputSale),output=d.lots.find(l=>l.id===record.outputLot);
    if(record.unit!==input.unit)throw Error('La unidad debe coincidir con el origen de la entrega de leche.');
    if(record.date<input.date||output.date< input.date||record.date!==output.date)throw Error('La transformación debe tener la fecha de recepción del derivado y ser posterior a la entrega de leche.');
    if(d.transformations.some(t=>!t.archived&&t.id!==existing?.id&&t.outputLot===record.outputLot))throw Error('El lote del derivado ya está vinculado a una transformación.');
    const allocated=d.transformations.filter(t=>!t.archived&&t.inputSale===input.id&&t.id!==existing?.id).reduce((n,t)=>n+t.liters,0);
    if(allocated+record.liters>input.quantity+.00001)throw Error('Los litros asignados superan la entrega de leche disponible.');
  }
  if(!existing)record.origin={file:'Registro local',sheet:schemas[collection].title,row:null};
  record.updatedAt=new Date().toISOString();
  return record;
}
export function archiveBlocker(collection,row,d){
  const has=(name,field)=>d[name].some(r=>!r.archived&&r[field]===row.id);
  if(collection==='units'&&['budgets','receipts','lots','sales','processes','expenses','transformations'].some(n=>has(n,'unit')))return 'La unidad tiene registros activos asociados.';
  if(collection==='products'&&(has('lots','product')||has('sales','product')))return 'El producto tiene lotes o salidas activos.';
  if(collection==='lots'&&(has('sales','lot')||has('transformations','outputLot')))return 'El lote tiene salidas o transformaciones activas.';
  if(collection==='processes'&&has('sales','process'))return 'El proceso tiene entregas activas asociadas.';
  if(collection==='sales'&&has('transformations','inputSale'))return 'La entrega tiene transformaciones activas asociadas.';
  return '';
}
export function validateRecordShape(data){
  for(const [collection,schema] of Object.entries(schemas))for(const row of data[collection]){
    for(const field of schema.fields){
      const value=row[field.key];
      if(value===null||value===undefined||value===''){
        if(field.required&&!['lot','product','receiver','reproductive','academicAmount'].includes(field.key))throw Error(`Falta ${field.label} en ${collection}.`);
        continue;
      }
      if(field.type==='number'&&(typeof value!=='number'||!Number.isFinite(value)||value<0))throw Error(`Número inválido en ${collection}.`);
      if(field.type!=='number'&&typeof value!=='string')throw Error(`Texto inválido en ${collection}.`);
      if(field.type==='date'&&(!/^\d{4}-\d{2}-\d{2}$/.test(value)||Number.isNaN(Date.parse(value))||new Date(value).toISOString().slice(0,10)!==value))throw Error(`Fecha inválida en ${collection}.`);
    }
    if(collection==='sales'&&(!Number.isFinite(row.amount)||row.amount<0||!['TRANSFERENCIA','AUTOCONSUMO'].includes(row.method)))throw Error('Importe o canal inválido en las salidas.');
    if(collection==='expenses'&&row.academicAmount>row.amount)throw Error('El costo académico supera el gasto.');
  }
  const keys=['productiveTarget','milkPrice','costTarget','freshDays','animalDays'];
  for(const key of keys)if(!Number.isFinite(data.parameters[key])||data.parameters[key]<0)throw Error('Faltan parámetros válidos.');
  if(data.parameters.milkPrice<=0||data.parameters.productiveTarget>100||data.parameters.costTarget>100)throw Error('Revisa los porcentajes y el precio de referencia.');
}
