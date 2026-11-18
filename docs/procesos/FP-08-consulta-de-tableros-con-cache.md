# FP-08 Consulta de tableros con caché

> Ficha de proceso (RDOC-001). Fuente: SRS v1.0, sección 3.18. Se implementa en la iteración I6; ningún PR de esta funcionalidad se aprueba sin actualizar la ficha con los archivos y pruebas reales.

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
