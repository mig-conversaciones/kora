# ADR-019: Importación asistida por fuentes, con lotes validados en la cola y aplicación reanudable

- Estado: Aceptado
- Fecha: 2026-11-18
- Responsables: Líder técnico (Senior); revisión de la contraparte del CAB
- Requisitos relacionados: RF-030 a RF-034, RF-083 a RF-097, D-35, RS-011, RDL-004, RAC-024

## Contexto

La I5 carga las bases del piloto desde ocho fuentes Excel (Anexo G). Los libros reales tienen fórmulas, encabezados en filas distintas, fechas como objetos o seriales, medidas fuera del esquema y textos con notas. El plan de Hostinger limita la memoria, el usuario debe ver qué va a cambiar antes de aplicar y una aplicación interrumpida no puede duplicar filas.

## Decisión

- **Una fuente = una clase.** `FuenteImportacion` declara el patrón de su hoja, sus columnas con alias, su clave natural, la validación de cada fila, cómo se aplica con procedencia y cómo se revierte. `RegistroFuentes` reúne las ocho y detecta en un libro las hojas reconocibles, por su nombre y porque sus columnas obligatorias aparecen en una de las 10 primeras filas.
- **Lectura en streaming con OpenSpout** (D-35, `openspout/openspout` ^4.28, dependencia nueva de producción). `LectorLibro` lee una fila a la vez y toma el valor calculado de las celdas con fórmula.
- **Mapeo por nombre normalizado**: primero coincidencia exacta del alias y después por inclusión («GASTOS: Mantenimiento Maquinaria…» contiene «mantenimiento»).
- **Un lote por fuente**, identificado por el hash SHA-256 del archivo, la fuente y el centro (RF-089). La carga guarda el libro con un nombre aleatorio fuera de `public/` y devuelve un token firmado (HMAC con separación de dominio) que la selección de fuentes presenta de vuelta. Así no hace falta guardar estado en la sesión.
- **Validación en la cola** (`importacion.validar`). Guarda en `filas_importacion` los datos normalizados, sin PII en claro, y la acción prevista (crear, actualizar o sin cambios). Nada de negocio cambia hasta aplicar (RF-087).
- **Aplicación reanudable** (`importacion.aplicar`): una transacción si hay hasta 5.000 filas, o bloques de 500. Cada fila aplicada queda marcada en la misma transacción que la escritura de negocio, así que un reintento continúa desde la siguiente sin duplicar (RF-088). Un conflicto de clave en una fila la marca como error sin detener el lote.
- **Reversión por fila** (`importacion.revertir`): archiva lo creado y restaura `valores_anteriores` en lo actualizado. Se bloquea si algún registro creado tiene dependencias posteriores, que devuelve cada fuente (RF-090).
- **Cifras de control** (RF-094): filas y suma del campo de la fuente calculadas del archivo, frente a las de los registros que el lote dejó en la base. Con diferencias, el lote no queda «verificado».
- **Correcciones prácticas sobre el Anexo G**, todas con advertencia en la fila:
  - «Manojo 250 gr» y «Panal» se registran como unidad (ud).
  - Una medida vacía en ventas toma la del producto o la del lote vinculado.
  - En el hato, un texto con fecha inicial («25/10/2026 MONTA NATURAL») toma esa fecha; un texto sin fecha deja el campo vacío.
  - Si la columna «Fecha actual» no es única, se exige elegir la fecha del corte al cargar.

## Consecuencias

- Con los libros reales, la validación reproduce los oráculos del SRS:
  - **Recaudos:** 106 de producción, 61 de Ganadería por 3.265.000 COP, y un recaudo ambiguo.
  - **Hato:** 35 hembras (21 en producción, 10 horras y 4 de práctica).
  - **Autoconsumo:** 17 entregas sin proceso.
- El respaldo previo de RDL-004 es, en esta iteración, el valor anterior guardado por fila: permite revertir el lote, pero no sustituye la copia de la base de la I7.
- Los 20 productos del catálogo con medidas de servicio (hora, hectárea, metro cuadrado…) quedan como error de fila hasta decidir si el esquema las admite.

## Alternativas consideradas

- **PhpSpreadsheet.** Carga el libro completo en memoria y no cumple D-35.
- **Aplicar en la misma solicitud.** Superaría los 400 ms de RNF-PERF-003 y no se podría reanudar.
- **Guardar el archivo en la sesión hasta elegir las fuentes.** El token firmado evita ese estado y caduca a la hora.
