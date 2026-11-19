# Estrategia de caché (RNF-PERF-012, RNF-PERF-013)

Anexo H, H.2. Decisiones: [ADR-010](../adr/010-cache-archivos-version.md), [ADR-020](../adr/020-tableros-e-indicadores.md) y [ADR-021](../adr/021-operacion-respaldos-despliegue.md).

La estrategia es *cache-aside* en archivos privados (`almacenamiento/cache`), con escritura atómica y `flock`. La invalidación se hace con claves versionadas: la versión de datos del centro sube en la misma transacción de cada escritura. La caché nunca decide permisos, pagos ni existencias.

## Recursos en caché

| Recurso | Clave | TTL | Invalidación | Implementación |
|---|---|---|---|---|
| Tablero central (IND_001, IND_002, estado, conciliación) | `tablero_central:{centro}:{huella_alcance}:{huella_filtros}:v{datos}:c{CALCULO}` | 10 min ± 10 % | Escritura relevante (versión de datos); cambio de fórmula (`ServicioTableros::CALCULO`) | `ServicioTableros::central` |
| Opciones del filtro de producto | `opciones_producto:{centro}:{huella_alcance}:{vigencia}:v{datos}:c{CALCULO}` | 10 min ± 10 % | Ídem | `ServicioTableros::opcionesProducto` (I7) |
| Tablero técnico (IND_003, 08, 12, 17) | `tablero_unidad:{centro}:{unidad}:{huella}:{filtros}:p{practica}:v{datos}:c{CALCULO}` | 10 min ± 10 % | Ídem | `ServicioTableros::unidad` |
| Permisos efectivos (roles, permisos y unidades) | `permisos:{ulid_usuario}:v{version_permisos}` | 5 min ± 10 % | Todo cambio de acceso sube `version_permisos` (ServicioUsuarios, ServicioPermisos). La fila del usuario (estado y versión) se lee en cada solicitud | `RepositorioUsuarios::identidad` (I7) |
| Versión de datos, unidades del catálogo y parámetros vigentes | Memoria de la solicitud | 1 s en las dos primeras; los parámetros, la solicitud | Incrementar la versión borra su memoria | `ServicioVersionDatos`, `ServicioCatalogos`, `ServicioIndicadores` (I7) |
| Estado de mantenimiento, sesiones, listados y HTML autenticado | No aplica | — | — | Señal leída en cada solicitud; `no-store` |

## Controles

- **Tamaño:** máximo de 200 MB. La limpieza diaria borra primero los vencidos y luego los más antiguos.
- **Estampida:** `flock` no bloqueante. Mientras otro proceso calcula, se sirve el valor vencido dentro de la gracia de 60 s o se espera hasta 2 s.
- **Caída del almacenamiento:** el cálculo es directo, sin avisos ni errores. Los fallos de `mkdir`, `fopen` y `rename` se absorben (endurecido en la I7) y quedan como métrica `cache.sin_almacenamiento` en el registro técnico.
- **Precalentamiento:** el trabajo `cache.precalentar`, los días hábiles a las 06:30, calcula la vista por defecto del central y del técnico de Ganadería para cada alcance distinto de las cuentas activas.
- **Vaciado:** `php cli/limpiar_cache.php`, en cada despliegue, restauración o cambio directo en la base.

## Pruebas obligatorias (H.2)

| Prueba | Criterio | Evidencia | Estado |
|---|---|---|---|
| Caché fría frente a caliente | p95 del tablero de 1.200 ms o menos en frío y de 500 ms o menos en caliente; aciertos del 80 % o más tras precalentar | Local, en proceso: p95 de 104 a 172 ms en frío y de 29 a 46 ms en caliente ([tiempos-locales.md](../../tests/carga/resultados/0.8.0-local/tiempos-locales.md)). Tras precalentar, la vista por defecto no recalcula (`testPrecalentamientoDejaCalienteLaVistaPorDefectoDeCadaAlcance`) | Local conforme; **staging con k6 pendiente** |
| Invalidación | Una salida registrada se ve en IND_001 en la siguiente consulta | `OraculosIndicadoresTest::testTableroCentralDelLiderSoloContieneSusUnidadesYLaCacheSeInvalida` | Conforme |
| Cambio de permisos | Al revocar un permiso, la siguiente solicitud ya no accede aunque exista una entrada | `RendimientoOperacionTest::testPermisosEnCacheSeInvalidanAlCambiarElAcceso` | Conforme |
| Aislamiento | Dos alcances con los mismos filtros generan claves distintas y ninguno ve datos del otro | `OraculosIndicadoresTest` (líder de GAN) y `RendimientoOperacionTest` (líder del segundo centro) | Conforme |
| Vencimiento | La entrada se recalcula después del TTL, con variación | `RendimientoOperacionTest::testCacheVenceConVariacionEvitaEstampidaYSobreviveSinAlmacenamiento` | Conforme |
| Estampida | 20 solicitudes simultáneas sobre una clave fría producen un solo cálculo | Ídem, con 20 procesos PHP | Conforme |
| Fallo del almacenamiento | Sin permisos en la caché, se responde con cálculo directo, sin errores | Ídem | Conforme |

## Riesgo aceptado

- **Cambios directos en la base.** Un cambio de permisos hecho con SQL de soporte, sin subir `version_permisos`, tarda hasta 5 minutos en verse.
- **Mitigación:** el runbook [Rotación de claves](../runbooks/rotacion-claves.md) y el de [Incidente de seguridad](../runbooks/incidente-seguridad.md) incluyen subir la versión, o ejecutar `php cli/limpiar_cache.php`, tras cualquier cambio manual de acceso.
