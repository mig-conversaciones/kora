## Anexo H. Rendimiento: consultas, caché, plan de carga y scripts

La carga se prueba en un entorno concreto: una versión, una infraestructura, un volumen de datos y un perfil de uso. **Nada de este anexo demuestra capacidad** mientras no se ejecute. Todos los resultados están en estado PENDIENTE DE EJECUCIÓN hasta que se archive su evidencia (RNF-PERF-018).

### H.1 `docs/rendimiento/consultas-indices.md`

**Contenido inicial:** el inventario de la sección 4.6.1, más el procedimiento de medición siguiente.

1. Cargar el dataset de 3 años en staging (RNF-PERF-016) y ejecutar `ANALYZE TABLE` sobre las tablas del inventario.
2. Para cada consulta crítica, capturar `ANALYZE FORMAT=JSON <consulta>` en MariaDB, o `EXPLAIN ANALYZE` en MySQL 8.0.18 o superior, solo en staging. Registrar el plan, `rows_examined` frente a `rows_produced`, el tiempo y el uso de *filesort* o temporales.
3. Medir el número de consultas por solicitud con el contador de `BaseDatos` (RNF-PERF-009).
4. Aplicar o ajustar el índice mediante una migración reversible y repetir la medición. Medir también el costo en escritura: inserción de 1.000 salidas antes y después.

**Registro por consulta** (todas las cifras PENDIENTES DE EJECUCIÓN):

| Consulta | Índice | Filas examinadas (antes / después) | Filas devueltas | Tiempo (antes / después) | Plan (resumen) | Costo en escritura | Decisión |
|---|---|---|---|---|---|---|---|

### H.2 `docs/rendimiento/estrategia-cache.md`

**Contenido inicial:** la tabla de la sección 4.6.2 y estas pruebas obligatorias.

| Prueba | Criterio |
|---|---|
| Caché fría frente a caliente | Tablero con p95 de 1.200 ms o menos en frío y de 500 ms o menos en caliente; tasa de aciertos de 80 % o más tras el precalentamiento |
| Invalidación | Una salida registrada se ve en IND_001 en la siguiente consulta |
| Cambio de permisos | Al revocar un permiso, la siguiente solicitud recibe 403 aunque exista una entrada de caché |
| Aislamiento | Dos alcances con los mismos filtros generan claves distintas y ninguno ve datos del otro |
| Vencimiento | La entrada se recalcula después del TTL, con variación |
| Estampida | 20 solicitudes simultáneas sobre una clave fría producen un solo cálculo |
| Fallo del almacenamiento | Con el directorio de caché sin permisos, el sistema responde mediante cálculo directo, sin errores |

### H.3 `docs/rendimiento/plan-carga.md`

