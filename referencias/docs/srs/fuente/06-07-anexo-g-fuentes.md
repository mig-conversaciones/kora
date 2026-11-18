## Anexo G. Mapeo de las fuentes Excel a las tablas

Las reglas de normalización provienen del extractor del prototipo (`scripts/prepare-data.mjs`), validado con las cifras de 2.10:

- **Unidades.** `UP_Ganaderia`, `Ganaderia`, `up_Agricola`, etc. se convierten a un código, sin tildes, en minúsculas y sin el prefijo `up_`. Lo que no es reconocible va a `SIN`.
- **Medidas.** «Litros» y «Litro» se convierten a L; «Kilos» y «Kilogramo», a kg; «Libra», a lb; «Unidad» y «Unidades», a ud; «Cubeta 30u», a cubeta.
- **Fechas.** Los seriales de Excel se convierten tomando como época el 1899-12-30.
- **Textos.** Se recortan y se normalizan a NFC.

Las columnas sin destino se descartan y se documentan.

### G.1 `BBDD_DATOS ADMON.xlsx`

| Hoja (fuente) | Columna | Destino | Regla |
|---|---|---|---|
| Ingresos - Gastos (presupuesto). Encabezado en la fila 2; vigencia elegida al cargar y validada contra el título de la fila 1 | A ID (`UP_*`) | `asignaciones_presupuestales.unidad_id` | Mapa de unidades; solo las filas que empiezan por `UP_` |
| | B a G (fechas, regional, dependencia) | Resumen del lote | Se valida que la dependencia sea el centro 9124 |
| | H Valor Total Proyecto | `valor_total` | Número ≥ 0 |
| | I Mantenimiento maquinaria y equipo | `mantenimiento` | Número ≥ 0 |
| | J Alimentación de semovientes | `alimentacion` | Número ≥ 0 |
| | K Materiales de formación | `materiales_formacion` | Número ≥ 0; desglose ≤ total |
| | L Número de semovientes | `numero_semovientes` | Entero ≥ 0 |
| | M Presupuesto requerido, N Estado | Resumen del lote | Informativo |
| Ingresos_Centro… (recaudos de la DG). Periodo en la fila 2; encabezado en la fila 3 | A Regional, B Código, C CentroFormacion | Validación | B debe ser 9124 |
| | D Identificación | `identificacion_hmac` y `identificacion_ultimos4` | HMAC-SHA256 y los 4 últimos dígitos; nunca en claro (RF-034) |
| | E PrimerNombre | **No se almacena** | Minimización de datos personales |
| | F PrimerApellido | **No se almacena** | Contiene valores con forma de referencia (S-18, PENDIENTE DE VALIDACIÓN) |
| | G Valor Pago | `valor` | Número ≥ 0 |
| | H CupónPago | `cupon_pago` | Forma parte de la clave natural |
| | I Fecha | `fecha_pago` | Serial de Excel a DATE |
| | J Producto | `concepto_fuente` y `producto_id` | Homologación explícita; un genérico queda como concepto fuente |
| | K Unidad productiva | `unidad_fuente` y `unidad_id` | Mapa de unidades. Si es ambigua («Ganadería y Granja»), estado de clasificación `ambiguo` |
| | L Prefactura | `prefactura` | Conciliación (RF-032) |
| | M TipoIngreso | `tipo_ingreso` | Solo «Producción» cuenta en IND_001 |
| | N OrigenPago | `pasarela` | Wompi, Ecollect o QR |
| | O Asignado | Resumen del lote | Significado PENDIENTE DE VALIDACIÓN con el Grupo de Recaudo |
| | P NroFacElec | `factura_electronica` | Conciliación |
| Autoconsumo_2026 (cupos). Encabezado en la fila 1; vigencia según el nombre de la hoja, confirmada al cargar | A ID_proceso | `procesos_autoconsumo.codigo` | Patrón `AUTO_\d+` |
| | B UP_Origen | `unidad_id` | Mapa de unidades |
| | C UP_Destino y/o Cliente | `destino_id` o `destino_texto` | Catálogo de destinos internos |
| | D Producto | `producto_texto` y `producto_id` | Homologación («Leche» a 5013170101) |
| | E Cant_Autoconsumo | `cupo_cantidad` | Mayor que 0 |
| | F Unidad de medida | `medida` | Normalizada |
| | G Valor | `cupo_valor` | Número ≥ 0 |

### G.2 `BBDD_Produccion de Centro.xlsx`

