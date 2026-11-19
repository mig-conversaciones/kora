-- Reversión de 010 (Anexo B, 06-02). Solo en entornos sin datos que conservar; en producción, exportar antes (RDL-004).
DROP TABLE IF EXISTS conciliaciones_recaudo;
DROP TABLE IF EXISTS transformaciones;
DROP TABLE IF EXISTS salidas;
DROP TABLE IF EXISTS recepciones_lote;
