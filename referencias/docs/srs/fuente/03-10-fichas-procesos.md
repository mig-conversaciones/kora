## 3.18 Fichas técnicas de procesos

Cada ficha documenta un proceso crítico con el formato obligatorio de RDOC-001. Las fichas se trasladan a `docs/procesos/FP-NN-nombre.md` en la iteración 0 y se mantienen actualizadas con cada cambio.

### FP-01 Autenticación con segundo factor

<!-- anchos: 22,78 -->
| Campo | Detalle |
|---|---|
| Propósito | Verificar la identidad del usuario y abrir una sesión segura con el nivel de autenticación que exige su rol. |
| Alcance | `/ingresar`, `/2fa`, `/salir`, enrolamiento de 2FA y regeneración de la sesión. No incluye la recuperación (FP de la cuenta, RAC-006). |
| Actores | Todos los usuarios. Superadministrador y Administrador con 2FA obligatoria. |
| Entradas | Correo y contraseña; código TOTP o código de respaldo; token CSRF. |
| Salidas | Sesión `__Host-pcab_sesion` con nivel `contrasena` o `2fa`; redirección a la vista inicial del rol (RF-001). |
| Precondiciones | Cuenta activa e invitación completada. Si el rol exige 2FA y no está configurada, se fuerza el enrolamiento. |
| Postcondiciones | ID de sesión regenerado, registro en `sesiones`, contador de fallos reiniciado. |
| Flujo principal | 1) GET del formulario con CSRF. 2) POST con las credenciales; verificación con Argon2id en tiempo homogéneo. 3) Si exige 2FA, se crea una sesión parcial y se pide el código. 4) El TOTP se verifica con ventana de ±1 y anti-repetición. 5) Se regenera el ID y se fija el nivel `2fa`. 6) Redirección a la vista inicial. |
| Flujos alternos | Código de respaldo en lugar del TOTP; enrolamiento forzado; contraseña que requiere un nuevo hash (`password_needs_rehash`). |
| Reglas de negocio | Mensajes genéricos (RAC-005); expiración por inactividad de 30 o 15 min y absoluta de 12 u 8 h (RAC-019). |
| Errores esperados | 401 genérico ante credenciales inválidas; 429 con `Retry-After` por límite (RAC-029, RAC-013); 403 por cuenta desactivada (mensaje genérico); 503 en mantenimiento salvo la ruta exceptuada. |
| Controles de seguridad | Argon2id, CSRF, cookie `__Host-`, regeneración del ID, límites de frecuencia, verificación de Origin y auditoría. |
| Eventos de auditoría | `sesion.iniciar` (éxito o fallo), `sesion.2fa` (éxito o fallo), `sesion.regenerar`, `sesion.bloqueo_temporal`, `sesion.cerrar`. |
| Pruebas | PI-RAC-001, PI-RAC-005, PE-RAC-001, PI-RAC-012, PI-RAC-013, PS-RAC-018. |
| Archivos afectados | `AutenticacionControlador.php`, `ServicioAutenticacion.php`, `ServicioTotp.php`, `ManejadorSesionBd.php`, `LimitadorTasa.php`, `vistas/autenticacion/*.php`. |

### FP-02 Importación asistida de una fuente Excel

