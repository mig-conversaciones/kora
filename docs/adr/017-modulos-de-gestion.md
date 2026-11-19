# ADR-017: Módulos de gestión con montos decimales exactos y reglas de bloqueo que se activan con el esquema

- Estado: Aceptado
- Fecha: 2026-11-18
- Responsables: Líder técnico (Senior); revisión de la contraparte del CAB
- Requisitos relacionados: RF-016, RF-017, RF-024 a RF-028, RF-036, RNF-005, RUI-015, RBD-009

## Contexto

La I3 entrega los primeros módulos de negocio: catálogos, presupuesto, gastos, procesos y cierres. Hay cuatro problemas que se repetirán en cada módulo posterior:

1. **Montos y cantidades.** Los montos van en COP con hasta 13 dígitos, las cantidades llevan 3 decimales y los factores, 4. Con `float` aparecen errores de redondeo en sumas y comparaciones: 0,1 + 0,2 ≠ 0,3. Las personas además escriben «12.500.000» o «3.045.000,50».
2. **Reglas de bloqueo que dependen de tablas futuras.**
   - Una unidad con lotes, salidas o transformaciones no se inactiva (RF-016).
   - Un producto con recepciones no cambia de medida (RF-017).
   - Un proceso con entregas no cambia su unidad ni su medida (RF-036).

   Esas tablas llegan con la migración 010, en la I4.
3. **Periodo cerrado.** RF-028 pide un 409 «Periodo cerrado», no un 403.
4. **Errores en un formulario HTMX.** Un 409 que reemplaza el formulario borra lo escrito.

## Decisión

- **Decimales como texto canónico.** `App\Nucleo\Entrada::decimal()` convierte lo escrito en un texto como «12500000.00», que va directo a `DECIMAL` sin pasar por `float`.
  - Acepta el formato local con punto de miles y coma decimal, y el del campo numérico del navegador.
  - El punto de miles solo se interpreta en montos (2 decimales o menos). En factores y cantidades sería ambiguo: «8.500 L/kg».
  - `Entrada::comparar()` y `Entrada::sumar()` operan sobre el texto o en centavos enteros. No exigen bcmath, que Hostinger no garantiza.
- **Reglas de bloqueo con tablas condicionales.** `ReglasDependencias` cuenta los registros activos de cada tabla dependiente, pero solo si la tabla existe (`information_schema`, con caché por solicitud). Las tablas de la I4 usan las mismas columnas (`centro_id`, `unidad_id`, `producto_id`, `proceso_id`, `archivado_en`). Al aplicar la migración 010 las reglas se activan sin cambiar el código; mientras tanto, la ejecución de los procesos es 0.
- **El periodo cerrado es un conflicto de estado, no de autorización.** `ServicioPeriodos::exigirAbierto()` responde un 409 con el título «Periodo cerrado». Verifica la fecha nueva y la anterior del registro. La política (`PoliticaAlcanceUnidad`) decide solo el permiso y la unidad.
  - `ErrorHttp` admite un título propio.
- **Ciclo HTMX común.** `ControladorModulo` define un único flujo para todos los módulos:
  - La página `modulos/pagina` lleva el encabezado y las pestañas de la sección; cada módulo intercambia su contenido en `#panel-modulo`.
  - Un 422 devuelve el formulario con lo escrito.
  - Un guardado correcto devuelve el listado, un toast y `HX-Push-Url`.
  - Los errores controlados de HTMX (403, 404, 409…) llevan `HX-Retarget: #contenedor-errores` y nunca reemplazan el formulario.
- **Autorización sobre el registro.** `AutorizacionRecurso::exigir()` llama al Autorizador con un `RecursoUnidad` o un `RecursoUsuario` armado con datos del servidor. Las denegaciones quedan auditadas y responden 403, o 404 si están fuera del alcance.
- **Auditoría por recurso.** `RecursoAuditado` (tipo, id y unidad) llena `recurso`, `recurso_id` y `unidad_id` del evento, y así la consulta de la auditoría puede filtrar por unidad.

## Alternativas consideradas

- **`float` con redondeo al mostrar.** Falla en las comparaciones, por ejemplo «desglose ≤ total» con centavos.
- **bcmath.** No está garantizado en Hostinger, y ADR-005 limita las extensiones.
- **Crear en la I3 tablas vacías de la I4 solo para las reglas.** Rompe el orden de las migraciones del Anexo B.
- **Responder 403 en un periodo cerrado.** Contradice RF-028 y mezcla autorización con estado.

## Consecuencias

- Los módulos de la I4 a la I6 reutilizan `ControladorModulo`, `parciales/campo`, `Entrada` y `AutorizacionRecurso`.
- Las pruebas de la I4 deben cubrir que los bloqueos se activan solos al aplicar la migración 010.
- Los importadores de la I5 deben usar `Entrada::decimal()` para que la normalización sea la misma que en los formularios (RF-022).

## Evolución

Si se adopta MySQL 8 o MariaDB con funciones de ventana, la ejecución por línea podría calcularse en una sola consulta. Hoy son dos consultas agregadas por solicitud.
