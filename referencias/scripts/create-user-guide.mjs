import PDFDocument from '../tmp/pdf-tools/node_modules/pdfkit/js/pdfkit.node.mjs';
import sharp from '../tmp/pdf-tools/node_modules/sharp/dist/index.cjs';
import mupdf from '../tmp/pdf-tools/node_modules/mupdf/dist/mupdf.js';
import { createWriteStream, readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { once } from 'node:events';

const out='output/pdf/Guia_de_uso_Produccion_CAB.pdf';
mkdirSync('output/pdf',{recursive:true});mkdirSync('tmp/pdfs/revision',{recursive:true});
const img={};
async function crop(name,file,left,top,width,height){img[name]=await sharp(`tmp/pdfs/capturas/${file}.png`).extract({left,top,width,height}).png().toBuffer();}
await crop('cover','rol-admin-v2',315,320,1550,475);
await crop('roles','roles',1640,125,245,115);
await crop('filters','rol-admin-v2',320,535,1545,265);
await crop('chart','autoconsumo',320,120,1540,440);
await crop('process','autoconsumo',320,580,1540,440);
await crop('herdTop','rol-lider-v2',320,635,1540,250);
await crop('biology','tecnico',320,280,760,405);
await crop('dairy','tecnico',1095,280,770,405);
await crop('cost','tecnico',1095,700,770,330);
await crop('rotation','tecnico',320,700,760,330);
await crop('inventory','hato',320,415,1540,400);
await crop('form','formulario',630,245,685,660);
await crop('backup','respaldos',320,480,1550,425);
await crop('backupButtons','respaldos',340,725,650,160);
await crop('adminView','rol-admin-v2',45,210,1820,590);
await crop('leaderView','rol-lider-v2',45,210,1820,675);
await crop('academicView','rol-academico-v2',320,330,1545,105);
await crop('practiceTable','practica-v2',320,550,1545,430);
await crop('tabModes','pestanas-v2',320,535,1545,440);
const doc=new PDFDocument({autoFirstPage:false,size:'A4',layout:'landscape',margins:{top:38,left:38,right:38,bottom:12},bufferPages:true,info:{Title:'Guía de uso del mockup Producción CAB',Author:'Centro Agropecuario de Buga',Subject:'Tableros, roles y administración local de la información',Language:'es-CO'}});
const stream=createWriteStream(out);doc.pipe(stream);
doc.registerFont('Body','C:/Windows/Fonts/segoeui.ttf');doc.registerFont('Bold','C:/Windows/Fonts/segoeuib.ttf');
const W=841.89,H=595.28,M=38,CW=W-M*2;
const colors={ink:'#183c32',text:'#43594e',muted:'#7c8b82',green:'#237445',pale:'#edf5ed',line:'#dce7df',amber:'#9c6c24'};
function text(t,x,y,w=CW,size=11,color=colors.text,bold=false){doc.font(bold?'Bold':'Body').fontSize(size).fillColor(color).text(t,x,y,{width:w,lineGap:3});}
function page(section,title,subtitle){doc.addPage();doc.rect(0,0,W,9).fill(colors.ink);text('PRODUCCIÓN CAB',M,25,300,9,colors.green,true);text(section.toUpperCase(),550,25,254,9,colors.muted);text(title,M,55,CW,26,colors.ink,true);if(subtitle)text(subtitle,M,96,CW,11);}
function image(name,x,y,w,h){doc.save();doc.roundedRect(x-1,y-1,w+2,h+2,7).fillAndStroke('#fff',colors.line);doc.image(img[name],x,y,{fit:[w,h],align:'center',valign:'center'});doc.restore();}
function caption(t,x,y,w=CW){text(t,x,y,w,8.5,colors.muted);}
function note(title,body,x,y,w=CW,h=63){doc.roundedRect(x,y,w,h,8).fill(colors.pale);text(title,x+13,y+10,w-26,10,colors.green,true);text(body,x+13,y+28,w-26,10);}
function step(n,title,body,x,y,w){doc.circle(x+10,y+10,10).fill(colors.green);text(String(n),x+6,y+2,18,10,'#fff',true);text(title,x+28,y,w-28,12,colors.ink,true);text(body,x+28,y+24,w-28,10.5);}

page('Guía de uso · versión 2 · noviembre de 2026','Administrar la producción del centro','Mockup funcional de Producción CAB · Centro Agropecuario de Buga · SENA Regional Valle · Código 9124');
image('cover',M,138,CW,235);
caption('Figura 1. Captura real del tablero central. Franja ámbar: escenario demostrativo con operaciones simuladas de abril de 2026.',M,382);
text('Versión actualizada con los espacios por rol: inicio, menú, accesos rápidos y prioridades. Incluye nuevas capturas reales de Administración, Líder de unidad y Coordinación académica. Las operaciones de la demo siguen identificadas como simuladas.',M,410,CW,11);
const contents=[['02','Roles y permisos'],['03–05','Espacio de trabajo de cada perfil'],['06','Gestión y consulta por pestaña'],['07–08','Tablero central y autoconsumo'],['09–10','Gestión técnica de Ganadería'],['11–12','Inventario y edición de registros'],['13','Respaldos y recuperación'],['14','Inicio y solución de dudas']];
contents.forEach(([n,label],i)=>{const x=M+(i%2)*390,y=467+Math.floor(i/2)*21;text(n,x,y,48,11,colors.green,true);text(label,x+55,y,325,9.5);});

page('Perfiles de uso','Elegir el rol adecuado','El selector cambia la vista inicial, el menú, los accesos rápidos y las prioridades, además de la edición.');
text('Coordinación administrativa: control presupuestal, recaudo y administración general. Líder de unidad productiva: operación, lotes y desempeño técnico. Coordinación académica: disponibilidad didáctica y articulación con las prácticas.',M,135,520,11);
image('roles',610,128,194,92);caption('Figura 2. Selector real de los tres perfiles.',610,227,194);
text('Al cambiar de perfil se abre su tablero principal y se aplican sus filtros iniciales. El escenario y los registros se conservan; las nuevas vistas no representan bases de datos separadas por usuario.',M,201,520,10);
const rows=[['Consultar tableros y detalles','Sí','Sí','Sí'],['Exportar respaldo JSON','Sí','Sí','Sí'],['Presupuesto, unidades y recaudos','Editar','Consultar','Consultar'],['Producción y lotes','Editar','Editar','Consultar'],['Ventas y procesos de autoconsumo','Editar','Ventas: editar / procesos: consultar','Consultar'],['Inventario del hato','Editar','Editar','Editar'],['Gastos ejecutados','Editar','Editar','Consultar'],['Transformaciones lácteas','Editar','Editar','Editar'],['Catálogos, metas, importar y restaurar','Editar / administrar','Consultar','Consultar']];
const widths=[280,140,190,156],heads=['Acción o módulo','Administración','Líder de unidad','Coord. académica'];let ty=260;
function tableRow(vals,y,header=false){let x=M;vals.forEach((v,i)=>{doc.rect(x,y,widths[i],25).fill(header?colors.ink:(Math.round((y-285)/25)%2===0?'#f2f6f2':'#fff'));text(v,x+8,y+6,widths[i]-16,8.6,header?'#fff':colors.text,header);x+=widths[i];});}
tableRow(heads,ty,true);rows.forEach((r,i)=>tableRow(r,ty+25+i*25));
caption('Editar incluye crear, modificar y archivar cuando corresponde. Los permisos son de demostración: no hay autenticación ni control de acceso de servidor. Todos los perfiles pueden consultar las demás secciones.',M,526);

page('Espacio administrativo','Coordinación administrativa','Al seleccionar este perfil se abre automáticamente el Tablero central.');
image('adminView',M,130,CW,251);
caption('Captura actualizada A. Menú de Administración y panel “Control administrativo del centro”. Escenario demostrativo.',M,390);
step(1,'Inicio financiero','Revisa presupuesto, recaudo externo, ventas por autoconsumo, cobertura y saldo comercial desde el central.',M,424,245);
step(2,'Accesos administrativos','“Gestionar presupuesto”, “Revisar recaudos” y “Gestionar procesos” abren directamente las secciones correspondientes.',M+260,424,245);
step(3,'Administración completa','Este perfil mantiene edición de los módulos, catálogos y metas; también importa respaldos y restaura el escenario.',M+520,424,245);
caption('Puedes abrir Ganadería para consultar el detalle técnico. Al volver a seleccionar Administración desde otro perfil, regresas al Tablero central. Los datos del escenario no se modifican por cambiar el rol.',M,529);

page('Espacio operativo','Líder de unidad productiva','Al seleccionar este perfil se abre Ganadería, con práctica académica excluida y el filtro de alertas seleccionado.');
image('leaderView',M,130,CW,283);
caption('Captura actualizada B. Menú “Mi gestión”, sección “Consulta del centro” y accesos operativos del Líder.',M,421);
step(1,'Producción y salidas','Usa “Registrar producción” y “Gestionar salidas” para abrir los listados con sus acciones de creación y edición.',M,453,245);
step(2,'Gastos de la unidad','El acceso “Registrar gastos” y la opción del menú abren la pestaña Gastos operativos ejecutados, no el presupuesto anual.',M+260,453,245);
step(3,'Alertas y consulta','La tabla reproductiva inicia en “Con alertas”. Recaudos y catálogos siguen disponibles en la sección de consulta.',M+520,453,245);

page('Espacio académico','Coordinación académica','Al seleccionar este perfil se abre Ganadería con práctica incluida, disponibilidad didáctica primero y tabla prioritaria.');
image('academicView',M,130,CW,52);image('practiceTable',M,206,CW,213);
caption('Capturas actualizadas C y D. Accesos académicos y tabla prioritaria con los cuatro animales de práctica.',M,427);
step(1,'Mi gestión','El menú prioriza Hato y Transformaciones lácteas. Sus accesos abren las secciones editables del perfil.',M,456,245);
step(2,'Práctica incluida','El interruptor inicia activado: la tasa usa 21/35 = 60 %. La tabla inicia con los cuatro animales de práctica, antes de los gráficos.',M+260,456,245);
step(3,'Edición y consulta','Puedes editar hato y transformaciones; los demás módulos quedan en consulta. El interruptor y los filtros se pueden ajustar.',M+520,456,245);

page('Gestión y consulta','Distinguir los permisos de cada pestaña','El menú orienta el trabajo; las etiquetas “Edición” y “Consulta” precisan las acciones disponibles.');
image('tabModes',M,134,CW,218);
caption('Captura actualizada E. Coordinación académica abre Transformaciones lácteas en Edición; Ventas y entregas y Procesos de autoconsumo quedan en Consulta.',M,362);
step(1,'Mi gestión','Agrupa los módulos con alguna pestaña editable para tu perfil. En módulos mixtos, el acceso del menú abre primero la pestaña editable.',M,411,245);
step(2,'Consulta del centro','Permite revisar las demás secciones. En consulta no aparecen Nuevo registro, editar ni archivar; el detalle sigue disponible.',M+260,411,245);
step(3,'Cambia de perfil','Usa el selector superior. Se reinician las prioridades del rol y se abre su tablero; cambiar de escenario sigue siendo una acción independiente.',M+520,411,245);
caption('Ejemplos: Líder > Gastos de la unidad abre Gastos; Coordinación académica > Transformaciones lácteas abre Transformaciones. La separación es de interfaz y no sustituye una autorización de servidor.',M,532);

page('Tablero central','Consultar presupuesto y ventas','Perfil principal: Coordinación administrativa. Los otros perfiles también pueden consultar esta vista.');
image('filters',M,137,CW,132);caption('Figura 3. Filtros y seis indicadores del tablero central, con cifras demostrativas.',M,277);
step(1,'Selecciona el contexto','Elige vigencia, fechas, unidad productiva, producto y canal. El botón de restablecer limpia los filtros.',M,314,245);
step(2,'Lee las seis tarjetas','Revisa asignación, recaudo externo, autoconsumo, total realizado, cobertura y saldo por cubrir.',M+260,314,245);
step(3,'Explora la unidad','Pulsa “Explorar Ganadería”. El acceso conserva la vigencia y las fechas de la consulta central.',M+520,314,245);
note('Cómo interpretar los importes','El presupuesto permanece anual al filtrar meses. El total realizado suma recaudo externo y autoconsumo; las transferencias registradas no vuelven a sumarse. El saldo mostrado es comercial, no saldo de caja ni ejecución CDP/RP.',M,447,CW,80);

page('Comparación y autoconsumo','Comparar resultados y revisar entregas','IND_001 compara asignación y ventas. IND_002 sigue los cupos de los procesos de autoconsumo.');
image('chart',M,134,460,132);image('process',M,293,460,132);
caption('Figuras 4 y 5. Comparativo y fragmento real de la ejecución por proceso. Escenario demostrativo.',M,436,460);
text('Asignación frente a ventas',523,139,280,14,colors.ink,true);text('Azul/gris: presupuesto. Verde: recaudo de venta directa. Ámbar: transferencia interna. Pasa sobre las barras para ver valores y desglose presupuestal.',523,167,280,11);
text('Cupos y entregas',523,257,280,14,colors.ink,true);text('Alterna “Pesos COP” y “Unidades físicas”. Cada proceso conserva su medida. La tabla inferior muestra origen, destino, cupo, entregado, saldo, avance y lotes.',523,285,280,11);
note('Si falta un vínculo','Una entrega sin ID de proceso puede sumar autoconsumo en el central, pero no se asigna automáticamente a una meta. Revisa Ventas y autoconsumos antes de interpretar el avance.',M,477,CW,65);

page('Unidad de Ganadería','Separar producción y práctica académica','Perfiles principales: Líder de unidad productiva y Coordinación académica.');
image('herdTop',M,135,CW,124);caption('Figura 6. Interruptor académico e indicadores de Ganadería. Datos demostrativos de costo; hato de la base suministrada.',M,267);
image('biology',M,309,363,193);
text('Uso del interruptor',425,315,378,15,colors.ink,true);text('Sin práctica: la tasa productiva usa 21 de 31 hembras (67,7 %). Con práctica: usa 21 de 35 (60 %). La meta inicial es 75 %. El anillo conserva siempre el inventario completo: 21 en producción, 10 horras y 4 de práctica.',425,346,378,11);
text('Para la coordinación académica',425,437,378,13,colors.ink,true);text('Consulta disponibilidad de animales y usa el filtro “Práctica académica” en la tabla reproductiva. El hato es una fotografía al 9 de noviembre de 2026; las fechas del tablero no reconstruyen su historia.',425,462,378,10.5);
caption('Figura 7. Composición real del hato y referencia completa del denominador.',M,514,363);

page('Eficiencia de la unidad','Revisar transformación, rotación y costo','Los valores de esta página corresponden al escenario demostrativo.');
image('dairy',M,134,369,194);image('cost',435,134,369,158);
text('IND_08 · Transformación láctea',M,346,369,13,colors.ink,true);text('Selecciona Global, Queso cuajada, Yogurt, Manjar blanco o Cortado. Global usa equivalentes de leche; cada derivado usa L/kg o L/L. Requiere entrega de leche, lote recibido y factor de conversión vinculados.',M,373,369,10.5);
text('IND_17 · Costo por litro',435,313,369,13,colors.ink,true);text('Compara costo operativo y precio sugerido. Se excluye el costo asignado a vacas de práctica. La meta inicial es un costo máximo del 75 % del precio. Los $125 millones de la fuente son presupuesto, no gasto ejecutado.',435,340,369,10.5);
note('IND_12 · Rotación de lotes','Revisa la tarjeta “Rotación de lotes terminados”, debajo de los gráficos. Solo los lotes agotados entran al promedio. Un lote abierto muestra antigüedad, no rotación final. Referencias iniciales: 15 días para lácteos frescos y 30 para bovinos.',M,466,CW,77);
caption('Figuras 8 y 9. Capturas reales del rendimiento global y del costo operativo en el escenario demo.',M,443);

page('Administración de registros','Buscar, consultar y archivar','Ejemplo de uso en el inventario del hato. Los controles se repiten en los demás módulos según el rol.');
image('inventory',M,135,CW,199);caption('Figura 10. Lista real del hato, búsqueda, estado y acciones por registro.',M,343);
step(1,'Busca y filtra','Escribe un identificador o texto. Usa Activos, Archivados o Todos los estados. La paginación está al final de la tabla.',M,380,245);
step(2,'Abre el detalle','El icono de ojo muestra campos y procedencia: archivo, hoja y fila. El lápiz abre la edición cuando el perfil lo permite.',M+260,380,245);
step(3,'Archiva o restaura','Archivar conserva el registro y lo retira de los cálculos. Filtra Archivados y usa restaurar. Las dependencias activas pueden bloquear el archivo.',M+520,380,245);
caption('Semáforos del hato: IEP ideal hasta 365 días, aceptable 366–400 y alerta por encima de 400. Días abiertos: bueno 91–120, regular 121–135 y alerta desde 136. Los valores inferiores a 91 no tienen umbral definido en la guía.',M,511);

page('Crear y editar','Guardar información con trazabilidad','Roles con edición del hato: Administración, Líder de unidad y Coordinación académica.');
image('form',430,133,374,361);caption('Figura 11. Formulario real del animal 158. La captura se hizo sin guardar cambios.',430,503,374);
step(1,'Abre el formulario','Pulsa “Nuevo registro” o el icono de lápiz de una fila. El identificador queda fijo al editar.',M,143,355);
step(2,'Completa y valida','Revisa los campos con asterisco. En lotes y ventas se validan referencias, medida, fechas y saldo disponible. Una entrega de autoconsumo necesita un proceso compatible.',M,237,355);
step(3,'Guarda o cancela','“Guardar registro” confirma el cambio local. Si hay un error, corrige el mensaje del formulario. “Cancelar” o Escape cierran sin guardar.',M,359,355);
note('Después de guardar','Al volver a un tablero, los indicadores usan los registros activos del escenario. Los cambios no se escriben en los Excel originales.',M,463,355,77);

page('Conservación de la información','Exportar, importar y restaurar','Administración puede importar y restaurar. Todos los perfiles pueden exportar el respaldo del escenario.');
image('backup',M,135,CW,210);caption('Figura 12. Pantalla real Catálogos y parámetros > Fuentes y respaldos.',M,354);
step(1,'Exporta un respaldo','Pulsa “Exportar JSON” o “Exportar respaldo”. Guarda el archivo con el escenario completo y sus ediciones.',M,392,245);
step(2,'Importa en el mismo escenario','Elige Datos de los archivos o Demo antes de importar. Selecciona un JSON compatible y confirma la sustitución.',M+260,392,245);
step(3,'Restaura con cuidado','“Restaurar datos iniciales” elimina las ediciones locales del escenario activo. Exporta antes si necesitas conservarlas.',M+520,392,245);
caption('Los escenarios tienen almacenamientos independientes. Cambiar de escenario no mezcla sus registros. No hay sincronización entre computadores; otro navegador, dirección o puerto tiene su propio almacenamiento.',M,515);

page('Primer acceso y dudas frecuentes','Empezar y resolver situaciones habituales','Aplicación local para validar la experiencia de administración antes de una implementación centralizada.');
text('Abrir el mockup',M,139,360,15,colors.ink,true);
text('Con MAMP: inicia Apache y visita http://localhost/dashboard_laila/. Si tu instalación usa otro puerto, inclúyelo en la dirección.\n\nAlternativa con Node.js: abre una terminal en la carpeta del proyecto, ejecuta npm.cmd start y visita http://127.0.0.1:4173/. No requiere instalar paquetes para usar el mockup.',M,171,360,11);
text('Recorrido por rol',M,326,360,15,colors.ink,true);
text('Administración: central > presupuesto y recaudos > procesos > respaldo.\n\nLíder de unidad: Ganadería > producción y lotes > ventas > gastos y transformaciones.\n\nCoordinación académica: Ganadería > práctica académica > hato > transformaciones.',M,357,360,11);
const faq=[['No aparece “Nuevo registro”','Comprueba el perfil. Si el módulo es de consulta, la acción de crear o editar no se ofrece.'],['Un indicador está pendiente o vacío','Revisa vigencia, fechas y escenario. En las fuentes faltan vínculos de lotes, procesos, transformación y gastos ejecutados. No se inventan esos resultados.'],['No veo mis cambios en otro equipo','El guardado es local al navegador y a la dirección web. Exporta el JSON y cárgalo en el mismo escenario del otro navegador.'],['No puedo archivar una entidad','Puede tener movimientos asociados activos. Consulta el mensaje y resuelve las dependencias antes de archivarla.']];
faq.forEach(([q,a],i)=>{const y=139+i*92;text(q,435,y,369,12,colors.ink,true);text(a,435,y+25,369,10.5);});
caption('Alcance: perfiles simulados, sin inicio de sesión, usuarios concurrentes ni persistencia de servidor. Referencias de contenido: Guia_Tableros_Mando.docx, README.md y permisos implementados en js/schemas.js. Capturas del mockup local, tomadas el 16 de noviembre de 2026.',M,524);

const total=doc.bufferedPageRange().count;
for(let i=0;i<total;i++){doc.switchToPage(i);doc.moveTo(M,560).lineTo(W-M,560).strokeColor(colors.line).lineWidth(.5).stroke();text('SENA · Centro Agropecuario de Buga | Guía del mockup',M,568,650,8,colors.muted);text(`${String(i+1).padStart(2,'0')} / ${total}`,745,568,60,8,colors.muted);}
doc.end();await once(stream,'finish');
const pdf=mupdf.Document.openDocument(readFileSync(out),'application/pdf');
for(let i=0;i<pdf.countPages();i++){const page=pdf.loadPage(i);writeFileSync(`tmp/pdfs/revision/pagina-${String(i+1).padStart(2,'0')}.png`,page.toPixmap([1.4,0,0,1.4,0,0],mupdf.ColorSpace.DeviceRGB,false,true).asPNG());}
console.log(`PDF creado y renderizado: ${out} (${pdf.countPages()} páginas).`);
