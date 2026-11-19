# Runbook: maestros, presupuesto y cierres de periodo

Se aplica a la plataforma de la I3. Requisitos: RF-009 a RF-012, RF-016 a RF-028, RF-036 a RF-038 y RAU-001. Decisiones: [ADR-007](../adr/007-autorizacion-hibrida-alcance.md) y [ADR-017](../adr/017-modulos-de-gestion.md).

## 1. Puesta en marcha de los datos de configuración

```bash
php cli/migrar.php --aplicar   # hasta 009_crear_finanzas
php cli/sembrar.php            # 001: centro, unidades, roles; 002: categorías de gasto, parámetros y factores
```

- **Semilla 002:**
  - Siembra las tres categorías de gasto: Mantenimiento, Alimentación y vacunas, y Materiales de formación. Solo las dos primeras cuentan en IND_17.
  - Siembra los seis parámetros vigentes desde el 2026-01-01.
  - Configura los derivados lácteos (Queso cuajada, Yogurt, Manjar blanco y Cortado) si ya están en el catálogo y aún no tienen factor. Nunca pisa un factor ajustado.
- **Derivados que faltan.** Si no están en el catálogo, la pestaña Productos lo informa. Hay que crearlos con su código SGAC oficial, o esperar la importación del catálogo en la I5, y volver a ejecutar `php cli/sembrar.php`.

## 2. Quién hace qué

| Pantalla | Ruta | Consulta | Edita |
|---|---|---|---|
| Unidades, productos, homologación, categorías y destinos | `/catalogos/…` | Todos los roles de negocio (`catalogo.consultar`) | Administrador y Líder de producción de centro (`catalogo.gestionar`). Los factores de derivados: también la Coordinación académica (`catalogo.gestionar_factores`) |
| Parámetros de los indicadores | `/catalogos/parametros` | `parametros.consultar` | Administrador (`parametros.gestionar`) |
| Asignaciones presupuestales | `/finanzas/asignaciones` | Todos con `asignaciones.consultar`; el líder, solo sus unidades | Coordinación administrativa |
| Gastos ejecutados | `/finanzas/gastos` | `gastos.consultar`, con el alcance de cada uno | Coordinación administrativa y líder de unidad, en sus unidades |
| Ejecución por línea | `/finanzas/ejecucion` | `asignaciones.consultar` | — |
| Procesos de autoconsumo | `/finanzas/procesos` | `procesos.consultar` | Coordinación administrativa |
| Cierres de periodo | `/finanzas/periodos` | Coordinación administrativa (`periodos.cerrar`) | Ídem; reabrir exige reautenticación |
| Usuarios | `/sistema/usuarios` | Superadministrador y Administrador | Ídem, con separación de funciones (§ 4) |
| Roles y permisos | `/sistema/roles` | Cualquier usuario con sesión | Solo el Superadministrador, con reautenticación |
| Auditoría | `/sistema/auditoria` | Superadministrador (`auditoria.consultar`) | Solo lectura |

## 3. Cierre y reapertura de un mes (RF-028)

1. La Coordinación administrativa cierra el mes en `/finanzas/periodos` cuando terminó la conciliación. Los meses futuros no se pueden cerrar.
2. Un mes cerrado rechaza con **409 «Periodo cerrado»** crear, editar o archivar gastos con fecha en él, y desde la I4 también salidas, recepciones, recaudos e importaciones. Al editar se verifican la fecha anterior y la nueva: no se puede «sacar» un gasto de un mes cerrado.
3. **Reabrir:**
   - Se escribe un motivo de al menos 10 caracteres y se confirma la identidad con el código de la aplicación o la contraseña, si no se confirmó en los últimos 5 minutos.
   - La reapertura queda en la auditoría (`periodos.reabrir`) con su motivo.
   - Corregido el dato, se vuelve a cerrar.

## 4. Gestión de usuarios (RF-009 a RF-012)

- **Invitar.** En `/sistema/usuarios`, «Invitar usuario» envía un enlace de un solo uso (72 h). El líder de unidad exige al menos una unidad.
- **Cambiar el rol o las unidades.**
  - Exige reautenticación y queda auditado con el antes y el después (`usuarios.asignar_acceso`).
  - El cambio se aplica en la siguiente solicitud de la persona: `version_permisos` regenera su sesión.
- **Desactivar.**
  - Exige un motivo.
  - Revoca de inmediato todas las sesiones y los enlaces pendientes de la persona.
  - Reactivar devuelve la cuenta a «activo», o a «invitado» si nunca definió contraseña.
- **Separación de funciones.** Estos intentos responden 403 y quedan auditados como «denegado»:
  - modificar la propia cuenta, que se gestiona en «Mi cuenta»;
  - que un Administrador cree o toque cuentas de Superadministrador;
  - dar roles de negocio a un Superadministrador;
  - convertir una cuenta de negocio en Superadministrador.
- **Matriz de permisos.** Conceder o retirar un permiso afecta a todas las cuentas del rol en su siguiente solicitud. Los permisos del rol superadministrador no se cambian desde la interfaz: se fijan en la semilla.

## 5. Unidades y catálogos

| Situación | Qué hacer |
|---|---|
| «No se puede inactivar: tiene N gastos activos» | Archivar o reasignar esos registros. La regla cuenta asignaciones, gastos, procesos y recaudos activos, y desde la I4 también lotes, salidas y transformaciones |
| «La medida no puede cambiar» | El producto tiene recepciones (desde la I4). Crear un producto nuevo con la medida correcta |
| Concepto genérico de una fuente («Queso») | Dejarlo como concepto fuente, sin producto, hasta que se decida la variedad (RF-019) |
| Corregir un parámetro | Registrar un valor nuevo con su fecha de vigencia. Si es la misma fecha, reemplaza el valor y el anterior queda en la auditoría |

## 6. Soportes de gasto

- **Ubicación.** Se guardan en `almacenamiento/adjuntos/AAAA/MM/` con un nombre aleatorio, fuera de `public/`. El directorio responde 403 desde la web.
- **Copias.** Se incluyen en la copia diaria de `almacenamiento/` (`backup.sh`, I7).
- **Descarga.** Solo quien puede ver el gasto: cualquier otra persona recibe 404, que no revela que el soporte existe.
- **Rechazos.** Un archivo cuya firma no coincide con la extensión, por ejemplo un ejecutable renombrado a `.pdf`, se rechaza con 422. Exportar el soporte de nuevo desde el escáner o el sistema de facturación.
