## Anexo J. Checklist OWASP ASVS 5.0 nivel 2

Esta lista sirve para verificar cada versión (RGO-009). Se organiza por capítulos de ASVS 5.0.0 y por las áreas de control aplicables al sistema. La numeración detallada de cada requisito del estándar se incorpora en `docs/seguridad/asvs.md` durante la I2, a partir del texto oficial (github.com/OWASP/ASVS, carpeta 5.0). Así se evita citar números de memoria. Estados posibles:

- **Especificado:** el control está definido en este SRS; la evidencia está PENDIENTE DE EJECUCIÓN.
- **NO APLICA:** el control no aplica, con su justificación.

| Capítulo ASVS 5.0 | Área de control | Requisitos del SRS | Verificación prevista | Estado |
|---|---|---|---|---|
| V1 Codificación y saneamiento | Codificación de salida por contexto; consultas parametrizadas; sin interpolación de entrada en SQL; JSON y cabeceras escapados | RS-003, RS-004, D-34 | PHPStan con reglas, ZAP (inyección y XSS), revisión de las plantillas | Especificado |
| V1 Codificación y saneamiento | Inyección de comandos del sistema operativo o LDAP | — | No se ejecutan comandos con entrada del usuario; no hay LDAP | NO APLICA |
| V2 Validación y lógica de negocio | Validación en el servidor con listas permitidas; límites; reglas de negocio (saldos, fechas, cupos); antiautomatización | RS-002, RF-040, RF-043, RF-049, RAC-029 | PU y PI por regla; pruebas de abuso | Especificado |
| V3 Seguridad del frontend web | CSP estricta con nonce; cabeceras; sin `eval`; cookies seguras; protección contra *clickjacking*; SRI no necesaria (sin CDN) | RS-007, RS-008, RS-024, RAC-016 | Prueba de contrato de cabeceras, E2E sin violaciones de CSP | Especificado |
| V4 API y servicios web | API JSON de salud, trabajos y contrato de integración: autenticación HMAC, límites, errores RFC 9457 | RI-007, RF-095, ROB-011 | Schemathesis sobre OpenAPI | Especificado |
| V5 Manejo de archivos | Tipo real, tamaño, bombas ZIP, nombre aleatorio, almacenamiento fuera de la raíz web, descarga segura | RF-084, RS-011, RF-082 | Pruebas con archivos maliciosos | Especificado |
| V6 Autenticación | Contraseñas según NIST 800-63B-4 (longitud, lista de bloqueadas, sin composición); Argon2id; anti-enumeración; recuperación segura; 2FA TOTP | RAC-001 a RAC-014 | PI, PE y PS; vectores RFC 6238 | Especificado |
| V7 Gestión de sesiones | ID opaco de 256 bits, cookie `__Host-`, regeneración, expiración por inactividad y absoluta, revocación y cierre global | RAC-016 a RAC-022 | Pruebas de fijación, expiración y revocación | Especificado |
| V8 Autorización | Denegación por defecto; RBAC con ABAC; verificación en cada solicitud, fragmento y trabajo; aislamiento por centro y unidad; reautenticación | RAC-023 a RAC-028, RDI-001 a RDI-011 | Matriz de autorización probada y acceso cruzado | Especificado |
| V9 Tokens autocontenidos | JWT y similares | — | No se usan tokens autocontenidos (sesión opaca) | NO APLICA |
| V10 OAuth y OIDC | Clientes, servidores de autorización y OIDC | RAC-001 (Entra ID futuro, ADR-015) | Sin OAuth/OIDC en la v1 | NO APLICA (v1) |
| V11 Criptografía | Algoritmos aprobados (Argon2id, XChaCha20-Poly1305, SHA-256, HMAC); aleatoriedad CSPRNG; gestión y rotación de claves | RS-013, RAC-002, D-15 | PU de Cifrador; registro de rotación | Especificado |
| V12 Comunicación segura | TLS 1.2 o superior; HSTS; conexiones a servicios externos con TLS (SMTP) | RS-009, RI-005 | SSL Labs (nota A o mejor) y prueba de SMTP con TLS | Especificado |
| V13 Configuración | Secretos fuera del repositorio; sin modo depuración; dependencias auditadas; SBOM; sin archivos sobrantes | RS-012, RS-015, RNF-012, ROB-009 | CI (gitleaks, `composer audit`), revisión de la configuración | Especificado |
| V14 Protección de datos | Minimización de datos personales, enmascaramiento, `no-store` en lo sensible, sin datos sensibles en el navegador, retención | RF-034, RS-014, RS-024, RDL-006, RDL-007 | Búsqueda de datos personales y contrato de cabeceras | Especificado |
| V15 Codificación y arquitectura seguras | Modelo de amenazas, componentes con mínimo privilegio, revisión humana del código generado con IA, dependencias mínimas | RS-017, RS-018, RNF-011, ADR-005 | Revisión por PR y PHPStan | Especificado |
| V16 Registro de seguridad y manejo de errores | Auditoría de eventos de seguridad, registro estructurado sin secretos, errores sin información interna, integridad de la auditoría | RAU-001 a RAU-011, RS-010 | PI de la auditoría y verificación de la cadena | Especificado |
| V17 WebRTC | Medios en tiempo real | — | No se usa WebRTC | NO APLICA |

**Firma por versión:** responsable de seguridad del proveedor, responsable de TI del centro, fecha y versión. Los hallazgos abiertos se registran con su severidad, su plan y su fecha.
