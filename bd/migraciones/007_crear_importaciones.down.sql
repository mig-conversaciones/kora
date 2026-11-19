-- Reversión de 007 (Anexo B, 06-02). Solo en entornos sin datos que conservar; en producción, exportar antes (RDL-004).
DROP TABLE IF EXISTS filas_importacion;
DROP TABLE IF EXISTS lotes_importacion;
