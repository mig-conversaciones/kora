# 1. Introducción

## 1.1 Propósito

Este documento especifica los requisitos del sistema **Producción CAB**, la versión de producción del prototipo funcional de tableros de mando del Centro Agropecuario de Buga (SENA, Regional Valle, código 9124). El prototipo demostró los tableros y las reglas de cálculo con datos reales, pero funciona solo en el navegador, sin usuarios, sin servidor y sin control de acceso. Este SRS define todo lo necesario para construir un sistema multiusuario, seguro, auditable y desplegable en Hostinger Cloud.

El documento es el **insumo único para el desarrollo**. Contiene los requisitos verificables y la arquitectura técnica con código de referencia. También incluye el plan de iteraciones con recursos y costos, y los anexos operativos. Va dirigido a la contraparte funcional del centro, que valida el alcance y las reglas de negocio, y al equipo de desarrollo, que construye y prueba. También a los responsables de TI y seguridad de la información, que revisan los controles.

> **Importante.** Redactar este SRS no demuestra que el sistema cumpla los requisitos. Las pruebas y la evidencia se especifican aquí; mientras no exista ejecución verificable, su estado es PENDIENTE DE EJECUCIÓN.

## 1.2 Alcance

**Producción CAB** es una aplicación web para gestionar y analizar la producción de las unidades productivas del centro. Arranca con la **prueba piloto de la Unidad de Ganadería (Hato El Milagroso)** y su arquitectura está preparada para las demás unidades y para otros centros. El sistema:

- Consolida el **presupuesto asignado** por unidad, el **recaudo** de venta directa (pasarelas Wompi, Ecollect y QR, informado por la Dirección General) y las **ventas por autoconsumo** (transferencias internas entre unidades).
- Gestiona la **trazabilidad productiva**: recepciones de lotes, salidas (venta directa y autoconsumo), procesos de autoconsumo con cupos anuales y transformaciones lácteas con sus derivados.
- Administra el **inventario biológico** del hato y su estado reproductivo por cortes fechados, incluido el lote de práctica académica.
- Presenta dos tableros de mando interconectados. El **tablero central** (IND_001 e IND_002) sirve a la coordinación administrativa, el recaudo y el liderazgo de producción. El **tablero técnico de unidad** (IND_003, IND_08, IND_12 e IND_17) sirve a los líderes de unidad y a la coordinación académica.
- Permite **cargar las bases de datos Excel actualizadas** mediante importación asistida, con validación, vista previa, aplicación y reversión. Deja especificado el **contrato de integración** con el software de ventas que el centro está desarrollando.
- Incorpora usuarios reales, autorización híbrida por rol y unidad, 2FA, auditoría funcional, colas asíncronas, modo mantenimiento administrable, respaldo, observabilidad y pruebas de rendimiento.

**Fuera del alcance de la versión 1.0** (ver también 2.11):

- La implementación del API de integración con el software de ventas. Solo se especifica su contrato.
- La ejecución presupuestal oficial (CDP/RP), la contabilidad y la facturación electrónica. El sistema muestra cobertura comercial, no ejecución presupuestal ni saldo de caja.
- Las aplicaciones móviles nativas. La interfaz web es adaptable a móvil.
- Los tableros técnicos específicos de las unidades distintas a Ganadería. La estructura queda preparada.
- El inicio de sesión único institucional (Microsoft Entra ID) y las llaves de acceso WebAuthn, que quedan como evolución documentada.

## 1.3 Interesados

| Interesado (rol) | Interés principal | Participación en el SRS |
|---|---|---|
| Líder de Producción de Centro (contraparte funcional) | Visión consolidada de la producción y trazabilidad de todas las unidades | Valida el alcance, las reglas de negocio, los indicadores y los supuestos |
| Coordinación Administrativa | Presupuesto asignado, procesos de autoconsumo y cobertura comercial | Valida presupuesto, cupos, cierres de periodo y permisos |
| Grupo de Recaudo | Registro, clasificación y conciliación de los recaudos de la Dirección General | Valida la importación y la clasificación de recaudos y la protección de datos de pagadores |
| Líder de Unidad Productiva (piloto: Ganadería) | Operación de su unidad: producción, salidas, gastos, hato e indicadores técnicos | Valida los flujos operativos y el aislamiento por unidad |
| Coordinación Académica | Disponibilidad de animales de práctica y transformación láctea con fines formativos | Valida el hato, la práctica académica y las transformaciones |
| Dirección General SENA (fuente externa) | Envía el archivo de recaudos de las pasarelas | Define el formato de la fuente de recaudos |
| Equipo del software de ventas (en desarrollo) | Generará la base de producción y ventas | Consumidor del contrato de integración |
| Responsable de TI y seguridad de la información del centro | Cumplimiento de seguridad, privacidad, respaldo y continuidad | Revisa los controles RS, RAC, RAU, RDI y ROB |
| Equipo de desarrollo (proveedor) | Construcción, pruebas, despliegue y operación inicial | Responsable de la implementación y de la evidencia |
| Titulares de datos personales (pagadores, usuarios) | Tratamiento conforme a la Ley 1581 de 2012 | Beneficiarios de los controles de privacidad |

