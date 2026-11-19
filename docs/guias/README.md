# Guías de KORA (RDOC-009)

Las guías de este directorio son a la vez la **ayuda en línea** de la aplicación (menú **Ayuda**, ruta `/ayuda`) y la fuente de las **guías en PDF** que se entregan en la capacitación. Hay una sola fuente: no se editan PDF ni documentos aparte.

| Guía | Para quién | Archivo |
|---|---|---|
| Primeros pasos en KORA | Todos los usuarios | [primeros-pasos.md](primeros-pasos.md) |
| Coordinación administrativa | Presupuesto, gastos, procesos, cierres y carga inicial | [usuario-coordinacion-administrativa.md](usuario-coordinacion-administrativa.md) |
| Grupo de Recaudo | Recaudos de la DG, clasificación y conciliación | [usuario-grupo-recaudo.md](usuario-grupo-recaudo.md) |
| Líder de producción de centro | Producción, salidas, calidad y catálogo del centro | [usuario-lider-produccion-centro.md](usuario-lider-produccion-centro.md) |
| Líder de unidad productiva | Operación diaria de su unidad | [usuario-lider-unidad.md](usuario-lider-unidad.md) |
| Coordinación académica | Hato, práctica académica y transformación láctea | [usuario-coordinacion-academica.md](usuario-coordinacion-academica.md) |
| Administración del sistema | Usuarios, roles, auditoría, mantenimiento y colas | [usuario-administracion.md](usuario-administracion.md) |
| Carga inicial del piloto | Quien conduce o firma la carga inicial | [carga-inicial.md](carga-inicial.md) |
| Guía de operación | Titular de la cuenta y soporte técnico | [operacion.md](operacion.md) |

## Cómo se mantienen

- Se escriben en Markdown sencillo: títulos `#`, `##` y `###`, párrafos, listas, tablas, citas (`>`), **negrita**, `código` y enlaces. La ayuda en línea no admite HTML.
- Un enlace a otra guía se escribe con el nombre del archivo, por ejemplo `[Primeros pasos](primeros-pasos.md)`. La aplicación lo convierte en su página de ayuda.
- Las guías usan los nombres reales del menú y de las pantallas. Si cambia una pantalla, se actualiza su guía en el mismo cambio.
- Los PDF se generan con `npm run guias:pdf`, con la aplicación en marcha. Quedan en `almacenamiento/construccion/guias/`, fuera del repositorio.
- La validación con la contraparte se hace en la capacitación de la I8 ([plan de capacitación](../uat/capacitacion.md)).
