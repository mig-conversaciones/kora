// Auditoría automática WCAG 2.2 AA con axe-core (RUI-002 a RUI-024, RNF-003; anexo M.1) y CSP sin violaciones
// (RS-007, RS-008) en las pantallas principales de cada rol, en escritorio y en móvil. axe no reemplaza la revisión
// manual con teclado y lector de pantalla (docs/ui/auditoria-wcag.md), pero ninguna versión sale con violaciones.
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { appendFileSync, mkdirSync } from 'node:fs';
import { DIRECTORIO } from './preparar-sesiones.js';

const ETIQUETAS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const REGISTRO = 'almacenamiento/construccion/e2e/axe.jsonl';

const PANTALLAS = {
  publico: ['/ingresar', '/cuenta/recuperar', '/ruta-que-no-existe'],
  lider_produccion_centro: ['/', '/tableros/central', '/tableros/unidad/GAN', '/produccion/recepciones', '/produccion/recepciones/nueva',
    '/produccion/salidas', '/produccion/salidas/nueva', '/produccion/transformaciones', '/calidad', '/importaciones', '/catalogos/unidades',
    '/catalogos/productos', '/catalogos/parametros', '/cuenta', '/sistema-diseno'],
  lider_unidad: ['/tableros/central', '/tableros/unidad/GAN', '/hato', '/finanzas/gastos', '/finanzas/gastos/nuevo'],
  coordinacion_administrativa: ['/finanzas/asignaciones', '/finanzas/gastos', '/finanzas/ejecucion', '/finanzas/procesos', '/finanzas/periodos',
    '/recaudos', '/importaciones/carga-inicial', '/importaciones/acta', '/ayuda', '/ayuda/coordinacion-administrativa', '/ayuda/carga-inicial'],
  grupo_recaudo: ['/recaudos', '/recaudos/clasificar', '/recaudos/diferencias'],
};

mkdirSync('almacenamiento/construccion/e2e', { recursive: true });

for (const [rol, rutas] of Object.entries(PANTALLAS)) {
  test.describe(`Accesibilidad: ${rol}`, () => {
    if (rol !== 'publico') test.use({ storageState: `${DIRECTORIO}/${rol}.json` });

    for (const ruta of rutas) {
      test(`${ruta} sin violaciones WCAG 2.2 AA ni de CSP`, async ({ page }, informacion) => {
        const violacionesCsp = [];
        page.on('console', (mensaje) => {
          if (mensaje.type() === 'error' && /Content Security Policy/i.test(mensaje.text())) violacionesCsp.push(mensaje.text());
        });
        const respuesta = await page.goto(ruta);
        expect([200, 404]).toContain(respuesta?.status());
        expect(page.url(), 'La sesión del rol sigue vigente.').not.toContain('/ingresar?');
        await page.waitForLoadState('networkidle');

        const resultado = await new AxeBuilder({ page }).withTags(ETIQUETAS).analyze();
        const resumen = resultado.violations.map((v) => ({ regla: v.id, impacto: v.impact, nodos: v.nodes.length, ejemplo: v.nodes[0]?.target.join(' ') }));
        appendFileSync(REGISTRO, `${JSON.stringify({ proyecto: informacion.project.name, rol, ruta, violaciones: resumen, reglas: resultado.passes.length })}\n`);

        expect(resumen, `Violaciones de axe en ${ruta}`).toEqual([]);
        expect(violacionesCsp, `Violaciones de CSP en ${ruta}`).toEqual([]);
      });
    }
  });
}
