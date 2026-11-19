// Flujos de extremo a extremo en navegador real (I8): teclado, retroalimentación centralizada (toasts y diálogo de
// confirmación en lugar de alert/confirm), estados de error y alcance por rol de las observaciones de la contraparte.
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { DIRECTORIO } from './preparar-sesiones.js';

test.describe('Teclado y estructura', () => {
  test.use({ storageState: `${DIRECTORIO}/lider_produccion_centro.json` });

  test('el primer Tab lleva a «Ir al contenido» y este mueve el foco al contenido principal (WCAG 2.4.1)', async ({ page }) => {
    await page.goto('/tableros/central');
    await page.keyboard.press('Tab');
    const enlace = page.getByRole('link', { name: 'Ir al contenido' });
    await expect(enlace).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page.locator('#contenido-principal')).toBeFocused();
  });

  test('con el menú colapsado cada enlace conserva su nombre accesible (WCAG 2.4.4, 4.1.2)', async ({ page }) => {
    await page.goto('/tableros/central');
    const colapsar = page.getByRole('button', { name: 'Contraer o expandir el menú' });
    const estabaExpandida = (await colapsar.getAttribute('aria-expanded')) === 'true';
    if (estabaExpandida) await colapsar.click();
    await expect(colapsar).toHaveAttribute('aria-expanded', 'false');
    const resultado = await new AxeBuilder({ page }).include('#barra-lateral').withTags(['wcag2a', 'wcag2aa', 'wcag22aa']).analyze();
    expect(resultado.violations.map((v) => v.id)).toEqual([]);
    await expect(page.getByRole('link', { name: 'Tablero central' })).toBeVisible();
    if (estabaExpandida) await colapsar.click();
  });

  test('ningún alert, confirm ni prompt nativo en la vitrina de retroalimentación (RUI-009)', async ({ page }) => {
    const dialogosNativos = [];
    page.on('dialog', async (dialogo) => { dialogosNativos.push(dialogo.type()); await dialogo.dismiss(); });
    await page.goto('/sistema-diseno');
    // Solo los botones de la vitrina: los de la barra superior incluyen «Salir», que cerraría la sesión del rol.
    for (const boton of await page.locator('#contenido-principal').getByRole('button').all()) {
      if (await boton.isVisible() && await boton.isEnabled()) {
        await boton.click({ trial: false }).catch(() => {});
        await page.keyboard.press('Escape');
      }
    }
    expect(dialogosNativos).toEqual([]);
  });
});

test.describe('Confirmación accesible', () => {
  test.use({ storageState: `${DIRECTORIO}/coordinacion_administrativa.json` });

  test('el diálogo de confirmación atrapa el foco, se cierra con Escape y no ejecuta la acción', async ({ page }) => {
    await page.goto('/importaciones/carga-inicial');
    const iniciar = page.getByRole('button', { name: 'Iniciar la carga' });
    await iniciar.click();
    const dialogo = page.getByRole('dialog', { name: 'Iniciar la carga inicial' });
    await expect(dialogo).toBeVisible();
    await page.keyboard.press('Tab');
    await expect(dialogo.locator(':focus')).toHaveCount(1);
    await page.keyboard.press('Escape');
    await expect(dialogo).toBeHidden();
    await expect(iniciar).toBeFocused();
    await expect(page.getByRole('heading', { name: 'Iniciar la carga inicial' })).toBeVisible();
  });

  test('Obs. 2: la coordinación administrativa consulta recaudos sin poder registrarlos', async ({ page }) => {
    await page.goto('/recaudos');
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.getByRole('link', { name: /nuevo recaudo|nuevo registro/i })).toHaveCount(0);
    const directo = await page.goto('/recaudos/nuevo');
    expect([403, 404]).toContain(directo?.status());
  });
});

test.describe('Alcance del líder de unidad', () => {
  test.use({ storageState: `${DIRECTORIO}/lider_unidad.json` });

  test('Obs. 4: el tablero central del líder solo ofrece su unidad', async ({ page }) => {
    await page.goto('/tableros/central');
    const selector = page.getByLabel(/unidad/i).first();
    if (await selector.count()) {
      const opciones = await selector.locator('option').allTextContents();
      expect(opciones.filter((o) => o.trim() !== '' && !/todas/i.test(o)).length).toBeLessThanOrEqual(1);
    }
    const ajena = await page.goto('/tableros/unidad/AGR');
    expect([403, 404]).toContain(ajena?.status());
  });
});
