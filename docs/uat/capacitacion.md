# Plan de capacitación por rol (RDOC-009, I8)

La capacitación entrega las guías y las **valida con la contraparte**: cada sesión termina con las correcciones que pidan las personas que usarán KORA. Las correcciones se hacen en `docs/guias/`, que es a la vez la ayuda en línea y la fuente de los PDF.

## Preparación

- **Entorno de práctica:** una base aislada de staging con el dataset sintético «mini» (`php cli/generar_dataset.php --base=kora_capacitacion_pruebas --escala=mini --preparar --cuentas=12 --usuarios-k6=…`). Nunca se capacita sobre la base de producción, y los datos sintéticos no se mezclan con los reales (D-19).
- **Cuentas de práctica:** las crea el generador, una por rol; el archivo con sus contraseñas se entrega en la sesión y se destruye al final.
- **Material:** los PDF de las guías (`npm run guias:pdf`) y la ayuda en línea en el menú **Ayuda**.
- **Duración:** 90 minutos por grupo, con práctica guiada y 15 minutos de preguntas.

## Sesiones

| Sesión | Participantes | Guías | Práctica |
|---|---|---|---|
| 1. Común | Todos | Primeros pasos | Activar la cuenta, ingresar, salir, configurar la verificación en dos pasos, Mi cuenta, ayuda y accesibilidad |
| 2. Administrativa | Coordinación administrativa | Coordinación administrativa; Carga inicial | Asignación, gasto con soporte, proceso de autoconsumo, cierre de periodo, importar el presupuesto, ensayo de carga inicial |
| 3. Recaudo | Grupo de Recaudo | Grupo de Recaudo; Carga inicial (firma) | Importar recaudos, clasificar, conciliar y revisar el informe de diferencias; revisar y firmar un acta de práctica |
| 4. Producción | Líder de producción de centro y líderes de unidad | Líder de producción de centro; Líder de unidad | Recepción, salida por venta y por autoconsumo, transformación, bandeja de calidad, tablero técnico |
| 5. Académica | Coordinación académica | Coordinación académica | Lote de práctica, IND_003 con y sin práctica, factores de equivalencia, transformación láctea |
| 6. Administración | Administrador funcional y titular de la cuenta | Administración del sistema; Guía de operación | Invitar y desactivar usuarios, auditoría, mantenimiento, colas, copias y restauración |

## Registro de la capacitación

| Sesión | Fecha | Asistentes (rol) | Guías entregadas | Correcciones pedidas | Responsable |
|---|---|---|---|---|---|
| 1 | | | | | |
| 2 | | | | | |
| 3 | | | | | |
| 4 | | | | | |
| 5 | | | | | |
| 6 | | | | | |

Las guías quedan **validadas** cuando sus correcciones están hechas y la persona que las pidió las revisó.
