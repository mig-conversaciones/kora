-- Reversión de 008 (Anexo B, 06-02). Solo en entornos sin datos que conservar; en producción, exportar antes (RDL-004).
DROP TABLE IF EXISTS parametros_indicadores;
DROP TABLE IF EXISTS categorias_gasto;
DROP TABLE IF EXISTS homologaciones_producto;
DROP TABLE IF EXISTS productos;
