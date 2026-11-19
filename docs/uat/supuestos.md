# Registro de supuestos (SRS 2.7) al cierre de la I8

El anexo M.3 exige, antes de la salida a producción, que cada supuesto esté **validado** o tenga su **decisión registrada**. Este registro dice cómo quedó cada uno en el código y qué falta confirmar. Si un supuesto resulta falso, se actualizan sus requisitos con control de cambios (RGO-006).

Estados:

- **Decidido:** hay una decisión registrada (ADR o acta).
- **Implementado, por validar:** el sistema ya lo aplica; falta la confirmación de la persona indicada.
- **Pendiente:** depende de un tercero y bloquea algo.

| ID | Supuesto (resumen) | Cómo quedó en KORA | Estado | Valida |
|---|---|---|---|---|
| S-01 | Nombre «Producción CAB» | La aplicación se llama **KORA**; «Producción CAB» es el nombre del proyecto (ADR-014) | Decidido | Contraparte |
| S-02 | Sidebar y matriz de layout | Barra lateral con grupos de RF-002 y tableros según D-18 | Implementado, por validar en la UAT | Contraparte |
| S-03 | Paleta del prototipo, sin logotipo del SENA | Identidad visual propia de KORA (ADR-016), sin logotipo del SENA | Decidido; falta la autorización de Comunicaciones para usar el logotipo, si se quiere | Comunicaciones SENA |
| S-04 | Siete roles | Siete roles con la matriz de 3.10 (`bd/semillas/001`) | Implementado, por validar en la UAT | Contraparte |
| S-05 | Solo el Grupo de Recaudo registra recaudos | Coordinación administrativa solo consulta (prueba E2E de la Obs. 2) | Implementado, por validar en la UAT | Coordinación administrativa |
| S-06 | Hostinger Cloud con MariaDB 10.6 o superior | La CI prueba en MariaDB 10.6; falta `SELECT VERSION()` en la cuenta | Pendiente | Titular de la cuenta |
| S-07 | Perfil de carga de 2.6 | Dataset sintético y scripts k6 con ese perfil (I7) | Pendiente: bloquea la validación de RNF-PERF | Contraparte |
| S-08 | Objetivos de rendimiento de 2.6 | Medidos en local (indicativos); sin k6 en staging | Pendiente | Contraparte |
| S-09 | Presupuesto síncrono de 1.500 ms en p95 | p95 de servidor por debajo de 175 ms en local; falta staging | Implementado, por validar en staging | Equipo técnico |
| S-10 | Staging y autorización de carga | Sin staging ni autorización escrita | **Pendiente: bloquea k6 y ZAP en staging** | Titular de la cuenta |
| S-11 | IND_17 con el gasto ejecutado | Así se calcula (ADR-012); el costo presupuestado se muestra como referencia | Implementado, por validar en la UAT | Líder de Ganadería, Coordinación administrativa |
| S-12 | Factores de equivalencia iniciales | Configurables por la coordinación académica en el catálogo | Implementado, por validar con el taller de lácteos | Coordinación académica |
| S-13 | Componente académico explícito | Campo propio en el gasto; sin prorrateo automático | Implementado, por validar en la UAT | Coordinación administrativa |
| S-14 | «Materiales de formación» fuera de IND_17 | Categoría propia; no entra en el numerador de IND_17 | Implementado, por validar en la UAT | Contraparte |
| S-15 | Equipo y tarifas | Plan del capítulo 5 | Decidido en el plan | Proveedor |
| S-16 | Calendario | Plan del capítulo 5 | Decidido en el plan | Contraparte y proveedor |
| S-17 | Retención de 5 años y TRD | Sin borrado dentro del plazo; archivado lógico | Pendiente: confirmar la TRD | Gestión documental SENA |
| S-18 | Columna «PrimerApellido» del archivo de la DG | No se almacena (RF-034) | Implementado, por validar | Grupo de Recaudo |
| S-19 | Un solo centro en la v1, modelo multicentro | Todas las consultas filtran por centro; probado con un segundo centro | Decidido | Contraparte |
| S-20 | «PoD» = protección desde el diseño | Aplicado en RS-023 | Decidido | Proveedor |
| S-21 | SMTP de Hostinger y sus límites | Cola de correo con cortacircuitos; límites sin verificar | Pendiente | Titular de la cuenta |
| S-22 | Dominio o subdominio con Let's Encrypt | Aviso de vencimiento a 15 días; dominio sin definir | Pendiente | Contraparte |
| S-23 | Correo institucional por usuario | Invitación, recuperación y avisos por correo | Implementado, por validar con la lista de usuarios del piloto | Contraparte |
| S-24 | Centro de datos con menor latencia | Sin medir | Pendiente | Equipo técnico |
| S-25 | No se migra el `localStorage` del prototipo | Carga inicial guiada con los Excel y acta (RF-094, RDL-011) | Decidido | Contraparte |

**Pendientes que bloquean la salida a producción:** S-06, S-10, S-21 y S-22, porque sin cuenta, staging, SMTP y dominio no hay despliegue. S-07 y S-08 bloquean la aceptación del informe de rendimiento (anexo M.3).
