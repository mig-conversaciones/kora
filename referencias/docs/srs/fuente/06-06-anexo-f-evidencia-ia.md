## Anexo F. Evidencia de generación con IA

### F.1 Plantilla del registro por entrega (`docs/ia/AAAA-MM-DD-<tema>.md`)

```markdown
# Registro de entrega asistida por IA — <tema>

- Fecha: AAAA-MM-DD
- Herramienta y modelo: <herramienta, modelo y versión>
- Responsable humano: <rol>
- Requisitos y fichas relacionados: <IDs>

## Objetivo
<Qué se pidió y para qué.>

## Supuestos
<Supuestos usados, con referencia a 2.7 si aplica.>

## Decisiones
<Decisiones tomadas y su justificación; ADR creados o modificados.>

## Archivos creados o modificados
<Lista con su propósito.>

## Validaciones ejecutadas
<Comandos, pruebas y resultados reales (no esperados).>

## Riesgos
<Riesgos técnicos o de seguridad introducidos o identificados.>

## Pendientes
<Lo que no se hizo o quedó por verificar.>

## Criterio humano de aprobación
<Quién revisa, qué debe verificar y la evidencia de aprobación (PR, acta).>
```

### F.2 Registro de esta entrega (SRS v1.0)

| Campo | Contenido |
|---|---|
| Fecha | 17 de noviembre de 2026 |
| Herramienta | Asistente de IA (Claude Code) en el repositorio del prototipo, con revisión humana pendiente |
| Objetivo | Generar el SRS completo de Producción CAB en Word formal a partir de la plantilla del proveedor, la guía de especificaciones (con sus comentarios), el prototipo y su verificación, los Excel fuente y la revisión del mockup |
| Decisiones del solicitante | Entregable en Word (.docx); hosting en Hostinger Cloud (Startup o Professional); integración con carga asistida de Excel y contrato de API; estilo de Skeuomorfismo funcional sutil |
| Supuestos | S-01 a S-25 (2.7), entre ellos PoD como «Protección desde el diseño» (S-20), IND_17 con el gasto ejecutado (S-11) y los factores lácteos iniciales (S-12) |
| Decisiones técnicas | D-01 a D-39 (2.8, 4.3, 4.7) y ADR-001 a ADR-015 (4.1.3) |
| Evidencia consultada | Capturas de las 6 observaciones de la revisión (extraídas del .docx de revisión); comentarios de la guía; código y pruebas del prototipo; estructura de los Excel; documentación pública de Hostinger (MariaDB en Web y Cloud, cron, límites de los planes v6), OWASP ASVS 5.0, NIST SP 800-63B-4, htmx 2, Alpine CSP, WCAG 2.2 y festivos de Colombia 2026–2027 |
| Archivos creados | `docs/srs/fuente/*.md` (fuentes por sección); `docs/srs/fuente/metadatos.json`; `docs/srs/diagramas/*.svg` y `*.png`; `docs/srs/generador/` (generador .docx, verificador, render de diagramas, exportación a PDF por Word y rasterizador); `docs/srs/SRS_Produccion_CAB_v1.0.docx` y `.pdf`; `docs/srs/README.md`; este registro en `docs/ia/`. No se modificó ningún archivo del prototipo. |
| Validaciones ejecutadas | Verificador estructural: identificadores únicos, referencias existentes, encabezados obligatorios, archivos críticos y ausencia de marcadores. `php -l` (PHP 8.3) sobre los bloques PHP; `node --check` sobre JS y k6; `bash -n` sobre los scripts; JSON validado; balance de paréntesis en SQL. TOTP verificado contra los 5 vectores SHA-1 de la RFC 6238 (anexo B, recortados a 6 dígitos). Generación del .docx, actualización del índice y exportación a PDF con Microsoft Word, más revisión visual de páginas rasterizadas. |
| Validaciones no ejecutadas | El SQL no se ejecutó contra MariaDB, porque no hay una instancia de la versión de producción en el equipo (el MAMP local trae MySQL 5.7.24, D-02). No se ejecutaron pruebas funcionales, de carga ni de seguridad: el sistema aún no existe. Todo su estado es PENDIENTE DE EJECUCIÓN. |
| Riesgos | Supuestos sin validar (perfil de carga, plan Cloud y versión de MariaDB, TRD); límites reales de Hostinger; posible desfase entre el código de referencia y la implementación final si no se mantiene la trazabilidad |
| Pendientes | Validar los supuestos según el calendario (5.1); ejecutar el DDL en MariaDB en la I0; materializar `docs/procesos`, `docs/ui`, `docs/seguridad`, `docs/rendimiento` y `docs/adr` a partir de este SRS en la I0 |
| Criterio humano de aprobación | Revisión del líder técnico (coherencia técnica y seguridad) y de la contraparte del CAB (alcance, reglas, indicadores y observaciones). Firma de la tabla «Revisión y aprobación» del control del documento. |
