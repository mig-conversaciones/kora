# Registro de entrega asistida por IA — Iteración 8 «Endurecimiento, UAT y salida»

- **Fecha:** 2026-11-19
- **Herramienta y modelo:** Claude Code (extensión de VS Code), modelo Claude Opus 5.5 (`claude-opus-5-5`)
- **Responsable humano:** líder técnico del proyecto, que revisa y aprueba
- **Requisitos:** RF-094, RDL-011, RS-001, RS-016, RGO-009, RDOC-009, RUI-002 a RUI-024, RNF-003; anexo M.1 y M.3; SRS 4.8.2, ítems 19 y 20
- **Decisiones:** [ADR-022](../adr/022-salida-carga-inicial-verificacion.md)

## Objetivo

Dejar lista la salida a producción del piloto en todo lo que no depende de Hostinger ni de la contraparte:

- carga inicial guiada con acta;
- lista ASVS nivel 2;
- ZAP y gitleaks;
- auditoría WCAG 2.2 AA;
- guías por rol y ayuda en línea;
- plan de UAT, capacitación, supuestos y acta de salida.

## Supuestos

- **Fuentes de la carga.** Obligatorias: catálogo, presupuesto, cupos, recaudos, producción, ventas y hato. Los gastos ejecutados son opcionales, porque los libros del piloto no los traen.
- **Firmantes.** Firman la coordinación administrativa o el Grupo de Recaudo (anexo M.3, «Contraparte y Grupo de Recaudo»). No se exige que firme alguien distinto de quien condujo la carga.
- **Estados de la lista ASVS.** Una brecha frente al nivel 2 se marca como «Decisión» en lugar de ocultarse. La lista no es «firmable» como cumplimiento total hasta que la contraparte decida.
- **Ayuda en línea.** La guía de operación solo la ven quienes tienen `sistema.salud` o son superadministradores.

## Validaciones ejecutadas

Todas son resultados reales en MAMP: PHP 8.3.36 y MySQL 5.7.24.

- **`composer verificar`:** PSR-12, PHPStan nivel 6 sin errores y PHPUnit con 149 pruebas y 1.355 aserciones.
- **`CargaInicialTest`** (3 pruebas):
  - permisos y pestaña;
  - inicio único;
  - faltantes y registro ajeno (RDL-011);
  - fila rechazada con su número;
  - corrección y nueva carga;
  - un cambio posterior a la verificación impide firmar;
  - declaración, reautenticación, firma auditada y bloqueo tras la firma;
  - acta sin el número de identificación del libro;
  - anulación con motivo.
- **Ensayo con los libros reales** (la misma prueba, solo local). De las 7 fuentes obligatorias, 5 coinciden. El catálogo tiene 190 filas y 170 aplicadas (19 medidas no reconocidas y 1 precio mínimo mayor que el máximo); la base de ventas tiene 146 y 145 aplicadas. La carga queda con diferencias y el acta está en `docs/operacion/actas/2026-11-19-ensayo-carga-inicial-local.md`.
- **Playwright y axe en local,** contra el servidor embebido sobre `kora_carga`: **80 de 80 pruebas**, es decir, 74 recorridos sin violaciones WCAG 2.2 AA ni de CSP y 6 flujos. La primera pasada encontró 4 tipos de violación, todos corregidos (`docs/ui/auditoria-wcag.md`).
- **PDF de las guías:** 7 generados desde la ayuda en línea con una cuenta de coordinación. Las guías de administración y de operación necesitan una cuenta con esos permisos.
- **CI.** El push de la I7 mostró dos fallos, corregidos en commits aparte:
  - la prueba de concurrencia de la cola, sensible a los tiempos;
  - `pipefail` en `desplegar.sh`.

  Después quedaron en verde los cuatro trabajos (`7e6de12`), incluido el ensayo de despliegue, reversión y restauración en Linux.

## Hallazgos de seguridad corregidos en la iteración

- La demostración «respuesta lenta» de la vitrina era pública en producción: retenía un proceso de PHP 1,2 s sin sesión. Ahora responde 404 en producción.
- Al salir no se limpiaba el almacenamiento del navegador: se agregó `Clear-Site-Data`.

