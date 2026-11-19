# ADR-021: Copias cifradas en sobre desde PHP, despliegue por versiones verificado en la CI y presupuesto de consultas medido sobre un dataset sintético

- Estado: Aceptado
- Fecha: 2026-11-19
- Responsables: Líder técnico (Senior); revisión de la contraparte del CAB y del titular de la cuenta de Hostinger
- Requisitos relacionados: RNF-PERF-007 a RNF-PERF-018, RDL-003 a RDL-005, RDL-009, ROB-001 a ROB-005, ROB-010 a ROB-014, RS-012, RS-019, RS-022, RGO-004; ADR-003, ADR-010, ADR-011

## Contexto

La I7 debe demostrar capacidad y dejar la operación lista. Hay cuatro restricciones:

- **Sin entorno de pruebas.** No hay staging en Hostinger ni autorización para pruebas de carga (S-10). Lo que se pueda medir se mide en local y en la CI, y lo demás queda preparado y marcado como pendiente.
- **Copias.** El anexo D propone `age` o `gpg` en bash, pero no está confirmado que existan en el plan Cloud. Además, la importación masiva (RDL-004) necesita su copia previa desde el trabajador PHP.
- **MAMP en Windows.** El equipo de desarrollo no tiene enlaces simbólicos POSIX, así que el despliegue por versiones no se puede ensayar ahí.
- **Consultas.** El inventario de 4.6.1 tenía índices propuestos, pero ninguna medición.

## Decisión

### Copias de seguridad (`ServicioRespaldo`, `cli/respaldo.php`)

- **Contenido.**
  - La base, con `mysqldump --single-transaction` comprimido con gzip.
  - El almacenamiento privado en ZIP, sin la caché, los bloqueos, los latidos ni las propias copias.
- **Cifrado en sobre con libsodium** (`SobreCifrado`).
  - Cada archivo se cifra en bloques de 64 KiB con XChaCha20-Poly1305, con una clave aleatoria por archivo.
  - Esa clave va sellada con `crypto_box_seal` para la **llave pública** del custodio.
  - El servidor no tiene la llave privada.
  - Un archivo alterado o truncado se rechaza.
- **Registro y retención.**
  - Cada copia queda en la tabla `respaldos` (el registro de RDL-003) y en un manifiesto con el SHA-256 de cada archivo.
  - Se conservan 30 copias diarias y la última de cada uno de los 12 meses anteriores; las copias de cambios de riesgo, 30 días.
  - `scripts/backup.sh` añade la copia fuera de Hostinger por rsync.
- **Copia previa (RDL-004).**
  - El despliegue la pide antes de migrar.
  - La importación la pide antes de aplicar o revertir un lote con al menos `RESPALDO_IMPORTACION_FILAS` filas (500 por defecto).
  - En producción, sin llave configurada, no se aplica el cambio.
- **Simulacro de restauración (RDL-005).** `cli/respaldo.php simulacro`:
  1. Calcula las cifras de control de la base de origen.
  2. Hace la copia y la restaura en una base `*_restauracion`, vaciada antes.
  3. Recalcula las cifras de control (conteos y sumas por centro de 12 tablas, la migración y la cadena de auditoría, recalculada evento por evento).
  4. Mide el tiempo frente al RTO.
  5. Escribe el acta.

### Despliegue por versiones (ADR-011, materializado)

- **`scripts/empaquetar.sh`.** Toma el commit con `git archive`, instala las dependencias de producción y construye los activos. Añade `VERSION`, `COMMIT` y `manifiesto.sha256`.
- **`scripts/desplegar.sh`.**
  1. Verifica el manifiesto y enlaza lo compartido.
  2. Verifica el entorno.
  3. Con `--con-mantenimiento`: activa el mantenimiento y espera el drenaje de la cola.
  4. Hace la copia previa y aplica las migraciones.
  5. Cambia `current` de forma atómica.
  6. Limpia la caché, corre las pruebas de humo y registra el despliegue.
  7. Si usó mantenimiento, lo desactiva.
  - Se detiene en el primer fallo.
- **`scripts/rollback.sh`.** Revierte el enlace. Restaura la base solo con la llave del custodio y `--acepto-perdida`.
- **`cli/humo.php`.** Las pruebas de humo corren en proceso, sin red.
- **`cli/registrar_despliegue.php`.** Deja la fila en `despliegues` y el evento `sistema.desplegar` o `sistema.revertir` (ROB-013).
- **Ensayo en la CI.** El trabajo `operacion` lo ejercita todo en Linux con MariaDB 10.6:
  - dos versiones;
  - un despliegue con mantenimiento;
  - una reversión de 10 minutos o menos;
  - el simulacro de restauración;
  - la integridad alterada.