<!-- anchos: 22,78 -->
| Campo | Detalle |
|---|---|
| Propósito | Cargar las BBDD Excel actualizadas (Obs. 3) de forma verificable, con vista previa, sin duplicar y con posibilidad de revertir. |
| Alcance | Las 8 fuentes de RF-083, desde la carga hasta la aplicación y, si se requiere, la reversión. |
| Actores | Según la fuente (RF-092): Grupo de Recaudo, Coordinación administrativa, Líder de producción de centro, Líder de unidad y Coordinación académica. |
| Entradas | Archivo `.xlsx`, fuentes seleccionadas, clave de idempotencia y CSRF. |
| Salidas | Lote de importación con estado, resumen, errores por fila, registros creados o actualizados con procedencia e incidencias de calidad. |
| Precondiciones | Permiso de importación de la fuente; periodo abierto (RF-028); sin mantenimiento activo. |
| Postcondiciones | Lote en estado `aplicado`, versión de datos incrementada (caché invalidada) y copia previa registrada. |
| Flujo principal | 1) Carga del archivo (RF-084): almacenamiento privado, hash y lote `cargado`, con respuesta 202. 2) Trabajo `importacion.validar`: lectura en *streaming*, mapeo, normalización, reglas y filas de prueba en `filas_importacion`, hasta el estado `validado` o `con_errores`. 3) Vista previa (RF-087). 4) Confirmación con CSRF. 5) Trabajo `importacion.aplicar` con copia previa, bloques con punto de control y procedencia, hasta `aplicado`. 6) Trabajo `calidad.recalcular`. 7) Notificación al usuario. |
| Flujos alternos | Archivo ya aplicado: se informa y se enlaza el lote (RF-089). Aplicación parcial de las filas válidas si la fuente lo permite. Reversión (RF-090). Reintento tras un fallo transitorio desde el punto de control. |
| Reglas de negocio | Claves naturales por fuente (RF-089); filas fuera de alcance como error (RF-092); los conceptos genéricos no se homologan automáticamente. |
| Errores esperados | 413 por tamaño; 422 por formato o firma inválidos; errores por fila en el reporte; fallo definitivo en `trabajos_fallidos` con un mensaje sanitizado. |
| Controles de seguridad | Validación de firma y bomba ZIP (RS-011), autorización por fuente y unidad, reautenticación para revertir, sin ejecución de contenido. |
| Eventos de auditoría | `importacion.cargar`, `importacion.validar`, `importacion.aplicar`, `importacion.revertir`, `importacion.descargar_original`. |
| Pruebas | PI-RF-084 a PI-RF-093, PE-RF-083, PE-RF-087, PI-RF-Q-011, PCA-RNF-PERF-004. |
| Archivos afectados | `ImportacionesControlador.php`, `ServicioImportacion.php`, `NormalizadorFuentes.php`, `ValidadoresFuente/*`, `Trabajos/ValidarImportacion.php`, `Trabajos/AplicarImportacion.php`, `Trabajos/RevertirImportacion.php`. |

### FP-03 Clasificación y conciliación de recaudos

<!-- anchos: 22,78 -->
| Campo | Detalle |
|---|---|
| Propósito | Asignar la unidad y el producto a los recaudos de la DG que llegan sin ellos, y conciliarlos con las ventas registradas. |
| Alcance | Bandeja de clasificación, sugerencias, clasificación individual o en lote y conciliación con salidas. |
| Actores | Grupo de Recaudo (gestión). Coordinación administrativa (consulta, Obs. 2). |
| Entradas | Recaudos pendientes, reglas de homologación y salidas de venta directa con prefactura o factura. |
| Salidas | Recaudos clasificados o conciliados, informe de diferencias e IND_001 actualizado. |
| Precondiciones | Recaudos importados (FP-02); periodo abierto. |
| Postcondiciones | Estado de clasificación y conciliación actualizado; versión de datos incrementada. |
| Flujo principal | 1) Filtrar la bandeja. 2) Revisar las sugerencias. 3) Confirmar la unidad y el producto (uno o en lote, con idempotencia). 4) Buscar la venta por prefactura, factura o referencia. 5) Vincular; si los valores difieren, el estado pasa a «con diferencia». |
| Flujos alternos | Recaudo ambiguo, como «Ganadería y Granja»: queda sin distribuir con una nota. Desvincular una conciliación, con motivo. |
| Reglas de negocio | Sin prorrateo automático (RF-031); los recaudos y las ventas no se suman (RF-065); sin datos personales en claro (RF-034). |
| Errores esperados | 403 para quien no es del Grupo de Recaudo; 409 por periodo cerrado o por versión concurrente; 422 por unidad o producto inválidos. |
| Controles de seguridad | Política de recaudos, CSRF, idempotencia, alcance de centro y enmascaramiento de la identificación. |
| Eventos de auditoría | `recaudo.clasificar`, `recaudo.conciliar`, `recaudo.desconciliar`, `recaudo.alta_manual`. |
| Pruebas | PI-RF-029, PE-RF-029, PI-RF-031, PE-RF-031, PI-RF-032, PS-RF-034. |
| Archivos afectados | `ClasificacionRecaudosControlador.php`, `ServicioClasificacion.php`, `ServicioConciliacion.php`, `PoliticaRecaudos.php`. |

