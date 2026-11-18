# ADR-016: Identidad visual KORA (logo, íconos y paleta del sistema)

- Estado: Aceptado
- Fecha: 2026-11-17
- Responsables: Líder técnico (Senior) y UX/UI; revisión de la contraparte del CAB
- Requisitos relacionados: RUI-001, RUI-002, RUI-003, RUI-005, RUI-007, S-03, R12

## Contexto

El SRS (Anexo L) proponía una identidad provisional: favicon de hoja y gota de leche y una paleta verde derivada del prototipo, a la espera de la autorización de la marca SENA (S-03, riesgo R12). Al nombrar la aplicación KORA (ADR-014) se entregó un kit de identidad propio en `referencias/KORA_kit_identidad`, que incluye:

- **Logos:** horizontal claro y oscuro, vertical y monocromático.
- **Íconos:** favicon y concepto de ícono móvil de 1024 px.
- **Paleta del sistema:** `KORA_paleta_sistema.md`, con azul pizarra `#182B43`, turquesa `#20BFAE`, turquesa de acción `#087F74`, neutros y estados propios.

## Decisión

1. **El kit es la fuente de verdad de la marca.** `herramientas/preparar-marca.mjs` (`npm run marca`) deriva los recursos web sin alterar trazados ni colores:
   - recorta el lienzo;
   - deja fondo transparente en las versiones para fondos oscuros;
   - genera versiones sin lema para tamaños pequeños y el símbolo suelto.

   Los resultados van a `public/marca/*` y `public/favicon.svg`. `npm run construir` genera los PNG e ICO.
2. **Aplicación en la interfaz:**
   - **Menú lateral:** azul pizarra, con el logo horizontal oscuro sin lema y el símbolo cuando el menú está colapsado.
   - **Selección en el menú:** turquesa de acento, como franja lateral y fondo al 16 %.
   - **Botones primarios, enlaces, foco y controles activos:** turquesa de acción. El turquesa brillante no lleva texto blanco (2,3:1, regla 3 del kit).
3. **Tokens nuevos** en `recursos/css/tokens.css`, que reemplazan los del Anexo L:
   - **Marca:** `--color-primario`, `--color-acento`, `--color-accion`, `--color-acento-suave`.
   - **Neutros:** `--color-fondo`, `--color-superficie`, `--color-superficie-alt`, `--color-texto`, `--color-texto-secundario`, `--color-linea`, `--color-borde-control`.
   - **Estados del kit:** `--color-exito`, `--color-advertencia`, `--color-error`, `--color-info` y sus fondos.
4. **Las series de datos de los tableros son tokens aparte** (`--serie-venta`, `--serie-autoconsumo`, `--serie-presupuesto`, `--serie-asignacion`), distintos de los estados (regla 5 del kit):
   - Se conservan el verde de venta directa y el ámbar de autoconsumo que exige la Guía de tableros de mando.
   - El presupuesto pasa al azul pizarra de la marca.
5. **Los controles operables** (campos y botones secundarios) usan `--color-borde-control` (#7D8FA0, 3,3:1), según la regla 4 del kit.
6. **Modo oscuro derivado** de la familia del azul pizarra, porque el kit solo define el claro. En oscuro, la acción es el turquesa brillante con texto oscuro.
7. Se conservan la estructura (espaciado y radios), la tipografía Inter y el skeuomorfismo sutil: solo se retiñeron los gradientes y las sombras.

## Alternativas consideradas

- **Mantener la paleta verde del Anexo L con el logo nuevo:** incoherente con la marca entregada.
- **Usar los colores de estado del kit también para las series:** contradice la regla 5 del kit y los colores exigidos por la guía de tableros.
- **Usar los PNG del kit directamente:** pesan más y no se adaptan; el SVG recortado es nítido en cualquier tamaño.

## Consecuencias

- `npm run contraste` verifica 33 pares en modo claro y oscuro. Todos cumplen: 4,5:1 para texto y 3:1 para componentes y gráficos.
- **Diferencias con el SRS:** el Anexo L y el favicon de hoja quedan reemplazados. RUI-003 sigue cumpliéndose con la paleta nueva. Conviene actualizar el SRS en su próxima revisión (6.12).
- **Validaciones pendientes:** el kit advierte que no sustituye la revisión de marca ni la verificación de disponibilidad del nombre. Ambas, y la relación con la marca SENA (S-03), siguen pendientes con la contraparte.
- **Gráficos de la I6:** deben usar los tokens `--serie-*` y siempre con leyenda.

## Evolución

Si la contraparte exige la marca SENA o cambia el kit, se reemplazan los archivos de `referencias/KORA_kit_identidad` y se ejecutan `npm run marca` y `npm run construir`. Si cambia la paleta, se ajusta solo la capa 1 de `tokens.css` y se vuelve a ejecutar `npm run contraste`.