| Elemento | Definición |
|---|---|
| Objetivo | Verificar los SLO de RNF-PERF-003 a RNF-PERF-006 con la carga esperada y en el pico, la integridad y el aislamiento, y el vaciado de la cola |
| Entorno | Staging en Hostinger Cloud del mismo plan que producción, en un subdominio aislado con su propia base de datos ([ENTORNO_PRUEBAS_CARGA]). Las diferencias con producción se documentan en el informe. |
| Autorización | Acta del titular de la cuenta. Si el proveedor lo exige, se le avisa por escrito con la ventana y el tráfico máximo. |
| Generador de carga | k6 en un equipo externo al servidor, con capacidad verificada: CPU por debajo del 70 % y sin `dropped_iterations` significativas |
| Dataset | `GeneradorCapacitacion --escala=3a`: 3 años del centro y un segundo centro. Sumas de control registradas antes de la prueba. |
| Usuarios de prueba | 30 cuentas sintéticas repartidas entre roles y unidades (`tests/carga/usuarios.json`, fuera del repositorio); sin 2FA solo en staging y solo para los roles no privilegiados |
| Dobles | Correo con un SMTP de pruebas (no entrega real) y simulador de integración. Lo que los dobles no demuestran: la latencia real del SMTP de Hostinger, sus límites diarios y la entregabilidad. |
| Mezcla de flujos | Tableros 35 %, listados 25 %, formularios GET 15 %, escrituras 12 %, consulta de trabajos 8 %, importaciones y exportaciones 3 %, inicio de sesión 2 % |
| Escenarios | Smoke (1 req/s, 2 min); sostenida (5 req/s, 30 min); pico gradual (de 1 a 10 req/s); ráfaga (16 req/s durante 60 s, el doble del pico); resistencia (3 req/s, 2 h, solo si está autorizada) |
| Pausas | De 1 a 3 s entre acciones de un mismo usuario |
| Condiciones de parada | Errores de más del 5 % durante 2 min; p95 de más de 3 veces el objetivo durante 3 min; alerta de recursos en hPanel; indicio de afectación a terceros; orden del titular |
| Métricas | p50, p95 y p99 por flujo; rendimiento alcanzado frente al ofrecido; errores inesperados; `dropped_iterations`; CPU y RAM de hPanel; procesos PHP; conexiones y bloqueos de MariaDB; aciertos de la caché; profundidad y antigüedad de la cola; tiempo de vaciado |
| Validación de negocio | `tests/carga/validar_resultados.php`: sumas de control, ausencia de duplicados (idempotencia) y ausencia de fugas de alcance |
| Repetición | Tras cada optimización se repite el escenario comparable con la misma versión del dataset |

### H.4 Script de carga k6

