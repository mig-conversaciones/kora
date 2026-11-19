-- Reversión de 009 (Anexo B, 06-02). Solo en entornos sin datos que conservar; en producción, exportar antes (RDL-004).
DROP TABLE IF EXISTS periodos_cierre;
DROP TABLE IF EXISTS recaudos;
DROP TABLE IF EXISTS procesos_autoconsumo;
DROP TABLE IF EXISTS gastos_ejecutados;
DROP TABLE IF EXISTS asignaciones_presupuestales;