### FP-04 Registro de recepción de lote

<!-- anchos: 22,78 -->
| Campo | Detalle |
|---|---|
| Propósito | Ingresar a inventario un lote de producto terminado con su número interno trazable. |
| Alcance | Altas, ediciones, archivo y restauración de recepciones. |
| Actores | Líder de unidad (propias) y Líder de producción de centro. |
| Entradas | Unidad, fecha, producto, cantidad, precio sugerido, responsable y número interno (sugerido). |
| Salidas | Recepción con saldo inicial igual a la cantidad. |
| Precondiciones | Producto activo con medida; periodo abierto; unidad en el alcance. |
| Postcondiciones | Existencias disponibles para salidas y transformaciones; versión de datos incrementada. |
| Flujo principal | 1) Abrir el formulario (fragmento modal). 2) Sugerir el número interno `{PREFIJO}-REC-{DDMMAA}-{NNN}`. 3) Validar (RF-039 y RF-040). 4) Guardar en una transacción con `version` y auditoría. 5) Toast de éxito con `HX-Trigger` y actualización del listado. |
| Flujos alternos | Edición con salidas: se restringen los campos (RF-040). Archivo bloqueado si tiene salidas activas. |
| Reglas de negocio | Medida igual a la del catálogo; número interno único por producto; cantidad mayor o igual a lo entregado. |
| Errores esperados | 422 con mensajes por campo; 409 por conflicto de versión o consecutivo duplicado (se reintenta con el siguiente). |
| Controles de seguridad | Alcance de unidad, CSRF, lista de campos permitidos e idempotencia. |
| Eventos de auditoría | `recepcion.crear`, `recepcion.editar`, `recepcion.archivar`, `recepcion.restaurar`. |
| Pruebas | PU-RF-039, PU-RF-040, PI-RF-039, PE-RF-039. |
| Archivos afectados | `RecepcionesControlador.php`, `ReglasRecepcion.php`, `RepositorioRecepciones.php`, `vistas/recepciones/*`. |

### FP-05 Registro de salida (venta directa o autoconsumo)

<!-- anchos: 22,78 -->
| Campo | Detalle |
|---|---|
| Propósito | Registrar la salida de producto de un lote como venta directa o como entrega de autoconsumo vinculada a un proceso. |
| Alcance | Altas, ediciones, archivo y vinculación posterior de salidas heredadas. |
| Actores | Líder de unidad (propias) y Líder de producción de centro. |
| Entradas | Lote, fecha, cantidad, precio unitario, canal, proceso (si es autoconsumo), destino, factura y referencia. |
| Salidas | Salida con su valor calculado, existencias y avance del proceso actualizados. |
| Precondiciones | Lote con saldo; periodo abierto; proceso coherente si es autoconsumo. |
| Postcondiciones | Saldo del lote reducido; IND_001, IND_002 e IND_12 actualizados. |
| Flujo principal | 1) Elegir el lote; se heredan la unidad, el producto y la medida. 2) Bloquear la fila del lote (`FOR UPDATE`) y calcular el saldo. 3) Validar (RF-043). 4) Guardar en una transacción. 5) Incrementar la versión de datos. |
| Flujos alternos | Venta directa: no genera recaudo (RF-044). Salida que alimenta transformaciones: campos restringidos (RF-046). |
| Reglas de negocio | Cantidad no mayor que el saldo; fecha no anterior a la recepción; coherencia del proceso (unidad, medida, vigencia, producto); valor = cantidad × precio. |
| Errores esperados | 422 con mensajes del prototipo; 409 por conflicto de concurrencia. |
| Controles de seguridad | Alcance, CSRF, idempotencia y bloqueo de fila. |
| Eventos de auditoría | `salida.crear`, `salida.editar`, `salida.archivar`, `salida.vincular`. |
| Pruebas | PU-RF-043, PI-RF-043, PE-RF-043, PI-RNF-009 (concurrencia). |
| Archivos afectados | `SalidasControlador.php`, `ReglasSalida.php`, `RepositorioSalidas.php`. |

