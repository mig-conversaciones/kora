# FP-09 Exportación asíncrona

> Ficha de proceso (RDOC-001). Fuente: SRS v1.0, sección 3.18. Se implementa en la iteración I6; ningún PR de esta funcionalidad se aprueba sin actualizar la ficha con los archivos y pruebas reales.

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