| Hoja (fuente) | Columna | Destino | Regla |
|---|---|---|---|
| CatalogoProductos_CAB. Encabezado en la fila 2 | A Nombre | `productos.nombre` | Recortado |
| | B Codigo SGAC | `codigo_sgac` | Clave natural |
| | C Unidad Productiva | `unidad_sugerida_id` | Mapa de unidades (opcional) |
| | D, E y F Precios mínimo, máximo y de venta | `precio_minimo`, `precio_maximo`, `precio_venta` | Números ≥ 0 |
| | G Medida | `medida` | Normalizada |
| | H Clase, I CodigoFamilia, J NombreFamilia | `clase`, `codigo_familia`, `nombre_familia` | La familia «Animales vivos» se convierte en `categoria_rotacion = animal` |
| Num_Interno y Recibe (hoja oculta) | Prefijos y responsables | `unidades_productivas.prefijo_lote`; `responsable_texto` | Los responsables se vuelven usuarios del sistema (RF-014) |
| Base Produccion Centro (recepciones). Encabezado en la fila 1 | A Num_Interno | `recepciones_lote.numero_interno` | Formato `{PREFIJO}-REC-{DDMMAA}-{NNN}`; los no conformes generan una advertencia |
| | B Fecha Recepción | `fecha_recepcion` | Serial de Excel a DATE |
| | C Nombre Proveedor | `unidad_id` | Mapa de unidades |
| | D Nombre Producto, E Código SGARC | `producto_id` | Por código SGAC; el nombre se contrasta con el catálogo |
| | F Cantidad Recibida, G Unidad de Medida | `cantidad`, `medida` | Mayor que 0; la medida debe coincidir con la del catálogo (RF-039) |
| | H Precio de Venta Sugerido | `precio_sugerido` | Número ≥ 0 |
| | I Usuario que recibe | `responsable_texto` | Procedencia histórica |
| Base Ventas (salidas). Encabezado en la fila 1 | A Num_Interno_Compra | `numero_interno_texto` y `recepcion_id` | Vínculo único por número interno y código; si no existe, `recepcion_id` es NULL y se crea una incidencia (RF-045). La unidad sale del prefijo |
| | B No. Factura Didáctica | `factura` | Conciliación |
| | C Fecha Venta | `fecha` | Serial de Excel a DATE |
| | D Código SGARC, E Nombre del Producto | `producto_id`, `nombre_producto_fuente` | Si falta el código, se toma el de la recepción vinculada |
| | F Unidad de Medida, G Cantidad Vendida | `medida`, `cantidad` | Normalizada; mayor que 0 |
| | H Precio Unitario Venta | `precio_unitario` | Número ≥ 0 |
| | I Subtotal | `valor` | Se verifica contra cantidad × precio; una diferencia genera una incidencia |
| | J Método de Pago | `canal` | AUTOCONSUMO se mantiene; cualquier otro valor es VENTA_DIRECTA |
| | K Origen | `proceso_id` o metadato | Con el patrón `AUTO_\d+`, es el proceso; si no, es el origen del pago (por ejemplo, NEQUI) |
| | L Código Transacción | `referencia_transaccion` | «NO APLICA» se convierte en NULL |
| | M ID Cliente | `destino` | Si parece una identificación personal, se guarda en HMAC y no en claro |
| | N Columna1 | Se descarta | Sin contenido útil |

### G.3 `INFO_Hato.xlsx`

| Hoja (fuente) | Columna | Destino | Regla |
|---|---|---|---|
| ESTADO REP. Encabezado en la fila 2; fecha de corte = columna D, que debe ser única en el archivo, o la fecha elegida al cargar | A VACA | `animales.identificador` | Clave por unidad |
| | B LOTE | `registros_reproductivos.lote_hato` | VACA PRODUCCION a PRODUCCION; VACA HORRA a HORRA; VACA PRACTICA o PRACTICA a PRACTICA |
| | C Fecha de nacimiento | `animales.fecha_nacimiento` | Serial de Excel a DATE |
| | D Fecha actual | `cortes_hato.fecha_corte` | Corte del 9-nov-2026 en la base fuente |
| | E, F y G Días, meses y años | Se descartan | Son derivados |
| | H Penúltimo parto, I Último parto | `penultimo_parto`, `ultimo_parto` | El penúltimo debe ser anterior al último (RF-055) |
| | J IEP | `iep_importado` | Se contrasta con `iep_dias` calculado, ±1 día (RF-054) |
| | K # Servicios posparto | `servicios_posparto` | Entero ≥ 0 |
| | L Fecha servicio efectivo, M Última palpación | `servicio_efectivo`, `ultima_palpacion` | Serial de Excel a DATE |
| | N Estado reproductivo | `estado_reproductivo` | Normalización Unicode (PREÑADA, VACÍA…) |
| | O Edad gestación/días | `edad_gestacion` | Texto |
| | P Días abiertos | `dias_abiertos_importado` | Se contrasta con `dias_abiertos` calculado |

### G.4 Plantilla del sistema: gastos ejecutados

| Columna | Destino | Regla |
|---|---|---|
| Unidad (código) | `gastos_ejecutados.unidad_id` | Dentro del alcance de quien importa |
| Fecha | `fecha` | Debe estar en un periodo abierto |
| Categoría (MANTENIMIENTO, ALIMENTACION o MATERIALES_FORMACION) | `categoria_id` | Lista desplegable de la plantilla |
| Valor | `valor` | Número ≥ 0 |
| Componente académico | `valor_componente_academico` | Menor o igual que el valor |
| Descripción, Referencia del soporte | `descripcion`, `referencia_soporte` | Hasta 2.000 y 120 caracteres |