### FP-06 Registro de transformación láctea

<!-- anchos: 22,78 -->
| Campo | Detalle |
|---|---|
| Propósito | Vincular la leche entregada por autoconsumo con el derivado obtenido para calcular IND_08 (Obs. 6). |
| Alcance | Selección de la entrega de leche y del producto derivado, y vinculación o creación de la recepción del derivado. |
| Actores | Líder de unidad (origen propio) y Coordinación académica (unidades asignadas). |
| Entradas | Entrega de leche, producto derivado, litros asignados, fecha y datos de la recepción del derivado (existente o nueva). |
| Salidas | Transformación y, si aplica, recepción del derivado creada en la misma transacción. |
| Precondiciones | Entregas de leche con litros disponibles; derivados con factor configurado (RF-018); periodo abierto. |
| Postcondiciones | IND_08 recalculable con vínculo verificable. |
| Flujo principal | 1) Abrir el formulario; si faltan derivados o entregas, se muestra el estado vacío con la acción (RF-050). 2) Elegir la entrega, que muestra sus litros disponibles. 3) Elegir el derivado (4 en la semilla). 4) Elegir una recepción existente o crear una. 5) Validar (RF-049). 6) Guardar ambas en una sola transacción. |
| Flujos alternos | Recepción existente ya vinculada: error. Edición: se mantienen los vínculos. |
| Reglas de negocio | Litros acumulados no mayores que la entrega; fecha de la transformación igual a la de la recepción del derivado y no anterior a la entrega; una recepción por transformación. |
| Errores esperados | 422 con mensajes del prototipo; reversión de la transacción completa ante cualquier error. |
| Controles de seguridad | Política de transformaciones (visibilidad cruzada, RDI-007), CSRF e idempotencia. |
| Eventos de auditoría | `transformacion.crear` (con `recepcion.crear` vinculada), `transformacion.editar`, `transformacion.archivar`. |
| Pruebas | PI-RF-047, PE-RF-047, PI-RF-048, PU-RF-049, PE-RF-050. |
| Archivos afectados | `TransformacionesControlador.php`, `ServicioTransformaciones.php`, `ReglasTransformacion.php`, `vistas/transformaciones/formulario.php`. |

### FP-07 Actualización del hato por corte

<!-- anchos: 22,78 -->
| Campo | Detalle |
|---|---|
| Propósito | Registrar una fotografía fechada del inventario y del estado reproductivo para IND_003 y el panel de salud. |
| Alcance | Importación de «ESTADO REP» o edición individual por animal dentro de un corte. |
| Actores | Líder de la unidad pecuaria; Coordinación académica (lote de práctica). |
| Entradas | Fecha de corte y, por animal: lote, fechas de partos, servicios, palpación, estado y gestación. |
| Salidas | Corte con sus registros, IEP y días abiertos calculados e incidencias si hay diferencias. |
| Precondiciones | Unidad pecuaria en el alcance. |
| Postcondiciones | El tablero técnico usa el último corte. |
| Flujo principal | 1) Crear el corte (importación o manual). 2) Normalizar el lote y el estado. 3) Calcular IEP y días abiertos. 4) Validar las fechas (RF-055). 5) Guardar. 6) Marcar el corte como vigente. |
| Flujos alternos | Corrección de un registro en el corte vigente (auditada). Asignación o retiro de práctica por la Coordinación académica. |
| Reglas de negocio | Semáforos (RF-056); el corte no reconstruye la historia (RF-053). |
| Errores esperados | 422 por fechas inconsistentes; incidencia de calidad si el IEP importado difiere del calculado. |
| Controles de seguridad | Política del hato (campos por rol, RF-057), CSRF y alcance. |
| Eventos de auditoría | `hato.corte_crear`, `hato.registro_editar`, `hato.practica_asignar`, `hato.practica_retirar`. |
| Pruebas | PU-RF-054, PU-RF-056, PI-RF-053, PE-RF-057. |
| Archivos afectados | `AnimalesControlador.php`, `ServicioHato.php`, `ReglasHato.php`, `PoliticaHato.php`. |

### FP-08 Consulta de tableros con caché

