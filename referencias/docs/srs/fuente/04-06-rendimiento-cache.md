## 4.6 Estrategia SQL, índices, caché y capacidad

### 4.6.1 Inventario de consultas e índices

Los índices se derivan de las consultas reales (RNF-PERF-007 y RNF-PERF-008). Empiezan por las columnas de alcance (`centro_id`, `unidad_id`) y de archivado cuando la consulta las filtra, y se prefiere la cobertura (incluir la columna sumada) en los agregados de los tableros. La medición antes y después se hace con `ANALYZE FORMAT=JSON` en MariaDB sobre el dataset de 3 años (RNF-PERF-016). Mientras no se ejecute, su estado es PENDIENTE DE EJECUCIÓN.

| Consulta (frecuencia) | Filtros, orden y agrupación | Índice | Justificación | Medición esperada |
|---|---|---|---|---|
| Venta directa por unidad (cada tablero) | `centro_id`, `tipo_ingreso`, `archivado_en IS NULL`, rango `fecha_pago`; GROUP BY `unidad_id`; SUM `valor` | `idx_recaudos_tablero (centro_id, tipo_ingreso, archivado_en, fecha_pago, unidad_id, valor)` | Cubre la consulta (sin lectura de filas); el rango de fechas va después de las igualdades | Filas examinadas ≈ filas del periodo; sin *filesort* |
| Autoconsumo por unidad (cada tablero) | `centro_id`, `canal`, `archivado_en`, rango `fecha`; GROUP BY `unidad_id`; SUM `valor` | `idx_salidas_tablero (centro_id, canal, archivado_en, fecha, unidad_id, valor)` | Igual que el anterior para `salidas` | Ídem |
| Entregas por proceso, IND_002 (tablero) | `proceso_id IN (…)`, rango `fecha`, canal | `idx_salidas_proceso (proceso_id, fecha)` | Pocos procesos por vigencia; el rango por fecha es selectivo | Menos de 100 filas por proceso y periodo |
| Asignaciones de la vigencia | `centro_id`, `vigencia`, alcance | UNIQUE `uq_asignaciones_activa (centro_id, unidad_id, vigencia, activa_unica)` | Tabla pequeña; la unicidad sirve de índice | Lectura por índice |
| IND_003 del corte vigente | `corte_id` y agregación por `lote_hato` | UNIQUE `uq_registro_corte_animal (corte_id, animal_id)` | Un corte tiene decenas o cientos de animales | Menos de 500 filas |
| Recepciones del periodo, IND_12 e IND_17 | `centro_id`, `unidad_id`, `archivado_en`, rango `fecha_recepcion` | `idx_recepciones_tablero (centro_id, unidad_id, archivado_en, fecha_recepcion)` | También sirve para el listado paginado | Lectura de rango |
| Salidas por lote (rotación, saldos) | `recepcion_id`, `fecha` | `idx_salidas_recepcion (recepcion_id, fecha)` | Evita el N+1: una consulta por lote del periodo con IN | Una consulta por solicitud |
| Gastos del periodo, IND_17 y listado | `centro_id`, `unidad_id`, `archivado_en`, rango `fecha`; `categoria_id` | `idx_gastos_listado (centro_id, unidad_id, archivado_en, fecha, categoria_id)` | Listado ordenado por fecha y agregados | Lectura de rango |
| Bandeja de clasificación de recaudos | `centro_id`, `estado_clasificacion`; ORDER BY `fecha_pago` | `idx_recaudos_clasificacion (centro_id, estado_clasificacion, fecha_pago)` | Bandeja frecuente del Grupo de Recaudo | Sin *filesort* |
| Conciliación por prefactura o factura | `centro_id`, `prefactura` / `factura_electronica` | `idx_recaudos_prefactura`, `idx_recaudos_factura`, `idx_salidas_factura` | Búsqueda exacta | Lectura por índice |
| Deduplicación en la importación | `centro_id`, `clave_natural` | UNIQUE `uq_recaudos_clave`, UNIQUE `uq_salidas_clave` | Idempotencia (RF-089) | Lectura por clave única |
| Reclamo de la cola (cada ejecución) | `cola`, `estado`, `disponible_en`; ORDER BY `prioridad`, `disponible_en`, `id` | `idx_trabajos_reclamo (cola, estado, prioridad, disponible_en, id)` | Orden servido por el índice; `SKIP LOCKED` sobre filas del índice | Menos de 1 ms con un millón de trabajos históricos |
| Recuperación de reservas vencidas | `estado`, `bloqueado_hasta` | `idx_trabajos_vencidos (estado, bloqueado_hasta)` | Ejecución en cada pasada del trabajador | Rango pequeño |
| Auditoría por fecha (paginación por cursor) | ORDER BY `ocurrido_en DESC`, `id DESC` con cursor | `idx_auditoria_fecha (ocurrido_en, id)` | Evita OFFSET en millones de filas (RNF-PERF-010) | Página en menos de 50 ms |
| Auditoría por actor o recurso | `actor_id` o (`recurso`, `recurso_id`), por fecha | `idx_auditoria_actor (actor_id, ocurrido_en)`, `idx_auditoria_recurso (recurso, recurso_id)` | Filtros del módulo de auditoría | Lectura por índice |
| Permisos efectivos (cada solicitud, con caché) | `usuario_id`, luego los roles y permisos | PK de `usuarios_roles` y `roles_permisos` | Se cachean por versión de permisos del usuario | 1 consulta en un fallo de caché |
| Bandeja de calidad | `centro_id`, `unidad_id`, `estado`, `tipo` | `idx_incidencias_bandeja (centro_id, unidad_id, estado, tipo)` | Listado por alcance | Lectura por índice |

