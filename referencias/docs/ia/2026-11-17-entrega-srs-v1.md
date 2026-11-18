# Registro de entrega asistida por IA — SRS v1.0 de Producción CAB

- Fecha: 2026-11-17
- Herramienta y modelo: Claude Code (Claude Opus 5.5, `claude-opus-5-5`)
- Responsable humano: líder técnico del proyecto (revisión pendiente)
- Requisitos y fichas relacionados: todo el SRS-PCAB-001 v1.0 (357 requisitos, FP-01 a FP-11)

## Objetivo
Convertir el prototipo `dashboard_laila` en la especificación formal de un sistema productivo: PHP 8 MVC nativo, HTMX 2, Alpine 3 y Tailwind, sobre Hostinger Cloud. El documento sigue IEEE 830 e ISO/IEC/IEEE 29148 con las extensiones de la plantilla del proveedor y atiende las 6 observaciones de `Revision general_mockup laila.docx`.

## Supuestos
S-01 a S-25 (sección 2.7 del SRS). Los que más afectan el alcance:

- **S-20:** PoD se interpreta como «Protección desde el diseño y por defecto».
- **S-11:** IND_17 se calcula con el gasto ejecutado.
- **S-12:** factores lácteos iniciales en el límite inferior de los rangos de la guía.
- Perfil de carga: unas 60 cuentas y 15 sesiones en el pico.
- Equipo y fecha de inicio: lunes 30 de noviembre de 2026.

## Decisiones
- Decisiones del solicitante:
  - Entregable en Word (.docx).
  - Hosting en Hostinger Cloud (Startup o Professional).
  - Integración por carga asistida de Excel más un contrato de API, sin implementar la API en v1.
  - Estilo Skeuomorfismo funcional sutil.
- Decisiones técnicas: D-01 a D-39 y ADR-001 a ADR-015. Las principales:
  - SQL portable a MariaDB ≥ 10.6 y MySQL ≥ 8.0.
  - CSP estricta, con `allowEval=false` y la versión CSP de Alpine.
  - Cola en base de datos con cron por minuto.
  - Auditoría encadenada con sellado diferido.
  - Sesiones en base de datos.
  - TOTP propio, verificado contra la RFC 6238.

## Archivos creados o modificados
- `docs/srs/fuente/*.md` y `metadatos.json`: fuentes del documento.
- `docs/srs/diagramas/*.svg` y `*.png`: 6 figuras.
- `docs/srs/generador/`: generador, verificador, render de diagramas, exportación PDF y rasterizador.
- `docs/srs/SRS_Produccion_CAB_v1.0.docx` y `.pdf`: el documento tiene 248 páginas.
- `docs/srs/README.md`: explica cómo regenerar el documento.
- Este registro.

No se modificó ningún archivo del prototipo.

## Validaciones ejecutadas
- **`node verificar-srs.mjs`:** «Verificación superada». Revisó 357 requisitos con ID único, referencias existentes, 174 encabezados y los archivos críticos presentes. De 100 bloques de código, 74 tuvieron validación de sintaxis automática:
  - `php -l` con PHP 8.3.
  - `node --check`.
  - `bash -n`.
  - Validación de JSON.
  - Balance de paréntesis en SQL.
- **TOTP:** verificado contra los 5 vectores SHA-1 de la RFC 6238.
- **Documento:** Word actualizó el índice y exportó el PDF. Se revisaron visualmente estas páginas rasterizadas:
  - Portada, control e índice.
  - Tablas de requisitos.
  - Código.
  - Recursos.
  - Gantt.
  - Los 6 diagramas.

## Riesgos
- Supuestos sin validar: perfil de carga, plan Cloud, versión de MariaDB, TRD y factores lácteos.
- Límites reales del plan de Hostinger.
- Posible desfase entre el código de referencia del SRS y la implementación si no se mantiene la matriz de trazabilidad.

## Pendientes
- El DDL no se ejecutó contra MariaDB: el MAMP local trae MySQL 5.7.24 (D-02). Debe ejecutarse en la I0.
- No hay pruebas funcionales, de carga ni de seguridad, porque el sistema aún no existe. Todas están en estado PENDIENTE DE EJECUCIÓN.
- En la I0 deben crearse `docs/procesos`, `docs/ui`, `docs/seguridad`, `docs/rendimiento` y `docs/adr`.

## Criterio humano de aprobación
- **Líder técnico:** coherencia técnica y de seguridad.
- **Contraparte del CAB:** alcance, reglas de negocio, indicadores y las 6 observaciones de la revisión.
- **Evidencia de aprobación:** la firma de la tabla «Revisión y aprobación» en el control del documento.
