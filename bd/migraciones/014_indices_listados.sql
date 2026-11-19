-- 014: índices de los listados paginados, derivados del inventario de consultas de la I7 (RNF-PERF-007, RNF-PERF-008).
-- Los listados ordenan por fecha descendente dentro del centro (o de las unidades del alcance) y excluyen lo archivado.
-- Sin estos índices, MySQL 5.7 recorría las ~20.000 salidas del centro por la clave natural y ordenaba con filesort
-- (docs/rendimiento/consultas-indices.md). InnoDB añade la clave primaria al final de cada índice secundario, así que el
-- desempate «fecha DESC, id DESC» también sale del índice.
--
-- El índice por unidad de salidas empieza por unidad_id y no por (centro_id, unidad_id): con ese prefijo, InnoDB lo
-- tomaría para la llave foránea fk_salidas_unidad y retiraría su índice automático, y la reversión dependería de cómo
-- se creó ese índice. La unidad ya pertenece a un único centro, así que el filtro por centro no pierde selectividad.
ALTER TABLE salidas
    ADD KEY idx_salidas_listado (centro_id, archivado_en, fecha),
    ADD KEY idx_salidas_unidad_listado (unidad_id, archivado_en, fecha);

ALTER TABLE recepciones_lote
    ADD KEY idx_recepciones_listado (centro_id, archivado_en, fecha_recepcion);

ALTER TABLE recaudos
    ADD KEY idx_recaudos_listado (centro_id, archivado_en, fecha_pago),
    ADD KEY idx_recaudos_unidad_listado (centro_id, unidad_id, archivado_en, fecha_pago);
