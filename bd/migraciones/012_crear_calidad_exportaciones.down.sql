-- Reversión de 012 (Anexo B, 06-02). Solo en entornos sin datos que conservar; en producción, exportar antes (RDL-004).
DROP TABLE IF EXISTS exportaciones;
DROP TABLE IF EXISTS incidencias_calidad;
