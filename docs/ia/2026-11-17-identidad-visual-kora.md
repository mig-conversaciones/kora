# Registro de entrega asistida por IA — Identidad visual KORA

- **Fecha:** 2026-11-17
- **Herramienta y modelo:** Claude Code (extensión de VS Code), modelo Claude Opus 5.5 (`claude-opus-5-5`)
- **Responsable humano:** líder técnico del proyecto, que revisa y aprueba
- **Requisitos relacionados:** RUI-001, RUI-002, RUI-003, RUI-005, RUI-007, S-03; [ADR-016](../adr/016-identidad-visual-kora.md)

## Objetivo

Revisar el kit `referencias/KORA_kit_identidad` y adoptar en la aplicación su logo, sus íconos y la paleta recomendada antes de continuar con la I1. El kit contiene:

- **Logos:** horizontal claro y oscuro, vertical y monocromático.
- **Íconos:** favicon en SVG, PNG e ICO, y concepto de ícono móvil de 1024 px.
- **Paleta:** `KORA_paleta_sistema.md`.

## Supuestos

- El kit es la versión vigente de la marca. Su propia nota advierte que no sustituye la revisión de marca ni la disponibilidad del nombre.
- La Guía de tableros de mando sigue exigiendo el verde para venta directa y el ámbar para autoconsumo en los gráficos.

## Decisiones

- **Derivación reproducible desde el kit** con `herramientas/preparar-marca.mjs`. Los trazados y colores no se tocan; solo se recorta el lienzo, se quita el fondo en las versiones para fondos oscuros y se separan el lema y el símbolo.
- **Paleta del kit como capa 1 de los tokens,** con nombres nuevos (`--color-primario`, `--color-acento`, `--color-accion`, etc.). Las series de datos de los tableros quedan separadas de los estados (regla 5 del kit).
- **Turquesa de acción `#087F74`** para botones, enlaces y foco, y turquesa brillante solo como acento sobre azul oscuro (reglas 2 y 3 del kit).
- **Controles operables con `--color-borde-control`** (regla 4 del kit).
- **Modo oscuro derivado,** porque el kit no lo define.
- **ADR-016,** porque el número 015 está reservado en el SRS para el inicio de sesión único.

## Archivos creados o modificados

| Archivo | Cambio |
|---|---|
| `herramientas/preparar-marca.mjs`, `package.json` (`npm run marca`) | Derivación de los recursos de marca |
| `public/marca/*.svg`, `public/favicon.svg` | Logos, símbolo, ícono móvil y favicon del kit |
| `herramientas/construir-activos.mjs` | PNG e ICO desde el favicon y el ícono móvil; ícono adaptable con el símbolo |
| `recursos/css/tokens.css` | Paleta KORA en modo claro y oscuro, series de datos, foco y sombras retintadas |
| `recursos/css/app.css` | Tokens nuevos en todos los componentes, marca en el menú, selección turquesa y bordes de control |
| `tailwind.config.cjs` | Colores de Tailwind con los tokens nuevos |
| `herramientas/verificar-contraste.mjs` | 33 pares, incluidos foco, bordes de control, series y menú |
| `app/Vistas/parciales/menu.php`, `app/Vistas/diseno/base.php`, `public/site.webmanifest` | Logo, color del tema y descripción |
| `app/Vistas/sistema_diseno/contenido.php` | Sección de marca y paleta agrupada |
| `public/assets/mantenimiento/aviso.css` | Paleta del aviso de mantenimiento |
| `docs/adr/016-…`, `docs/adr/README.md`, `docs/ui/*.md`, `CHANGELOG.md`, `README.md` | Documentación |

## Validaciones ejecutadas

- **`npm run contraste`:** los 33 pares cumplen en modo claro y oscuro.
- **`php herramientas/composer.phar verificar`:** PSR-12 sin cambios pendientes, PHPStan sin errores y PHPUnit con 27 pruebas y 132 aserciones.
- **ESLint:** sin errores.
- **`npm run construir`:** JS de 39,9 KB y CSS de 6,8 KB comprimidos con gzip, dentro del presupuesto.
- **Rutas:** `/`, `/sistema-diseno`, `/marca/*.svg`, `/favicon.svg`, `/favicon.ico` y `/icono-512-mascara.png` responden 200.
- **Capturas con Edge en modo headless:** inicio y vitrina en modo claro y oscuro, hoja de contacto de los logos recortados y de los íconos de 32, 180, 192 y 512 px (adaptable).

## Riesgos

- Falta la revisión formal de la marca, la disponibilidad del nombre KORA y la relación con la marca SENA (S-03).
- El SRS (Anexo L, 6.12) quedó desactualizado frente a ADR-016. Debe revisarse en la próxima versión del documento Word.

## Pendientes

- Actualizar el Anexo L del SRS y regenerar el .docx.
- Validar la paleta y el uso del logo con la contraparte en el hito M1.
