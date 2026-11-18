-- Reversión de 004 (Anexo B, 06-02). Solo en entornos sin datos que conservar; en producción, exportar antes (RDL-004).
DROP TABLE IF EXISTS cadena_auditoria;
DROP TABLE IF EXISTS eventos_auditoria;
