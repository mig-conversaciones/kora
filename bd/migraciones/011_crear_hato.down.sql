-- Reversión de 011 (Anexo B, 06-02). Solo en entornos sin datos que conservar; en producción, exportar antes (RDL-004).
DROP TABLE IF EXISTS registros_reproductivos;
DROP TABLE IF EXISTS cortes_hato;
DROP TABLE IF EXISTS animales;