**Reglas de consulta** (RNF-PERF-009 y RNF-PERF-010):

- Los indicadores se calculan con consultas agregadas (`SUM`, `COUNT`, `GROUP BY`) en el servidor de base de datos, no trayendo las filas a PHP.
- Las relaciones se cargan en bloque, con `IN` y listas de IDs del alcance.
- Ninguna consulta de listado carece de `LIMIT`.
- El contador de consultas de `BaseDatos` hace fallar las pruebas si se supera el máximo por tipo de solicitud.

### 4.6.2 Estrategia de caché

Se empieza con *cache-aside* en archivos privados, con escritura atómica y bloqueos, compatible con el plan Cloud y sin Redis. Las claves incluyen la **versión de datos** del centro (`versiones_datos`), que se incrementa en la misma transacción de cada escritura relevante. Así, la invalidación es inmediata y sin carreras: una lectura concurrente con datos viejos escribe bajo la clave antigua, que ya nadie consulta. La caché nunca decide permisos, pagos ni existencias.

| Recurso | Capa y almacenamiento | Clave y alcance | TTL | Evento de invalidación | Frescura admitida | Fallback | Prueba |
|---|---|---|---|---|---|---|---|
| Agregados del tablero central (IND_001, IND_002) | Aplicación; archivos privados | `tablero_central:{centro}:{huella_alcance}:{hash_filtros}:v{version_datos}:c{version_calculo}` | 10 min ± 10 % | Escritura en recaudos, salidas, asignaciones o procesos, que incrementa la versión del centro; despliegue con cambio de cálculo | Inmediata tras escrituras de la aplicación | Cálculo directo con bloqueo de estampida | Fría y caliente; invalidación al registrar una salida; aislamiento entre alcances |
| Agregados del tablero técnico (IND_003, 08, 12, 17) | Aplicación; archivos | `tablero_unidad:{centro}:{unidad}:{huella}:{filtros}:{practica}:v{version_datos}:c{version_calculo}` | 10 min ± 10 % | Escritura en hato, transformaciones, recepciones, salidas, gastos o parámetros | Inmediata | Cálculo directo | Igual que el anterior, además del interruptor de práctica |
| Catálogos (productos, unidades, categorías, medidas) | Aplicación; archivos | `catalogo:{tipo}:{centro}:v{version_catalogo}` | 60 min | Edición o importación del catálogo | Inmediata | Consulta directa | El cambio de factor se ve en el formulario de transformación |
| Permisos efectivos del usuario | Aplicación; archivos más memoria de la solicitud | `permisos:{usuario}:v{version_permisos}` | 5 min | Cambio de roles, permisos o unidades del usuario | Inmediata (siguiente solicitud) | Consulta directa | Al revocar un permiso, la siguiente solicitud recibe 403; la autorización se evalúa siempre en el servidor |
| Estado de mantenimiento | NO APLICA | — | — | — | La señal se lee en cada solicitud (RF-MAN-013) | — | Propagación en 5 s o menos |
| Sesiones | NO APLICA: la fuente de verdad es la base de datos | — | — | — | — | — | — |
| Listados paginados y búsquedas | NO APLICA en la v1 | — | — | — | Las consultas indexadas cumplen el SLO; se reevalúa con las mediciones | — | Medición en PCA |
| HTML autenticado y fragmentos | NO APLICA: `Cache-Control: no-store` | — | — | — | Contienen datos de un alcance | — | Contrato de cabeceras |
| Recursos estáticos con huella | HTTP del navegador | URL con huella | 1 año, inmutable | Nueva huella en cada construcción | — | — | Contrato de cabeceras |
| Código PHP compilado | OPcache | Ruta real del archivo | `revalidate_freq` de 2 s | Cambio de versión (ruta nueva) | Hasta unos 120 s por la caché de rutas reales | Ejecución sin OPcache | Verificación tras el despliegue (RNF-PERF-014) |
| Exportaciones generadas | Almacenamiento privado | `exportaciones/{ulid}` | 24 h | Vencimiento | — | Regenerar la exportación | Descarga autorizada y vencida |
| Respuestas idempotentes | Tabla `claves_idempotencia` | (`usuario_id`, `clave`) | 24 h | Vencimiento | — | — | Un reintento devuelve la misma respuesta |