## 1.4 Definiciones y glosario

| Término | Definición en este documento |
|---|---|
| Unidad productiva (UP) | Área del centro que produce bienes con fines formativos y comerciales, por ejemplo Ganadería (GAN), Granja (GRA), Agrícola (AGR) o Agroindustria (IND). Es el alcance de datos de los líderes de unidad. |
| Centro | Centro de formación SENA. En la versión 1.0 es solo el Centro Agropecuario de Buga (9124); el modelo admite varios centros (multitenencia). |
| Vigencia | Año presupuestal (por ejemplo, 2026). |
| Asignación presupuestal | Valor anual asignado a una unidad, con desglose en mantenimiento de maquinaria y equipo, alimentación de semovientes y materiales de formación. Es presupuesto, no gasto ejecutado. |
| Gasto ejecutado | Erogación real y sustentada de una unidad en una categoría (mantenimiento, alimentación y vacunas, materiales de formación). |
| Componente académico | Parte de un gasto ejecutado atribuible a las vacas de práctica académica. Se descuenta en IND_17. |
| Recaudo | Pago externo recibido por una pasarela (Wompi, Ecollect, QR) e informado por la Dirección General. Es la fuente de la venta directa en IND_001. |
| Clasificación de recaudo | Asignación de la unidad productiva y del producto a un recaudo que llega sin ellos. |
| Conciliación | Vínculo verificado entre un recaudo y la venta registrada que lo origina, mediante prefactura, factura electrónica o referencia. |
| Autoconsumo | Entrega interna de producto de una unidad a otra o a un cliente interno (por ejemplo, Bienestar al Aprendiz). Se valora a precio de transferencia. |
| Proceso de autoconsumo | Cupo anual aprobado (AUTO_001 a AUTO_006 en 2026) con origen, destino, producto, cantidad física, medida y valor. |
| Recepción (lote) | Ingreso de producto terminado a inventario. Se identifica con un número interno del tipo GAN-REC-210126-001: prefijo de unidad, tipo de movimiento, fecha DDMMAA y consecutivo. |
| Salida | Movimiento que reduce las existencias de un lote: venta directa (transferencia) o entrega de autoconsumo. |
| Transformación láctea | Vínculo entre una entrega de leche por autoconsumo y la recepción del derivado obtenido (queso cuajada, yogurt, manjar blanco o cortado). |
| Factor de equivalencia en leche | Litros de leche equivalentes a una unidad de derivado (L/kg o L/L). Permite consolidar el aprovechamiento global sin mezclar unidades de medida. |
| Corte del hato | Fotografía fechada del inventario y del estado reproductivo de los animales. Los indicadores del hato usan el corte, no el rango de fechas. |
| Lote de práctica académica | Animales destinados a prácticas de formación (palpación, inseminación). Se excluyen opcionalmente del denominador de IND_003. |
| IEP | Intervalo entre partos, en días. |
| Días abiertos | Días entre el último parto y el servicio efectivo, o la fecha de corte si no hay servicio. |
| Cobertura comercial | (Venta directa + autoconsumo) / asignación × 100. No es ejecución presupuestal. |
| Número interno | Identificador del lote en la base de producción; sirve de llave entre recepciones y ventas. |
| SGAC / SGARC | Código del catálogo oficial de productos (por ejemplo, 5013170101 para Leche de vaca). Las fuentes usan ambas siglas. |
| Prefactura / factura electrónica | Documentos comerciales que permiten conciliar recaudos con ventas. |
| Software de ventas | Sistema que el centro está desarrollando y que generará la base de producción y ventas. Se integra mediante el contrato del anexo E. |
| Fragmento HTMX | Respuesta HTML parcial que reemplaza una región de la página sin recargarla. |
| Intermediario | Componente que procesa la solicitud antes o después del controlador, también llamado *middleware*: sesión, CSRF, autorización, mantenimiento. |
| Alcance de datos | Conjunto de centro y unidades que un usuario puede ver o modificar. Se construye en el servidor a partir de la sesión. |
| Trabajo | Tarea asíncrona persistida en la tabla `trabajos` y ejecutada por el trabajador de cron (*job*). |
| Trabajador de cron | Proceso PHP CLI que el cron de Hostinger invoca para reclamar y ejecutar trabajos (*worker*). |
| Idempotencia | Propiedad por la que repetir una operación con la misma clave no duplica sus efectos. |
| Cache-aside | Patrón en que la aplicación consulta la caché y, si no encuentra el dato, lo calcula desde la base y lo guarda. |
| Estampida (stampede) | Regeneración simultánea de la misma entrada de caché por muchas solicitudes. |
| p95 / p99 | Percentil 95 o 99 de una distribución de tiempos de respuesta. |
| SLO | Objetivo de nivel de servicio medible (latencia, errores, disponibilidad). |
| Oráculo de prueba | Cifra de referencia conocida, verificada en el prototipo con los Excel, que el sistema debe reproducir. Por ejemplo, la asignación total del centro es de 1.024.000.000 COP. |