<!-- anchos: 22,78 -->
| Campo | Detalle |
|---|---|
| Propósito | Calcular y presentar los indicadores dentro de los SLO, sin mezclar alcances. |
| Alcance | Tablero central y tablero técnico, en página completa y en fragmentos de filtros. |
| Actores | Todos los roles con acceso a los tableros (matriz 3.10). |
| Entradas | Filtros (vigencia, fechas, unidad, producto, canal), interruptor de práctica y alcance de la sesión. |
| Salidas | HTML con KPI, gráficos SVG, tablas y avisos; cabeceras `Vary` y `no-store`. |
| Precondiciones | Sesión válida; filtros validados. |
| Postcondiciones | Entrada de caché por clave de alcance, filtros y versión. |
| Flujo principal | 1) Validar los filtros y construir el `AlcanceDatos`. 2) Formar la clave de caché (centro, hash del alcance, filtros, versión de datos y versión del cálculo). 3) Si hay acierto, se responde. 4) Si no, se toma el bloqueo breve, se calcula con consultas agregadas por índices, se guarda con TTL y variación y se responde. 5) Se registran la duración y el número de consultas. |
| Flujos alternos | Bloqueo ocupado: se sirve el valor vencido si existe o se espera hasta 2 s. Caché caída: se calcula directamente con un límite de concurrencia. |
| Reglas de negocio | Fórmulas RF-065 a RF-077; presupuesto anual; sin sumar medidas distintas. |
| Errores esperados | 422 por filtros inválidos; fragmento de error controlado ante un fallo de cálculo (RUI-015). |
| Controles de seguridad | Alcance obligatorio (RDI-003), aislamiento de la caché (RDI-008) y sin datos fuera de alcance en el HTML. |
| Eventos de auditoría | No aplica a la lectura de tableros; el registro técnico guarda el acceso con su `request_id`. |
| Pruebas | PU-RF-065, PI-RF-066, PE-RF-072, PI-RNF-PERF-012, PCA-RNF-PERF-003. |
| Archivos afectados | `TableroCentralControlador.php`, `TableroUnidadControlador.php`, `ServicioIndicadores.php`, `Cache.php`, `Grafico*Svg.php`. |

### FP-09 Exportación asíncrona

<!-- anchos: 22,78 -->
| Campo | Detalle |
|---|---|
| Propósito | Entregar los datos filtrados en XLSX, CSV o PDF sin bloquear la solicitud. |
| Alcance | Listados y tableros. |
| Actores | Cualquier rol, dentro de su alcance. |
| Entradas | Tipo, formato, filtros y clave de idempotencia. |
| Salidas | Archivo privado con vigencia de 24 h y notificación. |
| Precondiciones | Permiso de consulta del recurso; reautenticación si contiene datos sensibles. |
| Postcondiciones | Registro en `exportaciones` y trabajo completado. |
| Flujo principal | 1) POST que responde 202 (RF-Q-002). 2) Fragmento de seguimiento con consulta periódica. 3) El trabajo genera el archivo en *streaming* con el alcance del actor. 4) Estado completado con el enlace de descarga. 5) Descarga autorizada (RF-Q-013). |
| Flujos alternos | Duplicado: se devuelve el mismo trabajo (RF-Q-003). Fallo transitorio: reintento. Vencimiento: limpieza. |
| Reglas de negocio | Sin identificación de pagadores; mismas cifras que en pantalla (RF-081). |
| Errores esperados | 429 por límite de exportaciones; 404 por enlace ajeno o vencido. |
| Controles de seguridad | Alcance, autorización en la descarga, `no-store` y `Content-Disposition`. |
| Eventos de auditoría | `exportacion.solicitar`, `exportacion.descargar`. |
| Pruebas | PI-RF-080, PE-RF-080, PI-RF-Q-012, PI-RF-Q-013, PS-RF-082. |
| Archivos afectados | `ExportacionesControlador.php`, `Trabajos/ExportarXlsx.php`, `Trabajos/ExportarPdf.php`, `ControladorTrabajos.php`. |

### FP-10 Activación y desactivación del modo mantenimiento