**Controles de la caché:**

- **Tamaño.** Máximo de 200 MB. La limpieza programada borra primero los vencidos y luego los más antiguos.
- **Estampida.** Bloqueo breve con `flock` no bloqueante. Mientras otro proceso calcula, se sirve el valor vencido (gracia de 60 s) o se espera hasta 2 s.
- **Variación del TTL** de ±10 %.
- **Caída del almacenamiento.** Se pasa al cálculo directo, sin tormenta de consultas gracias al límite de concurrencia por bloqueo.
- **Contenido.** No se guardan credenciales ni datos personales, solo agregados por alcance.
- **Métricas.** Aciertos y fallos por tipo de clave, en el registro técnico (RAU-010).

```php archivo=app/Nucleo/Cache.php
<?php
declare(strict_types=1);

namespace App\Nucleo;

/**
 * Caché de aplicación en archivos privados (cache-aside, ADR-010). La invalidación se resuelve con claves
 * versionadas: quien llama incluye la versión de datos del centro en la clave. Escritura atómica (temporal +
 * rename), JSON en lugar de unserialize (sin inyección de objetos) y protección de estampida con flock.
 * La caché nunca es fuente de verdad: cualquier fallo de almacenamiento cae al cálculo directo.
 */
final class Cache
{
    private const GRACIA_SEGUNDOS = 60;
    private const ESPERA_MAXIMA_SEGUNDOS = 2.0;

    public function __construct(private readonly string $directorio, private readonly ?Registro $registro = null)
    {
    }

    /**
     * @template T
     * @param callable():T $calcular debe devolver datos serializables en JSON (agregados, no objetos)
     * @return T
     */
    public function recordar(string $clave, int $ttlSegundos, callable $calcular): mixed
    {
        $ruta = $this->ruta($clave);
        $entrada = $this->leer($ruta);
        if ($entrada !== null && $entrada['expira'] > time()) {
            $this->metrica($clave, 'acierto');
            return $entrada['valor'];
        }
        $this->metrica($clave, 'fallo');
        $bloqueo = fopen($ruta . '.lock', 'c');
        if ($bloqueo === false) {
            return $calcular();
        }
        try {
            if (!flock($bloqueo, LOCK_EX | LOCK_NB)) {
                // Otro proceso está calculando: se sirve el valor vencido dentro de la gracia o se espera poco.
                if ($entrada !== null && $entrada['expira'] + self::GRACIA_SEGUNDOS > time()) {
                    return $entrada['valor'];
                }
                $limite = microtime(true) + self::ESPERA_MAXIMA_SEGUNDOS;
                while (microtime(true) < $limite) {
                    usleep(100000);
                    $nueva = $this->leer($ruta);
                    if ($nueva !== null && $nueva['expira'] > time()) {
                        return $nueva['valor'];
                    }
                }
                return $calcular();
            }
            // Doble verificación: otro proceso pudo escribir mientras se obtenía el bloqueo.
            $entrada = $this->leer($ruta);
            if ($entrada !== null && $entrada['expira'] > time()) {
                return $entrada['valor'];
            }
            $valor = $calcular();
            $variacion = (int) round($ttlSegundos * mt_rand(-10, 10) / 100);
            $this->escribirAtomico($ruta, ['expira' => time() + $ttlSegundos + $variacion, 'valor' => $valor]);
            return $valor;
        } finally {
            flock($bloqueo, LOCK_UN);
            fclose($bloqueo);
        }
    }

    /** Limpieza programada: elimina vencidos (fuera de gracia) y aplica el tamaño máximo (RNF-PERF-012). */
    public function limpiar(int $tamanoMaximoBytes): int
    {
        $archivos = glob($this->directorio . '/*/*.json') ?: [];
        $eliminados = 0;
        $vigentes = [];
        foreach ($archivos as $archivo) {
            $entrada = $this->leer($archivo);
            if ($entrada === null || $entrada['expira'] + self::GRACIA_SEGUNDOS < time()) {
                $eliminados += unlink($archivo) ? 1 : 0;
                continue;
            }
            $vigentes[$archivo] = (int) filemtime($archivo);
        }
        asort($vigentes);
        $tamano = array_sum(array_map('filesize', array_keys($vigentes)));
        foreach (array_keys($vigentes) as $archivo) {
            if ($tamano <= $tamanoMaximoBytes) {
                break;
            }
            $tamano -= (int) filesize($archivo);
            $eliminados += unlink($archivo) ? 1 : 0;
        }
        return $eliminados;
    }

    /** @return array{expira:int,valor:mixed}|null */
    private function leer(string $ruta): ?array
    {
        if (!is_file($ruta)) {
            return null;
        }
        $contenido = file_get_contents($ruta);
        $datos = $contenido === false ? null : json_decode($contenido, true);
        return is_array($datos) && isset($datos['expira']) && array_key_exists('valor', $datos) ? $datos : null;
    }

    /** @param array{expira:int,valor:mixed} $datos */
    private function escribirAtomico(string $ruta, array $datos): void
    {
        $temporal = $ruta . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temporal, json_encode($datos, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            return;
        }
        chmod($temporal, 0600);
        if (!rename($temporal, $ruta)) {
            unlink($temporal);
        }
    }

    private function ruta(string $clave): string
    {
        $hash = hash('sha256', $clave);
        $directorio = $this->directorio . '/' . substr($hash, 0, 2);
        if (!is_dir($directorio)) {
            mkdir($directorio, 0700, true);
        }
        return $directorio . '/' . $hash . '.json';
    }

    private function metrica(string $clave, string $resultado): void
    {
        $this->registro?->info('cache.' . $resultado, ['tipo' => strstr($clave, ':', true) ?: $clave]);
    }
}
```

