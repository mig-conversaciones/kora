-- Semilla 002: categorías de gasto, parámetros de indicadores y factores de derivados lácteos (RDL-008).
-- Extraída de la semilla mínima de producción y de derivados_lacteos.sql del Anexo B (06-02). Idempotente.
SET @centro = (SELECT id FROM centros WHERE codigo = '9124');

-- Categorías de gasto (RF-020, Obs. 1). Solo Mantenimiento y Alimentación cuentan en IND_17 (S-14).
INSERT IGNORE INTO categorias_gasto (centro_id, codigo, nombre, linea_presupuestal, incluye_ind17) VALUES
    (@centro, 'MANTENIMIENTO', 'Mantenimiento', 'mantenimiento', 1),
    (@centro, 'ALIMENTACION', 'Alimentación y vacunas', 'alimentacion', 1),
    (@centro, 'MATERIALES_FORMACION', 'Materiales de formación', 'materiales_formacion', 0);

-- Parámetros de indicadores con vigencia (RF-021).
INSERT IGNORE INTO parametros_indicadores (centro_id, clave, valor, vigente_desde) VALUES
    (@centro, 'meta_vacas_ordeno', 75, '2026-01-01'),
    (@centro, 'precio_referencia_leche', 2200, '2026-01-01'),
    (@centro, 'costo_maximo_precio', 75, '2026-01-01'),
    (@centro, 'rotacion_frescos', 15, '2026-01-01'),
    (@centro, 'rotacion_animales', 30, '2026-01-01'),
    (@centro, 'meta_aprovechamiento', 95, '2026-01-01');

-- Derivados lácteos (RF-018, S-12, Obs. 6). Factor inicial = límite inferior del rango de la guía,
-- PENDIENTE DE VALIDACIÓN con el Taller de Lácteos. Sin efecto si el producto aún no está en el catálogo;
-- el panel de catálogos lo informa. Solo se aplica a productos que todavía no tienen factor (no pisa ajustes).
UPDATE productos SET es_derivado_lacteo = 1, factor_leche = 8.0000, meta_rendimiento_min = 8.0000, meta_rendimiento_max = 8.5000
 WHERE centro_id = @centro AND LOWER(nombre) = 'queso cuajada' AND factor_leche IS NULL;
UPDATE productos SET es_derivado_lacteo = 1, factor_leche = 0.9500, meta_rendimiento_min = 0.9500, meta_rendimiento_max = 1.0500
 WHERE centro_id = @centro AND LOWER(nombre) = 'yogurt' AND factor_leche IS NULL;
UPDATE productos SET es_derivado_lacteo = 1, factor_leche = 3.2000, meta_rendimiento_min = 3.2000, meta_rendimiento_max = 3.6000
 WHERE centro_id = @centro AND LOWER(nombre) = 'manjar blanco' AND factor_leche IS NULL;
UPDATE productos SET es_derivado_lacteo = 1, factor_leche = 3.8000, meta_rendimiento_min = 3.8000, meta_rendimiento_max = 4.2000
 WHERE centro_id = @centro AND LOWER(nombre) = 'cortado' AND factor_leche IS NULL;
