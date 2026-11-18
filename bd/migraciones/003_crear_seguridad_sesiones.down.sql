-- Reversión de 003 (Anexo B, 06-02). Solo en entornos sin datos que conservar; en producción, exportar antes (RDL-004).
DROP TABLE IF EXISTS claves_idempotencia;
DROP TABLE IF EXISTS limites_tasa;
DROP TABLE IF EXISTS codigos_respaldo_2fa;
DROP TABLE IF EXISTS tokens_cuenta;
DROP TABLE IF EXISTS sesiones;
