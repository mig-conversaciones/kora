-- Reversión de 014: solo índices, sin pérdida de datos.
ALTER TABLE recaudos DROP KEY idx_recaudos_unidad_listado, DROP KEY idx_recaudos_listado;
ALTER TABLE recepciones_lote DROP KEY idx_recepciones_listado;
ALTER TABLE salidas DROP KEY idx_salidas_unidad_listado, DROP KEY idx_salidas_listado;