## 1.5 Acrónimos y abreviaturas

| Sigla | Significado |
|---|---|
| ABAC | Control de acceso basado en atributos |
| ADR | Registro de decisión de arquitectura (*Architecture Decision Record*) |
| ASVS | Estándar de verificación de seguridad de aplicaciones de OWASP |
| CAB | Centro Agropecuario de Buga |
| CDP / RP | Certificado de disponibilidad presupuestal / registro presupuestal |
| CLI | Interfaz de línea de comandos |
| CLS / INP / LCP | Métricas Core Web Vitals: estabilidad visual, respuesta a interacción y carga del contenido principal |
| COP | Peso colombiano |
| CSP | Política de seguridad de contenido (*Content Security Policy*) |
| CSRF | Falsificación de solicitudes entre sitios |
| DAST / SAST | Pruebas de seguridad dinámicas / análisis estático de seguridad |
| DG | Dirección General del SENA |
| DoR / DoD | Definición de listo para iniciar / definición de terminado |
| E2E | Prueba de extremo a extremo |
| HMAC | Código de autenticación de mensajes basado en hash |
| HSTS | *HTTP Strict Transport Security* |
| IND_xxx | Código de indicador de la guía de especificaciones |
| MFA / 2FA | Autenticación multifactor / de dos factores |
| MVC | Modelo-Vista-Controlador |
| OTP / TOTP | Contraseña de un solo uso / basada en tiempo (RFC 6238) |
| PDO | *PHP Data Objects* |
| PII | Información de identificación personal |
| **PoD** | **Protección desde el Diseño.** Término no estándar de la plantilla. Aquí designa la privacidad y la seguridad desde el diseño y por defecto (*Privacy & Security by Design and by Default*): proteger los datos y la seguridad desde la arquitectura y la configuración inicial, con minimización, valores restrictivos por defecto, cifrado y trazabilidad, y no como un control añadido al final. Ver RS-023 y el supuesto S-20. |
| RBAC | Control de acceso basado en roles |
| RLS | Seguridad a nivel de fila (*Row-Level Security*) |
| RPO / RTO | Pérdida máxima de datos tolerada / tiempo máximo de recuperación |
| SBOM | Inventario de componentes de software |
| SLO | Objetivo de nivel de servicio |
| STRIDE | Modelo de amenazas: suplantación, manipulación, repudio, divulgación, denegación y elevación |
| TRD | Tablas de Retención Documental (Ley 594 de 2000) |
| UP | Unidad productiva |
| ULID | Identificador único ordenable lexicográficamente |
| UTC | Tiempo universal coordinado |
| WCAG | Pautas de accesibilidad para el contenido web (W3C) |

## 1.6 Referencias

**Normas de ingeniería de requisitos**

- IEEE Std 830-1998, *Recommended Practice for Software Requirements Specifications* (estructura del documento).
- ISO/IEC/IEEE 29148:2018, *Systems and software engineering — Life cycle processes — Requirements engineering* (atributos de calidad de los requisitos).

**Seguridad, identidad y protocolos**

