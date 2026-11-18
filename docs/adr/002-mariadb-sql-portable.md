# ADR-002: MariaDB con SQL portable a MySQL 8 y desarrollo local en el MySQL 5.7 de MAMP

- Estado: Aceptado
- Fecha: 2026-11-17
- Responsables: Líder técnico (Senior); revisión de la contraparte del CAB
- Requisitos relacionados: RBD-001, RNF-016, ROB-008, D-01, D-02

## Contexto

La plantilla del proyecto exige MySQL 8, pero Hostinger Cloud ofrece MariaDB. El SRS (D-02) pedía desarrollar sobre la misma MariaDB de producción porque el MAMP local trae MySQL 5.7.24, sin SKIP LOCKED, CTE ni funciones de ventana. El 2026-11-17 el responsable del proyecto decidió desarrollar en local con el PHP 8.3 y el MySQL 5.7.24 de MAMP.

## Decisión

El SQL del proyecto se escribe en el **mínimo común entre MySQL 5.7.24, MariaDB 10.6 y MySQL 8.0**:

- Sin CTE, sin funciones de ventana, sin índices funcionales, sin colaciones `utf8mb4_0900_*`, sin `UUID_TO_BIN`, sin `JSON_TABLE` y sin `DEFAULT` en columnas JSON o TEXT.
- Las restricciones `CHECK` se declaran (MariaDB las aplica; MySQL 5.7 las ignora) y sus reglas se validan también en los validadores PHP.
- La cola usa la estrategia de reclamo `actualizacion_condicional` (`UPDATE … ORDER BY … LIMIT` con token de reserva) cuando el motor no soporta `SKIP LOCKED` (`BD_SKIP_LOCKED=false`).
- `cli/verificar_entorno.php` acepta MySQL 5.7.24 solo con `APP_ENTORNO=local` y exige MariaDB ≥ 10.6 o MySQL ≥ 8.0 en cualquier otro entorno.

## Alternativas consideradas

- MariaDB local (instalación aparte o contenedor): máxima paridad, pero el responsable eligió el stack de MAMP ya instalado.
- MySQL 8 en producción: no lo ofrece el plan Cloud.

## Consecuencias

- El código que pasa en local también es válido en producción, porque el subconjunto es común.
- Riesgo: una diferencia de comportamiento (CHECK ignorados, optimizador distinto) solo aparecería en MariaDB. Mitigación: la CI ejecuta migraciones y pruebas de integración contra **MariaDB 10.6 y MySQL 5.7**, y staging usa la MariaDB del plan.
- La I1 debe implementar y probar las dos estrategias de reclamo de la cola.
- Verificado en la I0: las migraciones 001 a 006 del Anexo B se aplican, revierten y reaplican sin cambios en MySQL 5.7.24.

## Verificación en la I1

- **`actualizacion_condicional` en MySQL 5.7.24:** reclamo atómico en una sola sentencia `UPDATE … WHERE estado = 'pendiente' ORDER BY … LIMIT` con token de reserva. Dos trabajadores concurrentes procesaron 24 trabajos sin repetir ninguno (`ColaTest::testDosTrabajadoresConcurrentesProcesanCadaTrabajoUnaSolaVez`).
- **`skip_locked`:** la misma prueba la ejecuta en la CI contra MariaDB 10.6.
- **Unicidad con NULL:** MySQL y MariaDB tratan los NULL como distintos en los índices únicos, así que `uq_trabajos_idempotencia (centro_id, tipo, clave_idempotencia)` no deduplica los trabajos sin centro. `ConductorColaMysql` busca la clave antes de insertar en ese caso; la ventana de carrera restante solo afecta a trabajos técnicos y se acepta.

## Evolución

Si una consulta de los tableros (I6) necesita funciones de ventana o CTE por rendimiento, se reabre esta decisión: el desarrollo local pasaría a MariaDB y el subconjunto se ampliaría a MariaDB 10.6 / MySQL 8.0, tal como preveía D-02.
