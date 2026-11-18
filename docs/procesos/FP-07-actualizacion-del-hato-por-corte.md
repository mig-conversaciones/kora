# FP-07 Actualización del hato por corte

> Ficha de proceso (RDOC-001). Fuente: SRS v1.0, sección 3.18. Se implementa en la iteración I4; ningún PR de esta funcionalidad se aprueba sin actualizar la ficha con los archivos y pruebas reales.

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
