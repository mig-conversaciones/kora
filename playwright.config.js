// Pruebas en navegador de KORA (I8): accesibilidad WCAG 2.2 AA con axe (RUI-002 a RUI-024, RNF-003), CSP sin
// violaciones (RS-007) y flujos de retroalimentación (RUI-009 a RUI-016). Corren en la CI contra el servidor embebido
// de PHP (tests/seguridad/servidor.php) con el dataset «mini»; la URL y las cuentas llegan por variables de entorno.
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: 'tests/e2e',
  // Playwright vacía outputDir al empezar: va en una subcarpeta para no borrar las sesiones ni el registro de axe.
  outputDir: 'almacenamiento/construccion/e2e/artefactos',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 60_000,
  reporter: [['list'], ['json', { outputFile: 'almacenamiento/construccion/e2e/resultados.json' }]],
  globalSetup: './tests/e2e/preparar-sesiones.js',
  use: {
    baseURL: process.env.KORA_URL ?? 'http://127.0.0.1:8080',
    locale: 'es-CO',
    timezoneId: 'America/Bogota',
    trace: 'retain-on-failure',
  },
  projects: [
    { name: 'escritorio', use: { ...devices['Desktop Chrome'] } },
    { name: 'movil', use: { ...devices['Pixel 7'] }, testMatch: /accesibilidad\.spec\.js/ },
  ],
});