<!-- anchos: 22,78 -->
| Campo | Detalle |
|---|---|
| Propósito | Cerrar de forma controlada el servicio de negocio durante cambios de riesgo y reabrirlo con verificaciones. |
| Alcance | Panel Sistema → Mantenimiento, intermediario, señal mínima, CLI de recuperación, trabajador y aviso público. |
| Actores | Superadministrador (o permiso delegado documentado) y operador con SSH (recuperación). |
| Entradas | Motivo interno, título y mensaje públicos, estimación opcional, CSRF y reautenticación con 2FA. |
| Salidas | Estado persistido (base de datos y señal), 503 para los no exceptuados y pausa de los trabajos de negocio. |
| Precondiciones | Permiso; reautenticación reciente. |
| Postcondiciones | Activo: drenaje en curso. Desactivado: comprobaciones superadas y cola reanudada gradualmente. |
| Flujo principal | 1) Abrir el panel. 2) Configurar el mensaje. 3) Confirmar en un modal con reautenticación. 4) Guardar con versión optimista y escribir la señal de forma atómica. 5) El intermediario responde 503. 6) El trabajador pausa. 7) El panel muestra el drenaje. 8) Al desactivar: comprobaciones (RF-MAN-016), borrado de la señal y reanudación gradual. |
| Flujos alternos | Base de datos caída: la CLI opera sobre la señal (RF-MAN-012). Comprobación fallida: el modo se mantiene con el detalle. |
| Reglas de negocio | Alcance global; sin omisiones; rutas exceptuadas mínimas; 503 con `Retry-After` y `no-store`. |
| Errores esperados | 403 sin permiso o sin CSRF; 409 por versión concurrente; 503 del propio modo. |
| Controles de seguridad | Reautenticación con 2FA, CSRF, auditoría, señal fuera de `public` y escritura atómica. |
| Eventos de auditoría | `mantenimiento.activar`, `mantenimiento.desactivar`, `mantenimiento.configurar`, `mantenimiento.acceso_excepcional`, `mantenimiento.intento_no_autorizado`. |
| Pruebas | PI-RF-MAN-001 a PI-RF-MAN-017, PE-RF-MAN-006, PO-RF-MAN-012, PA-RF-MAN-018. |
| Archivos afectados | `MantenimientoControlador.php`, `ServicioMantenimiento.php`, `Intermediarios/Mantenimiento.php`, `cli/mantenimiento.php`, `public/index.php`, `vistas/errores/mantenimiento.php`. |

### FP-11 Registro de gasto ejecutado

<!-- anchos: 22,78 -->
| Campo | Detalle |
|---|---|
| Propósito | Registrar los gastos reales de la unidad para la ejecución por línea e IND_17 (incluye la categoría Materiales de formación de la Obs. 1). |
| Alcance | Altas, ediciones, archivo y soporte documental opcional. |
| Actores | Líder de unidad (propias) y Coordinación administrativa. |
| Entradas | Unidad, fecha, categoría, valor, componente académico, descripción, referencia del soporte y archivo opcional. |
| Salidas | Gasto registrado; ejecución por línea e IND_17 actualizados. |
| Precondiciones | Categoría activa; periodo abierto; unidad en el alcance. |
| Postcondiciones | Versión de datos incrementada. |
| Flujo principal | 1) Formulario con las tres categorías (RF-020). 2) Validar que el componente académico no supere el valor. 3) Guardar con auditoría. 4) Toast de éxito y actualización del listado filtrado por alcance. |
| Flujos alternos | Adjuntar soporte (RF-027). Periodo cerrado: 409. |
| Reglas de negocio | Materiales de formación cuenta para su línea presupuestal y no para IND_17 (S-14). |
| Errores esperados | 422 por campos; 403 por unidad fuera del alcance; 409 por periodo cerrado o versión. |
| Controles de seguridad | Alcance, CSRF, idempotencia y validación del archivo. |
| Eventos de auditoría | `gasto.crear`, `gasto.editar`, `gasto.archivar`, `gasto.adjuntar`. |
| Pruebas | PI-RF-020, PE-RF-020, PU-RF-025, PI-RF-025, PE-RF-025, PU-RF-077. |
| Archivos afectados | `GastosControlador.php`, `ReglasGasto.php`, `RepositorioGastos.php`, `vistas/gastos/*`. |