```javascript archivo=tests/carga/escenarios.js
/**
 * Pruebas de carga de Producción CAB (RNF-PERF-015). Modelo abierto (tasa de llegada) para que la carga no
 * disminuya cuando el servidor se ralentiza. Ejecutar desde un equipo externo al servidor:
 *   k6 run -e BASE_URL=https://staging.dominio -e ESCENARIO=sostenida -e VERSION=1.0.0-rc1 \
 *          -e USUARIOS=./usuarios.json tests/carga/escenarios.js
 * Los thresholds hacen fallar la ejecución si se incumplen los SLO (RNF-PERF-003 a RNF-PERF-006).
 */
import http from 'k6/http';
import { check, fail, group, sleep } from 'k6';
import { SharedArray } from 'k6/data';
import { Counter, Trend } from 'k6/metrics';
import exec from 'k6/execution';

const BASE = __ENV.BASE_URL || 'https://staging.dominio-del-centro.gov.co';
const ESCENARIO = __ENV.ESCENARIO || 'smoke';
const usuarios = new SharedArray('usuarios', () => JSON.parse(open(__ENV.USUARIOS || './usuarios.json')));
const aceptacionTrabajo = new Trend('aceptacion_trabajo_ms', true);
const tiempoTotalExportacion = new Trend('exportacion_total_ms', true);
const fugasAlcance = new Counter('fugas_alcance');

// Solo los 5xx y los errores de red son errores inesperados; los 4xx esperados (403, 409, 422) no cuentan.
http.setResponseCallback(http.expectedStatuses({ min: 200, max: 499 }));

const ESCENARIOS = {
  smoke: { executor: 'constant-arrival-rate', rate: 1, timeUnit: '1s', duration: '2m', preAllocatedVUs: 5, maxVUs: 10 },
  sostenida: { executor: 'constant-arrival-rate', rate: 5, timeUnit: '1s', duration: '30m', preAllocatedVUs: 20, maxVUs: 60 },
  pico: {
    executor: 'ramping-arrival-rate', startRate: 1, timeUnit: '1s', preAllocatedVUs: 20, maxVUs: 80,
    stages: [{ target: 5, duration: '5m' }, { target: 10, duration: '5m' }, { target: 10, duration: '10m' }, { target: 1, duration: '5m' }],
  },
  rafaga: {
    executor: 'ramping-arrival-rate', startRate: 2, timeUnit: '1s', preAllocatedVUs: 30, maxVUs: 100,
    stages: [{ target: 2, duration: '2m' }, { target: 16, duration: '30s' }, { target: 16, duration: '60s' }, { target: 2, duration: '2m' }],
  },
  resistencia: { executor: 'constant-arrival-rate', rate: 3, timeUnit: '1s', duration: '2h', preAllocatedVUs: 15, maxVUs: 40 },
};

const toleranciaErrores = ESCENARIO === 'pico' || ESCENARIO === 'rafaga' ? 'rate<0.02' : 'rate<0.005';

export const options = {
  scenarios: { [ESCENARIO]: ESCENARIOS[ESCENARIO] },
  thresholds: {
    http_req_failed: [{ threshold: toleranciaErrores, abortOnFail: true, delayAbortEval: '2m' }],
    'http_req_duration{flujo:login}': ['p(95)<900'],
    'http_req_duration{flujo:tablero}': ['p(95)<1200'],
    'http_req_duration{flujo:tablero_caliente}': ['p(95)<500'],
    'http_req_duration{flujo:filtro}': ['p(95)<800'],
    'http_req_duration{flujo:listado}': ['p(95)<600'],
    'http_req_duration{flujo:formulario}': ['p(95)<700'],
    'http_req_duration{flujo:estado_trabajo}': ['p(95)<250'],
    aceptacion_trabajo_ms: ['p(95)<400'],
    exportacion_total_ms: ['p(95)<180000'],
    fugas_alcance: ['count==0'],
    dropped_iterations: ['count<10'],
    checks: ['rate>0.99'],
  },
};

const sesiones = {};

function csrfDe(respuesta) {
  return respuesta.html().find('meta[name="csrf-token"]').attr('content') || '';
}

function iniciarSesion(usuario) {
  const formulario = http.get(`${BASE}/ingresar`, { tags: { flujo: 'login' } });
  const csrf = formulario.html().find('input[name="_csrf"]').attr('value');
  const respuesta = http.post(`${BASE}/ingresar`, { correo: usuario.correo, contrasena: usuario.contrasena, _csrf: csrf }, { tags: { flujo: 'login' }, redirects: 0 });
  if (!check(respuesta, { 'inicio de sesión responde 303': (r) => r.status === 303 })) {
    fail(`No se pudo iniciar sesión con la cuenta de prueba ${usuario.alias}`);
  }
}

function usuarioDelVu() {
  const usuario = usuarios[(exec.vu.idInTest - 1) % usuarios.length];
  if (!sesiones[exec.vu.idInTest]) {
    iniciarSesion(usuario);
    sesiones[exec.vu.idInTest] = true;
  }
  return usuario;
}

function verificarAlcance(usuario, respuesta) {
  // Un líder de unidad nunca debe recibir marcas de otras unidades (RDI-006, Obs. 4).
  if (usuario.rol !== 'lider_unidad') return;
  for (const ajena of usuario.unidadesAjenas) {
    if (respuesta.body && respuesta.body.includes(`data-unidad="${ajena}"`)) fugasAlcance.add(1);
  }
}

function tablero(usuario) {
  const htmx = { 'HX-Request': 'true' };
  const filtros = `vigencia=2026&desde=2026-0${1 + Math.floor(Math.random() * 6)}-01&hasta=2026-12-31`;
  const pagina = http.get(`${BASE}/tablero/central?${filtros}`, { tags: { flujo: 'tablero' } });
  check(pagina, { 'tablero 200': (r) => r.status === 200 });
  verificarAlcance(usuario, pagina);
  const repetida = http.get(`${BASE}/tablero/central?vigencia=2026`, { tags: { flujo: 'tablero_caliente' } });
  check(repetida, { 'tablero caliente 200': (r) => r.status === 200 });
  const fragmento = http.get(`${BASE}/tablero/central?${filtros}&canal=AUTOCONSUMO`, { headers: htmx, tags: { flujo: 'filtro' } });
  check(fragmento, { 'fragmento 200 con Vary': (r) => r.status === 200 && (r.headers['Vary'] || '').includes('HX-Request') });
  verificarAlcance(usuario, fragmento);
}

function listado(usuario) {
  const respuesta = http.get(`${BASE}/produccion/recepciones?pagina=${1 + Math.floor(Math.random() * 5)}&q=leche`, { headers: { 'HX-Request': 'true' }, tags: { flujo: 'listado' } });
  check(respuesta, { 'listado 200': (r) => r.status === 200 });
  verificarAlcance(usuario, respuesta);
}

function registrarGasto(usuario) {
  const formulario = http.get(`${BASE}/presupuesto/gastos/nuevo`, { headers: { 'HX-Request': 'true' }, tags: { flujo: 'formulario' } });
  const csrf = csrfDe(http.get(`${BASE}/presupuesto/gastos`, { tags: { flujo: 'listado' } })) || csrfDe(formulario);
  const clave = `carga-${exec.vu.idInTest}-${exec.scenario.iterationInTest}`;
  const respuesta = http.post(`${BASE}/presupuesto/gastos`, {
    unidad: usuario.unidadPropia, fecha: '2026-11-03', categoria: 'MANTENIMIENTO', valor: '125000', valor_componente_academico: '0',
    descripcion: 'Gasto sintético de prueba de carga',
  }, { headers: { 'HX-Request': 'true', 'X-CSRF-Token': csrf, 'X-Idempotency-Key': clave }, tags: { flujo: 'formulario' } });
  check(respuesta, { 'gasto creado o duplicado idempotente': (r) => r.status === 201 || r.status === 200 });
}

function exportar() {
  const csrf = csrfDe(http.get(`${BASE}/presupuesto/gastos`, { tags: { flujo: 'listado' } }));
  const inicio = Date.now();
  const aceptada = http.post(`${BASE}/exportaciones`, { tipo: 'gastos', formato: 'xlsx', vigencia: '2026' }, {
    headers: { 'X-CSRF-Token': csrf, 'X-Idempotency-Key': `exp-${exec.vu.idInTest}-${exec.scenario.iterationInTest}`, Accept: 'application/json' },
    tags: { flujo: 'formulario' },
  });
  aceptacionTrabajo.add(aceptada.timings.duration);
  if (!check(aceptada, { 'exportación 202': (r) => r.status === 202 })) return;
  const ubicacion = aceptada.headers['Location'];
  for (const espera of [2, 3, 5, 8, 13, 15, 15, 15, 15, 15, 15, 15, 15, 15]) {
    sleep(espera);
    const estado = http.get(`${BASE}${ubicacion}`, { headers: { Accept: 'application/json' }, tags: { flujo: 'estado_trabajo' } });
    const cuerpo = estado.json();
    if (cuerpo && ['completado', 'fallido', 'cancelado'].includes(cuerpo.estado)) {
      tiempoTotalExportacion.add(Date.now() - inicio);
      check(cuerpo, { 'exportación completada': (c) => c.estado === 'completado' });
      return;
    }
  }
}

export default function () {
  const usuario = usuarioDelVu();
  const azar = Math.random();
  group('acciones', () => {
    if (azar < 0.35) tablero(usuario);
    else if (azar < 0.75) listado(usuario);
    else if (azar < 0.92) registrarGasto(usuario);
    else exportar();
  });
  sleep(1 + Math.random() * 2);
}

export function handleSummary(datos) {
  const destino = `tests/carga/resultados/${__ENV.VERSION || 'sin-version'}/${ESCENARIO}.json`;
  return { [destino]: JSON.stringify(datos, null, 2) };
}
```

