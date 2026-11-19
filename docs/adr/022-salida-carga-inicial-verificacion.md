# ADR-022: Carga inicial con verificación que se repite al firmar, lista ASVS generada desde el texto oficial y verificación de navegador y DAST en la CI

- Estado: Aceptado
- Fecha: 2026-11-19
- Responsables: Líder técnico (Senior); revisión de la contraparte del CAB y del responsable de seguridad
- Requisitos relacionados: RF-094, RDL-011, RS-001, RS-016, RGO-009, RDOC-009, RUI-002 a RUI-024, RNF-003; ADR-019, ADR-021

## Contexto

La I8 prepara la salida a producción del piloto. Cuatro necesidades guiaron las decisiones:

- **Carga inicial (RF-094).** El acta de carga de la I5 compara cada lote por separado, pero la producción empieza con un procedimiento completo, en orden y firmado. Una carga con diferencias no puede quedar «verificada». Además, entre la verificación y la firma pueden pasar días.
- **Lista ASVS (RS-001).** El SRS pide no citar requisitos de memoria. El anexo J solo tiene la estructura por capítulos.
- **DAST y accesibilidad (RS-016, anexo M.1).** No hay staging, y en Windows no hay Docker, que es donde corre ZAP. axe necesita un navegador real.
- **Guías (RDOC-009).** Se piden en PDF y como ayuda en línea, y deben validarse en la capacitación. Dos fuentes separadas divergirían.

## Decisión

### Carga inicial (`ServicioCargaInicial`, tabla `cargas_iniciales`)

- **Pasos.** Un orden fijo de fuentes: el catálogo primero, el hato al final. Cada fuente se importa con el flujo normal de la I5. Para cada fuente vale el último lote aplicado desde el inicio de la carga: si un archivo tenía errores, se corrige y se vuelve a cargar.
- **Verificación.** Compara el «archivo» (filas y sumas al leer el libro, con las filas con error) con la «base», que se recalcula en ese momento desde la tabla de la fuente con lo que sigue vigente del lote. Compara el total y cada unidad. También es una diferencia:
  - una fuente obligatoria sin cargar;
  - un lote más reciente sin aplicar;
  - un registro del centro que no viene de la carga (RDL-011).
- **Firma.** Solo para una carga verificada, con reautenticación y declaración explícita. Antes de firmar, **se verifica de nuevo** y se compara la huella SHA-256 del resultado: si algo cambió, no se firma. La firma, la huella y el firmante quedan en el acta y en la auditoría.
- **Una carga por centro.** Una carga firmada no se repite. Anular una carga no revierte sus lotes.

### Lista ASVS generada (`herramientas/generar-asvs.php`)

- El CSV oficial de ASVS 5.0.0 (CC BY-SA 4.0) se versiona en `docs/seguridad/fuentes/`.
- El estado de cada requisito vive en `docs/seguridad/asvs-estado.csv`: Cumple, Parcial, Staging, Decisión o No aplica, siempre con su evidencia.
- `docs/seguridad/asvs.md` se genera. `ListaAsvsTest` falla si falta un requisito de nivel 1 o 2 en alcance, o si el documento no coincide con su fuente.
- Las brechas frente al nivel 2 no se esconden: quedan como «Decisión», para que la contraparte las acepte o pida el cambio. Por ejemplo, la 2FA para todos los roles (V6.3.3) y el antivirus para los adjuntos (V5.4.3).

### Navegador y DAST en la CI (trabajos «Secretos» y «Navegador, accesibilidad y ZAP»)

- **Servidor.** El servidor embebido de PHP, con un router de pruebas (`tests/seguridad/servidor.php`), sirve la aplicación con MariaDB 10.6 y el dataset «mini» del generador de la I7, que crea además las cuentas por rol.
- **Playwright 1.55.1 y axe-core 4.10.1.** WCAG 2.2 AA en escritorio y móvil, violaciones de CSP y flujos de teclado. Se ingresa una vez por rol, porque el límite de ingresos por IP también cuenta los ingresos correctos.
- **OWASP ZAP 2.16.1.** Baseline sin sesión y escaneo completo autenticado: la cookie de sesión viene de un ingreso real con CSRF y se inyecta con el *replacer*. Un riesgo alto hace fallar el trabajo; el resto queda como anotación.
- **gitleaks 8.21.2.** Revisa todo el historial con valores ocultos; cualquier hallazgo bloquea.

### Guías con una sola fuente (`docs/guias/`)

- La ayuda en línea (`/ayuda`) renderiza las guías con un Markdown mínimo propio que escapa todo el texto y solo enlaza guías, anclas y `https`.
- Los PDF se generan desde esa misma página con Playwright (`npm run guias:pdf`).
- El paquete de despliegue conserva `docs/guias/` y descarta el resto de `docs/`.

## Consecuencias

- **Positivas.**
  - La carga no se puede firmar sobre datos que cambiaron.
  - El ensayo con los libros reales ya mostró las 21 filas que hay que corregir antes de producción.
  - La lista ASVS no se desactualiza sin que la CI lo note.
  - Cada push audita la accesibilidad y la seguridad en un navegador real.
- **Negativas.**
  - El escaneo de ZAP en la CI no reemplaza el de staging (TLS, LiteSpeed, proxy y cabeceras del servidor). Queda pendiente.
  - axe no reemplaza la revisión manual con lector de pantalla.
  - El trabajo de navegador alarga la CI unos 15 minutos.
- **Riesgos.**
  - Los primeros pasos de ZAP y gitleaks en la CI pueden marcar falsos positivos; se revisan y se exceptúan con justificación (`.gitleaks.toml`).
  - El servidor embebido de PHP no tiene el comportamiento exacto de LiteSpeed.

## Alternativas consideradas

- **Verificar solo al final, sin repetir al firmar:** se descartó porque entre ambos momentos se pueden revertir lotes o editar registros.
- **Escribir la lista ASVS a mano en Markdown:** se descartó por el riesgo de numeración inventada y de desactualización.
- **Pa11y o Lighthouse para accesibilidad:** cubren menos reglas de WCAG 2.2 que axe y no reutilizan las sesiones por rol de Playwright.
- **Guías en Word o PDF aparte:** se descartó porque dos fuentes divergen.
