-- Reversión de 013. El registro de copias y de despliegues es evidencia operativa: exportarlo antes en producción.
DROP TABLE IF EXISTS despliegues;
DROP TABLE IF EXISTS respaldos;