### 4.6.3 Capacidad del hosting y dimensionamiento

Por la ley de Little, la concurrencia media es el rendimiento por la latencia:

- Con la carga esperada: 5 req/s × 0,6 s ≈ 3 procesos PHP simultáneos.
- En una ráfaga: 10 req/s × 1,2 s ≈ 12 procesos.

Ambas cifras quedan muy por debajo de los PHP workers de referencia del plan Cloud. La capacidad real se demuestra con PCA, no con este cálculo.

| Parámetro | Referencia (verificar) | Cómo se verifica en la I0 | Efecto en el diseño |
|---|---|---|---|
| Plan | Cloud Startup o Professional | hPanel → Plan | Determina las señales de ampliación (RNF-PERF-019) |
| CPU y RAM | Startup: unos 2 núcleos y 3 GB. Professional: unos 4 núcleos y 6 GB (tabla v6 de Hostinger) | hPanel → Uso de recursos | Calibración del costo de Argon2id (RAC-002) y del lote de la cola |
| PHP workers | 100 (Startup) o 200 (Professional) | hPanel → Plan | Concurrencia de las solicitudes web |
| Conexiones MariaDB por usuario | Sin confirmar (tablas anteriores: 100 a 150) | `SHOW VARIABLES LIKE 'max_user_connections'` | Conexiones no persistentes, cerradas al terminar la solicitud |
| Versión de MariaDB | 10.6 o superior (requerida) | `SELECT VERSION()` | Estrategia de reclamo de la cola (RF-Q-006) |
| Cron | Cada minuto | hPanel → Tareas cron | Espera de la cola (RNF-PERF-004) |
| Duración máxima de procesos CLI | Por verificar | Script de prueba que dure 55 s | `COLA_PRESUPUESTO_SEGUNDOS` |
| Disco e inodos | Según el plan | hPanel | Retención de importaciones y caché (RDL-010) |
| SSH, rsync y Git | Disponibles en los planes Cloud (verificar) | Conexión de prueba | Pipeline de despliegue (ROB-001) |
| SMTP del dominio | Límite de envíos por día (verificar) | Documentación del plan de correo | Ritmo de la cola de correo y cortacircuitos |
| Redis | No disponible | — | Cola solo en MariaDB (D-12) |
| PHP y extensiones | 8.3 con sodium, intl, zip, pdo_mysql y opcache | `php cli/verificar_entorno.php` | Arranque del sistema (RNF-018) |