- OWASP ASVS 5.0.0 (mayo de 2025), nivel 2: [owasp.org/www-project-application-security-verification-standard](https://owasp.org/www-project-application-security-verification-standard/). Capítulos V1 a V17, de *Encoding and Sanitization* a *WebRTC*.
- NIST SP 800-63B-4 (2025), autenticadores y contraseñas: [pages.nist.gov/800-63-4/sp800-63b.html](https://pages.nist.gov/800-63-4/sp800-63b.html).
- RFC 6238 (TOTP) y RFC 4226 (HOTP): [rfc-editor.org/rfc/rfc6238](https://www.rfc-editor.org/rfc/rfc6238).
- RFC 9110, *HTTP Semantics*: estado 503 (sección 15.6.4) y cabecera Retry-After (sección 10.2.3): [rfc-editor.org/rfc/rfc9110](https://www.rfc-editor.org/rfc/rfc9110.html).
- RFC 6265 y su revisión *rfc6265bis* (prefijo de cookie `__Host-`, atributo SameSite).
- W3C *Content Security Policy Level 3*.

**Accesibilidad**

- W3C WCAG 2.2, nivel AA: [w3.org/TR/WCAG22](https://www.w3.org/TR/WCAG22/).
- MinTIC, Resolución 1519 de 2020 (accesibilidad web en entidades públicas) y NTC 5854.

**Datos, rendimiento y arquitectura**

- MySQL 8.0, optimización e índices: [dev.mysql.com/doc/refman/8.0/en/optimization-indexes.html](https://dev.mysql.com/doc/refman/8.0/en/optimization-indexes.html); EXPLAIN: [dev.mysql.com/doc/refman/8.0/en/explain.html](https://dev.mysql.com/doc/refman/8.0/en/explain.html).
- MariaDB Knowledge Base: `SELECT … FOR UPDATE SKIP LOCKED` (desde 10.6), sentencia `ANALYZE` y tipo JSON: [mariadb.com/kb](https://mariadb.com/kb/en/).
- Microsoft Azure Architecture Center: [cache-aside](https://learn.microsoft.com/en-us/azure/architecture/patterns/cache-aside), [buenas prácticas de caché](https://learn.microsoft.com/en-us/azure/architecture/best-practices/caching), [solicitud-respuesta asíncrona](https://learn.microsoft.com/en-us/azure/architecture/patterns/asynchronous-request-reply) y [fallos transitorios](https://learn.microsoft.com/en-us/azure/architecture/best-practices/transient-faults).
- Grafana k6: [umbrales](https://grafana.com/docs/k6/latest/using-k6/thresholds/) y [modelos abierto y cerrado](https://grafana.com/docs/k6/latest/using-k6/scenarios/concepts/open-vs-closed/).
- htmx 2.x: [htmx.org/docs](https://htmx.org/docs/) (configuración `responseHandling`, `allowEval` y nonces). Alpine.js, versión CSP: [alpinejs.dev/advanced/csp](https://alpinejs.dev/advanced/csp).
- Hostinger: [tareas cron](https://docs.hostinger.com/websites/cron-jobs), [parámetros y límites de los planes](https://www.hostinger.com/support/6976044-parameters-and-limits-of-hosting-plans-in-hostinger/) y [motor de base de datos](https://support.hostinger.com/mysql/what-kind-of-sql-is-being-used-for-hostingers-servers).

**Marco legal colombiano**

- Ley 1581 de 2012 y Decreto 1377 de 2013 (compilado en el Decreto 1074 de 2015): protección de datos personales.
- Ley 1712 de 2014: transparencia y acceso a la información pública.
- Ley 594 de 2000: Ley General de Archivos y Tablas de Retención Documental.
- Ley 1273 de 2009: delitos informáticos.
- MinTIC: Modelo de Seguridad y Privacidad de la Información (MSPI) y Política de Gobierno Digital.

**Documentos del proyecto**

- *Documento guía de especificaciones — Ecosistema de tableros de mando, Producción de Centros SENA (piloto Unidad de Ganadería)*, con tres comentarios de la contraparte (noviembre de 2026). Cita el procedimiento GOR-P-004 y el instructivo GRF-I-005.
- Prototipo funcional *Producción CAB*: README, PLAN y VERIFICACION, con 23 pruebas automatizadas aprobadas el 16-nov-2026 y la guía de uso en PDF.
- *Revisión general del mockup* (noviembre de 2026): seis observaciones con capturas, analizadas en 2.9.
- Fuentes Excel: `BBDD_DATOS ADMON.xlsx`, `BBDD_Produccion de Centro.xlsx` e `INFO_Hato.xlsx`.
- Plantilla del SRS suministrada por el proveedor: rol, objetivo, variables, restricciones y directrices de UI, mensajería, rendimiento, colas, seguridad, resiliencia y mantenimiento.

## 1.7 Organización del documento

- **Capítulo 2.** Contexto del producto, roles, restricciones, variables del proyecto, supuestos, inconsistencias detectadas y decisiones, y el análisis de la revisión del mockup.
- **Capítulo 3.** Requisitos por familia (RF, RF-Q, RF-MAN, RNF, RNF-PERF, RI, RUI, RS, RAC, RAU, RDI, RDOC, RBD, RDL, ROB y RGO), las fichas técnicas de los procesos y la matriz de trazabilidad.
- **Capítulo 4.** Arquitectura técnica con código de referencia: MVC nativo, HTMX y Alpine, sistema de mensajería UI, colas y cron, datos, caché, rendimiento, seguridad y migración a Hostinger.
- **Capítulo 5.** Planificación de iteraciones, recursos humanos, costos y diagrama de Gantt.
- **Capítulo 6 (anexos).** Configuración, scripts de base de datos, correos, operación, mantenimiento, rendimiento, contrato de integración, mapeo de fuentes y listas de verificación.
