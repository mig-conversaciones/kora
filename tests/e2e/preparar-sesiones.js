// Ingreso una sola vez por rol (el límite de ingresos por IP es de 20 cada 15 min y todo sale de 127.0.0.1) y
// guardado del estado del navegador para cada prueba. Las cuentas las crea cli/generar_dataset.php con su contraseña
// en el archivo KORA_CUENTAS, que nunca se versiona.
import { chromium } from '@playwright/test';
import { mkdirSync, readFileSync } from 'node:fs';

export const ROLES = ['lider_produccion_centro', 'lider_unidad', 'coordinacion_administrativa', 'grupo_recaudo'];
export const DIRECTORIO = 'almacenamiento/construccion/e2e/sesiones';

export function cuenta(rol) {
  const cuentas = JSON.parse(readFileSync(process.env.KORA_CUENTAS ?? 'almacenamiento/carga/usuarios.json', 'utf8'));
  // El líder de unidad es el de Ganadería: es la única unidad con tablero técnico en la v1 (RF-079).
  const elegida = cuentas.find((c) => c.rol === rol && c.centro === '9124' && (rol !== 'lider_unidad' || c.unidadPropia === 'GAN'));
  if (!elegida) throw new Error(`No hay cuenta de prueba con el rol ${rol}.`);
  return elegida;
}

export default async function prepararSesiones(config) {
  const { baseURL } = config.projects[0].use;
  mkdirSync(DIRECTORIO, { recursive: true });
  const navegador = await chromium.launch();
  for (const rol of ROLES) {
    const { correo, contrasena } = cuenta(rol);
    const contexto = await navegador.newContext({ baseURL });
    const pagina = await contexto.newPage();
    await pagina.goto('/ingresar');
    await pagina.getByLabel('Correo').fill(correo);
    await pagina.getByLabel('Contraseña').fill(contrasena);
    await pagina.getByRole('button', { name: /ingresar/i }).click();
    await pagina.waitForURL((url) => !url.pathname.startsWith('/ingresar'), { timeout: 15_000 });
    await contexto.storageState({ path: `${DIRECTORIO}/${rol}.json` });
    await contexto.close();
  }
  await navegador.close();
}
