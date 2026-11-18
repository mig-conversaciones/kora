# SRS de Producción CAB — fuentes y generador

Este directorio contiene la Especificación de Requisitos de Software (SRS-PCAB-001) del sistema que reemplazará al prototipo `dashboard_laila`. El Word es un producto generado: **se edita el Markdown, no el .docx**.

| Ruta | Contenido |
|---|---|
| `SRS_Produccion_CAB_v1.0.docx` | Entregable formal (Word, carta, índice actualizado) |
| `SRS_Produccion_CAB_v1.0.pdf` | Copia de lectura exportada por Word |
| `fuente/metadatos.json` | Portada, control de versiones, aprobaciones, encabezado y pie |
| `fuente/NN-*.md` | Secciones del documento, en el orden de su prefijo numérico |
| `diagramas/*.svg` | Diagramas fuente; los `.png` y `tamanos.json` se generan |
| `generador/` | Scripts de verificación, generación y exportación |

## Requisitos del equipo

- Node.js 20 o superior (probado con Node 24).
- PHP 8.3 en el `PATH` para el verificador (`php -l` sobre los bloques de código). Si no está, el verificador omite esa comprobación y lo informa.
- Git Bash (`bash -n` sobre los scripts de shell).
- Microsoft Word en Windows para actualizar el índice y exportar el PDF (paso opcional).

## Regenerar el documento

```powershell
cd docs/srs/generador
npm install            # primera vez
npm run diagramas      # SVG -> PNG (sharp) y tamanos.json
npm run verificar      # IDs únicos, referencias, encabezados obligatorios, sintaxis del código
npm run generar        # escribe ../SRS_Produccion_CAB_v1.0.docx
npm run pdf            # Word: actualiza índice y campos, guarda y exporta el PDF
# o todo junto:
npm run todo
```

Sin `npm run pdf`, el índice del .docx aparece vacío hasta que se abra en Word y se pulse «Actualizar tabla» (F9).

Para revisar páginas como imagen: `node rasterizar-pdf.mjs <pdf> <carpeta-salida> 1,86,188`.

## Convenciones del Markdown

| Marca | Efecto |
|---|---|
| `<!-- salto -->` | Salto de página |
| `<!-- horizontal -->` / `<!-- vertical -->` | Nueva sección con orientación horizontal o vertical |
| `<!-- anchos: 10,30,60 -->` | Anchos relativos de las columnas de la tabla siguiente |
| `<!-- tabla-densa -->` | Tabla siguiente con letra reducida |
| `<!-- matriz-trazabilidad -->` | Inserta la matriz requisito → implementación → prueba → evidencia |
| `<!-- resumen-requisitos -->` | Inserta el conteo de requisitos por familia |
| ```` ```gantt-tabla ```` | Bloque JSON convertido en el Gantt sombreado |
| ```` ```php archivo=ruta ```` | Bloque de código con su ruta como título |
| Párrafo que empieza con **Supuesto**, **Decisión**, **Advertencia**, **Riesgo**, **Pendiente**, **Importante** o **Nota** | Recuadro de color |

Los requisitos son tablas cuya primera columna se titula `ID`. Las celdas `Impl.:` y `Verif.:` alimentan la matriz de trazabilidad. Cada familia necesita al menos 5 requisitos y cada prueba citada debe seguir la convención `<tipo>-<ID>` (sección 3.1).

## Cambios al documento

1. Editar la fuente y aumentar la versión en `fuente/metadatos.json` (control de versiones).
2. `npm run todo` y revisar el PDF.
3. Registrar la entrega en `docs/ia/` si se usó asistencia de IA (anexo F).
