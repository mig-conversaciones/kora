-- Reversión de 001 (Anexo B, 06-02). Solo en entornos sin datos que conservar; en producción, exportar antes (RDL-004).
DROP TABLE IF EXISTS destinos_internos;
DROP TABLE IF EXISTS unidades_productivas;
DROP TABLE IF EXISTS centros;
