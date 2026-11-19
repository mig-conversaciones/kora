/**
 * Pruebas de carga de KORA (RNF-PERF-003 a RNF-PERF-006, RNF-PERF-015; Anexo H, H.4). Modelo abierto (tasa de llegada)
 * para que la carga no baje cuando el servidor se ralentiza. Se ejecuta desde un equipo EXTERNO al servidor, solo en
 * staging y con la autorización escrita del titular de la cuenta (docs/rendimiento/plan-carga.md):
 *
 *   k6 run -e BASE_URL=https://staging.dominio -e ESCENARIO=smoke -e VERSION=0.8.0 \
 *          -e USUARIOS=./usuarios.json -e VIGENCIA=2026 tests/carga/escenarios.js
 *
 * usuarios.json lo escribe cli/generar_dataset.php --usuarios-k6 (fuera del repositorio). Los thresholds hacen fallar la
 * ejecución si se incumplen los SLO; fugas_alcance debe quedar en 0 (Obs. 4).
 */
import http from 'k6/http';
import { check, fail, group, sleep } from 'k6';
import { SharedArray } from 'k6/data';
import { Counter, Trend } from 'k6/metrics';
import exec from 'k6/execution';

const BASE = (__ENV.BASE_URL || 'https://staging.invalid').replace(/\/$/, '');
const ESCENARIO = __ENV.ESCENARIO || 'smoke';
const VIGENCIA = __ENV.VIGENCIA || String(new Date().getFullYear());
const usuarios = new SharedArray('usuarios', () => JSON.parse(open(__ENV.USUARIOS || './usuarios.json')));
const aceptacionTrabajo = new Trend('aceptacion_trabajo_ms', true);
const exportacionTotal = new Trend('exportacion_total_ms', true);
const fugasAlcance = new Counter('fugas_alcance');

// Solo los 5xx y los errores de red son inesperados; los 4xx esperados (403, 409, 422) no cuentan.
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
if (!ESCENARIOS[ESCENARIO]) {
  throw new Error(`Escenario desconocido: ${ESCENARIO}`);
}
const toleranciaErrores = ESCENARIO === 'pico' || ESCENARIO === 'rafaga' ? 'rate<0.02' : 'rate<0.005';

