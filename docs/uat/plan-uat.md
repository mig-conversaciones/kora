# Plan de UAT del piloto de Ganadería (I8)

La prueba de aceptación (UAT) la hacen los **mismos perfiles que revisaron el prototipo**: coordinación administrativa y líder de la unidad de Ganadería (D-36), con el Grupo de Recaudo y la coordinación académica. Las observaciones 1 a 6 son criterios de aceptación. El resultado se registra en el acta de la sección final y es requisito del acta de salida a producción (anexo M.3).

## Condiciones

- **Entorno:** staging de Hostinger con la versión candidata. Si staging aún no existe, se hace una sesión preliminar en el equipo del proveedor y se repite en staging.
- **Datos:**
  - la carga inicial con los libros actualizados ([guía](../guias/carga-inicial.md));
  - para el entrenamiento previo, el escenario sintético de capacitación (RF-100), nunca mezclado con los datos reales.
- **Cuentas:** una por persona, invitada por el administrador funcional. No se comparten cuentas.
- **Duración:** dos sesiones de 2 horas: una por perfil administrativo y otra por perfil de unidad.
- **Evidencia:** captura de cada caso aceptado o con hallazgo, y la observación de quien prueba.

## Casos de las observaciones de la revisión

| Caso | Obs. | Perfil | Pasos | Resultado esperado |
|---|---|---|---|---|
| U-01 | 1 | Coordinación administrativa | Presupuesto y gastos → Gastos ejecutados → crear un gasto | La categoría «Materiales de formación» está en la lista; el gasto no cuenta en IND_17 |
| U-02 | 2 | Coordinación administrativa | Abrir Recaudos | Consulta los recaudos sin «Nuevo», editar ni archivar; `/recaudos/nuevo` responde 403 o 404 |
| U-03 | 3 | Coordinación administrativa | Importación de BBDD → Carga inicial con los libros actualizados | Carga guiada, cifras de control por fuente y acta (RF-094) |
| U-04 | 4 | Líder de Ganadería | Abrir el Tablero central | Solo aparece Ganadería en los indicadores, filtros y exportaciones |
| U-05 | 5 | Líder de Ganadería | Abrir Asignaciones, Unidades y Gastos | Solo se ven los registros de Ganadería; un enlace a otra unidad responde 404 |
| U-06 | 6 | Líder de Ganadería o coordinación académica | Transformación láctea → registrar una transformación | El desplegable ofrece queso, manjar blanco, cortado y yogur; se registra sin que exista antes la recepción del derivado |

## Casos de los flujos principales

| Caso | Perfil | Qué se prueba | Resultado esperado |
|---|---|---|---|
| U-07 | Todos | Activar la cuenta, ingresar, salir y recuperar la contraseña | Flujos completos con mensajes claros; la sesión vence a los 30 min |
| U-08 | Administración | Configurar la verificación en dos pasos e ingresar con el código | Obligatoria para administración; los códigos de respaldo funcionan una vez |
| U-09 | Coordinación administrativa | Asignación, gasto con soporte y cierre de periodo | El periodo cerrado no admite cambios; reabrirlo exige motivo |
| U-10 | Grupo de Recaudo | Importar recaudos de la DG, clasificar y conciliar | Sin datos personales en claro; la conciliación queda auditada |
| U-11 | Líder de Ganadería | Recepción, venta directa y entrega de autoconsumo | El saldo del lote baja; no deja vender más que el saldo |
| U-12 | Líder de Ganadería | Tablero técnico: IND_003 con y sin práctica, IND_08, IND_12 e IND_17 | Coinciden con los oráculos de 2.10 sobre la carga inicial |
| U-13 | Coordinación académica | Mover un animal al lote de práctica y ver IND_003 | El denominador cambia según el interruptor |
| U-14 | Cualquiera | Exportar desde un tablero | Llega la notificación y descarga solo quien lo pidió |
| U-15 | Cualquiera | Navegar solo con el teclado y con el zoom al 200 % | Todo es operable y legible; el foco siempre es visible |
| U-16 | Firmante | Firmar el acta de la carga inicial | Solo con la carga verificada; queda la firma con su huella |

## Criterios de aceptación

- Los casos U-01 a U-06 aceptados por los perfiles que hicieron cada observación.
- Ningún hallazgo **bloqueante** abierto, es decir, uno que impide una tarea del rol o da un resultado incorrecto.
- Los hallazgos **menores** tienen fecha de corrección acordada.

## Acta de UAT

| Caso | Probado por (rol) | Fecha | Resultado (aceptado / hallazgo) | Observación |
|---|---|---|---|---|
| U-01 | | | | |
| U-02 | | | | |
| U-03 | | | | |
| U-04 | | | | |
| U-05 | | | | |
| U-06 | | | | |
| U-07 a U-16 | | | | |

**Decisión:** ☐ Aceptada ☐ Aceptada con hallazgos menores ☐ No aceptada

| Firma | Nombre | Rol | Fecha |
|---|---|---|---|
| Contraparte | | | |
| Proveedor | | | |
