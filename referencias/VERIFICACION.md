# Verificación del prototipo

Verificado el 16 de noviembre de 2026.

## Comprobaciones automáticas

`npm.cmd test`: **23 pruebas aprobadas**, sin fallos.

Se verificaron las cifras de los Excel, filtros temporales y por producto, separación de canales, procesos sin vínculos, unidades incompatibles, denominadores del hato, límites de semáforos, equivalentes lácteos, exclusión del costo académico, cierre de lotes, divisiones por cero, aislamiento y restauración de escenarios, validación de respaldos, existencias y relaciones entre movimientos.

Las comprobaciones sintácticas de los módulos JavaScript y del extractor finalizaron correctamente. La vista local y sus archivos se sirvieron por HTTP con respuesta 200.

## Comprobaciones en navegador

- Tablero central con las cifras de los archivos y cambio al escenario demostrativo.
- Navegación al tablero de Ganadería y presentación de gráficos, indicadores y tablas.
- Interruptor de práctica: cambio de 67,7 % (21/31) a 60 % (21/35).
- Formulario del hato: apertura, guardado local y consulta de la fecha de edición después de recargar. Se guardaron los mismos valores del animal 158 en el escenario demo; no se cambiaron sus datos biológicos.
- Diseño de escritorio y emulación móvil de 412 × 924 px: menú desplegable, formularios y tablas con desplazamiento horizontal.
- La consola consultada no mostró errores del prototipo. Los tres errores visibles correspondían a scripts de una extensión Adobe del navegador.
- Diferencias por rol: Líder con menú de operación y acceso directo a Gastos; Coordinación académica con práctica incluida, cuatro animales didácticos y tabla prioritaria; Presupuesto en consulta académica. El regreso a Administración abre el tablero central. Los cambios de perfil conservan los datos del escenario.

## Límites de esta verificación

La importación/exportación y recuperación del repositorio se validan con pruebas automáticas; no se automatizó el selector nativo de archivos. La revisión visual móvil se realizó a 412 px y no constituye una certificación en todos los dispositivos. No se probaron usuarios reales, concurrencia ni persistencia centralizada porque el prototipo no incluye backend.

El servidor temporal de revisión utiliza el puerto 4173. El uso permanente puede hacerse desde Apache de MAMP o con `npm.cmd start`, siguiendo el README.