export const options = {
  scenarios: { [ESCENARIO]: ESCENARIOS[ESCENARIO] },
  thresholds: {
    // Parada (H.3): más del 5 % de errores sostenido 2 min aborta la prueba.
    http_req_failed: [toleranciaErrores, { threshold: 'rate<0.05', abortOnFail: true, delayAbortEval: '2m' }],
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
  const meta = respuesta.html().find('meta[name="csrf-token"]').attr('content');
  return meta || respuesta.html().find('input[name="_csrf"]').attr('value') || '';
}

function iniciarSesion(usuario) {
  const formulario = http.get(`${BASE}/ingresar`, { tags: { flujo: 'login' } });
  const respuesta = http.post(
    `${BASE}/ingresar`,
    { correo: usuario.correo, contrasena: usuario.contrasena, _csrf: csrfDe(formulario) },
    { tags: { flujo: 'login' }, redirects: 0 },
  );
  if (!check(respuesta, { 'inicio de sesión responde 303': (r) => r.status === 303 && !String(r.headers.Location || '').includes('/ingresar') })) {
    fail(`No se pudo iniciar sesión con la cuenta de carga ${usuario.alias}`);
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

// Un líder de unidad nunca recibe enlaces ni datos de otra unidad, ni siquiera en el HTML (RDI-006, Obs. 4).
function verificarAlcance(usuario, respuesta) {
  if (usuario.rol !== 'lider_unidad' || !respuesta.body) return;
  for (const ajena of usuario.unidadesAjenas) {
    if (respuesta.body.includes(`/tableros/unidad/${ajena}`)) fugasAlcance.add(1);
  }
}

function tablero(usuario) {
  const mes = 1 + Math.floor(Math.random() * 6);
  const filtros = `vigencia=${VIGENCIA}&desde=${VIGENCIA}-0${mes}-01&hasta=${VIGENCIA}-12-31`;
  const pagina = http.get(`${BASE}/tableros/central?${filtros}`, { tags: { flujo: 'tablero' } });
  check(pagina, { 'tablero 200': (r) => r.status === 200 });
  verificarAlcance(usuario, pagina);
  const repetida = http.get(`${BASE}/tableros/central?vigencia=${VIGENCIA}`, { tags: { flujo: 'tablero_caliente' } });
  check(repetida, { 'tablero caliente 200': (r) => r.status === 200 });
  const fragmento = http.get(`${BASE}/tableros/central/procesos?${filtros}&modo=unidades`, { headers: { 'HX-Request': 'true' }, tags: { flujo: 'filtro' } });
  check(fragmento, { 'fragmento 200 con Vary': (r) => r.status === 200 && String(r.headers.Vary || '').includes('HX-Request') });
  verificarAlcance(usuario, fragmento);
  if (usuario.unidadPropia === 'GAN' || usuario.rol !== 'lider_unidad') {
    const tecnico = http.get(`${BASE}/tableros/unidad/GAN?vigencia=${VIGENCIA}`, { tags: { flujo: 'tablero' } });
    check(tecnico, { 'tablero técnico 200 o 404 fuera del alcance': (r) => r.status === 200 || r.status === 404 });
  }
}

function listado(usuario) {
  const ruta = ['/produccion/recepciones', '/produccion/salidas', '/recaudos'][Math.floor(Math.random() * 3)];
  const respuesta = http.get(`${BASE}${ruta}?pagina=${1 + Math.floor(Math.random() * 5)}`, { headers: { 'HX-Request': 'true' }, tags: { flujo: 'listado' } });
  check(respuesta, { 'listado 200': (r) => r.status === 200 });
  verificarAlcance(usuario, respuesta);
}

function registrarGasto(usuario) {
  if (usuario.rol !== 'lider_unidad' || !usuario.unidadPropiaId) {
    listado(usuario);
    return;
  }
  const formulario = http.get(`${BASE}/finanzas/gastos/nuevo`, { tags: { flujo: 'formulario' } });
  const respuesta = http.post(`${BASE}/finanzas/gastos`, {
    unidad_id: String(usuario.unidadPropiaId), fecha: `${VIGENCIA}-11-03`, categoria_id: String(usuario.categoriaGastoId),
    valor: '125000', valor_componente_academico: '0', descripcion: 'Gasto sintético de prueba de carga', _csrf: csrfDe(formulario),
  }, {
    headers: { 'HX-Request': 'true', 'X-CSRF-Token': csrfDe(formulario), 'X-Idempotency-Key': `carga-${exec.vu.idInTest}-${exec.scenario.iterationInTest}` },
    tags: { flujo: 'formulario' },
  });
  check(respuesta, { 'gasto registrado (o 422 por periodo cerrado)': (r) => r.status === 200 || r.status === 303 || r.status === 422 });
}

function exportar(usuario) {
  const pagina = http.get(`${BASE}/tableros/central?vigencia=${VIGENCIA}`, { tags: { flujo: 'tablero_caliente' } });
  const inicio = Date.now();
  const aceptada = http.post(`${BASE}/exportaciones`, { tipo: 'tablero_central', formato: 'csv', vigencia: VIGENCIA, _csrf: csrfDe(pagina) }, {
    headers: { 'HX-Request': 'true', 'X-CSRF-Token': csrfDe(pagina), 'X-Idempotency-Key': `exp-${exec.vu.idInTest}-${exec.scenario.iterationInTest}` },
    tags: { flujo: 'formulario' },
  });
  aceptacionTrabajo.add(aceptada.timings.duration);
  if (!check(aceptada, { 'exportación 202': (r) => r.status === 202 })) return;
  const ulid = (String(aceptada.body).match(/\/exportaciones\/([0-9A-HJKMNP-TV-Z]{26})/) || [])[1];
  if (!ulid) return;
  for (const espera of [2, 3, 5, 8, 13, 15, 15, 15, 15, 15, 15, 15, 15, 15]) {
    sleep(espera);
    const estado = http.get(`${BASE}/exportaciones/${ulid}`, { headers: { 'HX-Request': 'true' }, tags: { flujo: 'estado_trabajo' } });
    if (String(estado.body).includes(`/exportaciones/${ulid}/descargar`) || estado.status >= 400) {
      exportacionTotal.add(Date.now() - inicio);
      check(estado, { 'exportación lista': (r) => r.status === 200 });
      verificarAlcance(usuario, estado);
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
    else if (azar < 0.97) exportar(usuario);
    else iniciarSesion(usuario);
  });
  sleep(1 + Math.random() * 2);
}

export function handleSummary(datos) {
  const destino = `tests/carga/resultados/${__ENV.VERSION || 'sin-version'}/${ESCENARIO}.json`;
  return { [destino]: JSON.stringify(datos, null, 2) };
}
