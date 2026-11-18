-- Reversión de 005. Solo en entornos sin trabajos que conservar; en producción, exportar antes trabajos_fallidos.
DROP TABLE IF EXISTS estado_circuitos;
DROP TABLE IF EXISTS trabajos_fallidos;
DROP TABLE IF EXISTS trabajos;
