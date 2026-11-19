// Guías en PDF (RDOC-009) generadas desde la ayuda en línea: la misma fuente (docs/guias) y la misma hoja de
// impresión que usa «Imprimir o guardar en PDF». Necesita la aplicación en marcha y una cuenta con permiso para cada
// guía (la de operación solo la ve quien consulta la salud del sistema).
//
//   KORA_URL=http://127.0.0.1:8080 KORA_CORREO=... KORA_CONTRASENA=... npm run guias:pdf
//
// Los PDF quedan en almacenamiento/construccion/guias/ y no se versionan.
import { chromium } from '@playwright/test';
import { mkdirSync } from 'node:fs';

const base = process.env.KORA_URL ?? 'http://127.0.0.1:8080';
const correo = process.env.KORA_CORREO;
const contrasena = process.env.KORA_CONTRASENA;
if (!correo || !contrasena) {
  process.stderr.write('Indica KORA_CORREO y KORA_CONTRASENA de una cuenta de staging o de pruebas.\n');
  process.exit(2);
}
const salida = 'almacenamiento/construccion/guias';
mkdirSync(salida, { recursive: true });

const navegador = await chromium.launch();
const pagina = await (await navegador.newContext({ baseURL: base, locale: 'es-CO' })).newPage();
await pagina.goto('/ingresar');
await pagina.getByLabel('Correo').fill(correo);
await pagina.getByLabel('Contraseña').fill(contrasena);
await pagina.getByRole('button', { name: /ingresar/i }).click();
await pagina.waitForURL((url) => !url.pathname.startsWith('/ingresar'));

await pagina.goto('/ayuda');
const guias = await pagina.locator('#panel-modulo a[href*="/ayuda/"]').evaluateAll((enlaces) => enlaces.map((a) => a.getAttribute('href')));
for (const ruta of guias) {
  await pagina.goto(ruta);
  await pagina.emulateMedia({ media: 'print' });
  const nombre = ruta.split('/').pop();
  await pagina.pdf({
    path: `${salida}/KORA-guia-${nombre}.pdf`,
    format: 'A4',
    margin: { top: '18mm', bottom: '18mm', left: '16mm', right: '16mm' },
    displayHeaderFooter: true,
    headerTemplate: '<div style="font-size:8px;width:100%;padding:0 16mm;color:#555">KORA — Centro Agropecuario de Buga</div>',
    footerTemplate: '<div style="font-size:8px;width:100%;padding:0 16mm;color:#555;text-align:right"><span class="pageNumber"></span> / <span class="totalPages"></span></div>',
  });
  await pagina.emulateMedia({ media: 'screen' });
  process.stdout.write(`${salida}/KORA-guia-${nombre}.pdf\n`);
}
await navegador.close();
