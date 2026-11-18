-- Reversión de 006 (Anexo B, 06-02). Solo en entornos sin datos que conservar; en producción, exportar antes (RDL-004).
DROP TABLE IF EXISTS archivos_adjuntos;
DROP TABLE IF EXISTS notificaciones;
DROP TABLE IF EXISTS versiones_datos;
DROP TABLE IF EXISTS configuracion_sistema;