### Rendimiento basado en evidencia

- **Generador sintético** (`GeneradorDatosCarga`, `cli/generar_dataset.php`).
  - Genera 3 años del centro 9124 (unas 36.000 salidas, 9.000 recaudos, 9.000 recepciones y 750.000 eventos de auditoría), más un segundo centro con el 10 % para verificar el aislamiento.
  - Es determinista con su semilla y nunca corre en producción (RDL-009).
  - Inserta la auditoría ya sellada con la fórmula de la aplicación.
- **Inventario de consultas** (`tests/carga/inventario_consultas.php`). Captura cada consulta real con un observador opcional de `BaseDatos` y la pasa por `EXPLAIN`. Mide las consultas por solicitud y el costo en escritura.
- **Correcciones derivadas de la medición:**
  - N+1 de la rotación (IND_12), con una consulta `IN` para todos los lotes.
  - N+1 de la bandeja de clasificación de recaudos.
  - Permisos efectivos en caché con la clave `permisos:{ulid}:v{version_permisos}` (4.6.2).
  - Unidades y versión de datos leídas una vez por solicitud.
  - Opciones del filtro de producto en una consulta, y cacheadas.
  - Estado de la información en una consulta.
  - Nombre del actor de la auditoría en una subconsulta escalar.
  - Migración 014 con los índices de los listados.
- **Precalentamiento.** El trabajo `cache.precalentar`, los días hábiles a las 06:30, calienta la vista por defecto de cada alcance con las mismas claves del controlador.

### Vigilancia

- **Integridad** (`VerificadorIntegridad`, trabajo `seguridad.verificar_integridad` a las 03:15). Compara la versión activa con su manifiesto y vigila los permisos y la huella del `.env`.
- **Certificado** (`MonitorCertificado`). El programador lo revisa cada día y avisa con 15 días de anticipación.
- **Salud.** `/health/ready` agrega, solo con sesión de superadministrador, si OPcache está activo y las horas desde la última copia diaria.

## Alternativas consideradas

- **`age` o `gpg` desde bash, como en el anexo D.** Depende de binarios que hay que verificar en el plan y no sirve para la copia previa que pide el trabajador PHP. libsodium ya es obligatorio (RNF-018) y da la misma garantía: el servidor solo tiene la llave pública.
- **Copia lógica en PHP, sin `mysqldump`.** Es más lenta y propensa a errores con los tipos y las restricciones. `mysqldump` está en Hostinger, en MAMP y en la CI.
- **Ensayar el despliegue solo en staging.** No existe todavía. La CI da un Linux real con enlaces simbólicos y MariaDB 10.6 en cada push.
- **Subir los límites de RNF-PERF-009.** La medición mostró que el exceso venía de consultas repetidas y de dos N+1, no de la complejidad de las pantallas.

## Consecuencias

- **Medición local (MySQL 5.7, 3 años de datos):** todas las pantallas medidas cumplen su máximo de consultas.
  - Tablero central: 14 en frío y 5 en caliente.
  - Tablero técnico: de 1.019 a 13.
  - Bandeja de clasificación: de 210 a 8.
  - Listados: de 6 a 8. Formulario de salida: 5.
- **Tiempos locales de servidor:** los tableros quedan por debajo de 175 ms en frío y de 50 ms en caliente, en p95. Son tiempos en proceso que orientan, pero no demuestran el SLO.
- **Simulacro local de restauración:** 66 MB cifrados, restaurados en unos 40 s, con todas las cifras de control y la cadena de 825.014 eventos conformes.
- **Llave del custodio.** Sin ella no se restaura nada: su custodia es parte del runbook «Backup corrupto».
- **Caché de permisos.** Un cambio de permisos hecho directamente en la base, sin subir `version_permisos`, tarda hasta 5 minutos en verse. Toda vía de la aplicación sube la versión; el soporte debe hacerlo a mano (runbooks «Rotación de claves» e «Incidente de seguridad»).
- **Pendiente de staging y de la autorización del titular:** k6, el monitoreo externo, el enlace de `public_html` y la prueba de OPcache con el cambio de `current`.

## Evolución

- Si staging muestra un p95 por encima del SLO con la carga esperada, se evalúan las señales de RNF-PERF-019 antes de optimizar más.
- Si el plan ofrece `age` y el titular prefiere esa herramienta, el formato del sobre puede cambiar sin tocar el resto. El registro y la retención no dependen del cifrado.