```php archivo=tests/carga/validar_resultados.php
<?php
declare(strict_types=1);

/**
 * Validación de resultados de negocio tras una prueba de carga (RNF-PERF-005). Compara las sumas de control con
 * las registradas antes de la prueba y busca duplicados por idempotencia y fugas de alcance en la auditoría.
 * Uso: php tests/carga/validar_resultados.php --antes=sumas_antes.json --esperados=esperados.json
 */

use App\Nucleo\BaseDatos;
use App\Nucleo\Entorno;

define('RAIZ', dirname(__DIR__, 2));
require RAIZ . '/vendor/autoload.php';
Entorno::cargar(RAIZ . '/.env');

$opciones = getopt('', ['antes:', 'esperados:']);
$antes = json_decode((string) file_get_contents($opciones['antes'] ?? 'sumas_antes.json'), true, 16, JSON_THROW_ON_ERROR);
$esperados = json_decode((string) file_get_contents($opciones['esperados'] ?? 'esperados.json'), true, 16, JSON_THROW_ON_ERROR);
$bd = new BaseDatos(require RAIZ . '/config/base_datos.php');

$fallos = [];
$gastosNuevos = (int) $bd->valor("SELECT COUNT(*) FROM gastos_ejecutados WHERE descripcion = 'Gasto sintético de prueba de carga'");
if ($gastosNuevos !== (int) $esperados['gastos_creados']) {
    $fallos[] = "Gastos creados: {$gastosNuevos}; esperados por respuestas 201: {$esperados['gastos_creados']} (posible duplicado o pérdida).";
}
$duplicados = (int) $bd->valor('SELECT COUNT(*) FROM (SELECT clave FROM claves_idempotencia GROUP BY usuario_id, clave HAVING COUNT(*) > 1) d');
if ($duplicados > 0) {
    $fallos[] = "Claves de idempotencia duplicadas: {$duplicados}.";
}
foreach (['recaudos' => 'valor', 'salidas' => 'valor'] as $tabla => $columna) {
    $suma = (string) $bd->valor("SELECT COALESCE(SUM({$columna}), 0) FROM {$tabla} WHERE archivado_en IS NULL");
    // Comparación a centavos con el valor DECIMAL devuelto como texto (sin depender de la extensión bcmath).
    if (number_format((float) $suma, 2, '.', '') !== number_format((float) $antes[$tabla], 2, '.', '')) {
        $fallos[] = "Suma de {$tabla} cambió sin escrituras previstas: {$antes[$tabla]} -> {$suma}.";
    }
}
$cola = (int) $bd->valor("SELECT COUNT(*) FROM trabajos WHERE estado IN ('pendiente', 'reservado')");
if ($cola > 0) {
    $fallos[] = "La cola no se vació: {$cola} trabajos pendientes o reservados.";
}

if ($fallos !== []) {
    fwrite(STDERR, implode(PHP_EOL, $fallos) . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "Integridad y aislamiento verificados tras la prueba de carga.\n");
```

### H.5 Plantilla del informe por versión (`tests/carga/resultados/<versión>/informe.md`)

| Sección | Contenido |
|---|---|
| Identificación | Versión y commit, fecha, responsable, escenario y comandos exactos |
| Entorno | Plan y recursos de hPanel, versiones de PHP y MariaDB, diferencias con producción |
| Dataset | Escala, sumas de control antes y después |
| Resultados por flujo | p50, p95 y p99, errores y rendimiento alcanzado frente al ofrecido; cumplimiento de cada umbral |
| Cola | Espera p95, tiempo total, profundidad máxima y tiempo de vaciado |
| Recursos | CPU, RAM, procesos PHP, conexiones y bloqueos de MariaDB, aciertos de la caché |
| Integridad y aislamiento | Resultado de `validar_resultados.php` y del contador `fugas_alcance` |
| Limitaciones | Lo que no demuestran los dobles; diferencias con producción; validez de la extrapolación |
| Decisiones | Optimizaciones aplicadas y repeticiones; señales de ampliación (RNF-PERF-019) |
| Estado | **PENDIENTE DE EJECUCIÓN** hasta la primera corrida en la I7 |