## Riesgos

- **Sin ejecutar todavía:** los trabajos «Secretos» y «Navegador, accesibilidad y ZAP» de la CI nunca han corrido. Pueden fallar en su primera ejecución, por un falso positivo de gitleaks o por el arranque del servidor embebido o de ZAP.
- **ZAP en staging:** sigue pendiente. El de la CI no cubre TLS, LiteSpeed, el proxy ni las cabeceras de los estáticos.
- **Revisión manual de accesibilidad:** pendiente, con NVDA y VoiceOver.
- **Lista ASVS:** tiene 4 decisiones de la contraparte:
  - 2FA para todos los roles (V6.3.3);
  - antivirus para los adjuntos (V5.4.3);
  - lista de salida en el servidor (V13.2.5);
  - almacén de secretos (V13.3.1).
- **RF-100:** el escenario de capacitación del prototipo solo existe dentro de la prueba de oráculos; la capacitación usa el dataset «mini» del generador.
- **Dependencias de desarrollo:** sharp, esbuild y eslint tienen avisos; no se despliegan.

## Pendientes

- **Titular y contraparte:**
  - cuenta, staging, SMTP y dominio (S-06, S-10, S-21 y S-22);
  - decisiones de la lista ASVS;
  - corrección de las 21 filas de los libros;
  - UAT con los perfiles de las observaciones;
  - capacitación;
  - firmas.
- **Staging:**
  - ZAP autenticado;
  - k6;
  - restauración trimestral;
  - revisión manual WCAG;
  - carga inicial verificada y firmada en producción;
  - acta M7.

## Aprobación humana

Pendiente: revisión del líder técnico, aceptación de la UAT y firma del acta de salida (hito M7).

## Anexo: pendientes de desarrollo cerrados en la 0.9.1

Por pedido del responsable, se desarrolló todo lo pendiente que se puede probar en local antes de migrar a Hostinger.

| Pendiente | Qué se hizo | Verificación local |
|---|---|---|
| RF-095 (contrato con simulador) | Firma alineada al anexo E, simulador del receptor y Schemathesis 3.39.7 en la CI (ADR-023) | Schemathesis: 7 comprobaciones, 160 de 160 casos; el simulador aceptó 29 lotes válidos y rechazó el resto con 4xx documentados. `ContratoVentasTest` (4 pruebas) |
| Recifrado (RS-013, D-15) | `ServicioRecifrado`, trabajo `seguridad.recifrar` y `cli/recifrar.php` | `RecifradoTest`: después de rotar, todo se descifra solo con la clave nueva; un trabajo reservado no se toca; repetirlo no hace nada |
| RF-100 (escenario de capacitación) | `GeneradorCapacitacion` y `cli/generar_capacitacion.php` | Las 9 pruebas de oráculos corren sobre el generador; se generó `kora_capacitacion` con 5 cuentas de práctica |
| ASVS V7.4.5 | Cerrar las sesiones de una persona o de todas desde Sistema > Usuarios | `CierreSesionesTest` (22 aserciones) |
| ASVS V4.1.2 | `/health` y `/api` responden 403 por HTTP | MAMP con un host externo: 403 en `/health` y `/api`, y 301 con la ruta original en `/ingresar` |
| ASVS V15.1.2 | SBOM CycloneDX 1.5 en cada paquete | `SbomTest` (302 aserciones) |
| ASVS V15.2.1 | sharp, esbuild y eslint actualizados | `npm audit`: 0 avisos; construcción y ESLint en verde |

**Hallazgos de esta ronda:**

- La redirección a HTTPS de la alternativa B apuntaba a `/public/…`. Se corrigió en el `.htaccess` de la raíz.
- El trabajo `operacion` de la CI fallaba porque `grep -q` cerraba la tubería de `tar`, que salía con error por `pipefail`. Se corrigió.

**Sigue sin poderse hacer en local:**

- el envío continuo de registros a un sistema separado (V16.4.3), que necesita el destino;
- las 4 decisiones de la contraparte en la lista ASVS;
- todo lo que depende de Hostinger y de staging.
